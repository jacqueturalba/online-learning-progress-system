<?php

namespace App\Services;

use App\Models\ZoomAttendanceOccurrence;
use App\Models\ZoomAttendanceOccurrenceStudent;
use App\Models\ZoomAttendanceRecord;
use App\Models\ZoomAttendanceSetting;
use App\Models\ZoomBatchMembership;
use Illuminate\Support\Facades\DB;

class ZoomAttendanceClassifier
{
    public function classify(ZoomAttendanceOccurrence $occurrence, bool $recalculate = false): void
    {
        $session = $occurrence->session;
        if (! $session?->zoom_batch_id) return;
        $settings = ZoomAttendanceSetting::query()->first() ?? ZoomAttendanceSetting::query()->create([]);
        $studentIds = ZoomBatchMembership::query()->where('zoom_batch_id', $session->zoom_batch_id)->distinct()->pluck('student_id');
        $durations = ZoomAttendanceRecord::query()->where('zoom_session_id', $session->id)
            ->where('zoom_meeting_uuid', $occurrence->meeting_uuid)->whereIn('student_id', $studentIds)
            ->whereIn('match_status', ['matched', 'manually_matched'])->groupBy('student_id')
            ->select('student_id', DB::raw('SUM(duration_seconds) as total_seconds'))->pluck('total_seconds', 'student_id');

        foreach ($studentIds as $studentId) {
            $duration = (int) ($durations[$studentId] ?? 0);
            $row = ZoomAttendanceOccurrenceStudent::query()->firstOrNew(['occurrence_id' => $occurrence->id, 'student_id' => $studentId]);
            $row->duration_seconds = $duration;
            if ($recalculate || ! $row->exists || ! $row->is_manual) {
                $percentage = $occurrence->duration_seconds > 0 ? ($duration / $occurrence->duration_seconds) * 100 : 0;
                $row->attendance_code = $duration <= 0 ? 'X' : ($percentage >= $settings->present_threshold ? 'O' : ($percentage >= $settings->late_threshold ? 'L' : 'Review'));
                if ($recalculate) { $row->is_manual = false; $row->updated_by = null; $row->manually_updated_at = null; }
            }
            $row->save();
        }
    }
}
