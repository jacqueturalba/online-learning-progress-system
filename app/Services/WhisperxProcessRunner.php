<?php

namespace App\Services;

interface WhisperxProcessRunner
{
    /** Run an argument-vector process and return its exit code without retaining stdout/stderr. */
    public function run(array $arguments, int $timeoutSeconds, ?callable $onHeartbeat = null): int;
}
