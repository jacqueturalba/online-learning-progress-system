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
    public int $timeout = 120;

    public function __construct(public readonly int $usageId, public readonly string $encryptedPayload) {}

    public function handle(AiOperationService $operations): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($this->encryptedPayload), true, flags: JSON_THROW_ON_ERROR);
            $result = $operations->runQueued($this->usageId, $payload);
            if (!($result['already_completed'] ?? false) && !($result['already_processing'] ?? false)) {
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
        AiOperationUsage::query()->whereKey($this->usageId)->update([
            'status' => 'failed', 'error_code' => $exception ? class_basename($exception) : 'QueueFailed',
            'error_message' => 'AI operation failed after retry attempts.', 'updated_at' => now(),
        ]);
    }
}
