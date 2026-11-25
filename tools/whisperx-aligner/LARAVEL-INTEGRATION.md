# Laravel WhisperX alignment integration

The integration is disabled by default. It reuses `ai_operation_usages`: a successful transcription operation is the transcript version, and a separate `whisperx_alignment` operation tracks queue/processing/result status. The transcript text and output metadata use the model's encrypted `result` field. No subtitle table is introduced; generated SRT remains private with `approval_status=pending_admin_review` until an admin approves it.

## Admin subtitle workspace

The Curriculum lesson list has an admin-only **Subtitles** action that opens the lesson subtitle workspace. It uses existing encrypted `ai_operation_usages` records for preparation, transcription, translation, alignment, and approvals; it does not create duplicate subtitle records or a second subtitle pipeline.

1. **Generate English transcript** dispatches private lesson audio preparation, then queues English transcription using the assigned provider. Preparation is idempotent by lesson video hash, and each transcription operation records its timeline offset and prepared audio path.
2. Admins edit and approve the transcript. Editing resets transcript approval; WhisperX aligns the approved text snapshot.
3. Admins queue alignment through the existing WhisperX runner, review its SRT, and approve an immutable English subtitle version.
4. Filipino translation is queued only from an approved transcript and only after its matching English subtitle version is approved. The translation provider returns text without timings, so admins edit that text and upload a timed UTF-8 SRT whose cue text matches the Filipino draft before approval.
5. Approved English and Filipino versions have separate admin-only download links. Versioned content is stored privately; drafts and unapproved output have no download route.

The transcription jobs use Laravel's default queue; audio preparation and WhisperX alignment use the configured `whisperx` connection and `whisperx-alignment` queue. Run workers for both queues.

## Admin flow

1. Admins inspect a successful transcript with `GET /api/admin/lessons/{lesson}/transcripts/{operation}`.
2. Admins explicitly approve it as English with `POST /api/admin/lessons/{lesson}/transcripts/{operation}/approve` and `{"language":"en"}`.
3. Admins queue alignment with `POST /api/admin/lessons/{lesson}/whisperx-alignments` and `transcript_operation_id`. `audio_path` is optional: new transcription operations retain the prepared chunk path in the existing encrypted operation result. For older operations, Laravel scans prepared chunk files and accepts only a chunk whose content hash reproduces that operation's stored request fingerprint. Admins can still pass `audio_path` relative to the private local disk when an older operation cannot be matched.
4. Alignment output stays pending review until an admin approves its English SRT using the subtitle approval endpoint below. The WhisperX job never approves or publishes its output.

## Approved subtitle downloads

The admin Lessons page lists approved English and Filipino SRT versions separately. Subtitle approval metadata and version hashes are stored in the existing encrypted operation result; immutable SRT files are copied to private storage. Re-approving changed content creates a new version while preserving the prior approved file. Identical content reuses its existing version.

- Approve the generated English SRT without uploading a file: `POST /api/admin/lessons/{lesson}/subtitles/{alignment_operation}/en/approve`.
- Approve a timed Filipino SRT tied to a successful translation operation: multipart `POST /api/admin/lessons/{lesson}/subtitles/{translation_operation}/fil/approve` with `srt_file` (`.srt`, UTF-8, at most 5 MB). Translation operations store text only today, so an admin must supply the timed Filipino SRT; the integration does not invent timing or modify the translation pipeline.
- List approved versions: `GET /api/admin/lessons/{lesson}/subtitles`.
- Download one exact approved version: use its returned `download_url`. Old approved versions remain individually downloadable. All routes require an authenticated admin; unapproved output is not downloadable.

The student video route is a playback stream with an expiring playback session, inline disposition, and browser download controls disabled. There is no downloadable-video route. A future soft-subtitle video feature would need a background FFmpeg derivative job that muxes both approved SRT files as separate selectable subtitle tracks, immutable per-version source references, private derivative storage, and an admin-only download route. Container/player subtitle-track compatibility should be decided and tested before implementation. Video processing and video downloads are not part of this integration.

The temporary audio workspace must be retained until the queued job completes. The job revalidates that the source is still inside the private preparation directory. It reads transcript text from the encrypted transcription operation, writes a private temporary UTF-8 file for Python, and deletes that temporary file after execution. Queue payloads contain only encrypted IDs and the relative audio path.

## Environment

Keep `WHISPERX_ENABLED=false` until the Plesk Python environment and worker are ready. Set these values in the Plesk application's environment, then clear/rebuild Laravel's config cache:

```dotenv
WHISPERX_ENABLED=true
WHISPERX_PYTHON_BINARY=/absolute/path/to/venv/bin/python
WHISPERX_SCRIPT_PATH=/absolute/path/to/app/tools/whisperx-aligner/align_transcript.py
WHISPERX_DEVICE=cpu
WHISPERX_PROCESS_TIMEOUT=7200
WHISPERX_JOB_TIMEOUT=7500
WHISPERX_WORKER_TIMEOUT=7500
WHISPERX_RETRY_AFTER=7800
```

Install Python and the CPU-only requirements in a dedicated Linux virtual environment on Plesk. The checked-in `requirements.txt` records the validated Windows environment; generate and validate a Linux-specific lock from the pinned WhisperX/PyTorch versions rather than assuming Windows wheels apply. Install FFmpeg on the server and make `ffmpeg` available on the worker's `PATH`. The first alignment also downloads the English alignment model into the worker user's writable PyTorch cache.

## Queue worker

Run database migrations that create `jobs` and `failed_jobs`, and ensure the application's database queue connection is usable. Audio preparation and alignment jobs use the dedicated `whisperx` database connection and `whisperx-alignment` queue; transcription jobs use the configured default database connection and queue. Run both workers:

Keep a PHP CLI worker running under Plesk's process supervisor/service manager:

```sh
php artisan queue:work whisperx --queue=whisperx-alignment --sleep=3 --tries=2 --timeout=7500
php artisan queue:work database --queue=default --sleep=3 --tries=3 --timeout=120
```

The Python process timeout is 7,200 seconds; Laravel's job and worker timeouts are 7,500 seconds; the queue `retry_after` is 7,800 seconds. Symfony terminates the process before the job deadline, leaving time for Laravel to record the timeout and clean incomplete outputs. Linux launches through `setsid`, verifies the new session/process-group ID, and records `/proc` start ticks; cleanup signals only the verified group. Windows launches through a PowerShell supervisor that assigns the alignment process to a Job Object configured with `KILL_ON_JOB_CLOSE`; terminating the supervisor handle also closes the job and terminates descendants. The worker publishes a local heartbeat while waiting and while an alignment is active. Heartbeat directories are owner-only (`0700`) and heartbeat files are owner-only (`0600`); the web app, queue worker, and scheduler must run as the same site OS user for status checks and stale cleanup to work. `whisperx:reconcile` checks operation tokens against pending, reserved, delayed, and failed queue records; the workflow API also reconciles when opened. Its scheduler mutex expires after 10 minutes if an unclean scheduler stop leaves the cache lock behind; the scheduler's cache lock path must be writable by the site account. Enable Laravel's scheduler for periodic stale-operation reconciliation and restart workers after deployment/configuration changes. Legacy `WHISPERX_TIMEOUT` remains a fallback for the process timeout. The first alignment also downloads the English alignment model into the worker user's writable PyTorch cache. Alignment outputs are stored under Laravel's private local disk at `whisperx-alignments/`.

If the queue worker is forcibly terminated outside Laravel's timeout handling (for example by a host reboot or `SIGKILL`), PHP cannot run its cleanup handler. The saved process-group identity allows the scheduled reconciler to terminate that exact local group once the worker heartbeat is stale. Keep the scheduler enabled and run the worker and scheduler under the same site account; this recovery is periodic rather than instantaneous.

Python output timestamps are relative to the prepared chunk. Laravel applies the transcription operation's stored timeline offset once to the aligned JSON and SRT; it does not merge chunks or deduplicate overlap.
