<?php

use App\Services\OpenAiProvider;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

function whisper1PendingRequest(Response|Throwable $postResult, int $timeout, int $connectTimeout): PendingRequest
{
    $pending = Mockery::mock(PendingRequest::class);
    $pending->shouldReceive('withToken')->once()->with('secret-test-key')->andReturnSelf();
    $pending->shouldReceive('timeout')->once()->with($timeout)->andReturnSelf();
    $pending->shouldReceive('connectTimeout')->once()->with($connectTimeout)->andReturnSelf();
    $pending->shouldReceive('withHeaders')->once()->andReturnSelf();
    $pending->shouldReceive('attach')->once()->withArgs(fn ($name, $handle, $filename) => $name === 'file' && is_resource($handle) && str_ends_with($filename, '.mp3'))->andReturnSelf();
    $pending->shouldReceive('post')->once()->with('https://api.openai.com/v1/audio/transcriptions', [
        'model' => 'whisper-1', 'response_format' => 'verbose_json', 'timestamp_granularities[]' => 'word',
    ])->andReturnUsing(function () use ($postResult) {
        if ($postResult instanceof Throwable) throw $postResult;
        return $postResult;
    });

    return $pending;
}

function whisper1TestAudioPath(): string
{
    $path = tempnam(sys_get_temp_dir(), 'whisper1-timeout-').'.mp3';
    file_put_contents($path, 'sample audio bytes');
    return $path;
}

it('loads the required Whisper-1 HTTP timeout defaults', function () {
    expect(config('openai.whisper_http_timeout'))->toBe(1800)
        ->and(config('openai.whisper_connect_timeout'))->toBe(30);
});

it('applies configured timeout values to a successful Whisper-1 request and logs safe diagnostics', function () {
    config(['openai.whisper_http_timeout' => 1200, 'openai.whisper_connect_timeout' => 45]);
    $response = new Response(new PsrResponse(200, ['x-request-id' => 'req_test_123'], json_encode([
        'text' => 'Approved words.', 'duration' => 8.5,
        'words' => [['word' => 'Approved', 'start' => 0.1, 'end' => 1.0], ['word' => 'words.', 'start' => 1.1, 'end' => 2.0]],
    ], JSON_THROW_ON_ERROR)));
    Http::shouldReceive('acceptJson')->once()->andReturn(whisper1PendingRequest($response, 1200, 45));
    Log::shouldReceive('info')->once()->with('OpenAI Whisper-1 timing request completed.', Mockery::on(function (array $context): bool {
        return $context['audio_file_size_bytes'] === strlen('sample audio bytes')
            && $context['audio_duration_seconds'] === 8.5
            && is_string($context['request_started_at']) && is_string($context['request_ended_at'])
            && is_int($context['request_duration_ms']) && $context['http_status'] === 200
            && $context['openai_request_id'] === 'req_test_123' && $context['job_attempt'] === 2
            && !array_key_exists('api_key', $context) && !array_key_exists('transcript', $context);
    }));
    $path = whisper1TestAudioPath();

    try {
        $result = app(OpenAiProvider::class)->transcribeWordTimestamps(
            ['endpoint' => 'https://api.openai.com/v1'], ['api_key' => 'secret-test-key'], $path, 'whisper1-test-key', 8.4, 2,
        );
    } finally {
        @unlink($path);
    }

    expect($result['duration_seconds'])->toBe(8.5)->and($result['request_reference'])->toBe('req_test_123');
});

it('logs a safe cURL timeout diagnostic and rethrows the original exception', function () {
    $exception = new ConnectionException('cURL error 28: Operation timed out for Bearer sk-secret-never-log');
    Http::shouldReceive('acceptJson')->once()->andReturn(whisper1PendingRequest($exception, 1800, 30));
    Log::shouldReceive('warning')->once()->with('OpenAI Whisper-1 timing request failed.', Mockery::on(function (array $context): bool {
        return $context['audio_file_size_bytes'] === strlen('sample audio bytes')
            && $context['audio_duration_seconds'] === 123.0
            && is_string($context['request_started_at']) && is_string($context['request_ended_at'])
            && is_int($context['request_duration_ms']) && $context['http_status'] === null
            && $context['openai_request_id'] === null && $context['job_attempt'] === 1
            && $context['curl_error_code'] === 28
            && !str_contains($context['curl_error_message'], 'sk-secret-never-log')
            && !array_key_exists('transcript', $context);
    }));
    $path = whisper1TestAudioPath();

    try {
        expect(fn () => app(OpenAiProvider::class)->transcribeWordTimestamps(
            ['endpoint' => 'https://api.openai.com/v1'], ['api_key' => 'secret-test-key'], $path, 'whisper1-test-key', 123.0, 1,
        ))->toThrow(ConnectionException::class, 'cURL error 28');
    } finally {
        @unlink($path);
    }
});
