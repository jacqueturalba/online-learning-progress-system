<?php

namespace App\Services;

use App\Models\AiIntegration;
use App\Models\AiOperationUsage;
use App\Services\OpenAiProvider;
use App\Support\AiProviderException;
use RuntimeException;

class OpenAiWhisper1Executor
{
    public function __construct(
        private readonly WhisperxAlignmentExecutor $artifacts,
        private readonly ApprovedTranscriptWordMatcher $matcher,
        private readonly SubtitleChunkOverlapDeduplicator $overlapDeduplicator,
    ) {}

    public function process(AiOperationUsage $usage, string $audioRelativePath, string $approvedTranscript, float $timelineOffsetSeconds, array $overlapContext = [], ?float $audioDurationSeconds = null, ?int $jobAttempt = null): array
    {
        $integration = AiIntegration::withTrashed()->find($usage->ai_integration_id);
        if (!$integration || $integration->provider !== 'openai' || !$integration->is_enabled || $integration->trashed()) {
            throw new RuntimeException('The queued OpenAI integration is no longer enabled.');
        }
        $audioPath = $this->artifacts->preparedAudioPath($audioRelativePath);
        $provider = app(\App\Support\AiProviderRegistry::class)->get($integration->provider);
        if (!$provider instanceof OpenAiProvider) throw new RuntimeException('The configured integration is not the OpenAI provider.');

        $response = $provider->transcribeWordTimestamps(
            ['endpoint' => $integration->endpoint],
            $integration->credentials ?? [],
            $audioPath,
            (string) $usage->idempotency_key,
            $audioDurationSeconds,
            $jobAttempt,
        );
        $mapping = $this->matcher->align($approvedTranscript, $response['words'], $response['duration_seconds']);
        $overlapInfo = ['status' => 'no_previous_chunk'];
        if (is_string($overlapContext['approved_text'] ?? null) && is_numeric($overlapContext['previous_chunk_end_seconds'] ?? null)) {
            $prefixLength = $this->overlapDeduplicator->duplicatePrefixLength($overlapContext['approved_text'], $approvedTranscript);
            $previousEnd = (float) $overlapContext['previous_chunk_end_seconds'];
            $duplicateCount = 0;
            for ($index = 0; $index < $prefixLength; $index++) {
                $word = $mapping['words'][$index] ?? null;
                if (!is_array($word) || !is_numeric($word['start'] ?? null) || !is_numeric($word['end'] ?? null)) continue;
                $absoluteStart = (float) $word['start'] + $timelineOffsetSeconds;
                $absoluteEnd = (float) $word['end'] + $timelineOffsetSeconds;
                if ($absoluteStart < $previousEnd && $absoluteEnd <= $previousEnd + 0.15) {
                    $mapping['words'][$index]['status'] = 'overlap_duplicate';
                    $mapping['words'][$index]['reason'] = 'duplicate_approved_text_in_audio_overlap';
                    $duplicateCount++;
                }
            }
            $overlapInfo = [
                'status' => 'checked', 'matched_prefix_token_count' => $prefixLength,
                'duplicate_word_count' => $duplicateCount,
                'previous_chunk_end_seconds' => round($previousEnd, 3),
                'current_chunk_start_seconds' => round($timelineOffsetSeconds, 3),
            ];
        } elseif (($overlapContext['status'] ?? null) === 'previous_not_approved') {
            $overlapInfo = ['status' => 'previous_not_approved', 'warning' => 'Previous overlapping chunk has not been approved; no words were removed.'];
        } elseif (($overlapContext['status'] ?? null) === 'duration_unavailable') {
            $overlapInfo = ['status' => 'duration_unavailable', 'warning' => 'Previous chunk duration is unavailable; no words were removed.'];
        }
        return $this->artifacts->saveWhisper1Words(
            (int) $usage->lesson_id,
            (int) $usage->id,
            $approvedTranscript,
            $timelineOffsetSeconds,
            $response['duration_seconds'],
            $mapping,
            $response['raw_response'],
            $response['usage'],
            $response['request_reference'],
            $response['request_duration_ms'],
            $overlapInfo,
        ) + ['request_duration_ms' => $response['request_duration_ms']];
    }
}
