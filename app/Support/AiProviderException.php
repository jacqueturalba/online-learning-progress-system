<?php

namespace App\Support;

use RuntimeException;

class AiProviderException extends RuntimeException
{
    public function __construct(
        public readonly string $providerCode,
        string $message,
        public readonly int $httpStatus,
    ) {
        parent::__construct($message);
    }
}
