<?php

namespace App\Support;

use InvalidArgumentException;

class AiProviderRegistry
{
    /** @var array<string, AiProvider> */
    private array $providers = [];

    public function register(AiProvider $provider): void { $this->providers[$provider->key()] = $provider; }
    public function has(string $key): bool { return isset($this->providers[$key]); }

    public function get(string $key): AiProvider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException('The AI provider is not registered.');
    }

    public function definitions(): array
    {
        return array_map(fn (AiProvider $provider): array => [
            'key' => $provider->key(), 'name' => $provider->name(),
            'capabilities' => $provider->capabilities(),
            'credential_fields' => $provider->credentialFields(),
            'models' => [
                'transcription' => $provider->supportedModels('transcription'),
                'translation' => $provider->supportedModels('translation'),
            ],
        ], array_values($this->providers));
    }
}
