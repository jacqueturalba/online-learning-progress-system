<?php

namespace App\Http\Controllers;

use App\Models\AiIntegration;
use App\Models\AiIntegrationAudit;
use App\Models\AiOperationUsage;
use App\Models\AiServiceAssignment;
use App\Support\AiProviderRegistry;
use App\Support\AiProviderException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class AdminAiIntegrationController extends Controller
{
    public function index(AiProviderRegistry $providers)
    {
        $integrations = AiIntegration::query()->with('assignments:id,ai_integration_id,service,model,target_language')
            ->orderBy('provider')->orderBy('label')->get()->map(fn (AiIntegration $integration): array => $this->safeIntegration($integration))->values();
        $assignments = AiServiceAssignment::query()->with('integration:id,provider,label,is_enabled,deleted_at')
            ->orderBy('service')->get()->map(fn (AiServiceAssignment $assignment): array => [
                'service' => $assignment->service, 'model' => $assignment->model,
                'target_language' => $assignment->target_language,
                'integration_id' => $assignment->ai_integration_id,
                'integration_label' => $assignment->integration?->label,
                'provider' => $assignment->integration?->provider,
                'is_available' => (bool) ($assignment->integration?->is_enabled && !$assignment->integration?->trashed()),
            ])->values();

        return response()->json(['providers' => $providers->definitions(), 'integrations' => $integrations, 'assignments' => $assignments]);
    }

    public function store(Request $request, AiProviderRegistry $providers)
    {
        $data = $this->validateConfiguration($request, $providers, true);
        $integration = DB::transaction(function () use ($request, $data): AiIntegration {
            $integration = AiIntegration::query()->create([
                'provider' => $data['provider'], 'label' => $data['label'], 'endpoint' => $data['endpoint'] ?? null,
                'credentials' => $data['credentials'], 'is_enabled' => false,
            ]);
            $this->audit($request, $integration, 'credentials.created', ['provider' => $integration->provider]);
            return $integration;
        });
        return response()->json(['integration' => $this->safeIntegration($integration), 'message' => 'AI integration added.'], 201);
    }

    public function update(Request $request, AiIntegration $integration, AiProviderRegistry $providers)
    {
        $data = $this->validateConfiguration($request, $providers, false);
        abort_unless($integration->provider === $data['provider'], 422, 'An account provider cannot be changed after creation.');
        $newCredentials = array_filter($data['credentials'] ?? [], fn ($value): bool => is_string($value) && trim($value) !== '');

        DB::transaction(function () use ($request, $integration, $data, $newCredentials): void {
            $locked = AiIntegration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            if (($data['is_enabled'] ?? $locked->is_enabled) === false) $this->assertCanDeactivate($locked);
            $locked->label = $data['label'];
            $locked->endpoint = $data['endpoint'] ?? null;
            if ($newCredentials) $locked->credentials = array_replace($locked->credentials ?? [], $newCredentials);
            if (array_key_exists('is_enabled', $data)) $locked->is_enabled = $data['is_enabled'];
            $locked->save();
            $this->audit($request, $locked, $newCredentials ? 'credentials.updated' : 'integration.updated', ['provider' => $locked->provider]);
        });
        return response()->json(['integration' => $this->safeIntegration($integration->fresh()), 'message' => 'AI integration updated.']);
    }

    public function setStatus(Request $request, AiIntegration $integration)
    {
        $data = $request->validate(['is_enabled' => ['required', 'boolean']]);
        $integration = DB::transaction(function () use ($request, $integration, $data): AiIntegration {
            $locked = AiIntegration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            if (!$data['is_enabled']) $this->assertCanDeactivate($locked);
            $locked->update(['is_enabled' => $data['is_enabled']]);
            $this->audit($request, $locked, $data['is_enabled'] ? 'integration.enabled' : 'integration.disabled');
            return $locked;
        });
        return response()->json(['integration' => $this->safeIntegration($integration->fresh()), 'message' => 'Integration status updated.']);
    }

    public function destroy(Request $request, AiIntegration $integration)
    {
        DB::transaction(function () use ($request, $integration): void {
            $locked = AiIntegration::query()->whereKey($integration->id)->lockForUpdate()->firstOrFail();
            $this->assertCanDeactivate($locked);
            $this->audit($request, $locked, 'integration.removed', ['provider' => $locked->provider]);
            $locked->delete();
        });
        return response()->json(['message' => 'Integration safely removed.']);
    }

    public function testConnection(Request $request, AiIntegration $integration, AiProviderRegistry $providers)
    {
        $provider = $providers->get($integration->provider);
        abort_unless(in_array('connection_test', $provider->capabilities(), true), 422, 'This provider does not support connection tests.');
        $started = hrtime(true);
        $success = false;
        $errorCode = null;
        $message = 'Connection test failed. Check the account settings and try again.';
        try {
            $result = $provider->testConnection(['endpoint' => $integration->endpoint], $integration->credentials ?? []);
            $success = (bool) ($result['success'] ?? false);
            $message = $success ? 'Connection successful.' : $message;
        } catch (Throwable $exception) {
            $errorCode = $exception instanceof AiProviderException ? $exception->providerCode : class_basename($exception);
            if ($exception instanceof AiProviderException) $message = $exception->getMessage();
        }

        $integration->update(['last_tested_at' => now(), 'last_test_status' => $success ? 'succeeded' : 'failed']);
        AiOperationUsage::query()->create([
            'ai_integration_id' => $integration->id, 'service' => 'connection_test', 'model' => 'provider-account',
            'status' => $success ? 'succeeded' : 'failed', 'processing_duration_ms' => (int) ((hrtime(true) - $started) / 1_000_000),
            'error_code' => $errorCode, 'error_message' => $success ? null : ($exception instanceof AiProviderException ? $exception->getMessage() : 'Connection test failed.'), 'request_reference' => (string) str()->uuid(),
        ]);
        $this->audit($request, $integration, 'connection.tested', ['result' => $success ? 'succeeded' : 'failed']);

        return response()->json(['success' => $success, 'message' => $message], $success ? 200 : 422);
    }

    public function assign(Request $request, string $service, AiProviderRegistry $providers)
    {
        abort_unless(in_array($service, ['transcription', 'translation'], true), 404);
        $data = $request->validate([
            'integration_id' => ['required', 'integer', 'exists:ai_integrations,id'],
            'model' => ['required', 'string', 'max:160'],
            'target_language' => [$service === 'translation' ? 'required' : 'nullable', 'string', 'max:35'],
        ]);
        $integration = AiIntegration::query()->whereKey($data['integration_id'])->where('is_enabled', true)->firstOrFail();
        $provider = $providers->get($integration->provider);
        abort_unless(in_array($service, $provider->capabilities(), true), 422, 'This provider does not support the selected service.');
        abort_unless(in_array($data['model'], $provider->supportedModels($service), true), 422, 'Choose a model supported by this provider for the selected service.');

        DB::transaction(function () use ($request, $service, $integration, $data): void {
            $locked = AiIntegration::query()->whereKey($integration->id)->lockForUpdate()->where('is_enabled', true)->firstOrFail();
            AiServiceAssignment::query()->updateOrCreate(['service' => $service], [
                'ai_integration_id' => $locked->id, 'model' => $data['model'],
                'target_language' => $service === 'translation' ? $data['target_language'] : null,
            ]);
            $this->audit($request, $locked, 'service.assignment_changed', ['service' => $service, 'model' => $data['model']]);
        });
        return response()->json(['message' => 'Service assignment saved.']);
    }

    private function validateConfiguration(Request $request, AiProviderRegistry $providers, bool $creating): array
    {
        $base = $request->validate([
            'provider' => ['required', 'string', 'max:80'], 'label' => ['required', 'string', 'max:120'],
            'endpoint' => ['nullable', 'url', 'max:2048'], 'is_enabled' => ['sometimes', 'boolean'],
            'credentials' => [$creating ? 'required' : 'sometimes', 'array'],
        ]);
        abort_unless($providers->has($base['provider']), 422, 'Choose a supported AI provider.');
        $provider = $providers->get($base['provider']);
        $credentialKeys = array_column($provider->credentialFields(), 'key');
        $baseRules = $creating ? 'required' : 'sometimes';
        Validator::make($request->all(), [
            'credentials' => [$baseRules, 'array:'.implode(',', $credentialKeys)],
        ])->validate();
        foreach ($provider->credentialFields() as $field) {
            $rules = $creating ? ['required', 'string', 'max:4096'] : ['sometimes', 'nullable', 'string', 'max:4096'];
            $validator = Validator::make($request->all(), ['credentials.'.$field['key'] => $rules]);
            $validator->validate();
        }
        if (isset($base['endpoint']) && (parse_url($base['endpoint'], PHP_URL_USER) || parse_url($base['endpoint'], PHP_URL_PASS))) {
            throw ValidationException::withMessages(['endpoint' => 'Do not put credentials in the API endpoint URL.']);
        }
        if ($base['provider'] === 'openai' && !empty($base['endpoint'])) {
            $endpoint = parse_url($base['endpoint']);
            $validOpenAiEndpoint = is_array($endpoint)
                && strtolower($endpoint['scheme'] ?? '') === 'https'
                && strtolower($endpoint['host'] ?? '') === 'api.openai.com'
                && in_array(rtrim($endpoint['path'] ?? '', '/'), ['', '/v1'], true)
                && !array_key_exists('query', $endpoint) && !array_key_exists('fragment', $endpoint)
                && (!isset($endpoint['port']) || $endpoint['port'] === 443);
            if (!$validOpenAiEndpoint) {
                throw ValidationException::withMessages(['endpoint' => 'Use the official OpenAI endpoint https://api.openai.com/v1 or leave this field blank.']);
            }
        }
        return $base;
    }

    private function assertCanDeactivate(AiIntegration $integration): void
    {
        abort_if($integration->assignments()->exists(), 409, 'Change the transcription or translation service assignment before disabling or removing this integration.');
        abort_if($integration->usages()->whereIn('status', ['queued', 'processing'])->exists(), 409,
            'This integration is being used by an active AI job. Wait for the job to finish before disabling or removing it.');
    }

    private function safeIntegration(AiIntegration $integration): array
    {
        $credentials = $integration->credentials ?? [];
        return [
            'id' => $integration->id, 'provider' => $integration->provider,
            'label' => $integration->label, 'endpoint' => $integration->endpoint,
            'is_enabled' => $integration->is_enabled,
            'credential_fields' => array_map(fn (string $field): array => ['key' => $field, 'configured' => !empty($credentials[$field]), 'masked' => !empty($credentials[$field]) ? '••••••••' : null], array_keys($credentials)),
            'last_tested_at' => $integration->last_tested_at,
            'last_test_status' => $integration->last_test_status,
            'assignments' => $integration->assignments->map(fn (AiServiceAssignment $assignment): array => ['service' => $assignment->service, 'model' => $assignment->model])->values(),
        ];
    }

    private function audit(Request $request, ?AiIntegration $integration, string $action, array $metadata = []): void
    {
        AiIntegrationAudit::query()->create([
            'actor_id' => $request->user()?->id, 'ai_integration_id' => $integration?->id,
            'action' => $action, 'metadata' => $metadata, 'created_at' => now(),
        ]);
    }
}
