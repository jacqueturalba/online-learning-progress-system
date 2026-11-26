# Subtitle processing methods

The administrator setting is persisted in the existing `site_settings` table under `subtitle_processing_method`. Its safe default is `whisperx_standard`. A selected method is copied into each new `whisperx_alignment` operation's encrypted result metadata when the operation is queued; changing the setting later does not alter that queued operation.

## Methods

- `whisperx_standard` invokes the existing configured `align_transcript.py` runner. The process runner, Linux isolation, Windows Job Object containment, worker heartbeat, timeout settings, retry behavior, output layout, and transcript checks remain the same.
- `whisperx_bounded` uses `align_bounded_windows.py`. `BoundedWindowPlanner` accepts only source transcript segments with valid, monotonic audio timestamps that exactly reconcile, token by token, with the approved transcript after punctuation/case normalization. It combines complete segments into crops no longer than 45 seconds and overlaps adjacent windows by a complete timestamped segment. Missing, edited, unordered, oversized, or otherwise unusable anchors cause an explicit fallback to standard WhisperX. A bounded result with failed windows or untimed approved words also falls back. The operation records both requested and effective methods and the fallback reason.
- `openai_whisper1` posts each prepared chunk to the assigned OpenAI integration with `whisper-1`, `verbose_json`, and word timestamp granularity. API words are matched in sequence to the approved transcript. Approved source tokens remain the output text; unmatched/ambiguous/invalid time tokens keep null timestamps and fail the operation rather than being assigned inferred boundaries. The raw response is stored as a private per-operation artifact. The method does not invoke WhisperX.

For overlapping prepared chunks, duplicate approved-text prefix tokens are omitted from the current chunk's SRT only when the previous chunk has an approved English transcript, usable duration metadata, and the token timestamps fall within the previous chunk's measured end. If that context is unavailable, the new chunk fails safely. Previously recorded chunk times are not rewritten.

All successful outputs use `aligned.json`, `aligned.srt`, and `alignment-report.json` in the existing private `whisperx-alignments/{lesson}/{operation}` layout. Laravel applies the chunk offset once. Existing English and Filipino approval and cue translation continue to use the common alignment operation and approved SRT workflow.

## Local verification

Tests use `phpunit.xml`'s forced SQLite `:memory:` database. Set `LARAVEL_STORAGE_PATH` to a new temporary directory for tests when existing `storage/` content must remain untouched. No database migration is needed for the method setting.

## Deployment considerations (not performed here)

Deploy code and compiled frontend assets together. No migration is needed. Preserve configured WhisperX runner/model/Python dependencies for the standard method and the bounded runner. Configure an enabled official OpenAI integration with transcription capability before selecting Whisper-1. The first bounded requests may fall back when existing transcription records lack segment timestamps or no longer match the approved transcript. Confirm private storage capacity for the retained Whisper-1 raw response artifact. Production selection and deployment require a separate release decision; this local implementation does not change production settings.
