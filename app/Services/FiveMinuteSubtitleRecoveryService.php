<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Jobs\PrepareLessonAudioForSubtitles;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Registers an existing private transcript/alignment as new, unapproved recovery drafts. */
class FiveMinuteSubtitleRecoveryService
{
    public function recover(array $manifest, bool $dryRun = false): array
    {
        $artifacts = $this->validateArtifacts($manifest);
        if ($dryRun) return ['dry_run' => true] + $this->inspectDatabase($manifest, $artifacts, false);

        return DB::transaction(function () use ($manifest, $artifacts): array {
            $state = $this->inspectDatabase($manifest, $artifacts, true);
            $lesson = Lesson::query()->whereKey($manifest['lesson_id'])->lockForUpdate()->firstOrFail();
            $operation = AiOperationUsage::query()->whereKey($manifest['preparation_operation_id'])->lockForUpdate()->firstOrFail();

            if ($state['cancel_job']) {
                $job = DB::table('jobs')->where('id', $manifest['queue_job_id'])->lockForUpdate()->first();
                $this->assertExactQueuedPreparation($job, $operation, $lesson, $manifest);
                $deleted = DB::table('jobs')->where('id', $job->id)
                    ->where('queue', $manifest['queue'])
                    ->where('attempts', 0)
                    ->whereNull('reserved_at')
                    ->where('payload', $job->payload)
                    ->delete();
                if ($deleted !== 1) throw new RuntimeException('The queued preparation job changed while recovery was starting; no records were changed.');
                $operation->update([
                    'status' => 'cancelled',
                    'error_code' => 'RecoverySuperseded',
                    'error_message' => 'Cancelled before execution because this existing subtitle sample is being recovered; this job did not prepare audio or queue transcription.',
                ]);
            }

            $transcript = $this->findOrCreateRecoveredOperation($manifest, $artifacts, 'transcription');
            $alignment = $this->findOrCreateRecoveredOperation($manifest, $artifacts, 'whisperx_alignment', $transcript);

            return [
                'dry_run' => false,
                'queue_job_cancelled' => $state['cancel_job'],
                'preparation_operation_id' => $operation->id,
                'transcript_operation_id' => $transcript->id,
                'alignment_operation_id' => $alignment->id,
                'lesson_id' => $lesson->id,
                'transcript_sha256' => $artifacts['transcript_sha256'],
                'srt_sha256' => $artifacts['srt_sha256'],
                'audio_input_association' => 'unverified',
                'approval_status' => 'pending_admin_review',
            ];
        }, 3);
    }

    private function validateArtifacts(array $manifest): array
    {
        $disk = Storage::disk('local');
        $contents = [];
        $hashes = [];
        foreach ($manifest['files'] as $key => $file) {
            $path = $file['path'] ?? null;
            $expected = $file['sha256'] ?? null;
            if (!is_string($path) || str_contains($path, '..') || str_starts_with($path, '/') || str_contains($path, '\\')
                || !is_string($expected) || !preg_match('/^[a-f0-9]{64}$/i', $expected) || !$disk->exists($path)) {
                throw new RuntimeException("A required recovery artifact is unavailable: {$key}.");
            }
            $bytes = $disk->get($path);
            $actual = hash('sha256', $bytes);
            if (!hash_equals(strtolower($expected), $actual)) throw new RuntimeException("A recovery artifact changed after inspection: {$key}.");
            $contents[$key] = $bytes;
            $hashes[$key] = $actual;
        }

        if (!mb_check_encoding($contents['transcript'], 'UTF-8') || trim($contents['transcript']) === '') {
            throw new RuntimeException('The recovered transcript is empty or is not UTF-8.');
        }
        $originalJson = json_decode($contents['aligned_json'], true, flags: JSON_THROW_ON_ERROR);
        $optimizedJson = json_decode($contents['optimized_json'], true, flags: JSON_THROW_ON_ERROR);
        $report = json_decode($contents['alignment_report'], true, flags: JSON_THROW_ON_ERROR);
        $optimizedReport = json_decode($contents['optimized_report'], true, flags: JSON_THROW_ON_ERROR);
        $words = $originalJson['words'] ?? null;
        $optimizedWords = $optimizedJson['words'] ?? null;
        $expectedWords = (int) ($manifest['expected_word_count'] ?? 586);
        $expectedCues = (int) ($manifest['expected_cue_count'] ?? 47);
        $expectedDuration = (float) ($manifest['expected_audio_duration_seconds'] ?? 300.278);
        if (!is_array($words) || !is_array($optimizedWords) || count($words) !== $expectedWords || count($optimizedWords) !== count($words)
            || (int) ($report['total_words'] ?? 0) !== count($words)
            || (int) ($report['successfully_aligned_words'] ?? 0) !== count($words)
            || (int) ($report['missing_or_unreliable_words'] ?? -1) !== 0
            || abs((float) ($report['audio_duration_seconds'] ?? 0) - $expectedDuration) > 0.001
            || !str_contains(mb_strtolower((string) ($report['timeline'] ?? '')), 'no original timeline offset is applied')) {
            throw new RuntimeException('The recovered alignment report does not match the inspected five-minute sample.');
        }
        foreach ($words as $index => $word) {
            $other = $optimizedWords[$index] ?? null;
            foreach (['text', 'start', 'end', 'status'] as $field) {
                if (($word[$field] ?? null) !== ($other[$field] ?? null)) throw new RuntimeException('The original and optimized word timelines no longer match.');
            }
        }
        if ((int) ($optimizedReport['total_words'] ?? 0) !== count($words)
            || (int) ($optimizedReport['successfully_aligned_words'] ?? 0) !== count($words)
            || abs((float) ($optimizedReport['audio_duration_seconds'] ?? 0) - (float) $report['audio_duration_seconds']) > 0.001
            || ($optimizedReport['timeline'] ?? null) !== ($report['timeline'] ?? null)) {
            throw new RuntimeException('The optimized alignment report is invalid.');
        }

        $srtService = app(SubtitleSrtCueService::class);
        $cues = $srtService->parse($contents['aligned_srt']);
        $optimizedCues = $srtService->parse($contents['optimized_srt']);
        $srtService->assertSameTiming($cues, $optimizedCues);
        if (count($cues) !== $expectedCues || $this->compactText($contents['transcript']) !== $this->compactText(implode(' ', array_column($words, 'text')))
            || $this->compactText($contents['transcript']) !== $this->compactText(implode(' ', array_column($cues, 'text')))) {
            throw new RuntimeException('The recovered transcript, aligned words, and 47 SRT cues do not agree.');
        }

        $audioCandidates = [];
        foreach (['audio', 'chunk'] as $candidate) {
            $audioCandidates[$candidate] = [
                'path' => $manifest['files'][$candidate === 'audio' ? 'audio' : 'chunk']['path'],
                'sha256' => $hashes[$candidate === 'audio' ? 'audio' : 'chunk'],
                'size_bytes' => strlen($contents[$candidate]),
            ];
        }
        if (hash_equals($audioCandidates['audio']['sha256'], $audioCandidates['chunk']['sha256'])) {
            throw new RuntimeException('The inspected audio files no longer have distinct hashes; review the recovery manifest.');
        }

        return [
            'contents' => $contents,
            'hashes' => $hashes,
            'words' => $words,
            'report' => $report,
            'optimized_report' => $optimizedReport,
            'cues' => $cues,
            'audio_candidates' => $audioCandidates,
            'transcript_sha256' => $hashes['transcript'],
            'srt_sha256' => $hashes['aligned_srt'],
        ];
    }

    private function inspectDatabase(array $manifest, array $artifacts, bool $lock): array
    {
        $lessonQuery = Lesson::query()->whereKey($manifest['lesson_id']);
        $operationQuery = AiOperationUsage::query()->whereKey($manifest['preparation_operation_id']);
        if ($lock) { $lessonQuery->lockForUpdate(); $operationQuery->lockForUpdate(); }
        $lesson = $lessonQuery->first();
        $operation = $operationQuery->first();
        if (!$lesson || !$operation || $lesson->video_path !== $manifest['video_path']
            || $operation->service !== 'audio_preparation' || (int) $operation->lesson_id !== (int) $lesson->id
            || ($operation->result['video_sha256'] ?? null) !== $manifest['video_sha256']) {
            throw new RuntimeException('Lesson 4 or its queued preparation operation no longer matches the inspected source video.');
        }
        $videoPath = Storage::disk('local')->path($lesson->video_path);
        if (!is_file($videoPath) || !hash_equals($manifest['video_sha256'], hash_file('sha256', $videoPath))) {
            throw new RuntimeException('The Lesson 4 source video hash changed after inspection.');
        }

        $transcriptKey = $this->idempotencyKey('transcription', $manifest, $artifacts);
        $alignmentKey = $this->idempotencyKey('whisperx_alignment', $manifest, $artifacts);
        $this->assertNoConflictingOperations($manifest['lesson_id'], $transcriptKey, $alignmentKey);
        $existingTranscript = AiOperationUsage::query()->where('idempotency_key', $transcriptKey)->first();
        $existingAlignment = AiOperationUsage::query()->where('idempotency_key', $alignmentKey)->first();
        foreach ([[$existingTranscript, 'transcription'], [$existingAlignment, 'whisperx_alignment']] as [$existing, $service]) {
            if ($existing && ($existing->service !== $service || (int) $existing->lesson_id !== (int) $lesson->id
                || $existing->request_fingerprint !== $this->fingerprint($service, $manifest, $artifacts))) {
                throw new RuntimeException("An idempotency key for recovered {$service} data is already associated with different content.");
            }
        }
        if ($existingTranscript && !$existingAlignment
            && ($existingTranscript->result['transcript_draft']['text'] ?? $existingTranscript->result['text'] ?? null) !== $artifacts['contents']['transcript']) {
            throw new RuntimeException('The recovered transcript draft has since been edited; refusing to create an alignment that would not match it.');
        }

        $job = DB::table('jobs')->where('id', $manifest['queue_job_id'])->first();
        $alreadyCancelled = !$job && $operation->status === 'cancelled' && $operation->error_code === 'RecoverySuperseded';
        if (!$alreadyCancelled) $this->assertExactQueuedPreparation($job, $operation, $lesson, $manifest);

        return ['cancel_job' => !$alreadyCancelled, 'lesson' => $lesson, 'operation' => $operation,
            'existing_transcript_id' => $existingTranscript?->id, 'existing_alignment_id' => $existingAlignment?->id,
            'source_audio_candidates' => $artifacts['audio_candidates'],
            'transcript_sha256' => $artifacts['transcript_sha256'], 'srt_sha256' => $artifacts['srt_sha256']];
    }

    private function assertExactQueuedPreparation(?object $job, AiOperationUsage $operation, Lesson $lesson, array $manifest): void
    {
        if (!$job || (int) $job->attempts !== 0 || $job->reserved_at !== null || $job->queue !== $manifest['queue']
            || $operation->status !== 'queued' || $operation->error_code !== null) {
            throw new RuntimeException('The Lesson 4 preparation job is no longer unreserved with zero attempts; cancellation and recovery were aborted.');
        }
        try {
            $payload = json_decode($job->payload, true, flags: JSON_THROW_ON_ERROR);
            $serialized = $payload['data']['command'] ?? null;
            $command = is_string($serialized) ? unserialize($serialized, ['allowed_classes' => false]) : null;
            $properties = is_object($command) ? get_object_vars($command) : [];
        } catch (\Throwable) {
            throw new RuntimeException('The queued preparation payload could not be safely verified; recovery was aborted.');
        }
        if (($payload['displayName'] ?? null) !== PrepareLessonAudioForSubtitles::class
            || ($payload['data']['commandName'] ?? null) !== PrepareLessonAudioForSubtitles::class
            || ($properties['__PHP_Incomplete_Class_Name'] ?? null) !== PrepareLessonAudioForSubtitles::class
            || (int) ($properties['usageId'] ?? 0) !== (int) $operation->id
            || ($properties['connection'] ?? null) !== $manifest['connection']
            || ($properties['queue'] ?? null) !== $manifest['queue']
            || ($properties['afterCommit'] ?? false) !== true) {
            throw new RuntimeException('The queued row is not the exact inspected preparation job; recovery was aborted.');
        }
        if ((int) $operation->lesson_id !== (int) $lesson->id
            || $operation->idempotency_key !== $manifest['preparation_idempotency_key']
            || isset($operation->result['preparation'])) {
            throw new RuntimeException('The preparation operation no longer matches the inspected lesson and idempotency key.');
        }
    }

    private function assertNoConflictingOperations(int $lessonId, string $transcriptKey, string $alignmentKey): void
    {
        $transcriptExists = AiOperationUsage::query()->where('lesson_id', $lessonId)->where('service', 'transcription')
            ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $transcriptKey))->exists();
        $alignmentExists = AiOperationUsage::query()->where('lesson_id', $lessonId)->where('service', 'whisperx_alignment')
            ->where(fn ($query) => $query->whereNull('idempotency_key')->orWhere('idempotency_key', '!=', $alignmentKey))->exists();
        if ($transcriptExists || $alignmentExists) throw new RuntimeException('A transcript or alignment operation now exists for Lesson 4; recovery was aborted to avoid duplicates.');
    }

    private function findOrCreateRecoveredOperation(array $manifest, array $artifacts, string $service, ?AiOperationUsage $transcript = null): AiOperationUsage
    {
        $key = $this->idempotencyKey($service, $manifest, $artifacts);
        $fingerprint = $this->fingerprint($service, $manifest, $artifacts);
        $existing = AiOperationUsage::query()->where('idempotency_key', $key)->first();
        if ($existing) {
            if ($existing->status !== 'succeeded') throw new RuntimeException('An existing recovered operation is not in its expected reviewable state.');
            return $existing;
        }

        $now = now()->toIso8601String();
        $provenance = [
            'recovered_artifact' => true,
            'recovered_at' => $now,
            'recovery_source' => 'existing_private_files',
            'workspace' => $manifest['workspace'],
            'source_audio_candidates' => $artifacts['audio_candidates'],
            'alignment_input_association' => 'unverified',
            'timeline_offset_status' => 'relative_to_supplied_audio_chunk_no_original_offset_applied',
            'timeline_offset_seconds' => 0.0,
            'audio_duration_seconds' => (float) $artifacts['report']['audio_duration_seconds'],
            'alignment_report' => $artifacts['report'],
            'alignment_report_sha256' => $artifacts['hashes']['alignment_report'],
            'source_file_hashes' => ['lesson_video' => $manifest['video_sha256']] + $artifacts['hashes'],
            'comparison_outputs' => [
                'optimized_json_path' => $manifest['files']['optimized_json']['path'],
                'optimized_json_sha256' => $artifacts['hashes']['optimized_json'],
                'optimized_srt_path' => $manifest['files']['optimized_srt']['path'],
                'optimized_srt_sha256' => $artifacts['hashes']['optimized_srt'],
                'optimized_report_path' => $manifest['files']['optimized_report']['path'],
                'optimized_report_sha256' => $artifacts['hashes']['optimized_report'],
                'optimized_alignment_duration_seconds' => $artifacts['optimized_report']['alignment_duration_seconds'] ?? null,
            ],
        ];

        if ($service === 'transcription') {
            $text = $artifacts['contents']['transcript'];
            $result = [
                'text' => $text,
                'language' => 'en',
                'transcript_draft' => ['text' => $text, 'sha256' => $artifacts['transcript_sha256'], 'recovered_at' => $now],
                'transcript_review' => ['status' => 'pending_review', 'language' => null],
                'recovery' => $provenance + [
                    'source_transcript_path' => $manifest['files']['transcript']['path'],
                    'source_transcript_sha256' => $artifacts['transcript_sha256'],
                    'aligned_word_count' => count($artifacts['words']),
                ],
            ];
            $model = 'recovered-transcript-file';
            $timelineOffset = 0.0;
        } else {
            if (!$transcript) throw new RuntimeException('The recovered transcript operation is missing.');
            $report = $artifacts['report'];
            $result = [
                'transcript_operation_id' => $transcript->id,
                'transcript_sha256' => $artifacts['transcript_sha256'],
                'approval_status' => 'pending_admin_review',
                'approval_statuses' => ['en' => 'pending_review'],
                'outputs' => [
                    'srt' => $manifest['files']['aligned_srt']['path'],
                    'json' => $manifest['files']['aligned_json']['path'],
                    'report' => $manifest['files']['alignment_report']['path'],
                ],
                'total_words' => (int) $report['total_words'],
                'successfully_aligned_words' => (int) $report['successfully_aligned_words'],
                'missing_or_unreliable_words' => (int) $report['missing_or_unreliable_words'],
                'audio_duration_seconds' => (float) $report['audio_duration_seconds'],
                'alignment_duration_seconds' => (float) $report['alignment_duration_seconds'],
                'model_used' => (string) $report['model_used'],
                'processing_device' => (string) $report['processing_device'],
                'timeline_offset_seconds' => 0.0,
                'recovery' => $provenance + [
                    'source_alignment_json_path' => $manifest['files']['aligned_json']['path'],
                    'source_alignment_json_sha256' => $artifacts['hashes']['aligned_json'],
                    'source_srt_path' => $manifest['files']['aligned_srt']['path'],
                    'source_srt_sha256' => $artifacts['srt_sha256'],
                    'source_transcript_path' => $manifest['files']['transcript']['path'],
                    'source_transcript_sha256' => $artifacts['transcript_sha256'],
                    'whisperx_package_version' => 'not recorded in the recovered artifacts',
                    'approval_status' => 'pending_admin_review',
                    'cue_count' => count($artifacts['cues']),
                ],
            ];
            $model = 'recovered-alignment-files/'.(string) $report['model_used'];
            $timelineOffset = 0.0;
        }

        return AiOperationUsage::query()->create([
            'ai_integration_id' => null,
            'service' => $service,
            'model' => $model,
            'lesson_id' => $manifest['lesson_id'],
            'timeline_offset_seconds' => $timelineOffset,
            'status' => 'succeeded',
            'request_reference' => (string) str()->uuid(),
            'idempotency_key' => $key,
            'request_fingerprint' => $fingerprint,
            'result' => $result,
        ]);
    }

    private function idempotencyKey(string $service, array $manifest, array $artifacts): string
    {
        $prefix = $service === 'transcription' ? 'subtitle-recovery-transcript' : 'subtitle-recovery-alignment';
        $sourceHash = $service === 'transcription' ? $artifacts['transcript_sha256'] : $artifacts['srt_sha256'];
        return $prefix.'-'.$manifest['lesson_id'].'-'.substr($sourceHash, 0, 24);
    }

    private function fingerprint(string $service, array $manifest, array $artifacts): string
    {
        return hash('sha256', json_encode([
            'recovery' => true,
            'service' => $service,
            'lesson_id' => $manifest['lesson_id'],
            'workspace' => $manifest['workspace'],
            'source_file_hashes' => $artifacts['hashes'],
            'lesson_video_sha256' => $manifest['video_sha256'],
            'alignment_input_association' => 'unverified',
            'timeline_offset_status' => 'relative_to_supplied_audio_chunk_no_original_offset_applied',
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function compactText(string $text): string
    {
        return preg_replace('/\s+/u', '', $text) ?? '';
    }
}
