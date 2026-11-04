<?php

namespace App\Http\Controllers;

use App\Models\StudentZoomAssignment;
use App\Models\User;
use App\Models\ZoomIntegrationSetting;
use App\Services\ZoomApiException;
use App\Services\ZoomApiService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminZoomController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'batch', 'unassigned', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $settings = ZoomIntegrationSetting::query()->first();
        $status = $filters['status'] ?? 'all';
        $students = User::query()->where('role', 'student')
            ->with(['zoomAssignment:id,student_id,zoom_url,is_active,assigned_by,updated_at', 'zoomBatchMemberships' => fn ($q) => $q->where('status', 'active')->with('zoomBatch')->orderByDesc('id')])
            ->when(trim($filters['search'] ?? '') !== '', function ($query) use ($filters) {
                $search = trim($filters['search']);
                $query->where(fn ($students) => $students->where('name', 'like', '%'.$search.'%')->orWhere('pseudocode', 'like', '%'.$search.'%'));
            })
            ->when($status === 'batch', fn ($query) => $query->whereHas('zoomBatchMemberships', fn ($membership) => $membership->where('status', 'active')))
            ->when($status === 'unassigned', fn ($query) => $query->whereDoesntHave('zoomBatchMemberships', fn ($membership) => $membership->where('status', 'active')))
            ->when($status === 'failed', fn ($query) => $query->whereHas('zoomBatchMemberships', fn ($membership) => $membership->where('status', 'active')->whereHas('zoomBatch.zoomSessions', fn ($session) => $session->whereHas('registrations', fn ($registration) => $registration->whereColumn('student_id', 'users.id')->where('registration_status', 'failed')))))
            ->orderBy('name')->orderBy('id')->paginate(25)->withQueryString();

        $students->getCollection()->transform(fn (User $student) => [
            'id' => $student->id,
            'name' => $student->name,
            'pseudocode' => $student->pseudocode,
            'zoom_assignment' => $student->zoomAssignment ? [
                'zoom_url' => $student->zoomAssignment->zoom_url,
                'is_active' => $student->zoomAssignment->is_active,
                'updated_at' => $student->zoomAssignment->updated_at,
            ] : null,
            'active_batch' => $this->activeBatchSummary($student),
        ]);

        return response()->json([
            'credentials' => [
                'configured' => (bool) ($settings?->account_id && $settings?->client_id && $settings?->client_secret),
                'updated_at' => $settings?->updated_at,
            ],
            'students' => $students,
        ]);
    }

    private function activeBatchSummary(User $student): ?array
    {
        $membership = $student->zoomBatchMemberships->first();
        if (! $membership) return null;
        $session = $membership->zoomBatch->zoomSessions()->whereNotNull('zoom_meeting_id')->orderByDesc('id')->first();
        $assignment = $session ? StudentZoomAssignment::query()->where('student_id', $student->id)->where('zoom_session_id', $session->id)->first() : null;
        return ['id' => $membership->zoomBatch->id, 'name' => $membership->zoomBatch->name,
            'session' => $session ? ['id' => $session->id, 'topic' => $session->title, 'scheduled_at' => $session->scheduled_at, 'recurrence' => $session->recurrence_config] : null,
            'registration_status' => $assignment?->registration_status ?? 'pending',
            'join_url' => $assignment?->registration_status === 'registered' ? $assignment->zoom_url : null,
            'registration_error' => $assignment?->registration_error];
    }

    public function saveCredentials(Request $request)
    {
        $settings = ZoomIntegrationSetting::query()->first();
        $required = $settings ? 'nullable' : 'required';
        $data = $request->validate([
            'account_id' => [$required, 'string', 'max:255'],
            'client_id' => [$required, 'string', 'max:255'],
            'client_secret' => [$required, 'string', 'max:255'],
        ]);

        $updates = array_filter($data, fn ($value) => is_string($value) && trim($value) !== '');
        $settings ??= new ZoomIntegrationSetting();
        $settings->fill($updates);
        $settings->save();

        return response()->json([
            'credentials' => [
                'configured' => (bool) ($settings->account_id && $settings->client_id && $settings->client_secret),
                'updated_at' => $settings->updated_at,
            ],
            'message' => 'Zoom credentials saved securely.',
        ]);
    }

    public function testConnection(ZoomApiService $zoom)
    {
        try {
            $user = $zoom->testConnection();
            return response()->json(['connected' => true, 'message' => 'Connected to Zoom as '.($user['display_name'] ?? $user['email'] ?? 'the configured account').'.']);
        } catch (ZoomApiException $exception) {
            return response()->json(['connected' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function saveAssignment(Request $request, int $student)
    {
        $studentRecord = User::query()->where('role', 'student')->findOrFail($student);
        $data = $request->validate([
            'zoom_url' => ['required', 'url', 'regex:/^https?:\/\//i', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ]);

        $assignment = StudentZoomAssignment::query()->updateOrCreate(
            ['student_id' => $studentRecord->id, 'zoom_session_id' => null],
            ['zoom_url' => $data['zoom_url'], 'is_active' => $data['is_active'], 'assigned_by' => $request->user()->id],
        );

        return response()->json(['assignment' => [
            'zoom_url' => $assignment->zoom_url,
            'is_active' => $assignment->is_active,
            'updated_at' => $assignment->updated_at,
        ]]);
    }
}
