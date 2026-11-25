<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessStartFailedException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class SymfonyWhisperxProcessRunner implements WhisperxProcessRunner
{
    public function run(array $arguments, int $timeoutSeconds, ?callable $onHeartbeat = null): int
    {
        if (DIRECTORY_SEPARATOR === '\\') return $this->runWindows($arguments, $timeoutSeconds, $onHeartbeat);

        $processId = null;
        $processIdentity = null;
        $processGroupIsolated = false;
        $timedOut = false;
        $process = null;
        try {
            if (DIRECTORY_SEPARATOR !== '\\') {
                $setsid = (new ExecutableFinder())->find('setsid');
                if (!$setsid || !function_exists('posix_kill')) {
                    throw new WhisperxAlignmentException('ProcessTreeIsolationUnavailable', 'WhisperX cannot start because process-tree cleanup is unavailable on this server.');
                }
                // setsid execs Python in its own process group, allowing cleanup
                // to include PyTorch/WhisperX child processes on worker timeout.
                array_unshift($arguments, $setsid);
                $processGroupIsolated = true;
            }

            $process = new Process($arguments);
            $process->disableOutput();
            $process->start();
            $processId = $process->getPid();
            if ($processGroupIsolated) {
                $processIdentity = $this->linuxProcessIdentity($processId);
                if (!$processIdentity || $processIdentity['process_group_id'] !== $processId || $processIdentity['session_id'] !== $processId) {
                    $this->terminateProcessTree($process, $processId, false, null);
                    throw new WhisperxAlignmentException('ProcessTreeIsolationUnavailable', 'WhisperX could not be verified inside its own process group.');
                }
            }
            $startedAt = microtime(true);
            $lastHeartbeat = microtime(true);
            if ($onHeartbeat) $onHeartbeat($processId, $processIdentity);

            while ($process->isRunning()) {
                if (microtime(true) - $startedAt >= $timeoutSeconds) {
                    $timedOut = true;
                    $this->terminateProcessTree($process, $processId, $processGroupIsolated, $processIdentity);
                    throw new WhisperxAlignmentException('ProcessTimedOut', 'WhisperX exceeded its configured processing timeout.');
                }
                if ($onHeartbeat && microtime(true) - $lastHeartbeat >= 15) {
                    $onHeartbeat($processId, $processIdentity);
                    $lastHeartbeat = microtime(true);
                }
                usleep(250_000);
            }

            $exitCode = $process->wait();
            if ($processGroupIsolated && $processId !== null && $processIdentity) {
                $this->terminateRemainingIsolatedGroup($processId, $processIdentity);
            }
            return $exitCode;
        } catch (WhisperxAlignmentException $exception) {
            if ($exception->safeCode !== 'ProcessTimedOut' && $processId !== null) {
                $this->terminateProcessTree($process, $processId, $processGroupIsolated, $processIdentity);
            }
            throw $exception;
        } catch (ProcessStartFailedException $exception) {
            throw new WhisperxAlignmentException('ProcessStartFailed', 'WhisperX could not start. Check the configured Python executable.', $exception);
        } catch (Throwable $exception) {
            if ($processId !== null) $this->terminateProcessTree($process ?? null, $processId, $processGroupIsolated, $processIdentity);
            if ($exception instanceof WhisperxAlignmentException) throw $exception;
            throw new WhisperxAlignmentException('ProcessFailed', 'WhisperX could not complete the alignment process.', $exception);
        } finally {
            if (!$timedOut && isset($process) && $process->isRunning()) {
                $this->terminateProcessTree($process, $processId, $processGroupIsolated, $processIdentity);
            }
        }
    }

    private function runWindows(array $arguments, int $timeoutSeconds, ?callable $onHeartbeat): int
    {
        $identityPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'whisperx-supervisor-'.bin2hex(random_bytes(12)).'.json';
        $commandLine = implode(' ', array_map(fn (string $argument): string => $this->escapeWindowsArgument($argument), $arguments));
        $commandLineBase64 = base64_encode($commandLine);
        $identityPathBase64 = base64_encode($identityPath);
        $script = $this->windowsJobSupervisorScript($commandLineBase64, $identityPathBase64);
        $encodedScript = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
        $powershell = (new ExecutableFinder())->find('powershell.exe');
        if (!$powershell) throw new WhisperxAlignmentException('ProcessTreeIsolationUnavailable', 'WhisperX cannot start because Windows process-tree cleanup is unavailable.');
        $pipes = [];
        $handle = @proc_open([$powershell, '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-EncodedCommand', $encodedScript], [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', 'NUL', 'a'],
            2 => ['file', 'NUL', 'a'],
        ], $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($handle)) {
            throw new WhisperxAlignmentException('ProcessStartFailed', 'WhisperX could not start. Check the configured Python executable.');
        }

        $processId = null;
        $startedAt = microtime(true);
        $lastHeartbeat = $startedAt;
        $processIdentity = null;
        try {
            $status = proc_get_status($handle);
            $processId = is_numeric($status['pid'] ?? null) ? (int) $status['pid'] : null;
            $identityDeadline = microtime(true) + 3;
            while (microtime(true) < $identityDeadline) {
                if (is_file($identityPath)) {
                    $candidate = json_decode((string) @file_get_contents($identityPath), true);
                    if (is_array($candidate) && (int) ($candidate['process_id'] ?? 0) === $processId) {
                        $processIdentity = $candidate;
                        break;
                    }
                }
                usleep(20_000);
            }
            if ($processIdentity === null) throw new WhisperxAlignmentException('ProcessTreeIsolationUnavailable', 'WhisperX process supervisor identity could not be verified.');
            if ($onHeartbeat) $onHeartbeat($processId, $processIdentity);
            while (($status = proc_get_status($handle))['running']) {
                if (microtime(true) - $startedAt >= $timeoutSeconds) {
                    $this->terminateWindowsTree($processId, $handle);
                    throw new WhisperxAlignmentException('ProcessTimedOut', 'WhisperX exceeded its configured processing timeout.');
                }
                if ($onHeartbeat && microtime(true) - $lastHeartbeat >= 15) {
                    $onHeartbeat($processId, $processIdentity);
                    $lastHeartbeat = microtime(true);
                }
                usleep(250_000);
            }
            $exitCode = (int) ($status['exitcode'] ?? -1);
            $closedCode = proc_close($handle);
            $handle = null;
            return $exitCode >= 0 ? $exitCode : (int) $closedCode;
        } catch (WhisperxAlignmentException $exception) {
            if ($exception->safeCode !== 'ProcessTimedOut') $this->terminateWindowsTree($processId, $handle);
            throw $exception;
        } catch (Throwable $exception) {
            $this->terminateWindowsTree($processId, $handle);
            throw new WhisperxAlignmentException('ProcessFailed', 'WhisperX could not complete the alignment process.', $exception);
        } finally {
            if (is_resource($handle)) {
                $status = proc_get_status($handle);
                if ($status['running']) $this->terminateWindowsTree($processId, $handle);
                @proc_close($handle);
            }
            @unlink($identityPath);
        }
    }

    private function windowsJobSupervisorScript(string $commandLineBase64, string $identityPathBase64): string
    {
        $source = <<<'CSHARP'
using System;
using System.Runtime.InteropServices;
using System.Text;
public static class WhisperxWindowsJob {
  [StructLayout(LayoutKind.Sequential, CharSet=CharSet.Unicode)] struct STARTUPINFO {
    public int cb; public string reserved; public string desktop; public string title;
    public int x; public int y; public int xSize; public int ySize; public int xCountChars; public int yCountChars;
    public int fillAttribute; public int flags; public short showWindow; public short reserved2; public IntPtr reservedPtr;
    public IntPtr stdInput; public IntPtr stdOutput; public IntPtr stdError;
  }
  [StructLayout(LayoutKind.Sequential)] struct PROCESS_INFORMATION { public IntPtr process; public IntPtr thread; public uint processId; public uint threadId; }
  [StructLayout(LayoutKind.Sequential)] struct IO_COUNTERS { public ulong readOps; public ulong writeOps; public ulong otherOps; public ulong readBytes; public ulong writeBytes; public ulong otherBytes; }
  [StructLayout(LayoutKind.Sequential)] struct BASIC_LIMIT {
    public long processTime; public long jobTime; public uint flags; public UIntPtr minWorkingSet; public UIntPtr maxWorkingSet;
    public uint activeProcessLimit; public UIntPtr affinity; public uint priorityClass; public uint schedulingClass;
  }
  [StructLayout(LayoutKind.Sequential)] struct EXTENDED_LIMIT {
    public BASIC_LIMIT basic; public IO_COUNTERS io; public UIntPtr processMemory; public UIntPtr jobMemory;
    public UIntPtr peakProcessMemory; public UIntPtr peakJobMemory;
  }
  [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern IntPtr CreateJobObject(IntPtr attributes, string name);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool SetInformationJobObject(IntPtr job, int infoClass, ref EXTENDED_LIMIT info, uint length);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool AssignProcessToJobObject(IntPtr job, IntPtr process);
  [DllImport("kernel32.dll", CharSet=CharSet.Unicode, SetLastError=true)] static extern bool CreateProcess(string app, StringBuilder command, IntPtr procAttr, IntPtr threadAttr, bool inherit, uint flags, IntPtr env, string cwd, ref STARTUPINFO startup, out PROCESS_INFORMATION info);
  [DllImport("kernel32.dll", SetLastError=true)] static extern uint ResumeThread(IntPtr thread);
  [DllImport("kernel32.dll", SetLastError=true)] static extern uint WaitForSingleObject(IntPtr handle, uint milliseconds);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool GetExitCodeProcess(IntPtr process, out uint code);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool TerminateProcess(IntPtr process, uint code);
  [DllImport("kernel32.dll", SetLastError=true)] static extern bool CloseHandle(IntPtr handle);
  const uint CREATE_SUSPENDED=0x00000004, CREATE_NO_WINDOW=0x08000000, JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE=0x00002000;
  static void Check(bool ok, string action) { if (!ok) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error(), action); }
  public static int Run(string commandLine) {
    IntPtr job=CreateJobObject(IntPtr.Zero, null); if (job==IntPtr.Zero) throw new System.ComponentModel.Win32Exception(Marshal.GetLastWin32Error());
    PROCESS_INFORMATION pi=new PROCESS_INFORMATION();
    try {
      EXTENDED_LIMIT limits=new EXTENDED_LIMIT(); limits.basic.flags=JOB_OBJECT_LIMIT_KILL_ON_JOB_CLOSE;
      Check(SetInformationJobObject(job, 9, ref limits, (uint)Marshal.SizeOf(typeof(EXTENDED_LIMIT))), "Configure job cleanup");
      STARTUPINFO si=new STARTUPINFO(); si.cb=Marshal.SizeOf(typeof(STARTUPINFO));
      Check(CreateProcess(null, new StringBuilder(commandLine), IntPtr.Zero, IntPtr.Zero, false, CREATE_SUSPENDED|CREATE_NO_WINDOW, IntPtr.Zero, null, ref si, out pi), "Create alignment process");
      Check(AssignProcessToJobObject(job, pi.process), "Assign alignment process to cleanup job");
      Check(ResumeThread(pi.thread)!=unchecked((uint)-1), "Start alignment process");
      WaitForSingleObject(pi.process, 0xFFFFFFFF);
      uint code; Check(GetExitCodeProcess(pi.process, out code), "Read alignment exit code"); return unchecked((int)code);
    } catch { if (pi.process!=IntPtr.Zero) TerminateProcess(pi.process, 1); throw; }
    finally { if (pi.thread!=IntPtr.Zero) CloseHandle(pi.thread); if (pi.process!=IntPtr.Zero) CloseHandle(pi.process); CloseHandle(job); }
  }
}
CSHARP;
        return '$ErrorActionPreference = "Stop"; '
            .'$line = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("'.$commandLineBase64.'")); '
            .'$identityPath = [Text.Encoding]::UTF8.GetString([Convert]::FromBase64String("'.$identityPathBase64.'")); '
            .'$self = Get-Process -Id $PID; $ticks = $self.StartTime.ToUniversalTime().Ticks; '
            .'$identity = @{ process_id = [int] $PID; creation_ticks = [string] $ticks } | ConvertTo-Json -Compress; '
            .'[IO.File]::WriteAllText($identityPath, $identity); '
            .'Add-Type -TypeDefinition @"'."\n".$source."\n".'"@; '
            .'exit [WhisperxWindowsJob]::Run($line);';
    }

    private function escapeWindowsArgument(string $argument): string
    {
        if ($argument !== '' && !preg_match('/[\s"]/', $argument)) return $argument;
        $escaped = '"';
        $slashes = 0;
        for ($index = 0, $length = strlen($argument); $index < $length; $index++) {
            $character = $argument[$index];
            if ($character === '\\') {
                $slashes++;
                continue;
            }
            if ($character === '"') {
                $escaped .= str_repeat('\\', $slashes * 2 + 1).'"';
                $slashes = 0;
                continue;
            }
            $escaped .= str_repeat('\\', $slashes).$character;
            $slashes = 0;
        }
        return $escaped.str_repeat('\\', $slashes * 2).'"';
    }

    /** @param resource|null $handle */
    private function terminateWindowsTree(?int $processId, $handle): void
    {
        // The PowerShell supervisor owns a Windows Job Object configured with
        // KILL_ON_JOB_CLOSE, so terminating its exact proc_open handle also
        // terminates every child in the isolated job without PID lookup.
        if (is_resource($handle)) @proc_terminate($handle, 9);
    }

    private function terminateProcessTree(?Process $process, ?int $processId, bool $isolatedGroup, ?array $identity): void
    {
        if ($isolatedGroup && $processId !== null && $identity && function_exists('posix_kill')) {
            $current = $this->linuxProcessIdentity($processId);
            if ($this->sameLinuxProcess($identity, $current)
                && ($current['process_group_id'] ?? null) === $processId
                && ($current['session_id'] ?? null) === $processId) {
                @posix_kill(-$processId, 15);
                // The group is isolated and identity-checked; immediately follow
                // TERM with KILL so descendants cannot outlive a terminated leader.
                @posix_kill(-$processId, 9);
            } elseif ($current === null) {
                $this->terminateRemainingIsolatedGroup($processId, $identity);
            }
        }

        if ($process && $process->isRunning()) $process->stop(1, 9);
    }

    private function terminateRemainingIsolatedGroup(int $groupId, array $identity): void
    {
        if (!function_exists('posix_kill') || $groupId <= 1
            || (int) ($identity['process_group_id'] ?? 0) !== $groupId
            || (int) ($identity['session_id'] ?? 0) !== $groupId) return;
        // An extant group ID cannot be reassigned while it still has members.
        if (@posix_kill(-$groupId, 0)) {
            @posix_kill(-$groupId, 15);
            @posix_kill(-$groupId, 9);
        }
    }

    /** @return array{process_id:int,process_group_id:int,session_id:int,start_ticks:string}|null */
    private function linuxProcessIdentity(int $pid): ?array
    {
        $stat = @file_get_contents('/proc/'.$pid.'/stat');
        if (!is_string($stat) || !preg_match('/^\d+ \(.*\) (.*)$/', $stat, $matches)) return null;
        $fields = preg_split('/\s+/', trim($matches[1]));
        if (!is_array($fields) || count($fields) < 20 || ($fields[0] ?? '') === 'Z') return null;
        return [
            'process_id' => $pid,
            'process_group_id' => (int) $fields[2],
            'session_id' => (int) $fields[3],
            'start_ticks' => (string) $fields[19],
        ];
    }

    private function sameLinuxProcess(array $expected, ?array $current): bool
    {
        return $current !== null
            && (int) ($expected['process_id'] ?? 0) === (int) ($current['process_id'] ?? -1)
            && hash_equals((string) ($expected['start_ticks'] ?? ''), (string) ($current['start_ticks'] ?? ''));
    }
}
