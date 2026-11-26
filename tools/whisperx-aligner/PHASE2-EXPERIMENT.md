# WhisperX phase 2 experiment

## Scope and fixture

This experiment used only locally generated synthetic speech. The five-minute recovery material under `storage/app/private` was excluded because its recovery manifest describes the historical audio/transcript association as unverified.

`experiments/generate_synthetic_fixture.ps1` generated a 302.975-second, 16 kHz mono WAV and its exact transcript using Windows SAPI. It contains 620 whitespace tokens and 4,239 characters (122.8 words/minute), close to the observed production transcript densities. The fixture generator also knows each spoken sentence clip's exact start and end. Those sentence boundaries are the explicit anchors in the window maps; no boundary was estimated from word count.

The alignment model was warmed with the first 35.595 seconds before the measured matrix. Each measured run used a fresh Python process and the cached local model. The single-call runner received the default 2-thread setting only for the warm-up; measured runs used `WHISPERX_CPU_THREADS=1,2,4,8`.

## Stage profile

For the corrected baseline runner, `alignment_duration_seconds` is exactly the wall time for `whisperx.align()` and matches `stage_timings_seconds.whisperx_align_seconds`. Total runtime and process CPU/peak working set are measured separately. One representative four-thread run:

| Stage | Seconds |
|---|---:|
| PyTorch import | 5.916 |
| WhisperX import | 11.863 |
| PyTorch thread setup | 0.039 |
| Audio decoding | 0.487 |
| Alignment model load | 3.899 |
| `whisperx.align()` | 153.243 |
| Word mapping/reconciliation | 0.004 |
| JSON/SRT output | 0.242 |
| Total script runtime | 177.606 |

The model loads once per process. On this sample, the alignment call accounted for most of the runtime. The corrected report also records `peak_working_set_mib` from Windows `GetProcessMemoryInfo` and `average_cpu_cores` from process CPU time divided by script wall time.

## Thread benchmark

Two measured runs per setting, after warm-up:

| Threads | Alignment seconds (runs) | Mean alignment seconds | Total seconds (runs) | Peak working set MiB (runs) | Mean CPU cores | Words aligned / low-score |
|---:|---:|---:|---:|---:|---:|---:|
| 1 | 379.841, 334.376 | 357.109 | 397.256, 362.405 | 10,543.1, 11,604.2 | 0.43 | 619 / 1 |
| 2 | 452.474, 374.425 | 413.450 | 487.798, 641.303 | 11,453.2, 10,633.2 | 0.33 | 619 / 1 |
| 4 | 193.342, 153.243 | 173.293 | 223.561, 177.606 | 11,366.0, 12,639.4 | 1.39 | 619 / 1 |
| 8 | 133.551, 280.110 | 206.831 | 164.217, 301.849 | 12,629.6, 12,511.7 | 1.86 | 619 / 1 |

Every baseline run preserved the 620 source tokens and reported 619 aligned plus one low-score word. The low-score word had timestamps; the baseline report counts it as missing/unreliable under its existing status semantics. Four threads had the best mean on this Windows host. Eight threads were faster in one run and substantially slower in the other. These two-run samples are noisy and do not establish a production thread setting.

An earlier external PowerShell process sampler aggregated unrelated Python process IDs and added monitoring overhead. Its timing and memory figures were discarded. The figures above come from metrics captured inside the alignment Python process.

## Bounded-window prototype

The separate `align_bounded_windows.py` runner consumes a JSON map of explicit audio bounds and source-token ranges. For each window it aligns the matching transcript token slice inside the mapped audio interval, while loading audio and the alignment model only once. The final comparison used 25 windows, each covering three synthetic sentences with one sentence of audio and token overlap.

Merge logic uses original token positions. Repeated spellings therefore remain separate occurrences. Where a token appears in multiple windows, it prefers the candidate farther from that window's crop edges, then uses alignment score as a tie-breaker. It preserves the original token text and order, leaves unavailable timestamps null, flags nonpositive scores for review, and reports backward timestamps without changing them. Python outputs stay relative to the supplied audio chunk; Laravel applies the lesson offset once.

| Run | Alignment seconds | Total seconds | Peak working set MiB | Mean CPU cores | Aligned / low-score | Window errors |
|---|---:|---:|---:|---:|---:|---:|
| Bounded 1 | 30.009 | 37.562 | 2,149.7 | 3.39 | 615 / 5 | 0 |
| Bounded 2 | 29.339 | 36.681 | 2,135.7 | 3.40 | 615 / 5 | 0 |

Mean `whisperx.align()` time was 29.674 seconds, about 5.8 times faster than the four-thread single-call mean (173.293 seconds). Mean total time was 37.122 seconds. Peak working set fell from 11.1–12.3 GiB in the four-thread baseline runs to about 2.1 GiB. Both bounded runs retained all 620 tokens in their original order, had timestamps for all 620, and reported no backward word timestamps or failed windows.

The initial six-sentence window experiment had one WhisperX backtrack failure. That motivated the three-sentence map for the repeated comparison. The final three-sentence runs had no failed windows.

## Timestamp and boundary comparison

The second bounded run was compared by source-token index with the second four-thread baseline run. This is parity against the current aligner, not ground truth.

- 620/620 token strings matched the original transcript in exact order; no output token was duplicated or dropped.
- All 620 positions had both timestamps in both outputs. The baseline had 619 `aligned` and one `review_low_score`; the bounded run had 615 `aligned` and five `review_low_score` (four additional low-score flags).
- For all 620 token positions, the median of each token's maximum start/end difference was 11 ms; p95 was 24 ms; maximum was 86 ms. No token differed by more than 250 ms.
- The window map duplicated 302 source positions across overlaps. For those boundary-context positions, p95 timestamp difference was 24 ms and maximum was 86 ms. There were no timestamp-order violations.

The synthetic fixture supplies exact sentence boundaries, but not ground-truth word boundaries. A small timestamp difference from the baseline does not prove the baseline or prototype is acoustically correct. The extra four low-score flags also need investigation on natural speech.

## Whether production anchors are available

The current flow cannot always derive trustworthy bounded-window anchors from an approved plain-text transcript and audio alone. `OpenAiProvider` requests segment timestamp granularity only for the `whisper-1` model. Other transcription model paths may return text without segment timestamps, and an administrator may edit the transcript after transcription. The approved text therefore is not guaranteed to retain a token-to-audio map.

The safe input is a timestamped source transcript whose segment text can be reconciled unambiguously with the approved text. Use sequence-aware source-token positions to transfer anchors through unchanged text; place window edges at those anchors with overlap. For edited or ambiguous spans, require human-verified phrase anchors or fall back to the single-call baseline. VAD alone can find pauses but cannot determine which sentence in a plain transcript belongs at each pause. Word-count or proportional-time splits are not safe anchors.

## Tests and decision

`tests/test_bounded_windows.py` contains ten pure-Python tests for repeated words, overlapping boundary words, punctuation, token order, timestamp ordering, relative timestamps, missing/low-score words, empty maps, all/partial failed windows, explicit anchor validation, and executor-compatible word records. All ten passed.

The existing Laravel feature test `passes an argument array, validates outputs, applies the stored offset once, and keeps SRT pending admin review` passed (27 assertions). `phpunit.xml` forces SQLite `:memory:` and there is no `.env.testing`; no development database was used.

**Recommendation:** the prototype is fast and has close timestamp parity on this anchored synthetic fixture, but it is not ready to integrate. The current production transcript path does not guarantee suitable anchors, the prototype produced four more low-score flags than baseline, and this fixture is not natural recorded speech. Next, evaluate it on a non-production local speech fixture with verified timestamped transcript segments, and require manual review of low-score and boundary tokens before changing the normal workflow. Keep the current single-call runner as the default.
