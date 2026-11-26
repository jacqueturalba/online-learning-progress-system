<?php

namespace App\Jobs;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Services\AiOperationService;
use App\Services\LessonAudioPreparationService;
use App\Services\SubtitleProcessingMethod;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class PrepareLessonAudioForSubtitles implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public array $backoff = [30, 120];
    public int $timeout = 3600;

    public function __construct(public readonly int $usageId) {}

    public function handle(LessonAudioPreparationService $preparation, AiOperationService $operations): void
    {
        $usage = AiOperationUsage::query()->where('service', 'audio_preparation')->findOrFail($this->usageId);
        if ($usage->status === 'succeeded') return;
        $usage->update(['status' => 'processing', 'error_code' => null, 'error_message' => null]);

        try {
            $lesson = Lesson::query()->findOrFail($usage->lesson_id);
            $result = is_array($usage->result) ? $usage->result : [];
            $processingMethod = is_string($result['processing_method'] ?? null)
                ? $result['processing_method'] : SubtitleProcessingMethod::current();
            $prepared = is_array($result['preparation'] ?? null) ? $result['preparation'] : null;

            if (!$prepared || !$this->chunksExist($prepared['chunks'] ?? null)) {
                $prepared = $preparation->prepare($lesson);
                $prepared['transcription_operations'] = [];
                $result['preparation'] = $prepared;
                $usage->update(['result' => $result]);
            }

            foreach ($prepared['chunks'] as $index => $chunk) {
                $existingId = $prepared['transcription_operations'][$index]['operation_id'] ?? null;
                if (is_numeric($existingId)) {
                    $existing = AiOperationUsage::query()->find($existingId);
                    if ($existing && in_array($existing->status, ['queued', 'processing', 'succeeded'], true)) continue;
                }
                $audioPath = Storage::disk('local')->path($chunk['path']);
                $queued = $operations->queueTranscription(
                    $audioPath,
                    'prep-'.$usage->id.'-chunk-'.($index + 1),
                    (int) $lesson->id,
                    'en',
                    (float) $chunk['offset_seconds'],
                    null,
                    $processingMethod,
                );
                $prepared['transcription_operations'][$index] = [
                    'operation_id' => $queued['usage_id'],
                    'status' => $queued['status'],
                ];
                $result['preparation'] = $prepared;
                $usage->update(['result' => $result]);
            }

            $usage->update([
                'status' => 'succeeded',
                'processing_duration_ms' => null,
                'result' => array_merge($result, ['processing_completed_at' => now()->toIso8601String()]),
            ]);
        } catch (Throwable $exception) {
            $usage->fresh()->update([
                'status' => $this->attempts() >= $this->tries ? 'failed' : 'queued',
                'error_code' => class_basename($exception),
                'error_message' => 'Lesson audio preparation or transcription queueing failed. Check the admin operation details and server log.',
            ]);
            Log::warning('Subtitle audio preparation job failed.', [
                'operation_id' => $usage->id,
                'lesson_id' => $usage->lesson_id,
                'exception' => class_basename($exception),
            ]);
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $usage = AiOperationUsage::query()->where('service', 'audio_preparation')->find($this->usageId);
        if (!$usage || $usage->status === 'succeeded') return;
        $usage->update([
            'status' => 'failed',
            'error_code' => $exception ? class_basename($exception) : 'AudioPreparationFailed',
            'error_message' => 'Lesson audio preparation or transcription queueing failed after retry attempts.',
        ]);
    }

    private function chunksExist(mixed $chunks): bool
    {
        if (!is_array($chunks) || $chunks === []) return false;
        foreach ($chunks as $chunk) {
            if (!is_array($chunk) || !is_string($chunk['path'] ?? null) || !Storage::disk('local')->exists($chunk['path'])) return false;
        }
        return true;
    }
}
