<?php

namespace App\Services;

class AiOperationCompleted
{
    public function __construct(public readonly int $usageId, public readonly string $service, public readonly ?int $lessonId, public readonly array $result) {}
}
