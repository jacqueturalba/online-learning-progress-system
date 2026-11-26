<?php

namespace App\Jobs;

use App\Models\AiOperationUsage;
use App\Services\WhisperxAlignmentException;
use App\Services\WhisperxAlignmentExecutor;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxWorkerHeartbeat;
use App\Services\OpenAiWhisper1Executor;
use App\Services\SubtitleProcessingMethod;
use App\Support\AiProviderException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class RunWhisperxAlignment implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [60, 300];
    public int $timeout;

    public function __construct(public readonly int $usageId, public readonly string $encryptedPayload, public readonly string $queueToken)
    {
        $this->timeout = max(60, (int) config('whisperx.job_timeout', 7500));
    }

    public function handle(WhisperxAlignmentExecutor $executor, ?OpenAiWhisper1Executor $whisper1 = null): void
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->findOrFail($this->usageId);
        if ($usage->status === 'succeeded') return;

        $attemptStartedClock = microtime(true);
        try {
            $payload = json_decode(Crypt::decryptString($this->encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
            $transcriptId = (int) ($payload['transcript_operation_id'] ?? 0);
            $transcript = AiOperationUsage::query()
                ->where('service', WhisperxAlignmentService::TRANSCRIPTION_SERVICE)
                ->where('lesson_id', $usage->lesson_id)
                ->whereKey($transcriptId)
                ->firstOrFail();
            $review = $transcript->result['transcript_review'] ?? null;
            $expectedHash = $usage->result['transcript_sha256'] ?? null;
            $transcriptText = $review['approved_text'] ?? $transcript->result['text'] ?? null;
            if ($transcript->status !== 'succeeded' || !is_array($review)
                || ($review['status'] ?? null) !== 'approved' || ($review['language'] ?? null) !== 'en'
                || !is_string($transcriptText) || !is_string($expectedHash)
                || !hash_equals($expectedHash, hash('sha256', $transcriptText))) {
                throw new WhisperxAlignmentException('TranscriptChanged', 'The approved transcript version is no longer available unchanged.');
            }

            $startedAt = now();
            $startedClock = microtime(true);
            $result = $usage->result ?? [];
            $result['processing_started_at'] = $startedAt->toIso8601String();
            $result['heartbeat_at'] = $startedAt->toIso8601String();
            $result['worker_pid'] = getmypid();
            $result['worker_host'] = gethostname() ?: null;
            $result['processing_completed_at'] = null;
            $result['approval_status'] = 'pending_admin_review';
            $usage->update(['status' => 'processing', 'processing_duration_ms' => null, 'error_code' => null, 'error_message' => null, 'result' => $result]);

            $heartbeat = function (?int $processId = null, ?array $processIdentity = null) use ($usage): void {
                    app(WhisperxWorkerHeartbeat::class)->touch();
                    $fresh = $usage->fresh();
                    if (!$fresh || $fresh->status !== 'processing') return;
                    $result = $fresh->result ?? [];
                    $result['heartbeat_at'] = now()->toIso8601String();
                    $result['worker_pid'] = getmypid();
                    $result['worker_host'] = gethostname() ?: null;
                    if ($processId !== null) $result['process_pid'] = $processId;
                    if ($processIdentity !== null) $result['process_identity'] = $processIdentity;
                    $fresh->update(['result' => $result]);
                };
            $processingMethod = $result['processing_method'] ?? SubtitleProcessingMethod::STANDARD;
            $effectiveMethod = $processingMethod;
            $fallbackReason = null;
            if ($processingMethod === SubtitleProcessingMethod::OPENAI_WHISPER1) {
                $whisper1 ??= app(OpenAiWhisper1Executor::class);
                $overlapContext = $this->previousChunkOverlap($transcript);
                if (in_array($overlapContext['status'] ?? null, ['previous_chunk_missing', 'previous_not_approved', 'duration_unavailable'], true)) {
                    $result['chunk_overlap'] = $overlapContext;
                    $result['processing_completed_at'] = now()->toIso8601String();
                    $usage->update([
                        'status' => 'failed', 'processing_duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
                        'error_code' => 'ChunkOverlapContextUnavailable',
                        'error_message' => 'The previous overlapping chunk is not available as an approved, duration-verified transcript. Approve or repair that chunk before timing this one.',
                        'result' => $result,
                    ]);
                    return;
                }
                $artifacts = $whisper1->process($usage, (string) ($payload['audio_relative_path'] ?? ''), $transcriptText,
                    (float) ($usage->timeline_offset_seconds ?? 0), $overlapContext);
            } elseif ($processingMethod === SubtitleProcessingMethod::BOUNDED) {
                try {
                    $segments = is_array($transcript->result['segments'] ?? null) ? $transcript->result['segments'] : [];
                    $artifacts = $executor->alignBounded((int) $usage->lesson_id, (int) $usage->id,
                        (string) ($payload['audio_relative_path'] ?? ''), $transcriptText, $segments,
                        (float) ($usage->timeline_offset_seconds ?? 0), $heartbeat);
                } catch (WhisperxAlignmentException $exception) {
                    if (!in_array($exception->safeCode, ['BoundedAnchorsUnavailable', 'BoundedWindowIncomplete', 'BoundedWindowFailed'], true)) throw $exception;
                    $fallbackReason = $exception->getMessage();
                    $effectiveMethod = SubtitleProcessingMethod::STANDARD;
                    $artifacts = $executor->align((int) $usage->lesson_id, (int) $usage->id,
                        (string) ($payload['audio_relative_path'] ?? ''), $transcriptText,
                        (float) ($usage->timeline_offset_seconds ?? 0), $heartbeat);
                    $this->annotateFallback($artifacts, $fallbackReason);
                }
            } else {
                $processingMethod = SubtitleProcessingMethod::STANDARD;
                $effectiveMethod = SubtitleProcessingMethod::STANDARD;
                $artifacts = $executor->align((int) $usage->lesson_id, (int) $usage->id,
                    (string) ($payload['audio_relative_path'] ?? ''), $transcriptText,
                    (float) ($usage->timeline_offset_seconds ?? 0), $heartbeat);
            }

            $result = array_merge($result, $artifacts, [
                'processing_method' => $processingMethod,
                'effective_method' => $effectiveMethod,
                'fallback_reason' => $fallbackReason,
                'processing_completed_at' => now()->toIso8601String(),
                'approval_status' => 'pending_admin_review',
            ]);
            if ($processingMethod === SubtitleProcessingMethod::OPENAI_WHISPER1 && ($artifacts['timestamps_complete'] ?? false) !== true) {
                $usage->update([
                    'status' => 'failed',
                    'processing_duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
                    'audio_minutes' => isset($artifacts['api_usage']['audio_minutes']) ? round((float) $artifacts['api_usage']['audio_minutes'], 4) : null,
                    'request_reference' => $artifacts['request_reference'] ?? $usage->request_reference,
                    'error_code' => 'WordTimestampsIncomplete',
                    'error_message' => 'Whisper-1 did not return valid, unambiguous timestamps for every approved transcript word. Review the saved report before retrying.',
                    'result' => $result,
                ]);
                return;
            }

            $usage->update([
                'status' => 'succeeded',
                'processing_duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
                'audio_minutes' => isset($artifacts['api_usage']['audio_minutes']) ? round((float) $artifacts['api_usage']['audio_minutes'], 4) : $usage->audio_minutes,
                'request_reference' => $artifacts['request_reference'] ?? $usage->request_reference,
                'error_code' => null,
                'error_message' => null,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            $usage = $usage->fresh();
            $result = $usage->result ?? [];
            $result['processing_completed_at'] = now()->toIso8601String();
            $safeCode = $exception instanceof WhisperxAlignmentException ? $exception->safeCode
                : ($exception instanceof AiProviderException ? $exception->providerCode : class_basename($exception));
            $safeMessage = $exception instanceof WhisperxAlignmentException
                ? $exception->getMessage()
                : ($exception instanceof AiProviderException ? $exception->getMessage()
                    : (($result['processing_method'] ?? null) === SubtitleProcessingMethod::OPENAI_WHISPER1
                        ? 'OpenAI Whisper-1 timing failed. Check the server operation log.'
                        : 'WhisperX alignment failed. Check the server operation log.'));
            $isFinalAttempt = $this->attempts() >= $this->tries;
            $usage->update([
                'status' => $isFinalAttempt ? 'failed' : 'queued',
                'processing_duration_ms' => (int) round((microtime(true) - ($startedClock ?? $attemptStartedClock)) * 1000),
                'error_code' => $safeCode,
                'error_message' => $safeMessage,
                'result' => $result,
            ]);
            Log::warning('WhisperX alignment job failed.', [
                'operation_id' => $usage->id,
                'lesson_id' => $usage->lesson_id,
                'exception' => $safeCode,
            ]);
            throw $exception;
        }
    }

    private function annotateFallback(array $artifacts, string $reason): void
    {
        $reportPath = $artifacts['outputs']['report'] ?? null;
        $alignedPath = $artifacts['outputs']['aligned_json'] ?? null;
        foreach ([$reportPath, $alignedPath] as $path) {
            if (!is_string($path)) continue;
            $contents = Storage::disk('local')->get($path);
            $data = json_decode($contents, true);
            if (!is_array($data)) continue;
            $data['processing_method'] = SubtitleProcessingMethod::BOUNDED;
            $data['effective_method'] = SubtitleProcessingMethod::STANDARD;
            $data['fallback_reason'] = $reason;
            Storage::disk('local')->put($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n");
        }
    }

    private function previousChunkOverlap(AiOperationUsage $transcript): array
    {
        $key = (string) $transcript->idempotency_key;
        if (!preg_match('/^prep-(\d+)-chunk-(\d+)$/', $key, $matches) || (int) $matches[2] < 2) {
            return ['status' => 'no_previous_chunk'];
        }
        $previous = AiOperationUsage::query()->where('service', WhisperxAlignmentService::TRANSCRIPTION_SERVICE)
            ->where('lesson_id', $transcript->lesson_id)->where('idempotency_key', 'prep-'.$matches[1].'-chunk-'.((int) $matches[2] - 1))->first();
        if (!$previous) return ['status' => 'previous_chunk_missing'];
        $review = $previous->result['transcript_review'] ?? null;
        if ($previous->status !== 'succeeded' || !is_array($review) || ($review['status'] ?? null) !== 'approved' || ($review['language'] ?? null) !== 'en') {
            return ['status' => 'previous_not_approved'];
        }
        $previousText = $review['approved_text'] ?? $previous->result['text'] ?? null;
        if (!is_string($previousText) || !is_numeric($previous->audio_minutes) || !is_numeric($previous->timeline_offset_seconds)) {
            return ['status' => 'duration_unavailable'];
        }
        $previousEnd = (float) $previous->timeline_offset_seconds + ((float) $previous->audio_minutes * 60);
        $currentStart = (float) ($transcript->timeline_offset_seconds ?? 0);
        if (!is_finite($previousEnd) || $previousEnd <= $currentStart) return ['status' => 'no_overlap'];
        return ['approved_text' => $previousText, 'previous_chunk_end_seconds' => $previousEnd];
    }

    public function failed(?Throwable $exception): void
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->find($this->usageId);
        if (!$usage || $usage->status === 'succeeded') return;
        $result = $usage->result ?? [];
        $result['processing_completed_at'] = now()->toIso8601String();
        $safeCode = $exception instanceof WhisperxAlignmentException ? $exception->safeCode
            : ($exception instanceof AiProviderException ? $exception->providerCode : 'SubtitleProcessingFailed');
        $safeMessage = $exception instanceof WhisperxAlignmentException
            ? $exception->getMessage()
            : ($exception instanceof AiProviderException ? $exception->getMessage()
                : (($result['processing_method'] ?? null) === SubtitleProcessingMethod::OPENAI_WHISPER1
                    ? 'OpenAI Whisper-1 timing failed after retry attempts.'
                    : 'WhisperX alignment failed after its retry attempts.'));
        $usage->update(['status' => 'failed', 'error_code' => $safeCode, 'error_message' => $safeMessage, 'result' => $result]);
        $this->removeIncompleteArtifacts($usage);
    }

    private function removeIncompleteArtifacts(AiOperationUsage $usage): void
    {
        $directory = 'whisperx-alignments/'.(int) $usage->lesson_id.'/'.(int) $usage->id;
        foreach (['aligned.json', 'aligned.srt', 'alignment-report.json', 'input-transcript.txt', 'window-map.json', 'whisper1-response.json'] as $name) {
            Storage::disk('local')->delete($directory.'/'.$name);
        }
    }
}
