<?php

namespace App\Services;

use App\Jobs\RunWhisperxAlignment;
use App\Models\AiOperationUsage;
use App\Models\Lesson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class WhisperxAlignmentService
{
    public const TRANSCRIPTION_SERVICE = 'transcription';
    public const ALIGNMENT_SERVICE = 'whisperx_alignment';

    public function __construct(
        private readonly WhisperxAlignmentExecutor $executor,
        private readonly WhisperxAlignmentReconciler $reconciler,
    ) {}

    public function transcriptVersion(Lesson $lesson, int $operationId): AiOperationUsage
    {
        $operation = AiOperationUsage::query()
            ->where('service', self::TRANSCRIPTION_SERVICE)
            ->where('lesson_id', $lesson->id)
            ->find($operationId);

        if (!$operation || $operation->status !== 'succeeded' || !is_array($operation->result)
            || !is_string($operation->result['text'] ?? null) || trim($operation->result['text']) === '') {
            throw new RuntimeException('A successful transcript version for this lesson was not found.');
        }
        return $operation;
    }

    public function approveEnglishTranscript(Lesson $lesson, int $operationId, int $adminId): AiOperationUsage
    {
        $operation = $this->transcriptVersion($lesson, $operationId);
        $result = $operation->result;
        $existing = $result['transcript_review'] ?? null;
        if (is_array($existing) && ($existing['status'] ?? null) === 'approved' && ($existing['language'] ?? null) === 'en') {
            return $operation;
        }

        $result['transcript_review'] = [
            'status' => 'approved',
            'language' => 'en',
            'approved_text' => $this->currentTranscriptText($operation),
            'approved_sha256' => hash('sha256', $this->currentTranscriptText($operation)),
            'approved_by' => $adminId,
            'approved_at' => now()->toIso8601String(),
        ];
        $operation->result = $result;
        $operation->save();
        return $operation->fresh();
    }

    public function currentTranscriptText(AiOperationUsage $operation): string
    {
        $text = $operation->result['transcript_draft']['text'] ?? $operation->result['text'] ?? '';
        return is_string($text) ? $text : '';
    }

    public function approvedTranscriptText(AiOperationUsage $operation): string
    {
        $review = $operation->result['transcript_review'] ?? null;
        if (!is_array($review) || ($review['status'] ?? null) !== 'approved' || ($review['language'] ?? null) !== 'en') {
            throw new RuntimeException('Approve this English transcript before using it.');
        }
        $text = $review['approved_text'] ?? $operation->result['text'] ?? '';
        if (!is_string($text) || trim($text) === '') throw new RuntimeException('The approved English transcript is empty.');
        return $text;
    }

    public function saveTranscriptDraft(Lesson $lesson, int $operationId, string $text, int $adminId): AiOperationUsage
    {
        $operation = $this->transcriptVersion($lesson, $operationId);
        if (trim($text) === '') throw new RuntimeException('The transcript draft cannot be empty.');
        $result = $operation->result;
        $result['transcript_draft'] = [
            'text' => $text,
            'sha256' => hash('sha256', $text),
            'updated_by' => $adminId,
            'updated_at' => now()->toIso8601String(),
        ];
        $result['transcript_review'] = ['status' => 'pending_review', 'language' => null];
        $operation->result = $result;
        $operation->save();
        return $operation->fresh();
    }

    public function queueAlignment(Lesson $lesson, int $transcriptOperationId, ?string $audioRelativePath, int $adminId): array
    {
        if (!config('whisperx.enabled')) throw new RuntimeException('WhisperX alignment is disabled.');

        $transcript = $this->transcriptVersion($lesson, $transcriptOperationId);
        $review = $transcript->result['transcript_review'] ?? null;
        if (!is_array($review) || ($review['status'] ?? null) !== 'approved' || ($review['language'] ?? null) !== 'en') {
            throw new RuntimeException('Approve this English transcript version before queueing alignment.');
        }

        $audioRelativePath = $this->resolvePreparedAudioPath($transcript, $audioRelativePath);
        $audioHash = $this->executor->preparedAudioHash($audioRelativePath);
        $transcriptText = $this->approvedTranscriptText($transcript);
        $transcriptHash = hash('sha256', $transcriptText);
        $offset = (float) ($transcript->timeline_offset_seconds ?? 0);
        $fingerprint = hash('sha256', json_encode([
            'service' => self::ALIGNMENT_SERVICE,
            'lesson_id' => $lesson->id,
            'transcript_operation_id' => $transcript->id,
            'transcript_sha256' => $transcriptHash,
            'audio_sha256' => $audioHash,
            'timeline_offset_seconds' => $offset,
            'device' => config('whisperx.device'),
            'script_sha256' => is_file((string) config('whisperx.script_path')) ? hash_file('sha256', (string) config('whisperx.script_path')) : null,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $key = 'whisperx-'.$lesson->id.'-'.$transcript->id.'-'.substr($transcriptHash, 0, 20).'-'.substr($audioHash, 0, 20);

        try {
            $usage = AiOperationUsage::query()->firstOrCreate(['idempotency_key' => $key], [
                'ai_integration_id' => null,
                'service' => self::ALIGNMENT_SERVICE,
                'model' => 'whisperx-3.8.6/WAV2VEC2_ASR_BASE_960H',
                'lesson_id' => $lesson->id,
                'timeline_offset_seconds' => $offset,
                'status' => 'queued',
                'request_reference' => (string) str()->uuid(),
                'request_fingerprint' => $fingerprint,
                'result' => [
                    'transcript_operation_id' => $transcript->id,
                    'transcript_sha256' => $transcriptHash,
                    'audio_sha256' => $audioHash,
                    'requested_by' => $adminId,
                    'approval_status' => 'pending_admin_review',
                    'timeline_offset_seconds' => $offset,
                    'outputs' => null,
                    'processing_started_at' => null,
                    'processing_completed_at' => null,
                ],
            ]);
        } catch (QueryException $exception) {
            $usage = AiOperationUsage::query()->where('idempotency_key', $key)->first();
            if (!$usage) throw $exception;
        }

        $isNewOperation = $usage->wasRecentlyCreated;
        $shouldDispatch = DB::transaction(function () use (&$usage, $fingerprint, $isNewOperation): bool {
            $locked = AiOperationUsage::query()->lockForUpdate()->findOrFail($usage->id);
            if (!hash_equals((string) $locked->request_fingerprint, $fingerprint)) {
                throw new RuntimeException('This alignment key is already associated with different inputs.');
            }
            if (!$isNewOperation && in_array($locked->status, ['queued', 'processing', 'succeeded'], true)) return false;

            if (!$isNewOperation && $locked->status === 'failed'
                && in_array($this->reconciler->queueStateFor($locked), ['pending', 'delayed', 'reserved', 'unknown'], true)) {
                $locked->update(['status' => 'queued', 'error_code' => null, 'error_message' => null]);
                return false;
            }

            $result = $locked->result ?? [];
            $result['approval_status'] = 'pending_admin_review';
            $result['outputs'] = null;
            $result['processing_started_at'] = null;
            $result['processing_completed_at'] = null;
            $result['queue_token'] = (string) str()->uuid();
            $result['heartbeat_at'] = null;
            $result['worker_pid'] = null;
            $result['worker_host'] = null;
            $result['process_pid'] = null;
            $result['process_identity'] = null;
            $locked->update(['status' => 'queued', 'error_code' => null, 'error_message' => null, 'result' => $result]);
            $usage = $locked->fresh();
            return true;
        });

        if (!$shouldDispatch) return ['usage' => $usage->fresh(), 'already_submitted' => true];
        $result = $usage->result ?? [];

        try {
            $payload = Crypt::encryptString(json_encode([
                'transcript_operation_id' => $transcript->id,
                'audio_relative_path' => $audioRelativePath,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            RunWhisperxAlignment::dispatch($usage->id, $payload, $result['queue_token'])
                ->onConnection((string) config('whisperx.connection'))
                ->onQueue((string) config('whisperx.queue'))
                ->afterCommit();
        } catch (Throwable $exception) {
            $usage->update([
                'status' => 'failed',
                'error_code' => 'QueueDispatchFailed',
                'error_message' => 'The WhisperX job could not be queued.',
            ]);
            throw new RuntimeException('The WhisperX job could not be queued.', previous: $exception);
        }

        return ['usage' => $usage->fresh(), 'already_submitted' => false];
    }

    public function srtForAdmin(AiOperationUsage $usage): string
    {
        if ($usage->service !== self::ALIGNMENT_SERVICE || $usage->status !== 'succeeded'
            || !$usage->lesson || !is_array($usage->result)
            || ($usage->result['approval_statuses']['en'] ?? null) !== 'approved') {
            throw new RuntimeException('This alignment SRT is not approved for download.');
        }
        $version = app(ApprovedSubtitleService::class)->approvedVersion($usage->lesson, $usage, 'en');
        return Storage::disk('local')->get($version['path']);
    }

    public function safeStatus(AiOperationUsage $usage): array
    {
        if ($usage->service !== self::ALIGNMENT_SERVICE) throw new RuntimeException('Alignment operation not found.');
        $result = is_array($usage->result) ? $usage->result : [];
        return [
            'operation_id' => $usage->id,
            'lesson_id' => $usage->lesson_id,
            'transcript_operation_id' => $result['transcript_operation_id'] ?? null,
            'status' => $usage->status,
            'approval_status' => $result['approval_status'] ?? null,
            'processing_started_at' => $result['processing_started_at'] ?? null,
            'heartbeat_at' => $result['heartbeat_at'] ?? null,
            'processing_completed_at' => $result['processing_completed_at'] ?? null,
            'error_code' => $usage->error_code,
            'error_message' => $usage->error_message,
            'total_words' => $result['total_words'] ?? null,
            'successfully_aligned_words' => $result['successfully_aligned_words'] ?? null,
            'missing_or_unreliable_words' => $result['missing_or_unreliable_words'] ?? null,
        ];
    }

    private function resolvePreparedAudioPath(AiOperationUsage $transcript, ?string $providedPath): string
    {
        if (is_string($providedPath) && trim($providedPath) !== '') return $providedPath;

        $storedPath = $transcript->result['prepared_audio_path'] ?? null;
        if (is_string($storedPath) && $storedPath !== '') {
            $this->executor->preparedAudioHash($storedPath);
            return $storedPath;
        }

        // Older operations did not retain the path. Rebuild their original fingerprint
        // from each private prepared chunk; only an exact fingerprint match is accepted.
        if (!$transcript->lesson_id || !$transcript->ai_integration_id || !$transcript->model) {
            throw new RuntimeException('The prepared audio cannot be resolved for this older transcript. Supply its private audio path.');
        }
        $language = (string) ($transcript->result['language'] ?? 'en');
        $offset = (float) ($transcript->timeline_offset_seconds ?? 0);
        $tempDirectory = trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\');
        $disk = Storage::disk('local');
        $matches = [];
        foreach ($disk->allFiles($tempDirectory) as $candidate) {
            if (!preg_match('~(?:^|/)chunk-\d+\.(?:mp3|wav)$~i', $candidate)) continue;
            try {
                $audioHash = $this->executor->preparedAudioHash($candidate);
            } catch (Throwable) {
                continue;
            }
            $request = [
                'service' => self::TRANSCRIPTION_SERVICE,
                'lesson_id' => (int) $transcript->lesson_id,
                'language' => $language,
                'audio_sha256' => $audioHash,
                'timeline_offset_seconds' => $offset,
            ];
            $requestFingerprint = hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
            $scopedFingerprint = hash('sha256', implode('|', [
                $requestFingerprint,
                (int) $transcript->ai_integration_id,
                $transcript->model,
                '',
            ]));
            if (is_string($transcript->request_fingerprint) && hash_equals($transcript->request_fingerprint, $scopedFingerprint)) {
                $matches[] = $candidate;
            }
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) === 1) return $matches[0];
        if (count($matches) > 1) {
            // Identical bytes produce an equivalent alignment input; pick stable path order.
            sort($matches, SORT_STRING);
            return $matches[0];
        }

        throw new RuntimeException('The prepared audio cannot be resolved from the existing preparation files. Supply its private audio path.');
    }
}
