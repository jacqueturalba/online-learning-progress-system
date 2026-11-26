"""Small standard-library process CPU and peak working-set measurements."""

from __future__ import annotations

import ctypes
import os
import time


def peak_working_set_mib() -> float | None:
    """Return this process's lifetime peak RSS/working set when supported."""
    try:
        if os.name == "nt":
            class ProcessMemoryCounters(ctypes.Structure):
                _fields_ = [
                    ("cb", ctypes.c_ulong),
                    ("PageFaultCount", ctypes.c_ulong),
                    ("PeakWorkingSetSize", ctypes.c_size_t),
                    ("WorkingSetSize", ctypes.c_size_t),
                    ("QuotaPeakPagedPoolUsage", ctypes.c_size_t),
                    ("QuotaPagedPoolUsage", ctypes.c_size_t),
                    ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t),
                    ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
                    ("PagefileUsage", ctypes.c_size_t),
                    ("PeakPagefileUsage", ctypes.c_size_t),
                ]

            counters = ProcessMemoryCounters()
            counters.cb = ctypes.sizeof(counters)
            kernel32 = ctypes.WinDLL("kernel32", use_last_error=True)
            psapi = ctypes.WinDLL("psapi", use_last_error=True)
            kernel32.GetCurrentProcess.restype = ctypes.c_void_p
            ok = psapi.GetProcessMemoryInfo(
                ctypes.c_void_p(kernel32.GetCurrentProcess()),
                ctypes.byref(counters),
                counters.cb,
            )
            if not ok:
                return None
            return round(counters.PeakWorkingSetSize / (1024 * 1024), 1)

        import resource

        peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        # Linux reports KiB; macOS and the BSDs report bytes.
        return round(peak / (1024 if sys_platform_is_linux() else 1024 * 1024), 1)
    except (AttributeError, OSError, ValueError):
        return None


def sys_platform_is_linux() -> bool:
    return os.name == "posix" and os.path.exists("/proc/self/stat")


def average_cpu_cores(cpu_started: float, wall_seconds: float) -> float | None:
    """Average logical cores consumed by this process over the measured run."""
    if wall_seconds <= 0:
        return None
    return round(max(0.0, time.process_time() - cpu_started) / wall_seconds, 2)
