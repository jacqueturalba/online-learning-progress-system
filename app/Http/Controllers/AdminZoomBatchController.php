<?php

namespace App\Http\Controllers;

use App\Models\StudentZoomAssignment;
use App\Models\User;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Models\ZoomSession;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomAttendanceOccurrence;
use App\Models\ZoomAttendanceSetting;
use App\Services\ZoomApiException;
use App\Services\ZoomApiService;
use App\Services\ZoomAttendanceClassifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;
use Carbon\Carbon;
use Illuminate\Support\Str;

class AdminZoomBatchController extends Controller
{
    public function index()
    {
        return response()->json(['batches' => ZoomBatch::query()->withCount(['memberships as enrolled_count' => fn ($q) => $q->where('status', 'active')])
            ->with(['zoomSessions' => fn ($q) => $q->whereNotNull('zoom_meeting_id')->orderByDesc('id')])
            ->orderByDesc('created_at')->get()->map(fn (ZoomBatch $batch) => $this->batchSummary($batch))]);
    }

    public function store(Request $request)
    {
        $batch = ZoomBatch::create($this->validateBatch($request) + ['created_by' => $request->user()->id]);
        return response()->json(['batch' => $this->details($batch)], 201);
    }

    public function update(Request $request, ZoomBatch $batch)
    {
        $batch->update($this->validateBatch($request));
        return response()->json(['batch' => $this->details($batch->fresh())]);
    }

    public function show(ZoomBatch $batch)
    {
        return response()->json(['batch' => $this->details($batch)]);
    }

    public function startMeeting(Request $request, ZoomBatch $batch, ZoomApiService $zoom)
    {
        $session = $batch->zoomSessions()->whereNotNull('zoom_meeting_id')->latest('id')->first();
        abort_unless($session, 422, 'This batch does not have a Zoom meeting configured.');
        abort_unless(is_string($session->zoom_meeting_id) && trim($session->zoom_meeting_id) !== '', 422, 'This batch does not have a valid Zoom meeting ID.');
        abort_unless(is_string($session->zoom_host_id) && trim($session->zoom_host_id) !== '', 422, 'This meeting does not have a valid assigned Zoom host. Reconfigure the meeting host before starting it.');

        try {
            $user = $request->user();
            $isSuperAdmin = $user->role === 'super_admin';
            $isAssignedBatchAdmin = $user->role === 'admin' && (int) $batch->created_by === (int) $user->id;
            $isAssignedZoomHost = false;
            if (! $isSuperAdmin && ! $isAssignedBatchAdmin && $user->role === 'admin') {
                $assignedHostId = (string) $session->zoom_host_id;
                $host = $zoom->user($assignedHostId);
                $isAssignedZoomHost = (string) ($host['id'] ?? '') === $assignedHostId
                    && is_string($host['email'] ?? null)
                    && mb_strtolower(trim($host['email'])) === mb_strtolower(trim($user->email));
            }

            abort_unless($isSuperAdmin || $isAssignedBatchAdmin || $isAssignedZoomHost, 403,
                'Only the assigned Zoom host, the batch’s assigned admin, or a super admin can start this meeting.');

            // Only authorized callers reach the Zoom API response containing its private start URL.
            $meeting = $zoom->meeting((string) $session->zoom_meeting_id);
            $actualHostId = (string) ($meeting['host_id'] ?? '');
            abort_unless($actualHostId !== '' && hash_equals((string) $session->zoom_host_id, $actualHostId), 422,
                'The Zoom meeting host no longer matches this batch’s assigned host. Reconfigure the meeting host before starting it.');
        } catch (ZoomApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
        $startUrl = $meeting['start_url'] ?? null;
        $startHost = is_string($startUrl) ? strtolower((string) parse_url($startUrl, PHP_URL_HOST)) : '';
        abort_unless(is_string($startUrl) && parse_url($startUrl, PHP_URL_SCHEME) === 'https'
            && ($startHost === 'zoom.us' || str_ends_with($startHost, '.zoom.us')), 422, 'Zoom did not return a valid secure host start link.');
        return redirect()->away($startUrl);
    }

    public function students()
    {
        return response()->json(['students' => User::query()->where('role', 'student')->where('is_active', true)
            ->orderBy('name')->get(['id', 'name', 'email', 'pseudocode'])]);
    }

    public function addStudent(Request $request, ZoomBatch $batch)
    {
        $data = $request->validate(['student_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', 'student')->where('is_active', true)]]);
        $activeCount = $batch->memberships()->where('status', 'active')->count();
        abort_if($activeCount >= $batch->capacity, 422, 'This batch has reached its configured capacity. Increase the capacity before adding another student.');

        $membership = ZoomBatchMembership::query()->firstOrNew(['zoom_batch_id' => $batch->id, 'student_id' => $data['student_id'], 'status' => 'active']);
        if (! $membership->exists) {
            $membership->fill(['joined_at' => now(), 'created_by' => $request->user()->id]);
            $membership->save();
        }
        return response()->json(['batch' => $this->details($batch->fresh())], 201);
    }

    public function removeStudent(Request $request, ZoomBatch $batch, ZoomBatchMembership $membership)
    {
        abort_unless((int) $membership->zoom_batch_id === (int) $batch->id, 404);
        if ($membership->status === 'active') {
            DB::transaction(function () use ($batch, $membership): void {
                $membership->update(['status' => 'removed', 'ended_at' => now()]);
                $sessionIds = $batch->zoomSessions()->withTrashed()->pluck('id');
                StudentZoomAssignment::query()->where('student_id', $membership->student_id)->whereIn('zoom_session_id', $sessionIds)->update(['is_active' => false]);
            });
        }
        return response()->json(['batch' => $this->details($batch->fresh())]);
    }

    public function transferStudent(Request $request, ZoomBatch $batch, ZoomBatchMembership $membership)
    {
        abort_unless((int) $membership->zoom_batch_id === (int) $batch->id && $membership->status === 'active', 404);
        $data = $request->validate(['target_batch_id' => ['required', 'integer', Rule::exists('zoom_batches', 'id')]]);
        $target = ZoomBatch::query()->findOrFail($data['target_batch_id']);
        abort_if($target->is($batch), 422, 'Choose a different batch for the transfer.');
        abort_if($target->memberships()->where('status', 'active')->count() >= $target->capacity, 422, 'The destination batch has reached its configured capacity.');
        abort_if($target->memberships()->where('student_id', $membership->student_id)->where('status', 'active')->exists(), 422, 'The student is already enrolled in that batch.');

        DB::transaction(function () use ($membership, $target, $request): void {
            $membership->update(['status' => 'transferred', 'ended_at' => now()]);
            $sessionIds = $membership->zoomBatch->zoomSessions()->withTrashed()->pluck('id');
            StudentZoomAssignment::query()->where('student_id', $membership->student_id)->whereIn('zoom_session_id', $sessionIds)->update(['is_active' => false]);
            ZoomBatchMembership::create(['zoom_batch_id' => $target->id, 'student_id' => $membership->student_id,
                'status' => 'active', 'joined_at' => now(), 'created_by' => $request->user()->id]);
        });
        return response()->json(['batch' => $this->details($batch->fresh()), 'target_batch_id' => $target->id]);
    }

    public function hosts(ZoomApiService $zoom)
    {
        try {
            return response()->json(['hosts' => collect($zoom->users())->map(fn ($user) => [
                'id' => $user['id'] ?? null,
                'name' => $user['display_name'] ?? trim(($user['first_name'] ?? '').' '.($user['last_name'] ?? '')),
                'email' => $user['email'] ?? null,
                'type' => $user['type'] ?? null,
            ])->filter(fn ($user) => $user['id'] && $user['email'] && (int) $user['type'] === 2)->values()]);
        } catch (ZoomApiException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function createMeeting(Request $request, ZoomBatch $batch, ZoomApiService $zoom)
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'host_id' => ['required', 'string', 'max:191'],
            'start_time' => ['required', 'date', 'after:now'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
            'timezone' => ['required', 'timezone'],
            'frequency' => ['required', Rule::in(['one_time', 'daily', 'weekly', 'monthly'])],
            'repeat_interval' => ['nullable', 'integer', 'min:1', 'max:50'],
            'weekly_days' => ['nullable', 'array', 'min:1'],
            'weekly_days.*' => ['integer', 'between:1,7'],
            'end_date' => ['nullable', 'date', 'after:start_time'],
            'registration_enabled' => ['required', 'boolean', 'accepted'],
        ]);

        if ($data['frequency'] === 'weekly' && empty($data['weekly_days'])) {
            return response()->json(['message' => 'Choose at least one weekday for a weekly meeting.', 'errors' => ['weekly_days' => ['Choose at least one weekday.']]], 422);
        }

        $isRecurring = $data['frequency'] !== 'one_time';
        if ($isRecurring && empty($data['end_date'])) {
            return response()->json(['message' => 'A recurring Zoom meeting needs an end date.', 'errors' => ['end_date' => ['Choose a recurrence end date.']]], 422);
        }
        if ($isRecurring && $this->estimatedOccurrences($data) > 60) {
            return response()->json(['message' => 'Zoom supports at most 60 occurrences in this recurrence. Choose an earlier end date or a less frequent schedule.', 'errors' => ['end_date' => ['The selected recurrence would exceed Zoom’s 60 occurrence limit.']]], 422);
        }
        $type = $isRecurring ? 8 : 2;
        $recurrence = null;
        if ($isRecurring) {
            $recurrence = [
                'type' => ['daily' => 1, 'weekly' => 2, 'monthly' => 3][$data['frequency']],
                'repeat_interval' => (int) ($data['repeat_interval'] ?? 1),
                'end_date_time' => Carbon::parse($data['end_date'], $data['timezone'])->endOfDay()->utc()->toIso8601String(),
            ];
            if ($data['frequency'] === 'weekly') $recurrence['weekly_days'] = implode(',', $data['weekly_days']);
            if ($data['frequency'] === 'monthly') $recurrence['monthly_day'] = (int) Carbon::parse($data['start_time'], $data['timezone'])->day;
        }

        $payload = [
            'topic' => $data['topic'], 'agenda' => $data['description'] ?? '', 'type' => $type,
            'start_time' => Carbon::parse($data['start_time'], $data['timezone'])->format('Y-m-d\TH:i:s'),
            'duration' => (int) $data['duration'], 'timezone' => $data['timezone'],
            'settings' => ['approval_type' => 0, 'registration_type' => 1, 'registrants_confirmation_email' => true],
        ];
        if ($recurrence) $payload['recurrence'] = $recurrence;

        try {
            $meeting = $zoom->createMeeting($data['host_id'], $payload);
        } catch (ZoomApiException $exception) {
            return response()->json(['message' => 'Zoom could not create this meeting: '.$exception->getMessage()], 422);
        }

        $scheduledAt = isset($meeting['start_time']) ? Carbon::parse($meeting['start_time']) : Carbon::parse($data['start_time'], $data['timezone'])->utc();
        $recurrenceConfig = [...($recurrence ?? []), 'frequency' => $data['frequency'], 'timezone' => $data['timezone'], 'duration' => (int) $data['duration'], 'registration_enabled' => true];
        $session = DB::transaction(fn () => ZoomSession::create([
            'zoom_batch_id' => $batch->id, 'title' => $meeting['topic'] ?? $data['topic'],
            'meeting_url' => $meeting['join_url'] ?? '', 'scheduled_at' => $scheduledAt,
            'description' => $data['description'] ?? null, 'is_active' => true,
            'zoom_meeting_id' => (string) $meeting['id'], 'zoom_meeting_uuid' => $meeting['uuid'] ?? null,
            'zoom_host_id' => $meeting['host_id'] ?? $data['host_id'], 'zoom_meeting_type' => $type,
            'registration_type' => 1, 'recurrence_config' => $recurrenceConfig,
            'registration_url' => $meeting['registration_url'] ?? null, 'meeting_status' => 'active',
        ]));
        return response()->json(['batch' => $this->details($batch->fresh()), 'session_id' => $session->id], 201);
    }

    public function registerStudents(Request $request, ZoomBatch $batch, ZoomApiService $zoom)
    {
        $data = $request->validate(['student_ids' => ['required', 'array', 'min:1'], 'student_ids.*' => ['integer', 'distinct', Rule::exists('users', 'id')->where('role', 'student')]]);
        $session = $batch->zoomSessions()->whereNotNull('zoom_meeting_id')->where('is_active', true)->latest('id')->first();
        abort_unless($session && in_array((int) $session->zoom_meeting_type, [2, 8], true) && $session->registration_type !== null, 422, 'Create a registration-enabled scheduled or fixed-time recurring meeting for this batch first.');

        $results = [];
        foreach ($data['student_ids'] as $studentId) {
            $student = User::query()->where('role', 'student')->findOrFail($studentId);
            $membership = $batch->memberships()->where('student_id', $student->id)->where('status', 'active')->first();
            if (! $membership) {
                $results[] = ['student_id' => $student->id, 'status' => 'failed', 'message' => 'The student is not an active member of this batch.'];
                continue;
            }

            $assignment = StudentZoomAssignment::query()->firstOrNew(['student_id' => $student->id, 'zoom_session_id' => $session->id]);
            if ($assignment->registration_status === 'registered') {
                $results[] = ['student_id' => $student->id, 'status' => 'registered', 'message' => 'Already registered.'];
                continue;
            }
            if ($assignment->registration_status === 'pending') {
                $results[] = ['student_id' => $student->id, 'status' => 'pending', 'message' => 'Registration is already being processed.'];
                continue;
            }
            $lastName = $this->studentInitials($student);
            $registrationError = blank($student->pseudocode)
                ? 'The student profile is missing its permanent pseudocode.'
                : ($lastName === null
                    ? 'The student profile needs a name before Zoom registration can continue.'
                    : (! filter_var($student->email, FILTER_VALIDATE_EMAIL) ? 'The student account does not have a valid email address.' : null));
            if ($registrationError !== null) {
                $assignment->fill(['registration_source' => 'zoom_api', 'registration_status' => 'failed', 'zoom_url' => $assignment->zoom_url ?: '', 'pseudocode_snapshot' => $student->pseudocode, 'registration_error' => $registrationError, 'assigned_by' => $request->user()->id]);
                $assignment->save();
                $results[] = ['student_id' => $student->id, 'status' => 'failed', 'message' => $assignment->registration_error];
                continue;
            }

            $assignment->fill(['registration_source' => 'zoom_api', 'registration_status' => 'pending', 'zoom_url' => $assignment->zoom_url ?: '', 'pseudocode_snapshot' => $student->pseudocode, 'registration_error' => null, 'assigned_by' => $request->user()->id]);
            $assignment->save();
            try {
                // Pseudocode is the Zoom first name; initials provide the required last_name without changing the profile.
                $registrant = $zoom->register((string) $session->zoom_meeting_id, [
                    'email' => $student->email,
                    'first_name' => $student->pseudocode,
                    'last_name' => $lastName,
                ]);
                $assignment->fill([
                    'registrant_id' => $registrant['registrant_id'] ?? null,
                    'zoom_url' => $registrant['join_url'] ?? null,
                    'registration_status' => 'registered', 'registered_at' => now(), 'is_active' => true,
                    'registration_error' => null,
                ]);
                if (! $assignment->registrant_id || ! $assignment->zoom_url) throw new ZoomApiException('Zoom did not return a registrant ID and unique join URL.');
                $assignment->save();
                $results[] = ['student_id' => $student->id, 'status' => 'registered'];
            } catch (Throwable $exception) {
                $assignment->fill(['registration_status' => 'failed', 'registration_error' => $exception instanceof ZoomApiException ? $exception->getMessage() : 'Zoom registration could not be completed.']);
                $assignment->save();
                $results[] = ['student_id' => $student->id, 'status' => 'failed', 'message' => $assignment->registration_error];
            }
        }
        return response()->json(['results' => $results, 'batch' => $this->details($batch->fresh())]);
    }

    public function syncAttendance(Request $request, ZoomBatch $batch, ZoomSession $session, ZoomApiService $zoom, ZoomAttendanceClassifier $classifier)
    {
        abort_unless((int) $session->zoom_batch_id === (int) $batch->id && $session->zoom_meeting_id, 404);
        $range = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d']]);
        $timezone = config('app.timezone');
        $today = Carbon::today($timezone);
        $oldestAllowed = $today->copy()->subMonths(15);
        $scheduledDate = $session->scheduled_at ? Carbon::parse($session->scheduled_at)->timezone($timezone)->startOfDay() : null;
        $defaultFrom = $scheduledDate && $scheduledDate->lte($today) ? $scheduledDate->max($oldestAllowed) : $oldestAllowed;
        $fromDate = isset($range['from']) ? Carbon::createFromFormat('Y-m-d', $range['from'], $timezone)->startOfDay() : $defaultFrom;
        $toDate = isset($range['to']) ? Carbon::createFromFormat('Y-m-d', $range['to'], $timezone)->startOfDay() : $today;
        if (! isset($range['from']) && $fromDate->gt($toDate)) $fromDate = $oldestAllowed;
        if ($fromDate->gt($toDate) || $toDate->gt($today) || $fromDate->lt($oldestAllowed)) {
            return response()->json(['message' => 'Choose a valid date range within Zoom’s 15-month attendance history window.'], 422);
        }
        $from = $fromDate->toDateString();
        $to = $toDate->toDateString();
        $meetingInstances = [];
        $participantsRetrieved = 0;
        $matched = 0;
        $unmatched = 0;
        $cursor = Carbon::parse($from)->startOfMonth();
        $lastMonth = Carbon::parse($to)->startOfMonth();

        try {
            while ($cursor <= $lastMonth) {
                $windowFrom = $cursor->copy()->max(Carbon::parse($from))->toDateString();
                $windowTo = $cursor->copy()->endOfMonth()->min(Carbon::parse($to))->toDateString();
                $token = null;
                do {
                    $page = $zoom->historyMeetings($windowFrom, $windowTo, $token, (string) $session->zoom_meeting_id);
                    foreach ($page['history_meetings'] ?? [] as $meeting) {
                        if ((string) ($meeting['meeting_id'] ?? '') === (string) $session->zoom_meeting_id && ! empty($meeting['meeting_uuid'])) {
                            if (! empty($meeting['start_time']) && Carbon::parse($meeting['start_time'])->gt(now())) continue;
                            $meetingInstances[$meeting['meeting_uuid']] = $meeting;
                        }
                    }
                    $token = $page['next_page_token'] ?? null;
                } while ($token);
                $cursor->addMonth();
            }

            foreach ($meetingInstances as $uuid => $occurrence) {
                $occurrenceStart = ! empty($occurrence['start_time']) ? Carbon::parse($occurrence['start_time']) : null;
                $actualDuration = ! empty($occurrence['duration']) ? (int) $occurrence['duration'] * 60
                    : (! empty($occurrence['start_time']) && ! empty($occurrence['end_time'])
                        ? max(0, Carbon::parse($occurrence['start_time'])->diffInSeconds(Carbon::parse($occurrence['end_time'])))
                        : 0);
                $durationSeconds = $actualDuration > 0 ? $actualDuration : (int) (data_get($session->recurrence_config, 'duration', 0) * 60);
                $attendanceOccurrence = ZoomAttendanceOccurrence::query()->firstOrNew(['zoom_session_id' => $session->id, 'meeting_uuid' => (string) $uuid]);
                $attendanceOccurrence->fill(['started_at' => $occurrenceStart, 'duration_seconds' => max(0, $durationSeconds), 'sync_status' => 'syncing']);
                $attendanceOccurrence->save();
                $occurrenceParticipants = 0;
                $token = null;
                do {
                    $page = $zoom->pastMeetingParticipants((string) $uuid, $token);
                    foreach ($page['participants'] ?? [] as $participant) {
                        $participantsRetrieved++;
                        $occurrenceParticipants++;
                        $registrantId = $participant['registrant_id'] ?? null;
                        $name = trim((string) ($participant['name'] ?? $participant['user_name'] ?? ''));
                        $pseudocode = $this->pseudocodeFromName($name);
                        $assignment = $registrantId ? StudentZoomAssignment::query()
                            ->where('zoom_session_id', $session->id)->where('registrant_id', $registrantId)->first() : null;
                        $student = null;
                        $matchMethod = null;
                        if ($assignment = ($registrantId ? StudentZoomAssignment::query()->where('zoom_session_id', $session->id)->where('registrant_id', $registrantId)->first() : null)) {
                            $student = $assignment->student;
                            $matchMethod = 'registrant_id';
                        }
                        if (! $student && $pseudocode) {
                            $student = User::query()->where('role', 'student')->whereRaw('UPPER(pseudocode) = ?', [mb_strtoupper($pseudocode)])->first();
                            if ($student) $matchMethod = 'pseudocode';
                            $assignment ??= StudentZoomAssignment::query()->where('zoom_session_id', $session->id)->whereRaw('UPPER(pseudocode_snapshot) = ?', [mb_strtoupper($pseudocode)])->first();
                            if (! $student && $assignment?->student) { $student = $assignment->student; $matchMethod = 'pseudocode'; }
                        }
                        $email = mb_strtolower(trim((string) ($participant['user_email'] ?? $participant['email'] ?? '')));
                        if (! $student && $email !== '') {
                            $emailMatches = User::query()->where('role', 'student')->whereRaw('LOWER(email) = ?', [$email])->limit(2)->get();
                            if ($emailMatches->count() === 1) { $student = $emailMatches->first(); $matchMethod = 'email'; }
                        }
                        $joinedAt = ! empty($participant['join_time']) ? Carbon::parse($participant['join_time']) : null;
                        $leftAt = ! empty($participant['leave_time']) ? Carbon::parse($participant['leave_time']) : null;
                        $duration = isset($participant['duration']) ? max(0, (int) $participant['duration'])
                            : ($joinedAt && $leftAt ? max(0, $joinedAt->diffInSeconds($leftAt)) : 0);
                        $participantId = $participant['participant_user_id'] ?? $participant['id'] ?? null;
                        $identity = $registrantId ?: ($participantId ?: ($pseudocode ?: Str::uuid()->toString()));
                        $dedupeKey = hash('sha256', implode('|', [(string) $identity, $joinedAt?->toISOString() ?? 'unknown']));
                        $record = ZoomAttendanceRecord::query()->firstOrNew([
                            'zoom_session_id' => $session->id, 'zoom_meeting_uuid' => (string) $uuid, 'dedupe_key' => $dedupeKey,
                        ]);
                        $preserveManualMatch = $record->exists && $record->match_status === 'manually_matched';
                        $record->fill([
                            'student_id' => $preserveManualMatch ? $record->student_id : $student?->id,
                            'registrant_id' => $registrantId,
                            'participant_id' => $participantId,
                            'participant_pseudocode' => $assignment?->pseudocode_snapshot ?: $pseudocode,
                            'participant_name' => $name ?: null,
                            'participant_email' => $participant['user_email'] ?? $participant['email'] ?? null,
                            'joined_at' => $joinedAt,
                            'left_at' => $leftAt,
                            'duration_seconds' => $duration,
                            'match_status' => $preserveManualMatch ? 'manually_matched' : ($student ? 'matched' : 'unmatched'),
                            'matching_method' => $preserveManualMatch ? $record->matching_method : $matchMethod,
                            'raw_data' => ['occurrence_start_time' => $occurrence['start_time'] ?? null, 'participant' => $participant],
                        ]);
                        $record->save();
                        ($preserveManualMatch || $student) ? $matched++ : $unmatched++;
                    }
                    $token = $page['next_page_token'] ?? null;
                } while ($token);
                $attendanceOccurrence->fill(['participants_detected' => $occurrenceParticipants, 'synced_at' => now(), 'sync_status' => 'completed'])->save();
                $classifier->classify($attendanceOccurrence);
            }
            $session->forceFill(['last_synced_at' => now()])->save();
        } catch (ZoomApiException $exception) {
            $message = $exception->getMessage();
            $lower = mb_strtolower($message);
            $category = match (true) {
                $exception->httpStatus === 403 || str_contains($lower, 'permission') || str_contains($lower, 'scope') => 'missing_permissions',
                $exception->httpStatus === 401 => 'expired_or_invalid_token',
                $exception->httpStatus === 404 || str_contains($lower, 'meeting does not exist') => 'invalid_meeting_id',
                str_contains($lower, 'not end') || str_contains($lower, 'not ended') => 'unsupported_or_incomplete_meeting',
                $exception->httpStatus === 429 => 'rate_limited',
                default => 'zoom_api_error',
            };
            if ($category === 'missing_permissions') $message .= ' Check report:read:list_history_meetings:admin and meeting:read:list_past_participants:admin (or the corresponding classic admin scopes) in the Zoom app.';
            return response()->json(['message' => $message, 'category' => $category, 'zoom_http_status' => $exception->httpStatus,
                'meetings_retrieved' => count($meetingInstances), 'participants_retrieved' => $participantsRetrieved,
                'matched' => $matched, 'unmatched' => $unmatched], 422);
        }

        $noAttendance = $participantsRetrieved === 0;
        $latest = ZoomAttendanceOccurrence::query()->where('zoom_session_id', $session->id)->orderByDesc('started_at')->orderByDesc('id')->first();
        return response()->json(['message' => $noAttendance ? 'No historical attendance participants were returned by Zoom for this meeting and date range.' : 'Attendance sync completed.',
            'no_historical_attendance' => $noAttendance, 'meetings_retrieved' => count($meetingInstances),
            'participants_retrieved' => $participantsRetrieved, 'matched' => $matched, 'unmatched' => $unmatched,
            'occurrences' => count($meetingInstances), 'last_synced_at' => $session->last_synced_at,
            'latest_occurrence' => $latest ? ['meeting_date' => $latest->started_at, 'occurrence_id' => $latest->meeting_uuid,
                'sync_completed_at' => $latest->synced_at, 'participants_detected' => $latest->participants_detected,
                'sync_status' => $latest->sync_status, 'duration_seconds' => $latest->duration_seconds] : null]);
    }

    public function attendance(ZoomBatch $batch, ZoomSession $session)
    {
        abort_unless((int) $session->zoom_batch_id === (int) $batch->id, 404);
        $rows = ZoomAttendanceRecord::query()->where('zoom_session_id', $session->id)
            ->with('student:id,name,pseudocode')->orderBy('zoom_meeting_uuid')->orderBy('joined_at')->get()
            ->map(fn (ZoomAttendanceRecord $record) => [
                'id' => $record->id, 'occurrence_uuid' => $record->zoom_meeting_uuid,
                'occurrence_date' => $record->joined_at?->toDateString() ?? data_get($record->raw_data, 'occurrence_start_time'),
                'student_id' => $record->student_id, 'student_name' => $record->student?->name,
                'pseudocode' => $record->participant_pseudocode ?: $record->student?->pseudocode,
                'participant_name' => $record->participant_name, 'registrant_id' => $record->registrant_id,
                'joined_at' => $record->joined_at, 'left_at' => $record->left_at, 'duration_seconds' => $record->duration_seconds,
                'match_status' => $record->match_status,
            ]);
        $latest = ZoomAttendanceOccurrence::query()->where('zoom_session_id', $session->id)->latest('started_at')->first();
        return response()->json(['matched' => $rows->whereIn('match_status', ['matched', 'manually_matched'])->values(), 'unmatched' => $rows->where('match_status', 'unmatched')->values(), 'last_synced_at' => $session->last_synced_at,
            'latest_occurrence' => $latest ? ['meeting_date' => $latest->started_at, 'occurrence_id' => $latest->meeting_uuid,
                'sync_completed_at' => $latest->synced_at, 'participants_detected' => $latest->participants_detected,
                'sync_status' => $latest->sync_status, 'duration_seconds' => $latest->duration_seconds] : null]);
    }

    private function validateBatch(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:180'], 'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::in(ZoomBatch::STATUSES)], 'capacity' => ['required', 'integer', 'min:1', 'max:4999'],
            'starts_at' => ['nullable', 'date'], 'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function estimatedOccurrences(array $data): int
    {
        $start = Carbon::parse($data['start_time'], $data['timezone'])->startOfDay();
        $end = Carbon::parse($data['end_date'], $data['timezone'])->endOfDay();
        $interval = max(1, (int) ($data['repeat_interval'] ?? 1));
        $days = max(0, $start->diffInDays($end));
        return match ($data['frequency']) {
            'daily' => (int) ceil($days / $interval) + 1,
            'weekly' => (int) ceil($days / (7 * $interval)) * max(1, count($data['weekly_days'] ?? [])),
            'monthly' => (int) ceil(($days / 30.4375) / $interval) + 1,
            default => 1,
        };
    }

    private function studentInitials(User $student): ?string
    {
        $parts = preg_split('/\s+/u', trim($student->name), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($parts) && count($parts) ? mb_strtoupper(implode('', array_map(static fn ($part) => mb_substr($part, 0, 1), $parts))) : null;
    }

    private function pseudocodeFromName(string $name): ?string
    {
        return preg_match('/\b(STU-[A-Z0-9]{10})\b/i', $name, $matches) ? mb_strtoupper($matches[1]) : null;
    }

    private function batchSummary(ZoomBatch $batch): array
    {
        $session = $batch->zoomSessions->first();
        return ['id' => $batch->id, 'name' => $batch->name, 'description' => $batch->description, 'status' => $batch->status,
            'capacity' => $batch->capacity, 'starts_at' => $batch->starts_at, 'ends_at' => $batch->ends_at,
            'enrolled_count' => $batch->enrolled_count, 'session' => $session ? $this->sessionSummary($session) : null];
    }

    private function details(ZoomBatch $batch): array
    {
        $batch->load(['memberships' => fn ($q) => $q->where('status', 'active')->with('student:id,name,email,pseudocode'), 'zoomSessions' => fn ($q) => $q->whereNotNull('zoom_meeting_id')->orderByDesc('id')]);
        $session = $batch->zoomSessions->first();
        $members = $batch->memberships->map(function ($membership) use ($session): array {
            $assignment = $session ? StudentZoomAssignment::query()->where('student_id', $membership->student_id)->where('zoom_session_id', $session->id)->first() : null;
            return ['membership_id' => $membership->id, 'id' => $membership->student->id, 'name' => $membership->student->name,
                'email' => $membership->student->email, 'pseudocode' => $membership->student->pseudocode,
                'registration' => $assignment ? ['status' => $assignment->registration_status, 'join_url' => $assignment->registration_status === 'registered' ? $assignment->zoom_url : null,
                    'error' => $assignment->registration_error, 'registered_at' => $assignment->registered_at] : null];
        });
        return ['id' => $batch->id, 'name' => $batch->name, 'description' => $batch->description, 'status' => $batch->status,
            'capacity' => $batch->capacity, 'starts_at' => $batch->starts_at, 'ends_at' => $batch->ends_at,
            'enrolled_count' => $members->count(), 'students' => $members, 'session' => $session ? $this->sessionSummary($session) : null];
    }

    private function sessionSummary(ZoomSession $session): array
    {
        return ['id' => $session->id, 'meeting_id' => $session->zoom_meeting_id, 'meeting_uuid' => $session->zoom_meeting_uuid,
            'host_id' => $session->zoom_host_id, 'topic' => $session->title, 'join_url' => $session->meeting_url,
            'registration_url' => $session->registration_url, 'scheduled_at' => $session->scheduled_at,
            'duration' => data_get($session->recurrence_config, 'duration'), 'recurrence' => $session->recurrence_config,
            'meeting_type' => $session->zoom_meeting_type, 'registration_type' => $session->registration_type,
            'status' => $session->meeting_status, 'last_synced_at' => $session->last_synced_at,
            'registration_enabled' => data_get($session->recurrence_config, 'registration_enabled', $session->registration_url !== null)];
    }
}
