<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

class SymfonyWhisperxProcessRunner implements WhisperxProcessRunner
{
    public function run(array $arguments, int $timeoutSeconds): int
    {
        try {
            $process = new Process($arguments);
            $process->setTimeout($timeoutSeconds);
            $process->disableOutput();
            $process->run();
            return $process->getExitCode() ?? 1;
        } catch (ProcessTimedOutException $exception) {
            throw new WhisperxAlignmentException('ProcessTimedOut', 'WhisperX exceeded its configured processing timeout.', $exception);
        } catch (ProcessStartFailedException $exception) {
            throw new WhisperxAlignmentException('ProcessStartFailed', 'WhisperX could not start. Check the configured Python executable.', $exception);
        } catch (Throwable $exception) {
            throw new WhisperxAlignmentException('ProcessFailed', 'WhisperX could not complete the alignment process.', $exception);
        }
    }
}
