<?php

namespace App\Jobs;

use App\Models\AiOperationUsage;
use App\Services\WhisperxAlignmentException;
use App\Services\WhisperxAlignmentExecutor;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxWorkerHeartbeat;
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

    public function handle(WhisperxAlignmentExecutor $executor): void
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->findOrFail($this->usageId);
        if ($usage->status === 'succeeded') return;

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

            $artifacts = $executor->align(
                (int) $usage->lesson_id,
                (int) $usage->id,
                (string) ($payload['audio_relative_path'] ?? ''),
                $transcriptText,
                (float) ($usage->timeline_offset_seconds ?? 0),
                function (?int $processId = null, ?array $processIdentity = null) use ($usage): void {
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
                },
            );

            $result = array_merge($result, $artifacts, [
                'processing_completed_at' => now()->toIso8601String(),
                'approval_status' => 'pending_admin_review',
            ]);
            $usage->update([
                'status' => 'succeeded',
                'processing_duration_ms' => (int) round((microtime(true) - $startedClock) * 1000),
                'error_code' => null,
                'error_message' => null,
                'result' => $result,
            ]);
        } catch (Throwable $exception) {
            $usage = $usage->fresh();
            $result = $usage->result ?? [];
            $result['processing_completed_at'] = now()->toIso8601String();
            $safeCode = $exception instanceof WhisperxAlignmentException ? $exception->safeCode : class_basename($exception);
            $safeMessage = $exception instanceof WhisperxAlignmentException
                ? $exception->getMessage()
                : 'WhisperX alignment failed. Check the server operation log.';
            $isFinalAttempt = $this->attempts() >= $this->tries;
            $usage->update([
                'status' => $isFinalAttempt ? 'failed' : 'queued',
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

    public function failed(?Throwable $exception): void
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->find($this->usageId);
        if (!$usage || $usage->status === 'succeeded') return;
        $result = $usage->result ?? [];
        $result['processing_completed_at'] = now()->toIso8601String();
        $safeCode = $exception instanceof WhisperxAlignmentException ? $exception->safeCode : 'WhisperxJobFailed';
        $safeMessage = $exception instanceof WhisperxAlignmentException
            ? $exception->getMessage()
            : 'WhisperX alignment failed after its retry attempts.';
        $usage->update(['status' => 'failed', 'error_code' => $safeCode, 'error_message' => $safeMessage, 'result' => $result]);
        $this->removeIncompleteArtifacts($usage);
    }

    private function removeIncompleteArtifacts(AiOperationUsage $usage): void
    {
        $directory = 'whisperx-alignments/'.(int) $usage->lesson_id.'/'.(int) $usage->id;
        foreach (['aligned.json', 'aligned.srt', 'alignment-report.json', 'input-transcript.txt'] as $name) {
            Storage::disk('local')->delete($directory.'/'.$name);
        }
    }
}
