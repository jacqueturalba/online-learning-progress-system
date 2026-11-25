<?php

namespace App\Services;

use Illuminate\Queue\Events\Looping;
use Illuminate\Queue\Events\WorkerStopping;

class WhisperxWorkerHeartbeat
{
    public function looping(Looping $event): void
    {
        if ($this->isWhisperxWorker($event->connectionName, $event->queue)) $this->write();
    }

    public function stopping(WorkerStopping $event): void
    {
        if (!$this->isWhisperxWorker($event->connectionName, $event->queue)) return;
        $path = $this->path();
        if (is_file($path)) @unlink($path);
    }

    public function touch(): void
    {
        $this->write();
    }

    public function available(): bool
    {
        $directory = $this->directory();
        if (!is_dir($directory)) return false;
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $path) {
            if (is_file($path) && filemtime($path) >= time() - 30) return true;
        }
        return false;
    }

    private function isWhisperxWorker(?string $connection, ?string $queue): bool
    {
        return $connection === (string) config('whisperx.connection', 'whisperx')
            && in_array((string) config('whisperx.queue', 'whisperx-alignment'), explode(',', (string) $queue), true);
    }

    private function write(): void
    {
        $directory = $this->directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) return;
        @chmod($directory, 0700);
        $path = $this->path();
        @file_put_contents($path, json_encode([
            'pid' => getmypid(), 'host' => gethostname() ?: null, 'updated_at' => now()->toIso8601String(),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
        @chmod($path, 0600);
    }

    private function directory(): string
    {
        return storage_path('framework/whisperx-workers');
    }

    private function path(): string
    {
        return $this->directory().DIRECTORY_SEPARATOR.sha1((gethostname() ?: 'unknown').'-'.getmypid()).'.json';
    }
}
