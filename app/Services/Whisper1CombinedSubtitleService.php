<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Builds the reviewable subtitle operation from the same Whisper-1 transcription response. */
class Whisper1CombinedSubtitleService
{
    public function __construct(
        private readonly WhisperxAlignmentExecutor $artifacts,
        private readonly SubtitleChunkOverlapDeduplicator $overlapDeduplicator,
    ) {}

    public function createFromTranscript(AiOperationUsage $transcript): ?AiOperationUsage
    {
        $transcript->refresh();
        $transcriptResult = is_array($transcript->result) ? $transcript->result : [];
        if ($transcript->service !== 'transcription'
            || ($transcriptResult['processing_method'] ?? null) !== SubtitleProcessingMethod::OPENAI_WHISPER1
            || $transcript->model !== 'whisper-1'
            || !is_string($transcriptResult['text'] ?? null)
            || trim($transcriptResult['text']) === '') return null;

        $key = 'whisper1-subtitle-'.$transcript->id;
        $requestFingerprint = hash('sha256', implode('|', [
            (string) $transcript->id,
            hash('sha256', $transcriptResult['text']),
            hash('sha256', (string) ($transcriptResult['raw_response'] ?? '')),
            (string) ($transcript->timeline_offset_seconds ?? 0),
        ]));
        $usage = AiOperationUsage::query()->firstOrCreate(['idempotency_key' => $key], [
            'ai_integration_id' => $transcript->ai_integration_id,
            'service' => WhisperxAlignmentService::ALIGNMENT_SERVICE,
            'model' => 'whisper-1/combined-transcription',
            'lesson_id' => $transcript->lesson_id,
            'timeline_offset_seconds' => $transcript->timeline_offset_seconds,
            'status' => 'processing',
            'request_reference' => $transcript->request_reference,
            'request_fingerprint' => $requestFingerprint,
            'result' => ['transcript_operation_id' => $transcript->id, 'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1],
        ]);

        $existing = is_array($usage->result) ? $usage->result : [];
        if (in_array($usage->status, ['succeeded', 'partial'], true) && is_string($existing['outputs']['srt'] ?? null)
            && Storage::disk('local')->exists($existing['outputs']['srt'])) {
            $transcriptResult['raw_response_path'] ??= $existing['outputs']['raw_response'] ?? null;
            unset($transcriptResult['raw_response']);
            $transcriptResult['transcript_source'] = 'openai_whisper1_response';
            $transcript->update(['result' => $transcriptResult]);
            return $usage;
        }
        if (!hash_equals((string) $usage->request_fingerprint, $requestFingerprint)) {
            throw new RuntimeException('The saved Whisper-1 subtitle response does not match its operation.');
        }

        $text = $transcriptResult['text'];
        $responseWords = $transcriptResult['words'] ?? null;
        $duration = $transcriptResult['duration_seconds'] ?? null;
        $rawResponse = $transcriptResult['raw_response'] ?? null;
        if (!is_array($responseWords) || $responseWords === [] || !is_numeric($duration)
            || !is_finite((float) $duration) || (float) $duration <= 0 || !is_string($rawResponse)) {
            $directory = 'whisperx-alignments/'.(int) $transcript->lesson_id.'/'.(int) $usage->id;
            $disk = Storage::disk('local');
            $disk->makeDirectory($directory);
            $rawPath = $directory.'/whisper1-response.json';
            $rawStored = is_string($rawResponse) && $disk->put($rawPath, $rawResponse);
            $transcriptResult['transcript_source'] = 'openai_whisper1_response';
            if ($rawStored) $transcriptResult['raw_response_path'] = $rawPath;
            unset($transcriptResult['raw_response']);
            $transcript->update(['result' => $transcriptResult]);
            $message = !is_numeric($duration) || !is_finite((float) $duration) || (float) $duration <= 0
                ? 'Whisper-1 returned an invalid audio duration; word timestamps could not be reviewed safely.'
                : 'The Whisper-1 response did not contain usable word timestamp data.';
            $usage->update([
                'status' => 'failed', 'error_code' => 'InvalidWordTimestampResponse', 'error_message' => $message,
                'request_reference' => $transcript->request_reference,
                'result' => array_merge($existing, [
                    'transcript_operation_id' => $transcript->id,
                    'transcript_sha256' => hash('sha256', $text),
                    'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
                    'effective_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
                    'review_available' => false, 'timestamps_complete' => false,
                    'outputs' => $rawStored ? ['raw_response' => $rawPath] : null,
                    'warning' => $message, 'processing_completed_at' => now()->toIso8601String(),
                ]),
            ]);
            return $usage->fresh();
        }

        $offset = (float) ($transcript->timeline_offset_seconds ?? 0);
        $mapping = $this->mapResponseWords($responseWords, (float) $duration);
        $overlap = $this->overlapContext($transcript, $text);
        $mapping = $this->removeVerifiedOverlapWords($mapping, $overlap, $offset);
        $overlap = $mapping['overlap_context'] ?? $overlap;
        $requestDurationMs = (int) ($transcriptResult['request_duration_ms'] ?? $transcript->processing_duration_ms ?? 0);
        $apiUsage = is_array($transcriptResult['usage'] ?? null) ? $transcriptResult['usage'] : [];

        $artifacts = $this->artifacts->saveWhisper1Words(
            (int) $transcript->lesson_id,
            (int) $usage->id,
            $text,
            $offset,
            (float) $duration,
            $mapping,
            $rawResponse,
            $apiUsage,
            $transcript->request_reference,
            $requestDurationMs,
            $overlap,
        );

        $complete = ($artifacts['timestamps_complete'] ?? false) === true;
        $result = array_merge($existing, $artifacts, [
            'transcript_operation_id' => $transcript->id,
            'transcript_sha256' => hash('sha256', $text),
            'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'effective_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'review_available' => is_string($artifacts['outputs']['srt'] ?? null) && trim(Storage::disk('local')->get($artifacts['outputs']['srt'])) !== '',
            'approval_status' => 'pending_admin_review',
            'processing_completed_at' => now()->toIso8601String(),
            'warning' => $complete ? null : 'Some Whisper-1 words have missing or invalid timestamps. Review the diagnostics and correct the SRT before approval.',
        ]);
        $usage->update([
            'status' => $complete ? 'succeeded' : 'partial',
            'audio_minutes' => $apiUsage['audio_minutes'] ?? $transcript->audio_minutes,
            'request_reference' => $transcript->request_reference,
            'error_code' => $complete ? null : 'WordTimestampsIncomplete',
            'error_message' => $complete ? null : 'Whisper-1 returned an English transcript with incomplete word timestamps. Review and correct the subtitle draft before approval.',
            'result' => $result,
        ]);

        $transcriptResult['raw_response_path'] = $artifacts['outputs']['raw_response'] ?? null;
        unset($transcriptResult['raw_response']);
        $transcriptResult['transcript_source'] = 'openai_whisper1_response';
        $transcript->update(['result' => $transcriptResult]);

        return $usage->fresh();
    }

    private function mapResponseWords(array $responseWords, float $duration): array
    {
        $words = [];
        $missing = [];
        $orderingViolations = [];
        $previousStart = null;
        $previousEnd = null;
        foreach (array_values($responseWords) as $index => $item) {
            $word = is_array($item) && is_string($item['word'] ?? null) ? trim($item['word']) : '';
            if ($word === '') {
                $missing[] = ['word' => '', 'source_token_index' => $index, 'reason' => 'missing_word_text'];
                $words[] = ['text' => '', 'start' => null, 'end' => null, 'status' => 'missing_word_text', 'source' => 'whisper1_transcription_response'];
                continue;
            }
            $start = is_array($item) && is_numeric($item['start'] ?? null) ? (float) $item['start'] : null;
            $end = is_array($item) && is_numeric($item['end'] ?? null) ? (float) $item['end'] : null;
            $valid = $start !== null && $end !== null && is_finite($start) && is_finite($end)
                && $start >= 0 && $end > $start && $end <= $duration;
            $ordered = $valid && ($previousStart === null || ($start >= $previousStart && $end >= $previousEnd));
            if ($valid && !$ordered) {
                $orderingViolations[] = ['source_token_index' => $index, 'word' => $word, 'start' => $start, 'end' => $end];
                $valid = false;
            }
            if (!$valid) {
                $reason = $start === null || $end === null ? 'missing_timestamp' : 'invalid_timestamp';
                $missing[] = ['word' => $word, 'source_token_index' => $index, 'reason' => $reason];
            } else {
                $previousStart = $start;
                $previousEnd = $end;
            }
            $words[] = [
                'text' => $word,
                'start' => $valid ? $start : null,
                'end' => $valid ? $end : null,
                'status' => $valid ? 'aligned' : 'missing_timestamp',
                'source' => 'whisper1_transcription_response',
            ];
        }

        $count = count($words);
        $aligned = count(array_filter($words, fn (array $word): bool => is_numeric($word['start']) && is_numeric($word['end'])));
        return [
            'words' => $words, 'total_words' => $count, 'api_word_count' => count($responseWords),
            'successfully_aligned_words' => $aligned, 'missing_or_unreliable_words' => $count - $aligned,
            'missing_words' => $missing, 'added_api_words' => [], 'ambiguous_words' => [],
            'timestamp_order_violations' => $orderingViolations, 'timestamps_ordered' => $orderingViolations === [],
        ];
    }

    private function overlapContext(AiOperationUsage $transcript, string $text): array
    {
        if (!preg_match('/^prep-(\d+)-chunk-(\d+)$/', (string) $transcript->idempotency_key, $matches) || (int) $matches[2] < 2) {
            return ['status' => 'no_previous_chunk'];
        }
        $previous = AiOperationUsage::query()->where('service', 'transcription')->where('lesson_id', $transcript->lesson_id)
            ->where('idempotency_key', 'prep-'.$matches[1].'-chunk-'.((int) $matches[2] - 1))->first();
        $previousResult = is_array($previous?->result) ? $previous->result : [];
        if (!$previous || $previous->status !== 'succeeded' || !is_string($previousResult['text'] ?? null)
            || !is_numeric($previousResult['duration_seconds'] ?? null)) return ['status' => 'previous_chunk_not_ready'];
        $previousEnd = (float) ($previous->timeline_offset_seconds ?? 0) + (float) $previousResult['duration_seconds'];
        $offset = (float) ($transcript->timeline_offset_seconds ?? 0);
        if ($previousEnd <= $offset) return ['status' => 'no_overlap'];
        return [
            'status' => 'checked', 'approved_text' => $previousResult['text'],
            'previous_chunk_end_seconds' => $previousEnd,
            'matched_prefix_token_count' => $this->overlapDeduplicator->duplicatePrefixLength((string) $previousResult['text'], $text),
        ];
    }

    private function removeVerifiedOverlapWords(array $mapping, array $overlap, float $offset): array
    {
        if (($overlap['status'] ?? null) !== 'checked') {
            if (in_array($overlap['status'] ?? null, ['previous_chunk_not_ready'], true)) {
                $overlap['warning'] = 'The previous overlapping chunk was not ready; no possible duplicates were removed.';
            }
            return $mapping + ['overlap_context' => $overlap];
        }
        $prefixLength = (int) ($overlap['matched_prefix_token_count'] ?? 0);
        $previousEnd = (float) $overlap['previous_chunk_end_seconds'];
        $duplicates = 0;
        for ($index = 0; $index < min($prefixLength, count($mapping['words'])); $index++) {
            $word = $mapping['words'][$index];
            if (!is_numeric($word['start'] ?? null) || !is_numeric($word['end'] ?? null)) continue;
            $absoluteStart = (float) $word['start'] + $offset;
            $absoluteEnd = (float) $word['end'] + $offset;
            if ($absoluteStart < $previousEnd && $absoluteEnd <= $previousEnd + 0.15) {
                $mapping['words'][$index]['status'] = 'overlap_duplicate';
                $mapping['words'][$index]['reason'] = 'duplicate_api_text_in_audio_overlap';
                $duplicates++;
            }
        }
        $mapping['overlap_context'] = [
            'status' => 'checked', 'matched_prefix_token_count' => $prefixLength,
            'duplicate_word_count' => $duplicates, 'previous_chunk_end_seconds' => round($previousEnd, 3),
            'current_chunk_start_seconds' => round($offset, 3),
        ];
        return $mapping;
    }
}
