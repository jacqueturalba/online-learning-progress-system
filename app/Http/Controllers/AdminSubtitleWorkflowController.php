<?php

namespace App\Http\Controllers;

use App\Jobs\PrepareLessonAudioForSubtitles;
use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Services\AiOperationService;
use App\Services\ApprovedSubtitleService;
use App\Services\SubtitleSrtCueService;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxAlignmentReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class AdminSubtitleWorkflowController extends Controller
{
    public function show(Lesson $lesson, WhisperxAlignmentService $alignments, WhisperxAlignmentReconciler $reconciler)
    {
        $transcriptions = AiOperationUsage::query()->where('lesson_id', $lesson->id)->where('service', 'transcription')->latest('id')->limit(50)->get();
        $translations = AiOperationUsage::query()->where('lesson_id', $lesson->id)->where('service', 'translation')->latest('id')->limit(50)->get();
        $alignmentsByTranscript = AiOperationUsage::query()->where('lesson_id', $lesson->id)->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->latest('id')->limit(50)->get();
        $preparations = AiOperationUsage::query()->where('lesson_id', $lesson->id)->where('service', 'audio_preparation')->latest('id')->limit(20)->get();
        $approvedTranscriptHashes = $alignmentsByTranscript->filter(function (AiOperationUsage $operation): bool {
            $result = is_array($operation->result) ? $operation->result : [];
            return $operation->status === 'succeeded' && ($result['approval_statuses']['en'] ?? null) === 'approved';
        })->groupBy(function (AiOperationUsage $operation): int {
            $result = is_array($operation->result) ? $operation->result : [];
            return (int) ($result['transcript_operation_id'] ?? 0);
        })->map(function ($operations): array {
            return $operations->map(function (AiOperationUsage $operation): mixed {
                $result = is_array($operation->result) ? $operation->result : [];
                return $result['transcript_sha256'] ?? null;
            })->filter()->unique()->values()->all();
        })->all();

        return response()->json([
            'lesson_id' => $lesson->id,
            'preparations' => $preparations->map(function (AiOperationUsage $operation) use ($transcriptions): array {
                $result = is_array($operation->result) ? $operation->result : [];
                $preparation = $result['preparation'] ?? [];
                $chunks = [];
                foreach ($preparation['chunks'] ?? [] as $index => $chunk) {
                    $transcription = $preparation['transcription_operations'][$index] ?? [];
                    $transcriptionOperation = $transcriptions->firstWhere('id', $transcription['operation_id'] ?? null);
                    $chunks[] = [
                        'operation_id' => $transcription['operation_id'] ?? null,
                        'offset_seconds' => $chunk['offset_seconds'] ?? null,
                        'duration_seconds' => $chunk['duration_seconds'] ?? null,
                        'status' => $transcriptionOperation?->status ?? ($transcription['status'] ?? 'queued'),
                        'error_code' => $transcriptionOperation?->error_code,
                        'error_message' => $transcriptionOperation?->error_message,
                        'queue' => $this->queueDetails($transcriptionOperation, 'default'),
                    ];
                }
                return ['operation_id' => $operation->id, 'status' => $operation->status, 'error_code' => $operation->error_code,
                    'error_message' => $operation->error_message, 'queue' => $this->queueDetails($operation, 'whisperx'), 'chunks' => $chunks];
            })->values(),
            'transcripts' => $transcriptions->map(function (AiOperationUsage $operation) use ($approvedTranscriptHashes): array {
                $result = is_array($operation->result) ? $operation->result : [];
                $approvedText = $result['transcript_review']['approved_text'] ?? $result['text'] ?? '';
                $approvedHash = $result['transcript_review']['approved_sha256'] ?? (is_string($approvedText) ? hash('sha256', $approvedText) : null);
                return [
                    'operation_id' => $operation->id,
                    'status' => $operation->status,
                    'text' => $result['transcript_draft']['text'] ?? $result['text'] ?? '',
                    'approved' => ($result['transcript_review']['status'] ?? null) === 'approved',
                    'approved_at' => $result['transcript_review']['approved_at'] ?? null,
                    'recovered_artifact' => ($result['recovery']['recovered_artifact'] ?? false) === true,
                    'recovery_details' => $this->recoveryDetails($result['recovery'] ?? null),
                    'english_subtitles_approved' => is_string($approvedHash) && in_array($approvedHash, $approvedTranscriptHashes[$operation->id] ?? [], true),
                    'offset_seconds' => (float) ($operation->timeline_offset_seconds ?? 0),
                    'error_code' => $operation->error_code,
                    'error_message' => $operation->error_message,
                    'queue' => $this->queueDetails($operation, (string) config('queue.default'), 600),
                ];
            })->values(),
            'alignments' => $alignmentsByTranscript->map(function (AiOperationUsage $operation) use ($alignments, $reconciler): array {
                $reconciliation = $reconciler->reconcile($operation);
                $operation->refresh();
                $result = is_array($operation->result) ? $operation->result : [];
                return $alignments->safeStatus($operation) + [
                    'approved' => ($result['approval_statuses']['en'] ?? null) === 'approved',
                    'transcript_operation_id' => $result['transcript_operation_id'] ?? null,
                    'download_version_id' => $result['subtitle_versions']['en']['current_approved_version_id'] ?? null,
                    'transcript_sha256' => $result['transcript_sha256'] ?? null,
                    'recovered_artifact' => ($result['recovery']['recovered_artifact'] ?? false) === true,
                    'recovery_details' => $this->recoveryDetails($result['recovery'] ?? null),
                    'queue' => $this->queueDetails($operation, 'whisperx', null, $reconciliation['queue_state']),
                    'queue_state' => $reconciliation['queue_state'],
                ];
            })->values(),
            'translations' => $translations->map(function (AiOperationUsage $operation): ?array {
                $result = is_array($operation->result) ? $operation->result : [];
                $targetLanguage = mb_strtolower((string) ($result['target_language'] ?? ''));
                if ($targetLanguage !== '' && !in_array($targetLanguage, ['fil', 'fil-ph', 'filipino'], true)) return null;
                if ($targetLanguage === '' && !is_numeric($result['source_transcript_operation_id'] ?? null)) return null;
                return [
                    'operation_id' => $operation->id,
                    'status' => $operation->status,
                    'text' => $result['subtitle_draft']['text'] ?? $result['text'] ?? '',
                    'target_language' => $result['target_language'] ?? null,
                    'source_transcript_operation_id' => $result['source_transcript_operation_id'] ?? null,
                    'source_transcript_sha256' => $result['source_transcript_sha256'] ?? null,
                    'source_alignment_operation_id' => $result['source_alignment_operation_id'] ?? null,
                    'source_english_srt_version_id' => $result['source_english_srt_version_id'] ?? null,
                    'cues' => $result['subtitle_draft']['cues'] ?? $result['translated_cues'] ?? [],
                    'cue_timed' => is_string($result['subtitle_draft']['srt_path'] ?? null),
                    'approved' => ($result['approval_statuses']['fil'] ?? null) === 'approved',
                    'error_code' => $operation->error_code,
                    'error_message' => $operation->error_message,
                    'queue' => $this->queueDetails($operation, (string) config('queue.default')),
                ];
            })->filter()->values(),
        ], 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function transcribe(Lesson $lesson, AiOperationService $operations)
    {
        if (config('queue.default') === 'sync') {
            throw ValidationException::withMessages(['transcription' => 'Configure an asynchronous queue before generating a transcript.']);
        }
        try {
            $operations->assigned('transcription');
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['transcription' => $exception->getMessage()]);
        }

        $disk = Storage::disk('local');
        $video = $lesson->video_path ? realpath($disk->path($lesson->video_path)) : false;
        $privateRoot = realpath($disk->path(''));
        $prefix = $privateRoot ? rtrim($privateRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR : '';
        $inside = $video && $prefix && (DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($video), strtolower($prefix))
            : str_starts_with($video, $prefix));
        if (!$inside || !is_file($video) || !is_readable($video)) {
            throw ValidationException::withMessages(['transcription' => 'The lesson video is unavailable in private storage.']);
        }

        $videoHash = hash_file('sha256', $video);
        if (!is_string($videoHash)) throw ValidationException::withMessages(['transcription' => 'The lesson video could not be fingerprinted.']);
        $key = 'audio-prep-'.$lesson->id.'-'.substr($videoHash, 0, 32);
        $fingerprint = hash('sha256', json_encode([
            'service' => 'audio_preparation', 'lesson_id' => $lesson->id, 'video_sha256' => $videoHash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

        $usage = AiOperationUsage::query()->firstOrCreate(['idempotency_key' => $key], [
            'service' => 'audio_preparation', 'model' => 'ffmpeg-local', 'lesson_id' => $lesson->id,
            'status' => 'queued', 'request_reference' => (string) str()->uuid(), 'request_fingerprint' => $fingerprint,
            'result' => ['requested_by' => auth()->id(), 'video_sha256' => $videoHash],
        ]);
        if (!hash_equals((string) $usage->request_fingerprint, $fingerprint)) {
            throw ValidationException::withMessages(['transcription' => 'This audio preparation key is associated with different input.']);
        }
        if (!$usage->wasRecentlyCreated && in_array($usage->status, ['queued', 'processing', 'succeeded'], true)) {
            $chunkOperations = collect($usage->result['preparation']['transcription_operations'] ?? [])->pluck('operation_id')->filter()->all();
            $hasFailedChunk = $chunkOperations && AiOperationUsage::query()->whereIn('id', $chunkOperations)->where('status', 'failed')->exists();
            if (!$hasFailedChunk) return response()->json(['operation_id' => $usage->id, 'status' => $usage->status, 'already_submitted' => true], 200);
        }

        $usage->update(['status' => 'queued', 'error_code' => null, 'error_message' => null]);
        try {
            PrepareLessonAudioForSubtitles::dispatch($usage->id)
                ->onConnection((string) config('whisperx.connection', 'whisperx'))
                ->onQueue((string) config('whisperx.queue', 'whisperx-alignment'))
                ->afterCommit();
        } catch (Throwable $exception) {
            $usage->update(['status' => 'failed', 'error_code' => 'QueueDispatchFailed', 'error_message' => 'Lesson audio preparation could not be queued.']);
            throw new RuntimeException('Lesson audio preparation could not be queued.', previous: $exception);
        }
        return response()->json(['operation_id' => $usage->id, 'status' => 'queued', 'already_submitted' => false], 202);
    }

    private function queueDetails(?AiOperationUsage $operation, string $connectionName, ?int $jobTimeout = null, ?string $queueState = null): ?array
    {
        if (!$operation) return null;
        $connection = config('queue.connections.'.$connectionName, []);
        $queueName = (string) ($connection['queue'] ?? 'default');
        $workerTimeout = $connectionName === 'whisperx' ? (int) config('whisperx.worker_timeout', 7500) : ($jobTimeout ?? 120);
        $workerTries = $connectionName === 'whisperx' ? 2 : 3;
        $waitSeconds = $operation->status === 'processing' && is_array($operation->result)
            && is_string($operation->result['heartbeat_at'] ?? $operation->result['processing_started_at'] ?? null)
            ? max(0, (int) now()->diffInSeconds(\Illuminate\Support\Carbon::parse($operation->result['heartbeat_at'] ?? $operation->result['processing_started_at'])))
            : ($operation->status === 'queued' && $operation->updated_at ? max(0, (int) $operation->updated_at->diffInSeconds(now())) : 0);
        $stalled = $operation->status === 'queued'
            ? ($queueState === 'pending_no_worker' || (in_array($queueState, ['missing', 'untracked'], true) && $waitSeconds >= 60)
                || in_array($queueState, ['delayed', 'reserved_stalled', 'heartbeat_stale_worker_alive', 'heartbeat_stale_worker_unverifiable', 'heartbeat_stale_unverifiable'], true)
                || ($queueState === null && $waitSeconds >= 60))
            : ($operation->status === 'processing' && $waitSeconds > 90
                && in_array($queueState, ['missing', 'untracked', 'heartbeat_stale_worker_alive', 'heartbeat_stale_worker_unverifiable', 'heartbeat_stale_unverifiable'], true));
        return [
            'connection' => $connectionName,
            'queue' => $queueName,
            'waiting_seconds' => $waitSeconds,
            'worker_stalled' => $stalled,
            'state' => $queueState,
            'worker_command' => 'php artisan queue:work '.$connectionName.' --queue='.$queueName.' --sleep=3 --tries='.$workerTries.' --timeout='.$workerTimeout,
        ];
    }

    private function recoveryDetails(mixed $recovery): ?array
    {
        if (!is_array($recovery) || ($recovery['recovered_artifact'] ?? false) !== true) return null;
        return [
            'alignment_input_association' => $recovery['alignment_input_association'] ?? 'unverified',
            'timeline_offset_status' => $recovery['timeline_offset_status'] ?? null,
            'source_audio_candidates' => $recovery['source_audio_candidates'] ?? [],
        ];
    }

    public function saveTranscript(Request $request, Lesson $lesson, int $transcript, WhisperxAlignmentService $alignments)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:200000']]);
        try {
            $operation = $alignments->saveTranscriptDraft($lesson, $transcript, $data['text'], (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['text' => $exception->getMessage()]);
        }
        return response()->json([
            'operation_id' => $operation->id,
            'text' => $alignments->currentTranscriptText($operation),
            'review_status' => $operation->result['transcript_review']['status'] ?? 'pending_review',
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function translate(Request $request, Lesson $lesson, int $transcript, WhisperxAlignmentService $alignments, AiOperationService $operations, ApprovedSubtitleService $subtitles, SubtitleSrtCueService $srt)
    {
        try {
            $request->validate(['source_alignment_operation_id' => ['required', 'integer', 'min:1'], 'source_english_srt_version_id' => ['required', 'string', 'max:64']]);
            $source = $alignments->transcriptVersion($lesson, $transcript);
            $text = $alignments->approvedTranscriptText($source);
            $textHash = hash('sha256', $text);
            $alignmentId = $request->integer('source_alignment_operation_id');
            $versionId = $request->string('source_english_srt_version_id')->toString();
            $alignment = AiOperationUsage::query()->where('lesson_id', $lesson->id)
                ->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->where('status', 'succeeded')->find($alignmentId);
            $alignmentResult = is_array($alignment?->result) ? $alignment->result : [];
            $currentVersionId = $alignmentResult['subtitle_versions']['en']['current_approved_version_id'] ?? null;
            if (!$alignment || ($alignmentResult['approval_statuses']['en'] ?? null) !== 'approved'
                || (int) ($alignmentResult['transcript_operation_id'] ?? 0) !== (int) $source->id
                || ($alignmentResult['transcript_sha256'] ?? null) !== $textHash
                || !is_string($versionId) || $versionId === '' || $versionId !== $currentVersionId) {
                throw new RuntimeException('Approve the English subtitles aligned to this transcript before generating Filipino translation.');
            }
            $approvedEnglish = $subtitles->approvedVersion($lesson, $alignment, 'en', $versionId);
            $englishSrt = Storage::disk('local')->get($approvedEnglish['path']);
            if (!hash_equals((string) $approvedEnglish['sha256'], hash('sha256', $englishSrt))) throw new RuntimeException('The approved English SRT failed its integrity check.');
            $cues = $srt->parse($englishSrt);
            $assignment = $operations->assigned('translation')['integration'];
            if (!in_array(mb_strtolower((string) $assignment->target_language), ['fil', 'fil-ph', 'filipino'], true)) {
                throw new RuntimeException('Set the translation assignment target language to Filipino before queueing subtitles.');
            }
            $sourceMetadata = [
                'transcript_operation_id' => $source->id, 'transcript_sha256' => $textHash,
                'alignment_operation_id' => $alignment->id, 'english_srt_version_id' => $versionId,
                'english_srt_sha256' => $approvedEnglish['sha256'],
            ];
            $configHash = substr(hash('sha256', implode('|', [$assignment->ai_integration_id, $assignment->model, $assignment->target_language])), 0, 14);
            $key = 'filcue-'.$alignment->id.'-'.substr($approvedEnglish['sha256'], 0, 16).'-'.$configHash;
            $queued = $operations->queueSubtitleCueTranslation($cues, $sourceMetadata, $key, $lesson->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['translation' => $exception->getMessage()]);
        }
        $operation = AiOperationUsage::query()->whereKey($queued['usage_id'])->firstOrFail();
        return response()->json([
            'operation_id' => $operation->id,
            'status' => $operation->status,
            'target_language' => $operation->result['target_language'] ?? null,
            'already_submitted' => $queued['already_submitted'] ?? false,
        ], ($queued['already_submitted'] ?? false) ? 200 : 202, ['Cache-Control' => 'private, no-store']);
    }

    public function saveTranslation(Request $request, Lesson $lesson, int $translation)
    {
        $data = $request->validate(['text' => ['nullable', 'string', 'max:200000'], 'cues' => ['nullable', 'array', 'min:1', 'max:10000'], 'cues.*.index' => ['required_with:cues', 'integer', 'min:1'], 'cues.*.text' => ['required_with:cues', 'string', 'max:10000']]);
        $operation = AiOperationUsage::query()->where('service', 'translation')->where('lesson_id', $lesson->id)->findOrFail($translation);
        $result = is_array($operation->result) ? $operation->result : [];
        if ($operation->status !== 'succeeded' || trim((string) ($result['text'] ?? '')) === '') {
            throw ValidationException::withMessages(['text' => 'Wait for a completed Filipino translation before editing it.']);
        }
        $target = mb_strtolower((string) ($result['target_language'] ?? ''));
        if ($target !== '' && !in_array($target, ['fil', 'fil-ph', 'filipino'], true)) {
            throw ValidationException::withMessages(['text' => 'This translation does not target Filipino.']);
        }
        $cueDraft = $data['cues'] ?? null;
        $hasTimedDraft = is_string($result['subtitle_draft']['srt_path'] ?? null) && is_array($result['translated_cues'] ?? null);
        if ($hasTimedDraft && is_array($cueDraft)) {
            try {
                $sourceCues = $result['translated_cues'];
                if (count($sourceCues) !== count($cueDraft)) throw new RuntimeException('Keep every translated cue in its original order.');
                $edited = [];
                foreach ($sourceCues as $index => $cue) {
                    $item = $cueDraft[$index] ?? null;
                    if (!is_array($item) || (int) ($item['index'] ?? 0) !== (int) $cue['index'] || !is_string($item['text'] ?? null) || trim($item['text']) === '') {
                        throw new RuntimeException('Keep every translated cue in its original order and provide text for each cue.');
                    }
                    $cue['text'] = $item['text']; $edited[] = $cue;
                }
                $text = implode("\n", array_column($edited, 'text'));
                $path = $result['subtitle_draft']['srt_path'];
                $rendered = app(SubtitleSrtCueService::class)->render($edited);
                if (!Storage::disk('local')->put($path, $rendered)) throw new RuntimeException('The Filipino SRT draft could not be saved privately.');
                $result['translated_cues'] = $edited;
                $result['subtitle_draft']['cues'] = $edited;
                $result['subtitle_draft']['srt_sha256'] = hash('sha256', $rendered);
            } catch (RuntimeException $exception) { throw ValidationException::withMessages(['cues' => $exception->getMessage()]); }
        } else {
            $text = $data['text'] ?? '';
            if (trim($text) === '') throw ValidationException::withMessages(['text' => 'The Filipino translation draft cannot be empty.']);
        }
        $result['subtitle_draft'] = [
            ...($result['subtitle_draft'] ?? []),
            'text' => $text, 'sha256' => hash('sha256', $text),
            'updated_by' => $request->user()->id, 'updated_at' => now()->toIso8601String(),
        ];
        $result['approval_statuses']['fil'] = 'pending_review';
        $operation->result = $result;
        $operation->save();
        return response()->json(['operation_id' => $operation->id, 'text' => $text, 'cues' => $result['subtitle_draft']['cues'] ?? null], 200, ['Cache-Control' => 'private, no-store']);
    }
}
