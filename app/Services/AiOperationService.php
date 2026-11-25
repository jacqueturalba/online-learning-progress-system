<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Support\AiProviderRegistry;
use App\Support\AiProviderException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class AiOperationService
{
    public function __construct(private AiProviderRegistry $providers) {}

    public function assigned(string $service): array
    {
        $assignment = AiServiceAssignment::query()->with('integration')
            ->where('service', $service)->first();
        if (!$assignment || !$assignment->integration || !$assignment->integration->is_enabled || $assignment->integration->trashed()) {
            throw new RuntimeException('No enabled integration is assigned to this service.');
        }
        $provider = $this->providers->get($assignment->integration->provider);
        if (!in_array($service, $provider->capabilities(), true)) throw new RuntimeException('The assigned provider does not support this service.');
        return [
            'integration' => $assignment,
            'account' => $assignment->integration,
            'provider' => $provider,
        ];
    }

    public function transcribe(string $audioPath, string $idempotencyKey, ?int $lessonId = null, string $language = 'en'): array
    {
        $fingerprint = $this->fingerprint(['service' => 'transcription', 'lesson_id' => $lessonId, 'language' => $language,
            'audio_sha256' => is_file($audioPath) ? hash_file('sha256', $audioPath) : null]);
        return $this->execute('transcription', $lessonId, function (array $configuration, array $credentials, string $model, $provider, ?string $targetLanguage, string $key) use ($audioPath, $language): array {
            return $provider->transcribe($configuration, ['credentials' => $credentials, 'model' => $model, 'audio_path' => $audioPath, 'language' => $language, 'idempotency_key' => $key]);
        }, $idempotencyKey, $fingerprint);
    }

    public function translate(string $text, string $idempotencyKey, ?int $lessonId = null): array
    {
        $fingerprint = $this->fingerprint(['service' => 'translation', 'lesson_id' => $lessonId, 'text' => $text]);
        return $this->execute('translation', $lessonId, function (array $configuration, array $credentials, string $model, $provider, ?string $language, string $key) use ($text): array {
            if (!$language) throw new RuntimeException('Set a target language for the translation assignment.');
            return $provider->translate($configuration, ['credentials' => $credentials, 'model' => $model, 'text' => $text, 'target_language' => $language, 'idempotency_key' => $key]);
        }, $idempotencyKey, $fingerprint);
    }

    /** Queue encrypted subtitle work without placing lesson text in Laravel's serialized queue payload. */
    public function queueTranslation(string $text, string $idempotencyKey, ?int $lessonId = null, ?int $sourceTranscriptOperationId = null): array
    {
        $assigned = $this->assigned('translation');
        return $this->enqueue($assigned, 'translation', $lessonId, [
            'text' => $text,
            'target_language' => $assigned['integration']->target_language,
            'source_transcript_operation_id' => $sourceTranscriptOperationId,
            'source_transcript_sha256' => $sourceTranscriptOperationId ? hash('sha256', $text) : null,
        ], $idempotencyKey, $this->fingerprint(['service' => 'translation', 'lesson_id' => $lessonId, 'text' => $text,
            'target_language' => $assigned['integration']->target_language, 'source_transcript_operation_id' => $sourceTranscriptOperationId]), [
                'result' => array_filter([
                    'target_language' => $assigned['integration']->target_language,
                    'source_transcript_operation_id' => $sourceTranscriptOperationId,
                    'source_transcript_sha256' => $sourceTranscriptOperationId ? hash('sha256', $text) : null,
                ], fn ($value): bool => $value !== null),
            ]);
    }

    /** Queue cue preserving Filipino translation from an exact approved English SRT version. */
    public function queueSubtitleCueTranslation(array $cues, array $source, string $idempotencyKey, int $lessonId): array
    {
        $assigned = $this->assigned('translation');
        $language = $assigned['integration']->target_language;
        $input = [
            'cue_translation' => true, 'cues' => $cues, 'target_language' => $language,
            'source_transcript_operation_id' => $source['transcript_operation_id'],
            'source_transcript_sha256' => $source['transcript_sha256'],
            'source_alignment_operation_id' => $source['alignment_operation_id'],
            'source_english_srt_version_id' => $source['english_srt_version_id'],
            'source_english_srt_sha256' => $source['english_srt_sha256'],
        ];
        return $this->enqueue($assigned, 'translation', $lessonId, $input, $idempotencyKey,
            $this->fingerprint(['service' => 'translation-cues', 'lesson_id' => $lessonId, 'cues' => $cues, 'source' => $source, 'target_language' => $language]),
            ['result' => [
                'target_language' => $language,
                'source_transcript_operation_id' => $source['transcript_operation_id'],
                'source_transcript_sha256' => $source['transcript_sha256'],
                'source_alignment_operation_id' => $source['alignment_operation_id'],
                'source_english_srt_version_id' => $source['english_srt_version_id'],
                'source_english_srt_sha256' => $source['english_srt_sha256'],
            ]]);
    }

    public function queueTranscription(string $audioPath, string $idempotencyKey, ?int $lessonId = null, string $language = 'en', float $timelineOffsetSeconds = 0, ?string $queueName = 'ai-transcription-test'): array
    {
        $assigned = $this->assigned('transcription');
        return $this->enqueue($assigned, 'transcription', $lessonId, [
            'audio_path' => $audioPath,
            'prepared_audio_path' => $this->privatePreparedAudioRelativePath($audioPath),
            'language' => $language,
            'timeline_offset_seconds' => $timelineOffsetSeconds,
        ],
            $idempotencyKey, $this->fingerprint(['service' => 'transcription', 'lesson_id' => $lessonId, 'language' => $language,
                'audio_sha256' => is_file($audioPath) ? hash_file('sha256', $audioPath) : null, 'timeline_offset_seconds' => $timelineOffsetSeconds]),
            ['timeline_offset_seconds' => $timelineOffsetSeconds], $queueName);
    }

    public function runQueued(int $usageId, array $payload): array
    {
        $usage = DB::transaction(function () use ($usageId): AiOperationUsage|array {
            $row = AiOperationUsage::query()->whereKey($usageId)->lockForUpdate()->firstOrFail();
            if ($row->status === 'succeeded') return ['already_completed' => true, 'usage_id' => $row->id];
            if ($row->status === 'processing' && $row->updated_at?->greaterThan(now()->subSeconds(120))) {
                return ['already_processing' => true, 'usage_id' => $row->id];
            }
            if (!in_array($row->status, ['queued', 'processing'], true)) {
                throw new RuntimeException('The queued AI operation is not available to run.');
            }
            $row->update(['status' => 'processing']);
            return $row->fresh();
        });
        if (is_array($usage)) return $usage;
        $account = \App\Models\AiIntegration::query()->findOrFail($usage->ai_integration_id);
        if (!$account->is_enabled || ($payload['service'] ?? null) !== $usage->service) throw new RuntimeException('The queued AI operation is no longer available.');
        $provider = $this->providers->get($account->provider);
        if (!in_array($usage->service, $provider->capabilities(), true)) throw new RuntimeException('The assigned provider does not support this service.');
        $targetLanguage = $payload['target_language'] ?? null;
        $operation = $usage->service === 'transcription'
            ? fn (array $configuration, array $credentials, string $model): array => $provider->transcribe($configuration, [
                'credentials' => $credentials, 'model' => $model, 'audio_path' => $payload['audio_path'], 'language' => $payload['language'] ?? 'en', 'idempotency_key' => $usage->idempotency_key,
            ])
            : (($payload['cue_translation'] ?? false) === true
                ? function (array $configuration, array $credentials, string $model) use ($provider, $payload, $usage): array {
                    if (!$provider instanceof \App\Support\SubtitleCueTranslationProvider) throw new RuntimeException('The assigned provider does not support cue preserving subtitle translation.');
                    $translated = app(SubtitleCueTranslationService::class)->translate($payload['cues'], $configuration, $credentials, $model,
                        (string) $payload['target_language'], $usage->idempotency_key, $provider);
                    $parser = app(SubtitleSrtCueService::class);
                    $translatedCues = $parser->withTranslatedText($payload['cues'], $translated['cues']);
                    $srt = $parser->render($translatedCues);
                    $path = 'subtitle-drafts/'.$usage->lesson_id.'/'.$usage->id.'/filipino.srt';
                    if (!\Illuminate\Support\Facades\Storage::disk('local')->put($path, $srt)) throw new RuntimeException('The private Filipino SRT draft could not be saved.');
                    return [
                        'text' => implode("\n", array_column($translatedCues, 'text')),
                        'translated_cues' => $translatedCues,
                        'subtitle_draft' => ['text' => implode("\n", array_column($translatedCues, 'text')), 'cues' => $translatedCues,
                            'srt_path' => $path, 'srt_sha256' => hash('sha256', $srt)],
                        'source_alignment_operation_id' => $payload['source_alignment_operation_id'],
                        'source_english_srt_version_id' => $payload['source_english_srt_version_id'],
                        'source_english_srt_sha256' => $payload['source_english_srt_sha256'],
                        'source_transcript_operation_id' => $payload['source_transcript_operation_id'],
                        'source_transcript_sha256' => $payload['source_transcript_sha256'],
                        'target_language' => $payload['target_language'], 'usage' => $translated['usage'],
                    ];
                }
                : fn (array $configuration, array $credentials, string $model): array => $provider->translate($configuration, [
                    'credentials' => $credentials, 'model' => $model, 'text' => $payload['text'], 'target_language' => $targetLanguage, 'idempotency_key' => $usage->idempotency_key,
                ]));

        $result = $this->perform($usage, $account, $usage->model, $provider, $targetLanguage, $operation);
        if ($usage->service === 'transcription' && is_string($payload['prepared_audio_path'] ?? null)) {
            $result['prepared_audio_path'] = $payload['prepared_audio_path'];
            $result['language'] = (string) ($payload['language'] ?? 'en');
            $usage->update(['result' => $result]);
        } elseif ($usage->service === 'translation' && is_string($payload['target_language'] ?? null)) {
            $result['target_language'] = $payload['target_language'];
            if (is_numeric($payload['source_transcript_operation_id'] ?? null)) {
                $result['source_transcript_operation_id'] = (int) $payload['source_transcript_operation_id'];
                $result['source_transcript_sha256'] = $payload['source_transcript_sha256'] ?? null;
            }
            $usage->update(['result' => $result]);
        }
        return $result;
    }

    private function enqueue(array $assigned, string $service, ?int $lessonId, array $input, string $idempotencyKey, string $fingerprint, array $usageAttributes = [], ?string $queueName = null): array
    {
        $assignment = $assigned['integration'];
        $account = $assigned['account'];
        $this->validateIdempotencyKey($idempotencyKey);
        $fingerprint = $this->scopedFingerprint($fingerprint, $account->id, $assignment->model, $assignment->target_language);
        $usage = AiOperationUsage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'ai_integration_id' => $account->id, 'service' => $service, 'model' => $assignment->model,
            'lesson_id' => $lessonId, 'status' => 'queued', 'request_reference' => (string) str()->uuid(), 'request_fingerprint' => $fingerprint,
        ] + $usageAttributes);
        $this->assertSameRequest($usage, $fingerprint);
        if (!$usage->wasRecentlyCreated && in_array($usage->status, ['succeeded', 'queued', 'processing'], true)) {
            return ['usage_id' => $usage->id, 'request_reference' => $usage->request_reference, 'status' => $usage->status, 'already_submitted' => true];
        }
        $usage->update(['status' => 'queued', 'error_code' => null, 'error_message' => null]);
        try {
            $payload = Crypt::encryptString(json_encode(array_merge($input, [
                'service' => $service, 'lesson_id' => $lessonId,
            ]), JSON_THROW_ON_ERROR));
            $job = RunAiOperation::dispatch($usage->id, $payload);
            if ($queueName !== null) $job->onQueue($queueName);
            $job->afterCommit();
        } catch (Throwable $exception) {
            $usage->update(['status' => 'failed', 'error_code' => class_basename($exception), 'error_message' => 'AI operation could not be queued.']);
            throw $exception;
        }
        return ['usage_id' => $usage->id, 'request_reference' => $usage->request_reference, 'status' => 'queued'];
    }

    private function execute(string $service, ?int $lessonId, callable $operation, string $idempotencyKey, string $fingerprint): array
    {
        $assigned = $this->assigned($service);
        $assignment = $assigned['integration'];
        $row = $assigned['account'];
        $credentials = $row->credentials ?? [];
        $this->validateIdempotencyKey($idempotencyKey);
        $fingerprint = $this->scopedFingerprint($fingerprint, $row->id, $assignment->model, $assignment->target_language);
        $usage = AiOperationUsage::query()->firstOrCreate(['idempotency_key' => $idempotencyKey], [
            'ai_integration_id' => $row->id, 'service' => $service, 'model' => $assignment->model,
            'lesson_id' => $lessonId, 'status' => 'processing', 'request_reference' => (string) str()->uuid(), 'request_fingerprint' => $fingerprint,
        ]);
        $this->assertSameRequest($usage, $fingerprint);
        if ($usage->status === 'succeeded' && is_array($usage->result)) return $usage->result;
        if (in_array($usage->status, ['queued', 'processing'], true) && $usage->wasRecentlyCreated === false) {
            return ['status' => $usage->status, 'usage_id' => $usage->id, 'already_submitted' => true];
        }
        $usage->update(['status' => 'processing', 'error_code' => null, 'error_message' => null]);
        return $this->perform($usage, $row, $assignment->model, $assigned['provider'], $assignment->target_language,
            fn ($configuration, $credentials, $model, $provider, $language) => $operation($configuration, $credentials, $model, $provider, $language, $idempotencyKey));
    }

    private function perform(AiOperationUsage $usage, $row, string $model, $provider, ?string $language, callable $operation): array
    {
        $usage->update(['status' => 'processing', 'error_code' => null, 'error_message' => null]);
        $started = hrtime(true);

        try {
            $result = $operation(['endpoint' => $row->endpoint], $row->credentials ?? [], $model, $provider, $language);
            $metrics = $result['usage'] ?? [];
            $usage->update([
                'status' => 'succeeded', 'processing_duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'audio_minutes' => $metrics['audio_minutes'] ?? null, 'input_tokens' => $metrics['input_tokens'] ?? null,
                'output_tokens' => $metrics['output_tokens'] ?? null, 'request_reference' => $result['request_reference'] ?? $usage->request_reference,
                'result' => $result,
            ]);
            return $result;
        } catch (Throwable $exception) {
            $usage->update([
                'status' => 'failed', 'processing_duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error_code' => $exception instanceof AiProviderException ? $exception->providerCode : class_basename($exception),
                'error_message' => $exception instanceof AiProviderException ? $exception->getMessage() : 'Provider operation failed.',
            ]);
            throw $exception;
        }
    }

    private function validateIdempotencyKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)) {
            throw new RuntimeException('Provide a stable idempotency key between 8 and 100 safe characters.');
        }
    }

    private function assertSameRequest(AiOperationUsage $usage, string $fingerprint): void
    {
        if (!is_string($usage->request_fingerprint) || !hash_equals($usage->request_fingerprint, $fingerprint)) {
            throw new RuntimeException('This idempotency key has already been used for a different AI request.');
        }
    }

    private function fingerprint(array $request): string
    {
        return hash('sha256', json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function scopedFingerprint(string $fingerprint, int $integrationId, string $model, ?string $language): string
    {
        return hash('sha256', implode('|', [$fingerprint, $integrationId, $model, $language ?? '']));
    }

    private function privatePreparedAudioRelativePath(string $audioPath): string
    {
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $privateRoot = realpath($disk->path(''));
        $preparedRoot = realpath($disk->path(trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\')));
        $audio = realpath($audioPath);
        if (!$privateRoot || !$preparedRoot || !$audio || !is_file($audio) || !is_readable($audio)) {
            throw new RuntimeException('The prepared audio path could not be associated with this transcription.');
        }
        $privatePrefix = rtrim($privateRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $preparedPrefix = rtrim($preparedRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $insidePrivate = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($preparedRoot), strtolower($privatePrefix))
            : str_starts_with($preparedRoot, $privatePrefix);
        $insidePrepared = DIRECTORY_SEPARATOR === '\\'
            ? str_starts_with(strtolower($audio), strtolower($preparedPrefix))
            : str_starts_with($audio, $preparedPrefix);
        if (!$insidePrivate || !$insidePrepared) {
            throw new RuntimeException('The prepared audio must be inside private audio preparation storage.');
        }
        return str_replace(DIRECTORY_SEPARATOR, '/', substr($audio, strlen($privatePrefix)));
    }
}
