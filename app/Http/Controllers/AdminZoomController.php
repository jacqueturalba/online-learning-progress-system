<?php

namespace App\Http\Controllers;

use App\Models\StudentZoomAssignment;
use App\Models\User;
use App\Models\ZoomIntegrationSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminZoomController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'assigned', 'unassigned', 'active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $settings = ZoomIntegrationSetting::query()->find(1);
        $status = $filters['status'] ?? 'all';
        $students = User::query()->where('role', 'student')
            ->with(['zoomAssignment:id,student_id,zoom_url,is_active,assigned_by,updated_at'])
            ->when(trim($filters['search'] ?? '') !== '', function ($query) use ($filters) {
                $search = trim($filters['search']);
                $query->where(fn ($students) => $students->where('name', 'like', '%'.$search.'%')->orWhere('pseudocode', 'like', '%'.$search.'%'));
            })
            ->when($status === 'assigned', fn ($query) => $query->whereHas('zoomAssignment'))
            ->when($status === 'active', fn ($query) => $query->whereHas('zoomAssignment', fn ($assignment) => $assignment->where('is_active', true)))
            ->when($status === 'unassigned', fn ($query) => $query->whereDoesntHave('zoomAssignment'))
            ->when($status === 'inactive', fn ($query) => $query->whereHas('zoomAssignment', fn ($assignment) => $assignment->where('is_active', false)))
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
        ]);

        return response()->json([
            'credentials' => [
                'configured' => (bool) ($settings?->account_id && $settings?->client_id && $settings?->client_secret),
                'updated_at' => $settings?->updated_at,
            ],
            'students' => $students,
        ]);
    }

    public function saveCredentials(Request $request)
    {
        $settings = ZoomIntegrationSetting::query()->find(1);
        $required = $settings ? 'nullable' : 'required';
        $data = $request->validate([
            'account_id' => [$required, 'string', 'max:255'],
            'client_id' => [$required, 'string', 'max:255'],
            'client_secret' => [$required, 'string', 'max:255'],
        ]);

        $updates = array_filter($data, fn ($value) => is_string($value) && trim($value) !== '');
        $settings ??= ZoomIntegrationSetting::query()->findOrNew(1);
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

    public function saveAssignment(Request $request, int $student)
    {
        $studentRecord = User::query()->where('role', 'student')->findOrFail($student);
        $data = $request->validate([
            'zoom_url' => ['required', 'url', 'regex:/^https?:\/\//i', 'max:2048'],
            'is_active' => ['required', 'boolean'],
        ]);

        $assignment = StudentZoomAssignment::query()->updateOrCreate(
            ['student_id' => $studentRecord->id],
            ['zoom_url' => $data['zoom_url'], 'is_active' => $data['is_active'], 'assigned_by' => $request->user()->id],
        );

        return response()->json(['assignment' => [
            'zoom_url' => $assignment->zoom_url,
            'is_active' => $assignment->is_active,
            'updated_at' => $assignment->updated_at,
        ]]);
    }
}
