<?php

use App\Models\AiIntegration;
use App\Models\AiIntegrationAudit;
use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use App\Services\AiOperationService;
use App\Support\AiProvider;
use App\Support\AiProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Crypt;
use App\Services\RunAiOperation;

uses(RefreshDatabase::class);

function aiSettingsUser(string $role, string $email): User
{
    return User::create(['name' => 'AI Settings '.$role, 'email' => $email, 'password' => Hash::make('StrongPass123!'), 'role' => $role]);
}

function addOpenAiAccount($test, string $label, string $key = 'sk-test-private-key')
{
    return $test->postJson('/api/admin/ai-integrations', [
        'provider' => 'openai', 'label' => $label, 'endpoint' => 'https://api.openai.com/v1',
        'credentials' => ['api_key' => $key],
    ])->assertCreated()->json('integration');
}

it('stores multiple provider accounts with encrypted credentials and masks them in responses', function () {
    $admin = aiSettingsUser('admin', 'ai-accounts-admin@example.test');
    $this->actingAs($admin);
    $first = addOpenAiAccount($this, 'Primary account', 'sk-primary-sensitive');
    $second = addOpenAiAccount($this, 'Secondary account', 'sk-secondary-sensitive');

    expect($first['id'])->not->toBe($second['id'])
        ->and($first['credential_fields'][0]['masked'])->toBe('••••••••')
        ->and($first)->not->toHaveKey('credentials');
    $stored = AiIntegration::query()->findOrFail($first['id']);
    $raw = DB::table('ai_integrations')->where('id', $first['id'])->value('credentials');
    expect($raw)->not->toContain('sk-primary-sensitive')
        ->and($stored->credentials['api_key'])->toBe('sk-primary-sensitive');
    $this->getJson('/api/admin/ai-integrations')
        ->assertOk()->assertJsonMissing(['sk-primary-sensitive'])->assertJsonMissingPath('integrations.0.credentials');

    $this->putJson('/api/admin/ai-integrations/'.$first['id'], [
        'provider' => 'openai', 'label' => 'Primary renamed', 'endpoint' => null, 'credentials' => ['api_key' => ''],
    ])->assertOk();
    expect(AiIntegration::query()->findOrFail($first['id'])->credentials['api_key'])->toBe('sk-primary-sensitive');
    $this->putJson('/api/admin/ai-integrations/'.$first['id'], [
        'provider' => 'openai', 'label' => 'Primary renamed', 'endpoint' => null,
        'credentials' => ['api_key' => 'sk-replacement-private'],
    ])->assertOk();
    expect(AiIntegration::query()->findOrFail($first['id'])->credentials['api_key'])->toBe('sk-replacement-private');
});

it('rejects OpenAI endpoints that could receive the stored bearer credential', function () {
    $admin = aiSettingsUser('admin', 'ai-endpoint-admin@example.test');
    $this->actingAs($admin);

    $this->postJson('/api/admin/ai-integrations', [
        'provider' => 'openai', 'label' => 'Unsafe endpoint', 'endpoint' => 'https://api.openai.com.attacker.test/v1',
        'credentials' => ['api_key' => 'sk-endpoint-test'],
    ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
    $this->postJson('/api/admin/ai-integrations', [
        'provider' => 'openai', 'label' => 'Plain HTTP endpoint', 'endpoint' => 'http://api.openai.com/v1',
        'credentials' => ['api_key' => 'sk-endpoint-test'],
    ])->assertUnprocessable()->assertJsonValidationErrors('endpoint');
});

it('tests provider connectivity and records safe audit and usage outcomes', function () {
    $admin = aiSettingsUser('admin', 'ai-connection-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Test account');
    Http::fakeSequence('https://api.openai.com/v1/models')
        ->push(['data' => []], 200)
        ->push(['error' => ['message' => 'sk-test-private-key rejected']], 401);
    $this->postJson('/api/admin/ai-integrations/'.$integration['id'].'/test')->assertOk()->assertJsonPath('success', true);

    $saved = AiIntegration::query()->findOrFail($integration['id']);
    expect($saved->last_test_status)->toBe('succeeded')
        ->and(AiIntegrationAudit::query()->where('action', 'connection.tested')->first()->metadata)->toBe(['result' => 'succeeded'])
        ->and(AiOperationUsage::query()->where('service', 'connection_test')->first()->status)->toBe('succeeded');

    $this->postJson('/api/admin/ai-integrations/'.$integration['id'].'/test')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The OpenAI API key was rejected. Check the key and try again.')
        ->assertJsonMissing(['message' => 'sk-test-private-key rejected']);
    expect(AiOperationUsage::query()->where('service', 'connection_test')->latest('id')->first()->error_message)
        ->not->toContain('sk-test-private-key');
});

it('explains insufficient API credits without returning provider response details', function () {
    $admin = aiSettingsUser('admin', 'ai-credits-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Credits test account');
    Http::fake(['https://api.openai.com/v1/models' => Http::response([
        'error' => ['message' => 'internal provider detail', 'code' => 'insufficient_quota'],
    ], 429)]);

    $this->postJson('/api/admin/ai-integrations/'.$integration['id'].'/test')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The OpenAI account has no available API credits or has reached its spending limit.')
        ->assertJsonMissing(['message' => 'internal provider detail']);
    expect(AiOperationUsage::query()->where('service', 'connection_test')->firstOrFail()->error_code)
        ->toBe('insufficient_credits');
});

it('records an understandable unsupported-model error without storing provider details', function () {
    $admin = aiSettingsUser('admin', 'ai-model-error-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Model error account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-4o-mini', 'target_language' => 'Filipino',
    ])->assertOk();
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
        'error' => ['message' => 'provider internal detail', 'code' => 'model_not_found'],
    ], 404)]);

    try {
        app(AiOperationService::class)->translate('Short test', 'unsupported-model-test', null);
        test()->fail('The unsupported model request should fail.');
    } catch (\App\Support\AiProviderException $exception) {
        expect($exception->providerCode)->toBe('unsupported_model')
            ->and($exception->getMessage())->toContain('selected model is unavailable');
    }
    $usage = AiOperationUsage::query()->where('service', 'translation')->firstOrFail();
    expect($usage->status)->toBe('failed')
        ->and($usage->error_code)->toBe('unsupported_model')
        ->and($usage->error_message)->toContain('selected model is unavailable')
        ->and($usage->error_message)->not->toContain('provider internal detail');
});

it('validates service capabilities and model assignment and blocks removing active dependencies', function () {
    $admin = aiSettingsUser('admin', 'ai-assign-admin@example.test');
    $this->actingAs($admin);
    $first = addOpenAiAccount($this, 'Assignment account');
    $second = addOpenAiAccount($this, 'Replacement account');
    $this->patchJson('/api/admin/ai-integrations/'.$first['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->patchJson('/api/admin/ai-integrations/'.$second['id'].'/status', ['is_enabled' => true])->assertOk();

    $this->putJson('/api/admin/ai-services/transcription', [
        'integration_id' => $first['id'], 'model' => 'unsupported-model',
    ])->assertUnprocessable();
    $this->putJson('/api/admin/ai-services/transcription', [
        'integration_id' => $first['id'], 'model' => 'whisper-1',
    ])->assertOk();
    $this->patchJson('/api/admin/ai-integrations/'.$first['id'].'/status', ['is_enabled' => false])->assertStatus(409);
    $this->deleteJson('/api/admin/ai-integrations/'.$first['id'])->assertStatus(409);

    $this->putJson('/api/admin/ai-services/transcription', [
        'integration_id' => $second['id'], 'model' => 'gpt-4o-transcribe',
    ])->assertOk();
    AiOperationUsage::query()->create([
        'ai_integration_id' => $first['id'], 'service' => 'transcription', 'model' => 'whisper-1',
        'status' => 'processing',
    ]);
    $this->deleteJson('/api/admin/ai-integrations/'.$first['id'])->assertStatus(409);
    AiOperationUsage::query()->where('ai_integration_id', $first['id'])->update(['status' => 'failed']);
    $this->deleteJson('/api/admin/ai-integrations/'.$first['id'])->assertOk();
});

it('exposes and persists the new OpenAI model assignments', function () {
    $admin = aiSettingsUser('admin', 'ai-new-models-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'New model account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();

    $settings = $this->getJson('/api/admin/ai-integrations')->assertOk()->json();
    expect($settings['providers'][0]['models']['transcription'])->toContain('gpt-transcribe')
        ->and($settings['providers'][0]['models']['translation'])->toContain('gpt-6.1-terra');

    $this->putJson('/api/admin/ai-services/transcription', [
        'integration_id' => $integration['id'], 'model' => 'gpt-transcribe',
    ])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-6.1-terra', 'target_language' => 'Filipino',
    ])->assertOk();

    $assignments = collect($this->getJson('/api/admin/ai-integrations')->assertOk()->json('assignments'))->keyBy('service');
    expect($assignments['transcription']['model'])->toBe('gpt-transcribe')
        ->and($assignments['translation']['model'])->toBe('gpt-6.1-terra')
        ->and($assignments['translation']['target_language'])->toBe('Filipino');
});

it('performs assigned translation through the adapter and logs provider usage without lesson content', function () {
    $admin = aiSettingsUser('admin', 'ai-usage-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Translation account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-6.1-terra', 'target_language' => 'Filipino',
    ])->assertOk();
    Http::fake(['https://api.openai.com/v1/chat/completions' => Http::response([
        'choices' => [['message' => ['content' => 'Kumusta']]],
        'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3],
    ], 200, ['x-request-id' => 'req-ai-test-1'])]);

    $result = app(AiOperationService::class)->translate('Sensitive lesson transcript text', 'translate-test-0001', null);
    $duplicateResult = app(AiOperationService::class)->translate('Sensitive lesson transcript text', 'translate-test-0001', null);
    $usage = AiOperationUsage::query()->where('service', 'translation')->firstOrFail();
    expect($result['text'])->toBe('Kumusta')
        ->and($duplicateResult)->toBe($result)
        ->and(AiOperationUsage::query()->where('service', 'translation')->count())->toBe(1)
        ->and($usage->status)->toBe('succeeded')
        ->and((int) $usage->input_tokens)->toBe(7)
        ->and((int) $usage->output_tokens)->toBe(3)
        ->and($usage->request_reference)->toBe('req-ai-test-1')
        ->and($usage->error_message)->toBeNull();
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->hasHeader('Idempotency-Key', 'translate-test-0001'));
    Http::assertSent(fn ($request) => $request->data()['model'] === 'gpt-6.1-terra'
        && str_contains($request->data()['messages'][0]['content'], 'Bible references'));
    expect(DB::table('ai_operation_usages')->where('id', $usage->id)->value('result'))
        ->not->toContain('Kumusta');
    expect(fn () => app(AiOperationService::class)->translate('Different text', 'translate-test-0001', null))
        ->toThrow(RuntimeException::class, 'already been used for a different AI request');
    Http::assertSentCount(1);
    expect(json_encode($usage->getAttributes()))->not->toContain('Sensitive lesson transcript text');
});

it('uses gpt-transcribe language hints without assuming timestamp support', function () {
    $requestBody = '';
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => function ($request) use (&$requestBody) {
        $requestBody = $request->body();
        return Http::response([
            'text' => 'A short English lesson.', 'languages' => [['code' => 'en']],
            'usage' => ['type' => 'tokens', 'input_tokens' => 12, 'output_tokens' => 5],
        ], 200);
    }]);
    $audio = tempnam(sys_get_temp_dir(), 'gpt-transcribe-');
    file_put_contents($audio, 'controlled test audio fixture');

    try {
        $result = app(\App\Services\OpenAiProvider::class)->transcribe(['endpoint' => 'https://api.openai.com/v1'], [
            'credentials' => ['api_key' => 'sk-test-private-key'], 'model' => 'gpt-transcribe',
            'audio_path' => $audio, 'language' => 'en', 'idempotency_key' => 'gpt-transcribe-test',
        ]);
    } finally {
        @unlink($audio);
    }

    expect($requestBody)->toContain('name="model"')
        ->toContain('gpt-transcribe')
        ->toContain('name="languages[]"')
        ->toContain('en')
        ->not->toContain('name="language"')
        ->not->toContain('name="response_format"')
        ->not->toContain('name="timestamp_granularities[]"');
    expect($result['text'])->toBe('A short English lesson.')
        ->and($result['segments'])->toBeNull()
        ->and($result['timestamp_status'])->toBe('text_only')
        ->and($result['usage']['input_tokens'])->toBe(12);
});

it('returns timestamped English transcription and records only provider usage metadata', function () {
    $admin = aiSettingsUser('admin', 'ai-transcription-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Transcription account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/transcription', [
        'integration_id' => $integration['id'], 'model' => 'whisper-1',
    ])->assertOk();
    Http::fake(['https://api.openai.com/v1/audio/transcriptions' => Http::response([
        'text' => 'A short sample.',
        'segments' => [['start' => 0, 'end' => 1.25, 'text' => 'A short sample.']],
        'usage' => ['seconds' => 75],
    ], 200, ['x-request-id' => 'req-transcription-1'])]);
    $audio = tempnam(sys_get_temp_dir(), 'ai-audio-');
    file_put_contents($audio, 'controlled test audio fixture');
    try {
        $result = app(AiOperationService::class)->transcribe($audio, 'transcribe-test-0001');
    } finally {
        @unlink($audio);
    }
    $usage = AiOperationUsage::query()->where('service', 'transcription')->firstOrFail();
    expect($result['text'])->toBe('A short sample.')
        ->and($result['segments'][0]['start'])->toBe(0.0)
        ->and($result['segments'][0]['end'])->toBe(1.25)
        ->and((float) $usage->audio_minutes)->toBe(1.25)
        ->and($usage->status)->toBe('succeeded')
        ->and($usage->request_reference)->toBe('req-transcription-1');
});

it('queues retryable operations with encrypted content and an explicit queued usage record', function () {
    $admin = aiSettingsUser('admin', 'ai-queue-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Queued translation account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-4o-mini', 'target_language' => 'Filipino',
    ])->assertOk();
    $category = Category::create(['name' => 'Queued AI category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Queued AI lesson', 'sort_order' => 1, 'duration_seconds' => 100]);
    Queue::fake();

    $result = app(AiOperationService::class)->queueTranslation('Confidential draft subtitle text', 'queued-translate-001', $lesson->id);
    $duplicate = app(AiOperationService::class)->queueTranslation('Confidential draft subtitle text', 'queued-translate-001', $lesson->id);
    $usage = AiOperationUsage::query()->findOrFail($result['usage_id']);
    expect(Queue::pushed(RunAiOperation::class))->toHaveCount(1);
    $job = Queue::pushed(RunAiOperation::class)->first();
    $payload = Crypt::decryptString($job->encryptedPayload);
    expect($job->usageId)->toBe($usage->id)
        ->and($job->encryptedPayload)->not->toContain('Confidential draft subtitle text')
        ->and($payload)->toContain('Confidential draft subtitle text')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([10, 60, 180]);
    expect($usage->status)->toBe('queued')
        ->and($duplicate['usage_id'])->toBe($usage->id)
        ->and($duplicate['already_submitted'])->toBeTrue()
        ->and($usage->lesson_id)->toBe($lesson->id)
        ->and($result['request_reference'])->toBe($usage->request_reference);
});

it('persists encrypted AI jobs with the database queue driver', function () {
    $admin = aiSettingsUser('admin', 'ai-database-queue-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Database queue account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-4o-mini', 'target_language' => 'Filipino',
    ])->assertOk();
    $category = Category::create(['name' => 'Database queue category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Database queue lesson', 'sort_order' => 1, 'duration_seconds' => 100]);
    $usage = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration['id'], 'service' => 'translation', 'model' => 'gpt-4o-mini',
        'lesson_id' => $lesson->id, 'status' => 'queued',
    ]);
    $secretText = 'Private queued subtitle payload';
    $encrypted = Crypt::encryptString(json_encode(['text' => $secretText], JSON_THROW_ON_ERROR));
    config(['queue.default' => 'database']);

    RunAiOperation::dispatch($usage->id, $encrypted);

    $row = DB::table('jobs')->first();
    expect($row)->not->toBeNull()
        ->and($row->payload)->not->toContain($secretText)
        ->and((int) config('queue.connections.database.retry_after'))->toBeGreaterThan(120);
});

it('does not call the provider again when a completed queued operation is delivered twice', function () {
    $admin = aiSettingsUser('admin', 'ai-duplicate-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Idempotent queue account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-4o-mini', 'target_language' => 'Filipino',
    ])->assertOk();
    $category = Category::create(['name' => 'Duplicate AI category', 'sort_order' => 1, 'is_active' => true]);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Duplicate AI lesson', 'sort_order' => 1, 'duration_seconds' => 100]);
    $usage = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration['id'], 'service' => 'translation', 'model' => 'gpt-4o-mini',
        'lesson_id' => $lesson->id, 'status' => 'succeeded',
    ]);
    Http::fake();

    $result = app(AiOperationService::class)->runQueued($usage->id, [
        'service' => 'translation', 'lesson_id' => $lesson->id, 'text' => 'Already translated', 'target_language' => 'Filipino',
    ]);

    expect($result)->toBe(['already_completed' => true, 'usage_id' => $usage->id]);
    Http::assertNothingSent();
});

it('does not run a second worker for an operation that is already processing', function () {
    $admin = aiSettingsUser('admin', 'ai-active-worker-admin@example.test');
    $this->actingAs($admin);
    $integration = addOpenAiAccount($this, 'Active worker account');
    $this->patchJson('/api/admin/ai-integrations/'.$integration['id'].'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integration['id'], 'model' => 'gpt-4o-mini', 'target_language' => 'Filipino',
    ])->assertOk();
    $usage = AiOperationUsage::query()->create([
        'ai_integration_id' => $integration['id'], 'service' => 'translation', 'model' => 'gpt-4o-mini',
        'status' => 'processing', 'idempotency_key' => 'active-worker-test', 'request_fingerprint' => str_repeat('a', 64),
    ]);
    Http::fake();

    $result = app(AiOperationService::class)->runQueued($usage->id, [
        'service' => 'translation', 'text' => 'Already being processed', 'target_language' => 'Filipino',
    ]);

    expect($result)->toBe(['already_processing' => true, 'usage_id' => $usage->id]);
    Http::assertNothingSent();
});

it('rejects unauthorized access and unsupported provider capabilities', function () {
    $student = aiSettingsUser('student', 'ai-denied-student@example.test');
    $this->actingAs($student)->getJson('/api/admin/ai-integrations')->assertForbidden();
    $admin = aiSettingsUser('admin', 'ai-capability-admin@example.test');
    $this->actingAs($admin);

    $provider = new class implements AiProvider {
        public function key(): string { return 'test_limited'; }
        public function name(): string { return 'Limited test provider'; }
        public function capabilities(): array { return ['connection_test']; }
        public function credentialFields(): array { return [['key' => 'token', 'label' => 'Token', 'secret' => true]]; }
        public function supportedModels(string $service): array { return []; }
        public function testConnection(array $configuration, array $credentials): array { return ['success' => true, 'message' => 'ok']; }
        public function transcribe(array $configuration, array $input): array { throw new RuntimeException('unsupported'); }
        public function translate(array $configuration, array $input): array { throw new RuntimeException('unsupported'); }
    };
    $registry = new AiProviderRegistry();
    $registry->register($provider);
    app()->instance(AiProviderRegistry::class, $registry);
    $response = $this->postJson('/api/admin/ai-integrations', [
        'provider' => 'test_limited', 'label' => 'Limited', 'credentials' => ['token' => 'private-token'],
    ])->assertCreated();
    $integrationId = $response->json('integration.id');
    $this->patchJson('/api/admin/ai-integrations/'.$integrationId.'/status', ['is_enabled' => true])->assertOk();
    $this->putJson('/api/admin/ai-services/translation', [
        'integration_id' => $integrationId, 'model' => 'anything', 'target_language' => 'Filipino',
    ])->assertUnprocessable();
});
