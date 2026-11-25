<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/** Reconcile WhisperX operation state only when queue and heartbeat evidence exists. */
class WhisperxAlignmentReconciler
{
    public function __construct(private readonly WhisperxWorkerHeartbeat $workers) {}

    public function reconcile(AiOperationUsage $usage): array
    {
        if ($usage->service !== WhisperxAlignmentService::ALIGNMENT_SERVICE || !in_array($usage->status, ['queued', 'processing'], true)) {
            return ['queue_state' => null, 'reconciled' => false];
        }

        $queueState = $this->queueStateFor($usage);
        $result = is_array($usage->result) ? $usage->result : [];
        $now = now();

        if ($queueState === 'failed') {
            $this->markFailed($usage, 'QueueJobFailed', 'The WhisperX queue recorded this attempt as failed.');
            return ['queue_state' => 'failed', 'reconciled' => true];
        }

        if ($usage->status === 'queued') {
            if (in_array($queueState, ['pending', 'delayed', 'reserved'], true)) {
                if ($queueState === 'pending' && !$this->workers->available()) {
                    return ['queue_state' => 'pending_no_worker', 'reconciled' => false];
                }
                if ($queueState === 'reserved' && $this->olderThan($usage, 120)) {
                    return ['queue_state' => 'reserved_stalled', 'reconciled' => false];
                }
                return ['queue_state' => $queueState, 'reconciled' => false];
            }

            if ($queueState === 'missing' && $this->olderThan($usage, 60)) {
                $this->markFailed($usage, 'QueueJobMissing', 'No matching WhisperX queue job exists for this operation. Queue the alignment again after checking the worker.');
                return ['queue_state' => 'missing', 'reconciled' => true];
            }

            return ['queue_state' => $queueState, 'reconciled' => false];
        }

        $heartbeat = $result['heartbeat_at'] ?? null;
        $heartbeatAge = is_string($heartbeat) ? $now->diffInSeconds(Carbon::parse($heartbeat)) : null;
        if ($heartbeatAge === null) {
            if ($this->processingOlderThan($usage, 120) && in_array($queueState, ['missing', 'failed'], true)) {
                $processIds = $this->findMatchingAlignmentProcesses($usage);
                if ($processIds === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
                foreach ($processIds as $processId) {
                    if (DIRECTORY_SEPARATOR !== '\\') {
                        $terminated = $this->terminateDetectedLinuxAlignmentProcess($processId, $usage);
                        if ($terminated === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
                    } elseif ($this->matchesAlignmentProcess($processId, $usage) === true) {
                        $this->terminateOrphanProcess($processId);
                    }
                }
                $this->markFailed($usage, 'WorkerInterrupted', 'The alignment worker and queue attempt are gone; the historical processing attempt was reconciled.');
                return ['queue_state' => $queueState, 'reconciled' => true];
            }
            return ['queue_state' => $queueState, 'reconciled' => false];
        }
        if ($heartbeatAge <= 90) {
            return ['queue_state' => 'processing', 'reconciled' => false];
        }

        $host = $result['worker_host'] ?? null;
        if (!is_string($host) || $host === '' || !hash_equals($host, gethostname() ?: '')
            || (DIRECTORY_SEPARATOR !== '\\' && !function_exists('posix_kill'))) {
            // Heartbeat age alone is not sufficient evidence to stop a remote
            // worker or its process.
            return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
        }

        $workerPid = $result['worker_pid'] ?? null;
        $workerAlive = $this->processAlive($workerPid);
        if ($workerAlive === true) return ['queue_state' => 'heartbeat_stale_worker_alive', 'reconciled' => false];
        if ($workerAlive === null) return ['queue_state' => 'heartbeat_stale_worker_unverifiable', 'reconciled' => false];

        $processPid = $result['process_pid'] ?? null;
        $processAlive = $this->processAlive($processPid);
        if ($processAlive === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
        $identity = is_array($result['process_identity'] ?? null) ? $result['process_identity'] : null;
        if (DIRECTORY_SEPARATOR !== '\\' && $identity !== null && is_numeric($processPid)) {
            if ($processAlive) {
                $matches = $this->matchesAlignmentProcess((int) $processPid, $usage, $identity);
                if ($matches === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
                if ($matches && !$this->terminateVerifiedLinuxProcessGroup((int) $processPid, $identity)) {
                    return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
                }
            } elseif ($this->linuxProcessGroupExists((int) $processPid)
                && !$this->terminateVerifiedLinuxProcessGroup((int) $processPid, $identity, true)) {
                return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
            }
        } elseif (DIRECTORY_SEPARATOR !== '\\' && $identity === null) {
            // Upgrade recovery for attempts created before process start identity
            // was persisted. Require both the unique output path and an isolated
            // session/group, then re-read start ticks immediately before signaling.
            $processIds = $this->findMatchingAlignmentProcesses($usage);
            if ($processIds === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
            foreach ($processIds as $candidatePid) {
                if ($this->terminateDetectedLinuxAlignmentProcess($candidatePid, $usage) === null) {
                    return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
                }
            }
        } elseif ($processAlive) {
            $matches = $this->matchesAlignmentProcess((int) $processPid, $usage, $identity);
            if ($matches === null) return ['queue_state' => 'heartbeat_stale_unverifiable', 'reconciled' => false];
            if ($matches && DIRECTORY_SEPARATOR === '\\') $this->terminateOrphanProcess((int) $processPid, $identity);
        }

        $this->markFailed($usage, 'WorkerInterrupted', 'The alignment worker stopped reporting progress and no live local alignment process could be confirmed.');
        return ['queue_state' => $queueState, 'reconciled' => true];
    }

    public function queueStateFor(AiOperationUsage $usage): string
    {
        $result = is_array($usage->result) ? $usage->result : [];
        $token = $result['queue_token'] ?? null;
        return is_string($token) && $token !== ''
            ? $this->queueState($token)['state']
            : $this->legacyQueueState($usage);
    }

    private function legacyQueueState(AiOperationUsage $usage): string
    {
        $connectionName = (string) config('whisperx.connection', 'whisperx');
        $connectionConfig = config('queue.connections.'.$connectionName, []);
        if (($connectionConfig['driver'] ?? null) !== 'database') return 'untracked';
        $dbConnection = $connectionConfig['connection'] ?: config('database.default');
        $table = (string) ($connectionConfig['table'] ?? 'jobs');
        try {
            $rows = DB::connection($dbConnection)->table($table)
                ->where('queue', (string) ($connectionConfig['queue'] ?? 'whisperx-alignment'))->get(['payload', 'reserved_at', 'available_at']);
            foreach ($rows as $row) {
                if (!$this->payloadReferencesOperation((string) $row->payload, (int) $usage->id)) continue;
                if ($row->reserved_at !== null) return 'reserved';
                return (int) $row->available_at > now()->timestamp ? 'delayed' : 'pending';
            }

            $failedDatabase = config('queue.failed.database', config('database.default'));
            $failedTable = (string) config('queue.failed.table', 'failed_jobs');
            foreach (DB::connection($failedDatabase)->table($failedTable)->get(['payload']) as $row) {
                if ($this->payloadReferencesOperation((string) $row->payload, (int) $usage->id)) return 'failed';
            }
            return 'missing';
        } catch (Throwable) {
            return 'unknown';
        }
    }

    private function payloadReferencesOperation(string $payload, int $operationId): bool
    {
        $decoded = json_decode($payload, true);
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $command = $data['command'] ?? null;
        $commandName = $data['commandName'] ?? null;
        return is_string($command) && is_string($commandName)
            && str_contains($commandName, 'RunWhisperxAlignment')
            && str_contains($command, 's:7:"usageId";i:'.$operationId.';');
    }

    private function queueState(?string $token): array
    {
        if (!$token) return ['state' => 'untracked', 'reserved_at' => null];
        $connectionName = (string) config('whisperx.connection', 'whisperx');
        $connectionConfig = config('queue.connections.'.$connectionName, []);
        if (($connectionConfig['driver'] ?? null) !== 'database') return ['state' => 'unknown', 'reserved_at' => null];

        $dbConnection = $connectionConfig['connection'] ?: config('database.default');
        $table = (string) ($connectionConfig['table'] ?? 'jobs');
        try {
            $row = DB::connection($dbConnection)->table($table)
                ->where('queue', (string) ($connectionConfig['queue'] ?? 'whisperx-alignment'))
                ->where('payload', 'like', '%'.$token.'%')
                ->orderByDesc('id')->first();
            if ($row) {
                if ($row->reserved_at !== null) return ['state' => 'reserved', 'reserved_at' => (int) $row->reserved_at];
                return ['state' => (int) $row->available_at > now()->timestamp ? 'delayed' : 'pending', 'reserved_at' => null];
            }

            $failedDatabase = config('queue.failed.database', config('database.default'));
            $failed = DB::connection($failedDatabase)->table((string) config('queue.failed.table', 'failed_jobs'))
                ->where('payload', 'like', '%'.$token.'%')->exists();
            return ['state' => $failed ? 'failed' : 'missing', 'reserved_at' => null];
        } catch (Throwable) {
            // An unavailable queue table is not evidence that a job is missing.
            return ['state' => 'unknown', 'reserved_at' => null];
        }
    }

    private function olderThan(AiOperationUsage $usage, int $seconds): bool
    {
        return $usage->updated_at !== null && $usage->updated_at->lt(now()->subSeconds($seconds));
    }

    private function markFailed(AiOperationUsage $usage, string $code, string $message): void
    {
        $usage->refresh();
        if (!in_array($usage->status, ['queued', 'processing'], true)) return;
        $result = is_array($usage->result) ? $usage->result : [];
        $result['processing_completed_at'] = now()->toIso8601String();
        $result['reconciled_at'] = now()->toIso8601String();
        $result['reconciliation_reason'] = $code;
        $usage->update(['status' => 'failed', 'error_code' => $code, 'error_message' => $message, 'result' => $result]);
    }

    private function processAlive(mixed $pid): ?bool
    {
        if (!is_numeric($pid) || (int) $pid <= 1) return false;
        $pid = (int) $pid;
        if (function_exists('posix_kill')) {
            if (!@posix_kill($pid, 0)) return false;
            $stat = @file_get_contents('/proc/'.$pid.'/stat');
            if (is_string($stat) && preg_match('/^\d+ \(.*\) ([A-Z]) /', $stat, $matches)) return $matches[1] !== 'Z';
            return true;
        }
        if (DIRECTORY_SEPARATOR === '\\') {
            try {
                $tasklist = new Process(['tasklist.exe', '/FI', 'PID eq '.$pid, '/FO', 'CSV', '/NH']);
                $tasklist->setTimeout(5);
                $tasklist->run();
                foreach (preg_split('/\r?\n/', trim($tasklist->getOutput())) ?: [] as $line) {
                    $columns = str_getcsv($line);
                    if (isset($columns[1]) && (int) $columns[1] === $pid) return true;
                }
                return false;
            } catch (Throwable) {
                return null;
            }
        }
        return null;
    }

    private function matchesAlignmentProcess(int $pid, AiOperationUsage $usage, ?array $identity = null): ?bool
    {
        $outputDirectory = Storage::disk('local')->path('whisperx-alignments/'.(int) $usage->lesson_id.'/'.(int) $usage->id);
        if (DIRECTORY_SEPARATOR !== '\\') {
            $current = $this->linuxProcessIdentity($pid);
            if (!$current) return false;
            if ($identity === null || !$this->sameLinuxProcess($identity, $current)
                || $current['process_group_id'] !== $pid || $current['session_id'] !== $pid) return false;
            $commandLinePath = '/proc/'.$pid.'/cmdline';
            if (!is_readable($commandLinePath)) return null;
            $commandLine = str_replace("\0", ' ', (string) file_get_contents($commandLinePath));
            $runner = (string) config('whisperx.script_path');
            $runner = str_starts_with($runner, '/') ? $runner : base_path($runner);
            return str_contains($commandLine, $runner) && str_contains($commandLine, $outputDirectory);
        }

        try {
            if (!is_array($identity) || (int) ($identity['process_id'] ?? 0) !== $pid || !is_string($identity['creation_ticks'] ?? null)) return null;
            $script = '$p=Get-Process -Id '.$pid.' -ErrorAction SilentlyContinue; if (!$p) { exit 2 }; '
                .'[Console]::Out.Write($p.StartTime.ToUniversalTime().Ticks)';
            $powershell = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]);
            $powershell->setTimeout(5);
            $powershell->run();
            if ($powershell->getExitCode() === 2) return false;
            if ($powershell->getExitCode() !== 0) return null;
            return hash_equals((string) $identity['creation_ticks'], trim($powershell->getOutput()));
        } catch (Throwable) {
            return null;
        }
    }

    /** Return matching process IDs, or null when the host cannot be inspected. */
    private function findMatchingAlignmentProcesses(AiOperationUsage $usage): ?array
    {
        $outputDirectory = Storage::disk('local')->path('whisperx-alignments/'.(int) $usage->lesson_id.'/'.(int) $usage->id);
        $runner = (string) config('whisperx.script_path');
        if (DIRECTORY_SEPARATOR !== '\\') {
            $runner = str_starts_with($runner, '/') ? $runner : base_path($runner);
            $matches = [];
            foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
                if (!is_readable($path)) continue;
                $commandLine = str_replace("\0", ' ', (string) @file_get_contents($path));
                if (str_contains($commandLine, $runner) && str_contains($commandLine, $outputDirectory)) {
                    if (preg_match('~/proc/(\d+)/cmdline$~', $path, $pid)) $matches[] = (int) $pid[1];
                }
            }
            return $matches;
        }

        try {
            $runner = str_replace('/', '\\', $runner);
            if (!preg_match('/^[A-Za-z]:\\\\/', $runner)) $runner = str_replace('/', '\\', base_path($runner));
            $outputDirectory = str_replace('/', '\\', $outputDirectory);
            $quote = static fn (string $value): string => "'".str_replace("'", "''", $value)."'";
            $script = '$runner='.$quote($runner).'; $output='.$quote($outputDirectory).'; '
                .'Get-CimInstance Win32_Process | Where-Object { $_.CommandLine -like "*${runner}*" -and $_.CommandLine -like "*${output}*" } | Select-Object -ExpandProperty ProcessId';
            $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
            $powershell = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-EncodedCommand', $encoded]);
            $powershell->setTimeout(10);
            $powershell->run();
            if ($powershell->getExitCode() !== 0) return null;
            return array_values(array_filter(array_map('intval', preg_split('/\r?\n/', trim($powershell->getOutput())) ?: []), fn (int $pid): bool => $pid > 1));
        } catch (Throwable) {
            return null;
        }
    }

    private function processingOlderThan(AiOperationUsage $usage, int $seconds): bool
    {
        $result = is_array($usage->result) ? $usage->result : [];
        $startedAt = $result['processing_started_at'] ?? null;
        if (is_string($startedAt)) {
            try {
                return Carbon::parse($startedAt)->lt(now()->subSeconds($seconds));
            } catch (Throwable) {
                // Fall through to the database update timestamp.
            }
        }
        return $this->olderThan($usage, $seconds);
    }

    private function terminateOrphanProcess(int $pid, ?array $identity = null): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            try {
                if (!is_array($identity) || (int) ($identity['process_id'] ?? 0) !== $pid
                    || !is_string($identity['creation_ticks'] ?? null)
                    || $this->matchesWindowsSupervisor($pid, $identity) !== true) return;
                $ticks = (string) (int) $identity['creation_ticks'];
                $script = '$p=Get-Process -Id '.$pid.' -ErrorAction SilentlyContinue; '
                    .'if ($p -and $p.StartTime.ToUniversalTime().Ticks -eq [long] '.$ticks.') '
                    .'{ Stop-Process -Id '.$pid.' -Force -ErrorAction Stop }';
                $powershell = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]);
                $powershell->setTimeout(5);
                $powershell->run();
            } catch (Throwable) {
                // Operation remains failed even if the OS denies orphan cleanup.
            }
            return;
        }

        // Linux termination must use a persisted start identity and verified
        // process group through terminateVerifiedLinuxProcessGroup().
    }

    private function matchesWindowsSupervisor(int $pid, array $identity): ?bool
    {
        if ((int) ($identity['process_id'] ?? 0) !== $pid || !is_string($identity['creation_ticks'] ?? null)) return null;
        try {
            $script = '$p=Get-Process -Id '.$pid.' -ErrorAction SilentlyContinue; if (!$p) { exit 2 }; '
                .'[Console]::Out.Write($p.StartTime.ToUniversalTime().Ticks)';
            $powershell = new Process(['powershell.exe', '-NoProfile', '-NonInteractive', '-Command', $script]);
            $powershell->setTimeout(5);
            $powershell->run();
            if ($powershell->getExitCode() === 2) return false;
            if ($powershell->getExitCode() !== 0) return null;
            return hash_equals((string) $identity['creation_ticks'], trim($powershell->getOutput()));
        } catch (Throwable) {
            return null;
        }
    }

    private function linuxProcessIdentity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (!is_string($stat) || !preg_match('/^\\d+ \\(.*\\) (.*)$/', $stat, $matches)) return null;
        $fields = preg_split('/\\s+/', trim($matches[1]));
        if (!is_array($fields) || count($fields) < 20 || ($fields[0] ?? '') === 'Z') return null;
        return ['process_id' => $pid, 'process_group_id' => (int) $fields[2], 'session_id' => (int) $fields[3], 'start_ticks' => (string) $fields[19]];
    }

    private function sameLinuxProcess(array $expected, array $current): bool
    {
        return (int) ($expected['process_id'] ?? 0) === (int) $current['process_id']
            && hash_equals((string) ($expected['start_ticks'] ?? ''), (string) $current['start_ticks']);
    }

    private function terminateVerifiedLinuxProcessGroup(int $pid, array $identity, bool $leaderExited = false): bool
    {
        if (!function_exists('posix_kill')) return false;
        $current = $this->linuxProcessIdentity($pid);
        if ((int) ($identity['process_group_id'] ?? 0) !== $pid || (int) ($identity['session_id'] ?? 0) !== $pid) return false;
        if ($current) {
            if (!$this->sameLinuxProcess($identity, $current)
                || $current['process_group_id'] !== $pid || $current['session_id'] !== $pid) return false;
        } elseif (!$leaderExited || !$this->linuxProcessGroupExists($pid)) {
            return false;
        }
        @posix_kill(-$pid, 15);
        @posix_kill(-$pid, 9);
        return true;
    }

    private function terminateDetectedLinuxAlignmentProcess(int $pid, AiOperationUsage $usage): ?bool
    {
        $identity = $this->linuxProcessIdentity($pid);
        if (!$identity) return false;
        if ($identity['process_group_id'] !== $pid || $identity['session_id'] !== $pid) return false;
        $matches = $this->matchesAlignmentProcess($pid, $usage, $identity);
        if ($matches !== true) return $matches;
        $again = $this->linuxProcessIdentity($pid);
        if (!$again || !$this->sameLinuxProcess($identity, $again)
            || $again['process_group_id'] !== $pid || $again['session_id'] !== $pid) return false;
        @posix_kill(-$pid, 15);
        @posix_kill(-$pid, 9);
        return true;
    }

    private function linuxProcessGroupExists(int $groupId): bool
    {
        if (!function_exists('posix_kill') || $groupId <= 1 || !@posix_kill(-$groupId, 0)) return false;
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $path) {
            $stat = @file_get_contents($path);
            if (!is_string($stat) || !preg_match('/^\\d+ \\(.*\\) (.*)$/', $stat, $matches)) continue;
            $fields = preg_split('/\\s+/', trim($matches[1]));
            if (is_array($fields) && count($fields) >= 4 && $fields[0] !== 'Z'
                && (int) $fields[2] === $groupId && (int) $fields[3] === $groupId) return true;
        }
        return false;
    }
}
