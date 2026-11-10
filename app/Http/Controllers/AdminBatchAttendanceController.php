<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ZoomAttendanceOccurrence;
use App\Models\ZoomAttendanceOccurrenceStudent;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomAttendanceSetting;
use App\Models\ZoomBatch;
use App\Models\ZoomBatchMembership;
use App\Services\ReportXlsxExporter;
use App\Services\ZoomAttendanceClassifier;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminBatchAttendanceController extends Controller
{
    public function settings()
    {
        $settings = ZoomAttendanceSetting::query()->first() ?? ZoomAttendanceSetting::query()->create([]);
        return response()->json(['settings' => $settings]);
    }

    public function updateSettings(Request $request)
    {
        $data = $request->validate(['present_threshold' => ['required','integer','min:1','max:100'], 'late_threshold' => ['required','integer','min:0','max:99']]);
        if ($data['present_threshold'] <= $data['late_threshold']) throw ValidationException::withMessages(['present_threshold' => 'Present threshold must be higher than Late threshold.']);
        $settings = ZoomAttendanceSetting::query()->first() ?? new ZoomAttendanceSetting();
        $settings->fill($data)->save();
        return response()->json(['settings' => $settings, 'message' => 'Thresholds saved. Historical attendance was not recalculated.']);
    }

    public function index(Request $request, ReportXlsxExporter $exporter)
    {
        $filters = $request->validate(['batch_id' => ['nullable','integer','exists:zoom_batches,id'], 'format' => ['nullable','in:xlsx']]);
        $batches = ZoomBatch::withTrashed()->withCount(['memberships as student_count'])->orderBy('name')->get(['id','name','status','deleted_at']);
        $settings = ZoomAttendanceSetting::query()->first() ?? ZoomAttendanceSetting::query()->create([]);
        $batch = isset($filters['batch_id']) ? ZoomBatch::withTrashed()->findOrFail($filters['batch_id']) : null;
        $occurrences = $batch ? ZoomAttendanceOccurrence::query()->whereHas('session', fn ($q) => $q->where('zoom_batch_id', $batch->id))
            ->with('session:id,title,zoom_batch_id')->orderBy('started_at')->orderBy('id')->get() : collect();
        $memberships = $batch ? ZoomBatchMembership::query()->with('student.enrollmentForms')->where('zoom_batch_id', $batch->id)->orderBy('joined_at')->get()->unique('student_id') : collect();
        $codes = $occurrences->isNotEmpty() ? ZoomAttendanceOccurrenceStudent::query()->whereIn('occurrence_id', $occurrences->pluck('id'))->get()->keyBy(fn ($row) => $row->occurrence_id.'|'.$row->student_id) : collect();
        $meetingColumns = $occurrences->map(fn ($occurrence) => [
            'id' => $occurrence->id, 'uuid' => $occurrence->meeting_uuid, 'meeting' => $occurrence->session?->title,
            'started_at' => $occurrence->started_at, 'duration_seconds' => $occurrence->duration_seconds,
            'participants_detected' => $occurrence->participants_detected, 'synced_at' => $occurrence->synced_at,
            'sync_status' => $occurrence->sync_status, 'summary' => $this->summaryFor($occurrence->id, $codes),
        ])->values();
        $rows = $memberships->map(function (ZoomBatchMembership $membership) use ($occurrences, $codes, $batch): array {
            $student = $membership->student;
            $form = $student->enrollmentForms->sortByDesc('revision')->first();
            $attendance = [];
            foreach ($occurrences as $occurrence) {
                $entry = $codes->get($occurrence->id.'|'.$student->id);
                $attendance['occurrence_'.$occurrence->id] = $entry?->attendance_code;
            }
            return ['batch_no' => $batch->name, 'id_no' => $student->id, 'student_id' => $student->id, 'student_name' => $student->name,
                'gender' => data_get($form?->data, 'gender', '—'), 'birthday' => data_get($form?->data, 'birthday', '—'), 'pseudocode' => $student->pseudocode,
                ...$attendance];
        })->values();

        if (($filters['format'] ?? null) === 'xlsx') {
            abort_unless($batch, 422, 'Select a batch before exporting.');
            $columns = [
                ['key' => 'batch_no', 'label' => 'Batch No.'], ['key' => 'id_no', 'label' => 'ID No.'], ['key' => 'student_name', 'label' => 'Name'],
                ['key' => 'gender', 'label' => 'Gender'], ['key' => 'birthday', 'label' => 'Birthday'], ['key' => 'pseudocode', 'label' => 'Pseudocode'],
            ];
            foreach ($meetingColumns as $column) $columns[] = ['key' => 'occurrence_'.$column['id'], 'label' => $column['started_at'] ? $column['started_at']->format('Y-m-d H:i') : 'Occurrence'];
            $summary = [];
            foreach ($meetingColumns as $column) foreach ($column['summary'] as $code => $count) $summary[''.$column['started_at'].' · '.$code] = $count;
            return $exporter->download('batch-meeting-attendance.xlsx', 'Batch Meeting Attendance', $columns, $rows, ['Batch' => $batch->name], $summary);
        }

        return response()->json(['batches' => $batches, 'batch' => $batch ? ['id' => $batch->id, 'name' => $batch->name] : null,
            'occurrences' => $meetingColumns, 'rows' => $rows, 'settings' => $settings]);
    }

    private function summaryFor(int $occurrenceId, $codes): array
    {
        $summary = ['O' => 0, 'X' => 0, 'L' => 0, 'M' => 0, 'Review' => 0];
        foreach ($codes as $row) if ((int) $row->occurrence_id === $occurrenceId && isset($summary[$row->attendance_code])) $summary[$row->attendance_code]++;
        return $summary;
    }

    public function updateManual(Request $request, ZoomAttendanceOccurrence $occurrence, User $student)
    {
        $data = $request->validate(['attendance_code' => ['required','in:M']]);
        $batchId = $occurrence->session?->zoom_batch_id;
        abort_unless($batchId && ZoomBatchMembership::query()->where('zoom_batch_id', $batchId)->where('student_id', $student->id)->exists(), 404);
        $attendance = ZoomAttendanceOccurrenceStudent::query()->firstOrNew(['occurrence_id' => $occurrence->id, 'student_id' => $student->id]);
        $attendance->fill(['attendance_code' => $data['attendance_code'], 'is_manual' => true, 'updated_by' => $request->user()->id, 'manually_updated_at' => now()])->save();
        return response()->json(['attendance' => $attendance->fresh(), 'message' => 'Attendance marked as make-up / catch-up.']);
    }

    public function recalculate(Request $request, ZoomAttendanceClassifier $classifier)
    {
        $data = $request->validate(['batch_id' => ['nullable','integer','exists:zoom_batches,id']]);
        $occurrences = ZoomAttendanceOccurrence::query()->whereHas('session', fn ($q) => $q->when(isset($data['batch_id']), fn ($query) => $query->where('zoom_batch_id', $data['batch_id'])))->get();
        foreach ($occurrences as $occurrence) $classifier->classify($occurrence, true);
        return response()->json(['recalculated_occurrences' => $occurrences->count(), 'message' => 'Attendance recalculated using current thresholds. Manual M overrides were cleared.']);
    }
}
