<?php

namespace App\Services;

use App\Support\AiProvider;
use App\Support\AiProviderException;
use App\Support\SubtitleCueTranslationProvider;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiProvider implements AiProvider, SubtitleCueTranslationProvider
{
    private const MODELS = [
        'transcription' => ['gpt-transcribe', 'gpt-4o-mini-transcribe', 'gpt-4o-transcribe', 'whisper-1'],
        'translation' => ['gpt-6.1-terra', 'gpt-4o-mini', 'gpt-4o'],
    ];

    public function key(): string { return 'openai'; }
    public function name(): string { return 'OpenAI'; }
    public function capabilities(): array { return ['transcription', 'translation', 'connection_test']; }
    public function credentialFields(): array { return [['key' => 'api_key', 'label' => 'API key', 'secret' => true]]; }
    public function supportedModels(string $service): array { return self::MODELS[$service] ?? []; }

    public function testConnection(array $configuration, array $credentials): array
    {
        $response = $this->request($credentials)->get($this->url($configuration, '/models'));
        $this->assertSuccessful($response);
        return ['success' => true, 'message' => 'Connection successful.'];
    }

    public function transcribe(array $configuration, array $input): array
    {
        $path = $input['audio_path'] ?? null;
        if (!is_string($path) || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('The audio file is unavailable for transcription.');
        }
        $handle = fopen($path, 'rb');
        $model = $input['model'];
        $parameters = ['model' => $model];
        if ($model === 'gpt-transcribe') {
            $parameters['languages[]'] = $input['languages'] ?? ($input['language'] ?? 'en');
        } else {
            $parameters['language'] = $input['language'] ?? 'en';
        }
        if ($model === 'whisper-1') {
            $parameters['response_format'] = 'verbose_json';
            $parameters['timestamp_granularities[]'] = 'segment';
        }
        try {
            $response = $this->request($input['credentials'])
                ->withHeaders($this->idempotencyHeaders($input['idempotency_key'] ?? null))
                ->attach('file', $handle, basename($path))
                ->post($this->url($configuration, '/audio/transcriptions'), $parameters);
        } finally {
            if (is_resource($handle)) fclose($handle);
        }
        $this->assertSuccessful($response);
        $body = $response->json();
        $rawSegments = $body['segments'] ?? null;
        $segments = is_array($rawSegments) ? array_map(function ($segment): ?array {
            if (!is_array($segment) || !isset($segment['start'], $segment['end']) || !is_numeric($segment['start']) || !is_numeric($segment['end'])) return null;
            $start = (float) $segment['start'];
            $end = (float) $segment['end'];
            if (!is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start || !isset($segment['text']) || !is_string($segment['text'])) return null;
            return ['start' => $start, 'end' => $end, 'text' => $segment['text']];
        }, $rawSegments) : null;
        $segmentsWereProvided = array_key_exists('segments', $body) && $body['segments'] !== null;
        $hasInvalidSegments = ($segmentsWereProvided && !is_array($rawSegments)) || (is_array($segments) && in_array(null, $segments, true));
        $hasTimestampedSegments = is_array($segments) && $segments !== [] && !$hasInvalidSegments;

        return [
            'text' => (string) ($body['text'] ?? ''),
            'segments' => $hasTimestampedSegments ? $segments : null,
            'timestamp_status' => $hasTimestampedSegments ? 'timestamped' : ($hasInvalidSegments ? 'invalid_segments' : 'text_only'),
            'usage' => $this->usage($body), 'request_reference' => $response->header('x-request-id'),
        ];
    }

    public function translate(array $configuration, array $input): array
    {
        $response = $this->request($input['credentials'])->withHeaders($this->idempotencyHeaders($input['idempotency_key'] ?? null))->post($this->url($configuration, '/chat/completions'), [
            'model' => $input['model'],
            'messages' => [
                ['role' => 'system', 'content' => 'Translate the supplied text faithfully into '.$input['target_language'].'. Preserve meaning; do not add or omit content. Preserve personal names, Bible book names, Bible references, chapter and verse numbers, and quotation boundaries exactly. Keep theological terms unchanged when there is no clear standard equivalent in the target language. Do not add commentary. Return only the translation.'],
                ['role' => 'user', 'content' => $input['text']],
            ],
        ]);
        $this->assertSuccessful($response);
        $body = $response->json();
        return [
            'text' => (string) ($body['choices'][0]['message']['content'] ?? ''),
            'usage' => $this->usage($body), 'request_reference' => $response->header('x-request-id'),
        ];
    }

    public function translateSubtitleCues(array $configuration, array $input): array
    {
        $response = $this->request($input['credentials'])->withHeaders($this->idempotencyHeaders($input['idempotency_key'] ?? null))->post($this->url($configuration, '/chat/completions'), [
            'model' => $input['model'],
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => 'Translate each supplied subtitle cue faithfully into natural Filipino. Preserve meaning, names, Bible book names, references, numbers, punctuation intent, and theological terms. Use adjacent context only to resolve references and terminology. Do not add or omit meaning. Keep every cue ID exactly once and in the same order. Do not split, merge, renumber, or reorder cues. Keep each translation concise enough for its original subtitle timing. Return only JSON with shape {"cues":[{"index":1,"text":"..."}]} .'],
                ['role' => 'user', 'content' => json_encode(['context_before' => $input['context_before'] ?? [], 'cues' => $input['cues'] ?? [], 'context_after' => $input['context_after'] ?? []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)],
            ],
        ]);
        $this->assertSuccessful($response);
        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) throw new RuntimeException('The translation provider returned no Filipino cue data.');
        try { $translated = json_decode($content, true, flags: JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('The translation provider returned malformed Filipino cue data.'); }
        if (!is_array($translated['cues'] ?? null)) throw new RuntimeException('The translation provider returned no Filipino cue list.');
        return ['cues' => $translated['cues'], 'usage' => $this->usage($body), 'request_reference' => $response->header('x-request-id')];
    }

    private function request(array $credentials)
    {
        $key = $credentials['api_key'] ?? null;
        if (!is_string($key) || trim($key) === '') throw new RuntimeException('The provider credential is not configured.');
        return Http::acceptJson()->withToken($key)->timeout(90)->connectTimeout(10);
    }

    private function url(array $configuration, string $path): string
    {
        return rtrim($configuration['endpoint'] ?: 'https://api.openai.com/v1', '/').$path;
    }

    private function assertSuccessful(Response $response): void
    {
        if ($response->successful()) return;

        $status = $response->status();
        $providerCode = $response->json('error.code');
        $providerType = $response->json('error.type');
        $code = is_string($providerCode) ? $providerCode : (is_string($providerType) ? $providerType : 'provider_error');

        [$safeCode, $message] = match (true) {
            $status === 401 => ['invalid_credentials', 'The OpenAI API key was rejected. Check the key and try again.'],
            in_array($code, ['insufficient_quota', 'billing_hard_limit_reached'], true) => ['insufficient_credits', 'The OpenAI account has no available API credits or has reached its spending limit.'],
            $status === 403 => ['access_denied', 'The OpenAI account or API key does not have access to this request. Check account permissions and model access.'],
            $status === 404 || $code === 'model_not_found' => ['unsupported_model', 'The selected model is unavailable to this OpenAI account. Choose an available model and try again.'],
            $status === 429 => ['rate_limited', 'The OpenAI request limit was reached. Wait briefly and try again.'],
            $status >= 500 => ['provider_unavailable', 'OpenAI is temporarily unavailable. Try again later.'],
            $status === 400 => ['invalid_request', 'OpenAI rejected the request. Check the selected model and request settings.'],
            default => ['provider_error', 'OpenAI could not complete the request (HTTP '.$status.').'],
        };

        throw new AiProviderException($safeCode, $message, $status);
    }

    private function usage(array $body): array
    {
        $usage = $body['usage'] ?? [];
        return array_filter([
            'audio_minutes' => isset($usage['seconds']) ? $usage['seconds'] / 60 : null,
            'input_tokens' => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null,
            'output_tokens' => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null,
        ], fn ($value) => $value !== null);
    }

    private function idempotencyHeaders(mixed $key): array
    {
        return is_string($key) && preg_match('/^[A-Za-z0-9._:-]{8,100}$/', $key)
            ? ['Idempotency-Key' => $key]
            : [];
    }
}
