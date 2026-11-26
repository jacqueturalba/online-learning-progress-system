"""Small standard-library-only process metrics shared by local aligners."""

from __future__ import annotations

import os
import time


def average_cpu_cores(cpu_started: float, wall_seconds: float) -> float | None:
    try:
        cpu_seconds = time.process_time() - cpu_started
        return round(max(0.0, cpu_seconds) / wall_seconds, 2) if wall_seconds > 0 else None
    except Exception:
        return None


def peak_working_set_mib() -> float | None:
    if os.name == "nt":
        try:
            import ctypes
            from ctypes import wintypes

            class PROCESS_MEMORY_COUNTERS(ctypes.Structure):
                _fields_ = [
                    ("cb", wintypes.DWORD), ("PageFaultCount", wintypes.DWORD),
                    ("PeakWorkingSetSize", ctypes.c_size_t), ("WorkingSetSize", ctypes.c_size_t),
                    ("QuotaPeakPagedPoolUsage", ctypes.c_size_t), ("QuotaPagedPoolUsage", ctypes.c_size_t),
                    ("QuotaPeakNonPagedPoolUsage", ctypes.c_size_t), ("QuotaNonPagedPoolUsage", ctypes.c_size_t),
                    ("PagefileUsage", ctypes.c_size_t), ("PeakPagefileUsage", ctypes.c_size_t),
                ]

            counters = PROCESS_MEMORY_COUNTERS()
            counters.cb = ctypes.sizeof(counters)
            if ctypes.windll.psapi.GetProcessMemoryInfo(ctypes.windll.kernel32.GetCurrentProcess(), ctypes.byref(counters), counters.cb):
                return round(counters.PeakWorkingSetSize / (1024 * 1024), 1)
        except Exception:
            return None
    try:
        import resource
        value = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
        return round(value / (1024 if value > 1024 * 1024 else 1), 1)
    except Exception:
        return None
