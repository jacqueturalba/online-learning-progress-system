#!/usr/bin/env python3
"""Experimental bounded-window forced alignment using explicit timing anchors.

This runner is intentionally separate from align_transcript.py. It never
estimates audio boundaries from word counts. The window map must contain
reviewed or upstream timestamp anchors and source-token ranges.
"""

from __future__ import annotations

import argparse
import json
import math
import os
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import align_transcript as baseline
from process_metrics import average_cpu_cores, peak_working_set_mib

SAMPLE_RATE = 16_000


@dataclass(frozen=True)
class Window:
    token_start: int
    token_end: int
    audio_start: float
    audio_end: float


def read_windows(path: Path, word_count: int, duration: float) -> tuple[list[Window], str | None]:
    """Read explicit anchors and require overlapping coverage for every token."""
    data = json.loads(path.read_text(encoding="utf-8"))
    raw_windows = data.get("windows") if isinstance(data, dict) else None
    if not isinstance(raw_windows, list) or not raw_windows:
        raise ValueError("Window map must contain a non-empty windows array.")

    windows: list[Window] = []
    previous: Window | None = None
    coverage = [0] * word_count
    for index, raw in enumerate(raw_windows):
        if not isinstance(raw, dict):
            raise ValueError(f"Window {index} must be an object.")
        token_start, token_end = raw.get("token_start"), raw.get("token_end")
        audio_start, audio_end = raw.get("audio_start"), raw.get("audio_end")
        if (not isinstance(token_start, int) or isinstance(token_start, bool)
                or not isinstance(token_end, int) or isinstance(token_end, bool)
                or token_start < 0 or token_end <= token_start or token_end > word_count):
            raise ValueError(f"Window {index} has an invalid non-empty token range.")
        if (not isinstance(audio_start, (int, float)) or isinstance(audio_start, bool)
                or not isinstance(audio_end, (int, float)) or isinstance(audio_end, bool)
                or not math.isfinite(audio_start) or not math.isfinite(audio_end)
                or audio_start < 0 or audio_end <= audio_start or audio_end > duration):
            raise ValueError(f"Window {index} has an invalid audio range.")
        window = Window(token_start, token_end, float(audio_start), float(audio_end))
        if previous and (window.token_start >= previous.token_end
                         or window.audio_start >= previous.audio_end):
            raise ValueError("Adjacent transcript and audio windows must overlap.")
        if previous and (window.token_start < previous.token_start
                         or window.token_end < previous.token_end
                         or window.audio_start < previous.audio_start
                         or window.audio_end < previous.audio_end):
            raise ValueError("Windows must be ordered by transcript and audio position.")
        for token_index in range(token_start, token_end):
            coverage[token_index] += 1
        windows.append(window)
        previous = window

    missing = [index for index, count in enumerate(coverage) if count == 0]
    if missing:
        raise ValueError(f"Window map does not cover source token positions: {missing[:8]}.")
    anchor_source = data.get("anchor_source") if isinstance(data.get("anchor_source"), str) else None
    return windows, anchor_source


def _valid_candidate(word: dict[str, Any], duration: float) -> bool:
    start, end = word.get("start"), word.get("end")
    return (baseline.valid_time(start, duration) and baseline.valid_time(end, duration)
            and end > start)


def merge_window_results(
    source_words: list[str],
    windows: list[Window],
    window_results: list[list[dict[str, Any]] | None],
    duration: float,
) -> tuple[list[dict[str, Any]], list[dict[str, Any]], int]:
    """Merge by original token position; duplicate word spellings stay distinct."""
    if len(windows) != len(window_results):
        raise ValueError("A result slot is required for every window.")

    candidates: list[list[tuple[dict[str, Any], float]]] = [[] for _ in source_words]
    failed_windows: list[dict[str, Any]] = []
    duplicate_candidates = 0
    for window_index, (window, aligned_words) in enumerate(zip(windows, window_results)):
        if aligned_words is None:
            failed_windows.append({"window_index": window_index, "error": "alignment_failed"})
            continue
        local_words = source_words[window.token_start:window.token_end]
        position_map, _ = baseline.word_mapping(local_words, aligned_words)
        for local_index, aligned in position_map.items():
            position = window.token_start + local_index
            if candidates[position]:
                duplicate_candidates += 1
            if not _valid_candidate(aligned, duration):
                continue
            margin = min(float(aligned["start"]) - window.audio_start,
                         window.audio_end - float(aligned["end"]))
            score = aligned.get("score")
            finite_score = isinstance(score, (int, float)) and math.isfinite(score)
            # Prefer the observation furthest from a crop edge, then use the
            # model score as a tie-breaker. No text-based deduplication occurs.
            quality = (margin, float(score) if finite_score else -math.inf)
            candidates[position].append((aligned, quality))

    output_words: list[dict[str, Any]] = []
    for position, source_word in enumerate(source_words):
        available = candidates[position]
        selected = max(available, key=lambda item: item[1])[0] if available else {}
        start, end, score = selected.get("start"), selected.get("end"), selected.get("score")
        has_times = _valid_candidate(selected, duration)
        has_score = isinstance(score, (int, float)) and math.isfinite(score)
        if not has_times:
            start = end = None
            status = "missing_or_unreliable"
        else:
            start, end = round(float(start), 3), round(float(end), 3)
            status = "review_low_score" if has_score and score <= 0 else "aligned"
        output_words.append({
            "text": source_word,
            "start": start,
            "end": end,
            "score": float(score) if has_score else None,
            "status": status,
        })
    return output_words, failed_windows, duplicate_candidates


def timestamp_order_violations(words: list[dict[str, Any]]) -> list[int]:
    """Return source positions whose timed starts go backwards; never repair them."""
    violations: list[int] = []
    previous_start: float | None = None
    for position, word in enumerate(words):
        start = word.get("start")
        if start is None:
            continue
        if previous_start is not None and start < previous_start:
            violations.append(position)
        previous_start = start
    return violations


def unmapped_token_positions(source_words: list[str], aligned_words: list[dict[str, Any]]) -> list[int]:
    mapping, _ = baseline.word_mapping(source_words, aligned_words)
    return [position for position in range(len(source_words)) if position not in mapping]


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--audio", required=True, type=Path)
    parser.add_argument("--transcript", required=True, type=Path)
    parser.add_argument("--window-map", required=True, type=Path, help="JSON with explicit token and audio ranges")
    parser.add_argument("--output", required=True, type=Path)
    parser.add_argument("--language", default="en")
    parser.add_argument("--device", default="cpu")
    return parser.parse_args()


def main() -> int:
    args = parse_args()
    started = time.perf_counter()
    cpu_started = time.process_time()
    stage: dict[str, float | None] = {
        "torch_import_seconds": None,
        "whisperx_import_seconds": None,
        "pytorch_thread_setup_seconds": None,
        "audio_decode_seconds": None,
        "alignment_model_load_seconds": None,
        "whisperx_align_seconds": None,
        "window_merge_seconds": None,
        "output_processing_seconds": None,
        "report_write_seconds": None,
    }
    report: dict[str, Any] = {
        "total_words": 0,
        "successfully_aligned_words": 0,
        "missing_or_unreliable_words": 0,
        "low_score_word_count": 0,
        "alignment_duration_seconds": None,
        "total_script_runtime_seconds": None,
        "peak_working_set_mib": None,
        "average_cpu_cores": None,
        "stage_timings_seconds": stage,
        "audio_duration_seconds": None,
        "processing_device": args.device,
        "cpu_thread_count": None,
        "model_used": None,
        "warnings": [],
        "errors": [],
        "window_errors": [],
        "duplicate_candidates_resolved": 0,
        "timestamp_order_violations": [],
        "timeline": "Relative to the supplied audio chunk; no original timeline offset is applied.",
    }
    args.output.mkdir(parents=True, exist_ok=True)
    try:
        if not args.audio.is_file() or not args.transcript.is_file() or not args.window_map.is_file():
            raise FileNotFoundError("Audio, transcript, and explicit window map files must all exist.")
        transcript = args.transcript.read_text(encoding="utf-8-sig")
        alignment_text = " ".join(transcript.split())
        source_words = alignment_text.split() if alignment_text else []
        if not source_words:
            raise ValueError("Transcript is empty.")
        report["total_words"] = len(source_words)

        if args.device == "cpu":
            threads = max(1, int(os.environ.get("WHISPERX_CPU_THREADS", "2")))
            report["cpu_thread_count"] = threads
            os.environ["OMP_NUM_THREADS"] = str(threads)
            os.environ["MKL_NUM_THREADS"] = str(threads)
            tick = time.perf_counter()
            import torch
            stage["torch_import_seconds"] = round(time.perf_counter() - tick, 3)
            tick = time.perf_counter()
            torch.set_num_threads(threads)
            torch.set_num_interop_threads(1)
            stage["pytorch_thread_setup_seconds"] = round(time.perf_counter() - tick, 3)

        tick = time.perf_counter()
        import whisperx
        from whisperx.alignment import DEFAULT_ALIGN_MODELS_TORCH
        stage["whisperx_import_seconds"] = round(time.perf_counter() - tick, 3)
        model_name = DEFAULT_ALIGN_MODELS_TORCH.get(args.language)
        if model_name is None:
            raise ValueError(f"No default WhisperX alignment model is available for language {args.language!r}.")
        report["model_used"] = model_name

        tick = time.perf_counter()
        audio = whisperx.load_audio(str(args.audio))
        stage["audio_decode_seconds"] = round(time.perf_counter() - tick, 3)
        duration = len(audio) / SAMPLE_RATE
        report["audio_duration_seconds"] = round(duration, 3)
        if duration <= 0:
            raise ValueError("Audio file decoded to zero samples.")

        windows, anchor_source = read_windows(args.window_map, len(source_words), duration)
        report["window_anchor_source"] = anchor_source
        tick = time.perf_counter()
        model, metadata = whisperx.load_align_model(language_code=args.language, device=args.device)
        stage["alignment_model_load_seconds"] = round(time.perf_counter() - tick, 3)

        window_results: list[list[dict[str, Any]] | None] = []
        align_started = time.perf_counter()
        for window_index, window in enumerate(windows):
            text = " ".join(source_words[window.token_start:window.token_end])
            try:
                result = whisperx.align(
                    [{"start": window.audio_start, "end": window.audio_end, "text": text}],
                    model,
                    metadata,
                    audio,
                    args.device,
                    interpolate_method="ignore",
                )
                aligned_entries = result.get("word_segments", [])
                window_results.append(aligned_entries)
                unmatched = unmapped_token_positions(source_words[window.token_start:window.token_end], aligned_entries)
                if unmatched:
                    report["window_errors"].append({
                        "window_index": window_index,
                        "error_type": "UnmatchedWindowTokens",
                        "unmatched_token_count": len(unmatched),
                    })
            except Exception as exc:
                window_results.append(None)
                report["window_errors"].append({
                    "window_index": window_index,
                    "error_type": type(exc).__name__,
                    "message": str(exc),
                })
        stage["whisperx_align_seconds"] = round(time.perf_counter() - align_started, 3)

        tick = time.perf_counter()
        output_words, failed_windows, duplicate_count = merge_window_results(source_words, windows, window_results, duration)
        report["window_errors"].extend(failed_windows)
        report["duplicate_candidates_resolved"] = duplicate_count
        report["timestamp_order_violations"] = timestamp_order_violations(output_words)
        report["successfully_aligned_words"] = sum(word["status"] == "aligned" for word in output_words)
        report["missing_or_unreliable_words"] = len(output_words) - report["successfully_aligned_words"]
        report["low_score_word_count"] = sum(word["status"] == "review_low_score" for word in output_words)
        if report["window_errors"]:
            report["warnings"].append(f"{len(report['window_errors'])} window(s) failed; uncovered source words remain untimed.")
        if report["low_score_word_count"]:
            report["warnings"].append(f"{report['low_score_word_count']} word(s) had a zero or negative alignment score and are flagged for review.")
        if report["timestamp_order_violations"]:
            report["warnings"].append("Timed words contain backward timestamps; review anchor mapping and affected windows.")
        stage["window_merge_seconds"] = round(time.perf_counter() - tick, 3)

        tick = time.perf_counter()
        baseline.write_json(args.output / "aligned.json", {
            "language": args.language,
            "audio_duration_seconds": round(duration, 3),
            "words": output_words,
        })
        srt, omitted = baseline.make_srt(output_words)
        (args.output / "aligned.srt").write_text(srt, encoding="utf-8")
        if omitted:
            report["warnings"].append(f"{omitted} untimed word(s) were omitted from timed SRT cues.")
        stage["output_processing_seconds"] = round(time.perf_counter() - tick, 3)
        report["alignment_duration_seconds"] = stage["whisperx_align_seconds"]
        # Measure one report serialization, then persist the finalized report.
        report_started = time.perf_counter()
        baseline.write_json(args.output / "alignment-report.json", report)
        stage["report_write_seconds"] = round(time.perf_counter() - report_started, 3)
        wall_seconds = time.perf_counter() - started
        report["total_script_runtime_seconds"] = round(wall_seconds, 3)
        report["peak_working_set_mib"] = peak_working_set_mib()
        report["average_cpu_cores"] = average_cpu_cores(cpu_started, wall_seconds)
        baseline.write_json(args.output / "alignment-report.json", report)
        print(json.dumps({
            "output": str(args.output.resolve()),
            "total_words": report["total_words"],
            "successfully_aligned_words": report["successfully_aligned_words"],
            "missing_or_unreliable_words": report["missing_or_unreliable_words"],
            "failed_windows": len(report["window_errors"]),
        }))
        return 0
    except Exception as exc:
        report["errors"].append({"type": type(exc).__name__, "message": str(exc)})
        report["alignment_duration_seconds"] = stage["whisperx_align_seconds"]
        wall_seconds = time.perf_counter() - started
        report["total_script_runtime_seconds"] = round(wall_seconds, 3)
        report["peak_working_set_mib"] = peak_working_set_mib()
        report["average_cpu_cores"] = average_cpu_cores(cpu_started, wall_seconds)
        report_started = time.perf_counter()
        baseline.write_json(args.output / "alignment-report.json", report)
        stage["report_write_seconds"] = round(time.perf_counter() - report_started, 3)
        baseline.write_json(args.output / "alignment-report.json", report)
        print(f"Experimental bounded alignment failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
