<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ZoomBatch;
use App\Models\ZoomSession;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Services\ReportXlsxExporter;
use App\Support\HistoricalBatchScope;
use Illuminate\Validation\ValidationException;

class AdminReportController extends Controller
{
    private const REPORTS = ['lesson_completion', 'student_completion', 'category_progress', 'realizations', 'transfers', 'meeting_attendance'];

    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'report' => ['required', 'string', 'in:'.implode(',', self::REPORTS)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'batch_id' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'meeting_id' => ['nullable', 'integer', 'min:1'],
            'occurrence_uuid' => ['nullable', 'string', 'max:191'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'in:xlsx'],
            'status' => ['nullable', 'in:all,matched,unmatched'],
        ]);

        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        if ($filters['report'] === 'transfers') {
            return app(AdminTransferAnalysisController::class)->index($request);
        }

        if ($filters['report'] === 'meeting_attendance') {
            $attendance = $this->meetingAttendance($filters);
            if (($filters['format'] ?? null) === 'xlsx') {
                $summary = [
                    'Meeting occurrences' => $attendance['occurrence_count'],
                    'Matched participant records' => $attendance['matched_count'],
                    'Unmatched participant records' => $attendance['unmatched_count'],
                    'Total attendance duration' => $this->durationText($attendance['total_duration_seconds']),
                ];
                foreach ($attendance['meeting_summaries'] as $meeting) {
                    $date = $meeting['occurrence_date']?->format('Y-m-d H:i') ?? 'Date not set';
                    $summary['Meeting: '.$meeting['meeting_title'].' · '.$date] = 'Batch: '.$meeting['batch_name'].'; matched: '.$meeting['matched'].'; unmatched: '.$meeting['unmatched'].'; duration: '.$this->durationText($meeting['duration_seconds']);
                }
                foreach ($attendance['batch_summaries'] as $batch) {
                    $summary['Batch: '.$batch['batch_name']] = 'Occurrences: '.$batch['occurrences'].'; matched: '.$batch['matched'].'; unmatched: '.$batch['unmatched'].'; duration: '.$this->durationText($batch['duration_seconds']);
                }
                return app(ReportXlsxExporter::class)->download('meeting-attendance.xlsx', 'Meeting Attendance', $this->attendanceColumns(), $attendance['rows'], $this->attendanceFilterLabels($filters), $summary);
            }
            $page = max(1, (int) ($filters['page'] ?? 1));
            $pageRows = new LengthAwarePaginator($attendance['rows']->forPage($page, 25)->values(), $attendance['rows']->count(), 25, $page, ['path' => $request->url(), 'query' => $request->query()]);
            return response()->json([
                'report' => $filters['report'], 'rows' => $pageRows,
                'filters' => $this->filterOptions($filters),
                'attendance_summary' => [
                    'occurrence_count' => $attendance['occurrence_count'],
                    'matched_count' => $attendance['matched_count'],
                    'unmatched_count' => $attendance['unmatched_count'],
                    'total_duration_seconds' => $attendance['total_duration_seconds'],
                    'batches' => $attendance['batch_summaries'],
                    'meetings' => $attendance['meeting_summaries'],
                ],
            ]);
        }

        $query = match ($filters['report']) {
            'lesson_completion' => $this->lessonCompletion($filters),
            'student_completion' => $this->studentCompletion($filters),
            'category_progress' => $this->categoryProgress($filters),
            'realizations' => $this->realizations($filters),
            default => abort(404),
        };

        if (($filters['format'] ?? null) === 'xlsx') {
            return app(ReportXlsxExporter::class)->download($filters['report'].'.xlsx', $this->reportTitle($filters['report']), $this->columnsFor($filters['report']), $query->get(), $this->reportFilterLabels($filters));
        }

        return response()->json([
            'report' => $filters['report'],
            'rows' => $query->paginate(25)->withQueryString(),
            'transfer_summary' => null,
            'filters' => $this->filterOptions($filters),
        ]);
    }

    private function lessonCompletion(array $filters)
    {
        $query = DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->join('users as u', 'u.id', '=', 'p.student_id')
            ->where('u.role', 'student')->where('p.is_completed', true)->whereNotNull('p.completed_at');
        HistoricalBatchScope::join($query, 'p.student_id', 'p.completed_at');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'p.completed_at');
        HistoricalBatchScope::filter($query, $filters['batch_id'] ?? null);

        return $query->groupBy('l.id', 'l.title', 'l.sort_order', 'l.category_id', 'c.name', 'report_batch_membership.zoom_batch_id', 'report_batch.name')
            ->orderBy('c.name')->orderBy('l.sort_order')->orderBy('l.id')
            ->select(['l.id as lesson_id', 'l.title as lesson_title', 'l.category_id', 'c.name as category_name'])
            ->addSelect('report_batch_membership.zoom_batch_id as batch_id')->selectRaw("COALESCE(report_batch.name, 'No batch assigned') as batch_name")
            ->selectRaw('COUNT(DISTINCT p.student_id) as completed_students')
            ->selectRaw('MAX(p.completed_at) as latest_completion')
            ->orderBy('report_batch.name');
    }

    private function studentCompletion(array $filters)
    {
        $query = DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('users as u', 'u.id', '=', 'p.student_id')
            ->leftJoin('pace_groups as pg', 'pg.code', '=', 'u.pace_group')
            ->where('u.role', 'student')->where('p.is_completed', true)->whereNotNull('p.completed_at');
        HistoricalBatchScope::join($query, 'p.student_id', 'p.completed_at');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'p.completed_at');
        HistoricalBatchScope::filter($query, $filters['batch_id'] ?? null);

        return $query->groupBy('u.id', 'u.name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target', 'report_batch_membership.zoom_batch_id', 'report_batch.name')
            ->orderBy('u.name')->orderBy('u.id')->orderBy('report_batch.name')
            ->select(['u.id as student_id', 'u.name as student_name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target'])
            ->addSelect('report_batch_membership.zoom_batch_id as batch_id')->selectRaw("COALESCE(report_batch.name, 'No batch assigned') as batch_name")
            ->selectRaw('COUNT(DISTINCT p.lesson_id) as completed_lessons')
            ->selectRaw('MAX(p.completed_at) as last_completion')
            ;
    }

    private function categoryProgress(array $filters)
    {
        $completionCounts = DB::table('student_lesson_progress as lp')
            ->join('lessons as ll', 'll.id', '=', 'lp.lesson_id')
            ->where('lp.is_completed', true)
            ->select('lp.student_id', 'll.category_id')
            ->selectRaw('COUNT(DISTINCT lp.lesson_id) as completed_lessons')
            ->selectRaw('MAX(lp.last_watched_at) as last_activity')
            ->groupBy('lp.student_id', 'll.category_id');

        $query = DB::table('student_category_progress as scp')
            ->join('users as u', 'u.id', '=', 'scp.student_id')
            ->join('categories as c', 'c.id', '=', 'scp.category_id')
            ->leftJoin('pace_groups as pg', 'pg.code', '=', 'u.pace_group')
            ->leftJoinSub($completionCounts, 'cc', function ($join): void {
                $join->on('cc.student_id', '=', 'scp.student_id')->on('cc.category_id', '=', 'scp.category_id');
            })
            ->leftJoinSub(DB::table('lessons')->where('is_published', true)->select('category_id')->selectRaw('COUNT(*) as total_lessons')->groupBy('category_id'), 'lt', 'lt.category_id', '=', 'scp.category_id')
            ->where('u.role', 'student');
        HistoricalBatchScope::join($query, 'scp.student_id', 'COALESCE(scp.started_at, scp.created_at)');
        $this->applyCategory($query, $filters, 'scp.category_id');
        $this->applyDates($query, $filters, 'scp.started_at');
        HistoricalBatchScope::filter($query, $filters['batch_id'] ?? null);

        return $query->orderBy('c.sort_order')->orderBy('c.id')->orderBy('u.name')->orderBy('u.id')->orderBy('report_batch.name')
            ->select(['scp.id', 'u.id as student_id', 'u.name as student_name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target', 'c.name as category_name'])
            ->addSelect('report_batch_membership.zoom_batch_id as batch_id')->selectRaw("COALESCE(report_batch.name, 'No batch assigned') as batch_name")
            ->selectRaw('COALESCE(cc.completed_lessons, 0) as completed_lessons')
            ->selectRaw('COALESCE(lt.total_lessons, 0) as total_lessons')
            ->selectRaw('CASE WHEN COALESCE(lt.total_lessons, 0) = 0 THEN 0 ELSE ROUND(COALESCE(cc.completed_lessons, 0) * 100.0 / lt.total_lessons, 1) END as progress_percentage')
            ->addSelect('cc.last_activity')
            ;
    }

    private function realizations(array $filters)
    {
        $query = DB::table('student_realizations as r')
            ->join('users as u', 'u.id', '=', 'r.student_id')
            ->join('lessons as l', 'l.id', '=', 'r.lesson_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('u.role', 'student');
        HistoricalBatchScope::join($query, 'r.student_id', 'r.submitted_at');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'r.submitted_at');
        HistoricalBatchScope::filter($query, $filters['batch_id'] ?? null);

        return $query->groupBy('c.id', 'c.name', 'l.id', 'l.title', 'u.id', 'u.name', 'u.pseudocode', 'report_batch_membership.zoom_batch_id', 'report_batch.name')
            ->orderBy('c.name')->orderBy('l.title')->orderBy('u.name')->orderBy('u.id')->orderBy('report_batch.name')
            ->select(['c.id as category_id', 'c.name as category_name', 'l.id as lesson_id', 'l.title as lesson_title', 'u.id as student_id', 'u.name as student_name', 'u.pseudocode'])
            ->addSelect('report_batch_membership.zoom_batch_id as batch_id')->selectRaw("COALESCE(report_batch.name, 'No batch assigned') as batch_name")
            ->selectRaw('COUNT(r.id) as realization_count')
            ->selectRaw('MAX(r.submitted_at) as latest_submission')
            ;
    }

    private function filterOptions(array $filters): array
    {
        $sessions = ZoomSession::query()->whereNotNull('zoom_meeting_id')
            ->when(($filters['report'] ?? null) === 'meeting_attendance', fn ($query) => $query->whereHas('attendanceRecords'))
            ->when(! empty($filters['category_id']), fn ($query) => $query->where('category_id', (int) $filters['category_id']))
            ->when(($filters['batch_id'] ?? null) === 'none', fn ($query) => $query->whereNull('zoom_batch_id'))
            ->when(! empty($filters['batch_id']) && $filters['batch_id'] !== 'none', fn ($query) => $query->where('zoom_batch_id', (int) $filters['batch_id']))
            ->with(['zoomBatch', 'attendanceRecords:id,zoom_session_id,zoom_meeting_uuid,raw_data,joined_at'])->orderBy('title')->orderBy('scheduled_at')->get();

        return [
            'categories' => Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']),
            'batches' => ZoomBatch::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            'meetings' => $sessions->map(fn (ZoomSession $session) => ['id' => $session->id, 'title' => $session->title, 'batch_id' => $session->zoom_batch_id,
                'occurrence_dates' => ($filters['report'] ?? null) === 'meeting_attendance'
                    ? $session->attendanceRecords->map(fn ($record) => data_get($record->raw_data, 'occurrence_start_time') ?: $record->joined_at?->toIso8601String())->filter()->unique()->sort()->values()
                    : [],
                'occurrences' => ($filters['report'] ?? null) === 'meeting_attendance'
                    ? $session->attendanceRecords->groupBy('zoom_meeting_uuid')->map(fn ($records, $uuid) => ['uuid' => $uuid,
                        'started_at' => $records->map(fn ($record) => data_get($record->raw_data, 'occurrence_start_time'))->filter()->first() ?: $records->first()?->joined_at?->toIso8601String()])
                        ->filter(fn ($occurrence) => is_string($occurrence['uuid']) && $occurrence['uuid'] !== '')
                        ->sortBy('started_at')->values()
                    : [],
            ])->values(),
            'pace_groups' => app(AdminPaceGroupController::class)->options(),
        ];
    }

    private function meetingAttendance(array $filters): array
    {
        $query = DB::table('zoom_attendance_records as ar')
            ->join('zoom_sessions as zs', 'zs.id', '=', 'ar.zoom_session_id')
            ->leftJoin('zoom_batches as zb', 'zb.id', '=', 'zs.zoom_batch_id')
            ->leftJoin('categories as cat', 'cat.id', '=', 'zs.category_id')
            ->leftJoin('users as u', 'u.id', '=', 'ar.student_id')
            ->when(! empty($filters['category_id']), fn ($q) => $q->where('zs.category_id', (int) $filters['category_id']))
            ->when(($filters['batch_id'] ?? null) === 'none', fn ($q) => $q->whereNull('zs.zoom_batch_id'))
            ->when(! empty($filters['batch_id']) && $filters['batch_id'] !== 'none', fn ($q) => $q->where('zs.zoom_batch_id', (int) $filters['batch_id']))
            ->when($filters['meeting_id'] ?? null, fn ($q, $id) => $q->where('zs.id', $id))
            ->when($filters['occurrence_uuid'] ?? null, fn ($q, $uuid) => $q->where('ar.zoom_meeting_uuid', $uuid))
            ->when(($filters['status'] ?? 'all') === 'matched', fn ($q) => $q->whereIn('ar.match_status', ['matched', 'manually_matched']))
            ->when(($filters['status'] ?? 'all') === 'unmatched', fn ($q) => $q->where('ar.match_status', 'unmatched'))
            ->select([
                'ar.id', 'ar.zoom_meeting_uuid', 'ar.student_id', 'ar.registrant_id', 'ar.participant_id', 'ar.participant_pseudocode',
                'ar.participant_name', 'ar.participant_email', 'ar.joined_at', 'ar.left_at', 'ar.duration_seconds', 'ar.match_status', 'ar.matching_method', 'ar.raw_data',
                'zs.id as session_id', 'zs.title as meeting_title', 'zs.scheduled_at as meeting_scheduled_at', 'zs.recurrence_config',
                'zs.zoom_batch_id as batch_id', 'zb.name as batch_name', 'zs.category_id', 'cat.name as category_name', 'u.name as student_name', 'u.pseudocode as student_pseudocode',
            ])->orderBy('zs.id')->orderBy('ar.zoom_meeting_uuid')->orderBy('ar.joined_at')->get();

        $groups = $query->groupBy(function ($record): string {
            $matched = in_array($record->match_status, ['matched', 'manually_matched'], true) && $record->student_id !== null;
            $identity = $matched ? 'student:'.$record->student_id
                : ($record->registrant_id ? 'registrant:'.$record->registrant_id
                    : ($record->participant_id ? 'participant:'.$record->participant_id
                        : ($record->participant_pseudocode ? 'pseudocode:'.$record->participant_pseudocode : 'record:'.$record->id)));
            return $record->session_id.'|'.$record->zoom_meeting_uuid.'|'.$identity;
        });

        $rows = $groups->map(function ($segments): array {
            $first = $segments->first();
            $occurrenceStart = data_get(json_decode((string) $first->raw_data, true) ?: [], 'occurrence_start_time');
            $startValue = $occurrenceStart ?: $first->joined_at;
            $start = $startValue ? Carbon::parse($startValue, config('app.timezone'))->timezone(config('app.timezone')) : null;
            $schedule = $this->meetingSchedule($first->meeting_scheduled_at, $first->recurrence_config);
            $joins = $segments->pluck('joined_at')->filter()->map(fn ($value) => Carbon::parse($value));
            $leaves = $segments->pluck('left_at')->filter()->map(fn ($value) => Carbon::parse($value));
            $matched = in_array($first->match_status, ['matched', 'manually_matched'], true) && $first->student_id !== null;
            return [
                'batch_id' => $first->batch_id,
                'batch_name' => $first->batch_name ?: 'No batch assigned',
                'category_name' => $first->category_name ?: 'No category assigned',
                'meeting_id' => $first->session_id,
                'meeting_title' => $first->meeting_title,
                'occurrence_uuid' => $first->zoom_meeting_uuid,
                'occurrence_date' => $start,
                'meeting_schedule' => $schedule,
                'student_name' => $matched ? ($first->student_name ?: 'Student') : ($first->participant_name ?: 'Unmatched participant'),
                'participant_name' => $first->participant_name,
                'pseudocode' => $first->participant_pseudocode ?: ($matched ? $first->student_pseudocode : null),
                'attendance_status' => $matched ? 'Matched' : 'Unmatched',
                'matching_method' => $first->matching_method,
                'join_time' => $joins->isNotEmpty() ? $joins->min() : null,
                'leave_time' => $leaves->isNotEmpty() ? $leaves->max() : null,
                'total_duration_seconds' => $segments->sum(fn ($segment) => (int) $segment->duration_seconds),
                'number_of_joins' => $segments->count(),
                'registrant_id' => $first->registrant_id,
                'participant_id' => $first->participant_id,
                'participant_email' => $first->participant_email,
                'segments' => $segments->map(fn ($segment) => ['joined_at' => $segment->joined_at, 'left_at' => $segment->left_at, 'duration_seconds' => (int) $segment->duration_seconds])->values()->all(),
            ];
        })->filter(function ($row) use ($filters): bool {
            if (! $row['occurrence_date'] && (isset($filters['date_from']) || isset($filters['date_to']))) return false;
            $occurrenceDate = $row['occurrence_date']?->timezone(config('app.timezone'))->toDateString();
            return (! isset($filters['date_from']) || $occurrenceDate >= $filters['date_from'])
                && (! isset($filters['date_to']) || $occurrenceDate <= $filters['date_to']);
        })->sortBy(fn ($row) => ($row['occurrence_date']?->timestamp ?? 0).'|'.$row['batch_name'].'|'.$row['student_name'])->values();

        $matchedCount = $rows->where('attendance_status', 'Matched')->count();
        $unmatchedCount = $rows->where('attendance_status', 'Unmatched')->count();
        $batchSummaries = $rows->groupBy(fn ($row) => ($row['batch_id'] ?? 'none').'|'.$row['batch_name'])->map(fn ($items) => [
            'batch_id' => $items->first()['batch_id'], 'batch_name' => $items->first()['batch_name'],
            'occurrences' => $items->groupBy(fn ($row) => $row['meeting_id'].'|'.$row['occurrence_uuid'])->count(),
            'matched' => $items->where('attendance_status', 'Matched')->count(),
            'unmatched' => $items->where('attendance_status', 'Unmatched')->count(),
            'duration_seconds' => $items->sum('total_duration_seconds'),
        ])->values();
        $meetingSummaries = $rows->groupBy(fn ($row) => $row['meeting_id'].'|'.$row['occurrence_uuid'])->map(fn ($items) => [
            'meeting_id' => $items->first()['meeting_id'], 'meeting_title' => $items->first()['meeting_title'],
            'batch_name' => $items->first()['batch_name'], 'occurrence_date' => $items->first()['occurrence_date'],
            'matched' => $items->where('attendance_status', 'Matched')->count(),
            'unmatched' => $items->where('attendance_status', 'Unmatched')->count(),
            'duration_seconds' => $items->sum('total_duration_seconds'),
        ])->values();

        return [
            'rows' => $rows, 'matched_count' => $matchedCount, 'unmatched_count' => $unmatchedCount,
            'occurrence_count' => $rows->groupBy(fn ($row) => $row['meeting_id'].'|'.$row['occurrence_uuid'])->count(),
            'total_duration_seconds' => $rows->sum('total_duration_seconds'),
            'batch_summaries' => $batchSummaries, 'meeting_summaries' => $meetingSummaries,
        ];
    }

    private function meetingSchedule($scheduledAt, $recurrence): string
    {
        if (! $scheduledAt) return 'Schedule not set';
        $recurrence = is_array($recurrence) ? $recurrence : (json_decode((string) $recurrence, true) ?: []);
        $frequency = $recurrence['frequency'] ?? 'one_time';
        $time = Carbon::parse($scheduledAt)->format('H:i');
        if ($frequency === 'one_time') return Carbon::parse($scheduledAt)->format('Y-m-d H:i');
        return ucfirst((string) $frequency).' · '.$time.' · '.($recurrence['timezone'] ?? 'Zoom timezone');
    }

    private function attendanceColumns(): array
    {
        return [
            ['key' => 'batch_name', 'label' => 'Batch'], ['key' => 'category_name', 'label' => 'Category'], ['key' => 'batch_id', 'label' => 'Batch ID', 'type' => 'number'],
            ['key' => 'meeting_title', 'label' => 'Meeting'], ['key' => 'occurrence_date', 'label' => 'Occurrence date', 'type' => 'date'],
            ['key' => 'meeting_schedule', 'label' => 'Meeting schedule'], ['key' => 'student_name', 'label' => 'Student'], ['key' => 'participant_name', 'label' => 'Zoom participant name'],
            ['key' => 'pseudocode', 'label' => 'Pseudocode'], ['key' => 'attendance_status', 'label' => 'Attendance status'],
            ['key' => 'join_time', 'label' => 'Join time', 'type' => 'date'], ['key' => 'leave_time', 'label' => 'Leave time', 'type' => 'date'],
            ['key' => 'total_duration_seconds', 'label' => 'Total duration', 'type' => 'duration'], ['key' => 'number_of_joins', 'label' => 'Number of joins', 'type' => 'number'],
            ['key' => 'registrant_id', 'label' => 'Zoom registrant ID'],
            ['key' => 'matching_method', 'label' => 'Matching method'],
            ['key' => 'participant_id', 'label' => 'Zoom participant ID'], ['key' => 'participant_email', 'label' => 'Participant email'],
        ];
    }

    private function columnsFor(string $report): array
    {
        $columns = match ($report) {
            'lesson_completion' => [
                ['key' => 'category_name', 'label' => 'Category'], ['key' => 'lesson_title', 'label' => 'Lesson'],
                ['key' => 'completed_students', 'label' => 'Students completed', 'type' => 'number'], ['key' => 'latest_completion', 'label' => 'Latest completion', 'type' => 'date'],
            ],
            'student_completion' => [
                ['key' => 'student_name', 'label' => 'Student'], ['key' => 'pseudocode', 'label' => 'Pseudocode'], ['key' => 'pace_group', 'label' => 'Pace group'],
                ['key' => 'weekly_target', 'label' => 'Weekly target', 'type' => 'number'], ['key' => 'completed_lessons', 'label' => 'Lessons completed', 'type' => 'number'], ['key' => 'last_completion', 'label' => 'Last completion', 'type' => 'date'],
            ],
            'category_progress' => [
                ['key' => 'category_name', 'label' => 'Category'], ['key' => 'student_name', 'label' => 'Student'], ['key' => 'pseudocode', 'label' => 'Pseudocode'],
                ['key' => 'pace_group', 'label' => 'Pace group'], ['key' => 'weekly_target', 'label' => 'Weekly target', 'type' => 'number'],
                ['key' => 'completed_lessons', 'label' => 'Completed', 'type' => 'number'], ['key' => 'total_lessons', 'label' => 'Lessons', 'type' => 'number'],
                ['key' => 'progress_percentage', 'label' => 'Progress percent', 'type' => 'number'], ['key' => 'last_activity', 'label' => 'Last activity', 'type' => 'date'],
            ],
            default => [
                ['key' => 'category_name', 'label' => 'Category'], ['key' => 'lesson_title', 'label' => 'Lesson'], ['key' => 'student_name', 'label' => 'Student'],
                ['key' => 'pseudocode', 'label' => 'Pseudocode'], ['key' => 'realization_count', 'label' => 'Submissions', 'type' => 'number'], ['key' => 'latest_submission', 'label' => 'Latest submission', 'type' => 'date'],
            ],
        };
        array_splice($columns, 0, 0, [['key' => 'batch_name', 'label' => 'Batch'], ['key' => 'batch_id', 'label' => 'Batch ID', 'type' => 'number']]);
        return $columns;
    }

    private function reportTitle(string $report): string
    {
        return match ($report) {
            'lesson_completion' => 'Category Lesson Completion', 'student_completion' => 'Student Lesson Completion',
            'category_progress' => 'Category Student Progress', 'realizations' => 'Realization Report', default => 'Admin Report',
        };
    }

    private function reportFilterLabels(array $filters): array
    {
        return ['Category ID' => $filters['category_id'] ?? 'All', 'Batch ID' => $filters['batch_id'] ?? 'All',
            'From' => $filters['date_from'] ?? 'All', 'To' => $filters['date_to'] ?? 'All'];
    }

    private function attendanceFilterLabels(array $filters): array
    {
        return ['Category ID' => $filters['category_id'] ?? 'All', 'Batch ID' => $filters['batch_id'] ?? 'All', 'Meeting ID' => $filters['meeting_id'] ?? 'All',
            'From' => $filters['date_from'] ?? 'All', 'To' => $filters['date_to'] ?? 'All', 'Attendance status' => $filters['status'] ?? 'all'];
    }

    private function durationText(int $seconds): string
    {
        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    private function applyCategory(Builder $query, array $filters, string $column): void
    {
        if (isset($filters['category_id'])) {
            $query->where($column, $filters['category_id']);
        }
    }

    private function applyDates(Builder $query, array $filters, string $column): void
    {
        if (isset($filters['date_from'])) {
            $query->where($column, '>=', $filters['date_from'].' 00:00:00');
        }
        if (isset($filters['date_to'])) {
            $query->where($column, '<', Carbon::parse($filters['date_to'])->addDay()->startOfDay());
        }
    }
}
