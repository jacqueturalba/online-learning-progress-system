<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Event;
use Throwable;

class RunAiOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 60, 180];
    public int $timeout;

    public function __construct(public readonly int $usageId, public readonly string $encryptedPayload, ?int $timeout = null)
    {
        $this->timeout = max(60, $timeout ?? 600);
    }

    public function handle(AiOperationService $operations, Whisper1CombinedSubtitleService $combinedSubtitles): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($this->encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
            $payload['job_attempt'] = $this->attempts();
            $result = $operations->runQueued($this->usageId, $payload);
            $alreadyCompleted = (bool) ($result['already_completed'] ?? false);
            $alreadyProcessing = (bool) ($result['already_processing'] ?? false);
            if (($payload['service'] ?? null) === 'transcription'
                && ($payload['subtitle_processing_method'] ?? null) === SubtitleProcessingMethod::OPENAI_WHISPER1) {
                $transcription = AiOperationUsage::query()->where('service', 'transcription')->find($this->usageId);
                if ($transcription) {
                    $combinedSubtitles->createFromTranscript($transcription);
                    $result = $transcription->fresh()->result ?? $result;
                }
            }
            if (!$alreadyCompleted && !$alreadyProcessing) {
                Event::dispatch(new AiOperationCompleted($this->usageId, $payload['service'], $payload['lesson_id'] ?? null, $result));
            }
        } catch (Throwable $exception) {
            $completed = AiOperationUsage::query()->whereKey($this->usageId)->where('status', 'succeeded')->exists();
            if (!$completed && $this->attempts() < $this->tries) {
                AiOperationUsage::query()->whereKey($this->usageId)->update(['status' => 'queued', 'updated_at' => now()]);
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $usage = AiOperationUsage::query()->find($this->usageId);
        $combinedWhisper1 = ($usage?->result['processing_method'] ?? null) === SubtitleProcessingMethod::OPENAI_WHISPER1;
        AiOperationUsage::query()->whereKey($this->usageId)->update([
            'status' => 'failed', 'error_code' => $exception ? class_basename($exception) : 'QueueFailed',
            'error_message' => $combinedWhisper1
                ? 'OpenAI Whisper-1 transcription and word timestamp generation failed after retries.'
                : 'AI operation failed after retry attempts.',
            'updated_at' => now(),
        ]);
    }
}
