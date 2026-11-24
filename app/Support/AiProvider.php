<?php

namespace App\Support;

interface AiProvider
{
    public function key(): string;

    public function capabilities(): array;

    public function credentialFields(): array;

    public function supportedModels(string $service): array;

    public function testConnection(array $configuration, array $credentials): array;

    public function transcribe(array $configuration, array $input): array;

    public function translate(array $configuration, array $input): array;
}
