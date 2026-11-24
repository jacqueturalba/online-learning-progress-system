#!/usr/bin/env python3
"""Forced-align an existing transcript without running speech recognition."""

from __future__ import annotations

import argparse
import gc
import json
import math
import os
import re
import shutil
import sys
import time
from difflib import SequenceMatcher
from pathlib import Path
from typing import Any


SAMPLE_RATE = 16_000
DEFAULT_FFMPEG = Path(r"C:\ffmpeg\bin\ffmpeg.exe")


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--audio", required=True, type=Path, help="Existing MP3 or WAV file")
    parser.add_argument("--transcript", required=True, type=Path, help="UTF-8 plain-text transcript")
    parser.add_argument("--output", required=True, type=Path, help="Directory for aligned.json, aligned.srt, and alignment-report.json")
    parser.add_argument("--language", default="en")
    parser.add_argument("--device", default="cpu")
    return parser.parse_args()


def token_key(value: str) -> str:
    return "".join(char.casefold() for char in value if char.isalnum())


def word_mapping(source_words: list[str], aligned_words: list[dict[str, Any]]) -> tuple[dict[int, dict[str, Any]], list[str]]:
    source_keys = [token_key(word) for word in source_words]
    aligned_keys = [token_key(str(word.get("word", ""))) for word in aligned_words]
    mapping: dict[int, dict[str, Any]] = {}
    warnings: list[str] = []

    if len(source_words) == len(aligned_words) and source_keys == aligned_keys:
        return dict(enumerate(aligned_words)), warnings

    matcher = SequenceMatcher(a=source_keys, b=aligned_keys, autojunk=False)
    for source_start, aligned_start, size in matcher.get_matching_blocks():
        for offset in range(size):
            if source_keys[source_start + offset]:
                mapping[source_start + offset] = aligned_words[aligned_start + offset]
    unmatched = len(source_words) - len(mapping)
    if unmatched:
        warnings.append(
            f"WhisperX returned {len(aligned_words)} word entries for {len(source_words)} transcript tokens; "
            f"{unmatched} source tokens could not be matched safely and were left unaligned."
        )
    return mapping, warnings


def valid_time(value: Any, duration: float) -> bool:
    return isinstance(value, (int, float)) and math.isfinite(value) and 0 <= value <= duration


def format_srt_time(seconds: float) -> str:
    milliseconds = max(0, round(seconds * 1000))
    hours, milliseconds = divmod(milliseconds, 3_600_000)
    minutes, milliseconds = divmod(milliseconds, 60_000)
    whole_seconds, milliseconds = divmod(milliseconds, 1000)
    return f"{hours:02d}:{minutes:02d}:{whole_seconds:02d},{milliseconds:03d}"


def make_srt(words: list[dict[str, Any]]) -> tuple[str, int]:
    """Make readable cues while keeping every token in the visible cue text.

    A word without its own time is never assigned one here. If its cue has other
    aligned words, cue bounds come only from those timed words; aligned.json is
    the source of truth for per-word availability.
    """
    cues: list[str] = []
    omitted_cues = 0
    group: list[dict[str, Any]] = []
    line_len = 0
    line_count = 1

    def flush() -> None:
        nonlocal group, line_len, line_count, omitted_cues
        if not group:
            return
        timed = [word for word in group if word["start"] is not None and word["end"] is not None]
        if timed:
            start = min(word["start"] for word in timed)
            end = max(word["end"] for word in timed)
            text = " ".join(word["text"] for word in group)
            cues.append(f"{len(cues) + 1}\n{format_srt_time(start)} --> {format_srt_time(end)}\n{text}\n")
        else:
            omitted_cues += len(group)
        group = []
        line_len = 0
        line_count = 1

    previous_end: float | None = None
    for word in words:
        token = word["text"]
        token_len = len(token) + (1 if line_len else 0)
        start = word["start"]
        long_pause = start is not None and previous_end is not None and start - previous_end > 1.25
        if group and (line_len + token_len > 42 or (line_len + token_len > 84 and line_count >= 2) or long_pause):
            if line_count < 2 and not long_pause and line_len + token_len > 42:
                group.append({**word, "text": "\n" + token})
                line_len = len(token)
                line_count += 1
            else:
                flush()
                group.append(word)
                line_len = len(token)
        else:
            group.append(word)
            line_len += token_len
            if "\n" in token:
                line_count += 1
        if word["end"] is not None:
            previous_end = word["end"]
    flush()
    return "\n".join(cues), omitted_cues


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")


def main() -> int:
    args = parse_args()
    started = time.perf_counter()
    warnings: list[str] = []
    report: dict[str, Any] = {
        "total_words": 0,
        "successfully_aligned_words": 0,
        "missing_or_unreliable_words": 0,
        "alignment_duration_seconds": None,
        "audio_duration_seconds": None,
        "processing_device": args.device,
        "model_used": "WAV2VEC2_ASR_BASE_960H" if args.language == "en" else None,
        "warnings": warnings,
        "errors": [],
        "timeline": "Relative to the supplied audio chunk; no original timeline offset is applied.",
    }
    args.output.mkdir(parents=True, exist_ok=True)

    try:
        if args.audio.suffix.lower() not in {".mp3", ".wav"}:
            raise ValueError("--audio must point to an MP3 or WAV file.")
        if not args.audio.is_file():
            raise FileNotFoundError(f"Audio file not found: {args.audio}")
        if not args.transcript.is_file():
            raise FileNotFoundError(f"Transcript file not found: {args.transcript}")
        if args.device != "cpu":
            warnings.append("This prototype is intended for CPU alignment; a non-CPU device was requested.")

        transcript_text = args.transcript.read_text(encoding="utf-8-sig")
        # Collapse only whitespace for WhisperX tokenization; lexical characters
        # and punctuation remain identical to the supplied transcript.
        alignment_text = " ".join(transcript_text.split())
        source_words = alignment_text.split() if alignment_text else []
        report["total_words"] = len(source_words)
        if not source_words:
            raise ValueError("Transcript is empty.")

        ffmpeg_dir = DEFAULT_FFMPEG.parent
        ffmpeg_path = DEFAULT_FFMPEG if DEFAULT_FFMPEG.is_file() else None
        if ffmpeg_path:
            # Process-local PATH change only; the machine PATH is not modified.
            os.environ["PATH"] = str(ffmpeg_dir) + os.pathsep + os.environ.get("PATH", "")
        elif not shutil.which("ffmpeg"):
            raise FileNotFoundError("FFmpeg was not found at C:\\ffmpeg\\bin\\ffmpeg.exe or on PATH.")

        if args.device == "cpu":
            # Limit this process before importing PyTorch/WhisperX or running any
            # eager tensor work. These are per-process settings, not system changes.
            os.environ["OMP_NUM_THREADS"] = "2"
            os.environ["MKL_NUM_THREADS"] = "2"
            import torch

            torch.set_num_threads(2)
            torch.set_num_interop_threads(1)

        import whisperx
        from whisperx.alignment import DEFAULT_ALIGN_MODELS_TORCH

        model_name = DEFAULT_ALIGN_MODELS_TORCH.get(args.language)
        report["model_used"] = model_name
        if model_name is None:
            raise ValueError(f"No default WhisperX alignment model is available for language {args.language!r}.")

        audio = whisperx.load_audio(str(args.audio))
        duration = len(audio) / SAMPLE_RATE
        report["audio_duration_seconds"] = round(duration, 3)
        if duration <= 0:
            raise ValueError("Audio file decoded to zero samples.")

        model, metadata = whisperx.load_align_model(language_code=args.language, device=args.device)
        external_transcript = [{"start": 0.0, "end": duration, "text": alignment_text}]
        aligned = whisperx.align(
            external_transcript,
            model,
            metadata,
            audio,
            args.device,
            interpolate_method="ignore",
        )

        aligned_entries = aligned.get("word_segments", [])
        index_map, map_warnings = word_mapping(source_words, aligned_entries)
        warnings.extend(map_warnings)
        output_words: list[dict[str, Any]] = []
        for index, source_word in enumerate(source_words):
            aligned_word = index_map.get(index, {})
            start = aligned_word.get("start")
            end = aligned_word.get("end")
            score = aligned_word.get("score")
            has_times = valid_time(start, duration) and valid_time(end, duration) and end > start
            has_score = isinstance(score, (int, float)) and math.isfinite(score)
            if not has_times:
                start = None
                end = None
                status = "missing_or_unreliable"
            else:
                start = round(float(start), 3)
                end = round(float(end), 3)
                status = "review_low_score" if has_score and score <= 0 else "aligned"
            output_words.append({
                "text": source_word,
                "start": start,
                "end": end,
                "score": float(score) if isinstance(score, (int, float)) and math.isfinite(score) else None,
                "status": status,
            })

        aligned_count = sum(word["status"] == "aligned" for word in output_words)
        report["successfully_aligned_words"] = aligned_count
        report["missing_or_unreliable_words"] = len(output_words) - aligned_count
        low_score_count = sum(word["status"] == "review_low_score" for word in output_words)
        if low_score_count:
            warnings.append(f"{low_score_count} word(s) had a zero or negative WhisperX alignment score and are flagged for review despite having timestamps.")
        if any(word["score"] is None for word in output_words if word["status"] == "aligned"):
            warnings.append("WhisperX did not provide a score for every aligned word; missing scores are null.")
        if any(word["score"] is not None for word in output_words):
            warnings.append("WhisperX score values are model alignment scores, not calibrated confidence probabilities.")
        punctuation_only = sum(not token_key(word) for word in source_words)
        if punctuation_only:
            warnings.append(f"{punctuation_only} punctuation-only transcript token(s) were retained; review them in context.")

        # Keep only the compact word records needed by the writers/report. The
        # model and decoded waveform are no longer needed after alignment.
        del aligned_entries, index_map, aligned, audio, model, metadata, external_transcript
        gc.collect()

        write_json(args.output / "aligned.json", {
            "language": args.language,
            "audio_duration_seconds": round(duration, 3),
            "words": output_words,
        })
        srt, omitted_cues = make_srt(output_words)
        (args.output / "aligned.srt").write_text(srt, encoding="utf-8")
        if omitted_cues:
            warnings.append(f"{omitted_cues} word(s) without reliable timing were not included in SRT cues because no cue time could be supplied safely.")
        report["alignment_duration_seconds"] = round(time.perf_counter() - started, 3)
        write_json(args.output / "alignment-report.json", report)
        print(json.dumps({
            "output": str(args.output.resolve()),
            "total_words": report["total_words"],
            "successfully_aligned_words": report["successfully_aligned_words"],
            "missing_or_unreliable_words": report["missing_or_unreliable_words"],
        }, ensure_ascii=False))
        return 0
    except Exception as exc:
        report["alignment_duration_seconds"] = round(time.perf_counter() - started, 3)
        report["errors"].append({"type": type(exc).__name__, "message": str(exc)})
        write_json(args.output / "alignment-report.json", report)
        print(f"Alignment failed: {type(exc).__name__}: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
