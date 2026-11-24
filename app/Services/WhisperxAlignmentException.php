<?php

namespace App\Services;

use RuntimeException;
use Throwable;

class WhisperxAlignmentException extends RuntimeException
{
    public function __construct(
        public readonly string $safeCode,
        string $safeMessage,
        ?Throwable $previous = null,
    ) {
        parent::__construct($safeMessage, 0, $previous);
    }
}
