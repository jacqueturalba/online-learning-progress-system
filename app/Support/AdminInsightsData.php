<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Builds a versioned, provider-neutral snapshot for future admin insight tools. */
class AdminInsightsData
{
    public function build(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'schema_version' => '1.0',
            'generated_at' => now()->toIso8601String(),
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'measurement_scopes' => [
                'student_progress' => 'Current totals; active students are measured within period.',
                'lesson_completion' => 'Completions within period.',
                'category_completion' => 'Current category completion snapshot from saved category progress.',
                'transfer_analysis' => 'Currently eligible categories completed within period; transfers require an explicit transfer record.',
                'weekly_learning_activity' => 'Activity within period, grouped by Monday-starting week; boundary weeks may be partial.',
                'pace_group_performance' => 'Current calendar week, Monday through generation time.',
                'realization_activity' => 'Submissions within period.',
            ],
            'student_progress' => $this->studentProgress($from, $to),
            'lesson_completion' => $this->lessonCompletion($from, $to),
            'category_completion' => $this->categoryCompletion(),
            'transfer_analysis' => $this->transferAnalysis($from, $to),
            'weekly_learning_activity' => $this->weeklyActivity($from, $to),
            'pace_group_performance' => $this->paceGroupPerformance(),
            'realization_activity' => $this->realizationActivity($from, $to),
        ];
    }

    private function studentProgress(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $students = DB::table('users as u')->where('u.role', 'student');
        $progress = DB::table('student_lesson_progress as p')
            ->join('users as u', 'u.id', '=', 'p.student_id')->where('u.role', 'student');

        return [
            'student_count' => (clone $students)->count(),
            'active_student_count' => (clone $students)->whereExists(DB::table('student_lesson_progress as recent')
                ->whereColumn('recent.student_id', 'u.id')->whereBetween('recent.last_watched_at', [$from->startOfDay(), $to->endOfDay()]))->count(),
            'students_with_learning_history' => (clone $students)->whereExists(DB::table('student_lesson_progress as history')
                ->whereColumn('history.student_id', 'u.id'))->count(),
            'lessons_started' => (clone $progress)->whereNotNull('p.started_at')->count(),
            'lessons_completed' => (clone $progress)->where('p.is_completed', true)->count(),
            'average_recorded_progress_percentage' => round((float) ((clone $progress)->avg('p.progress_percentage') ?? 0), 1),
            'by_category' => DB::table('categories as c')
                ->leftJoin('lessons as l', 'l.category_id', '=', 'c.id')
                ->leftJoin('student_lesson_progress as p', 'p.lesson_id', '=', 'l.id')
                ->leftJoin('users as u', function ($join): void {
                    $join->on('u.id', '=', 'p.student_id')->where('u.role', '=', 'student');
                })
                ->groupBy('c.id', 'c.name', 'c.sort_order')
                ->orderBy('c.sort_order')->orderBy('c.id')
                ->select(['c.id as category_id', 'c.name as category_name'])
                ->selectRaw('COUNT(DISTINCT CASE WHEN u.id IS NOT NULL AND p.started_at IS NOT NULL THEN p.student_id END) as students_with_progress')
                ->selectRaw('COUNT(CASE WHEN u.id IS NOT NULL AND p.started_at IS NOT NULL THEN p.id END) as lessons_started')
                ->selectRaw('COUNT(CASE WHEN u.id IS NOT NULL AND p.is_completed = 1 THEN p.id END) as lessons_completed')
                ->selectRaw('COALESCE(ROUND(AVG(CASE WHEN u.id IS NOT NULL THEN p.progress_percentage END), 1), 0) as average_recorded_progress_percentage')
                ->get()->map(fn ($row): array => [
                    'category_id' => (int) $row->category_id,
                    'category_name' => $row->category_name,
                    'students_with_progress' => (int) $row->students_with_progress,
                    'lessons_started' => (int) $row->lessons_started,
                    'lessons_completed' => (int) $row->lessons_completed,
                    'average_recorded_progress_percentage' => (float) $row->average_recorded_progress_percentage,
                ])->all(),
        ];
    }

    private function lessonCompletion(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('lessons as l')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->leftJoin('student_lesson_progress as p', function ($join) use ($from, $to): void {
                $join->on('p.lesson_id', '=', 'l.id')->where('p.is_completed', '=', true)
                    ->whereBetween('p.completed_at', [$from->startOfDay(), $to->endOfDay()]);
            })
            ->leftJoin('users as u', function ($join): void {
                $join->on('u.id', '=', 'p.student_id')->where('u.role', '=', 'student');
            })
            ->groupBy('c.id', 'c.name', 'c.sort_order', 'l.id', 'l.title', 'l.sort_order', 'l.is_published')
            ->orderBy('c.sort_order')->orderBy('c.id')->orderBy('l.sort_order')->orderBy('l.id')
            ->select(['c.id as category_id', 'c.name as category_name', 'l.id as lesson_id', 'l.title as lesson_title', 'l.is_published'])
            ->selectRaw('COUNT(DISTINCT u.id) as students_completed')
            ->selectRaw('MAX(CASE WHEN u.id IS NOT NULL THEN p.completed_at END) as latest_completion_at')
            ->get()->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'lesson_id' => (int) $row->lesson_id,
                'lesson_title' => $row->lesson_title,
                'is_published' => (bool) $row->is_published,
                'students_completed' => (int) $row->students_completed,
                'latest_completion_at' => $row->latest_completion_at,
            ])->all();
    }

    private function categoryCompletion(): array
    {
        return DB::table('categories as c')
            ->leftJoin('lessons as l', function ($join): void {
                $join->on('l.category_id', '=', 'c.id')->where('l.is_published', '=', true)->whereNull('l.deleted_at');
            })
            ->leftJoin('student_category_progress as scp', 'scp.category_id', '=', 'c.id')
            ->leftJoin('users as u', function ($join): void {
                $join->on('u.id', '=', 'scp.student_id')->where('u.role', '=', 'student');
            })
            ->groupBy('c.id', 'c.name', 'c.is_active', 'c.deleted_at', 'c.sort_order')
            ->orderBy('c.sort_order')->orderBy('c.id')
            ->select(['c.id as category_id', 'c.name as category_name', 'c.is_active', 'c.deleted_at'])
            ->selectRaw('COUNT(DISTINCT l.id) as published_lessons')
            ->selectRaw('COUNT(DISTINCT CASE WHEN u.id IS NOT NULL AND scp.started_at IS NOT NULL THEN scp.student_id END) as students_started')
            ->selectRaw('COUNT(DISTINCT CASE WHEN u.id IS NOT NULL AND scp.completed_at IS NOT NULL THEN scp.student_id END) as students_completed')
            ->get()->map(fn ($row): array => [
                'category_id' => (int) $row->category_id,
                'category_name' => $row->category_name,
                'is_active' => (bool) $row->is_active,
                'is_archived' => $row->deleted_at !== null,
                'published_lessons' => (int) $row->published_lessons,
                'students_started' => (int) $row->students_started,
                'students_completed' => (int) $row->students_completed,
            ])->all();
    }

    private function transferAnalysis(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $eligible = DB::table('student_category_progress as scp')
            ->join('users as u', 'u.id', '=', 'scp.student_id')->where('u.role', 'student')
            ->whereBetween('scp.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereExists(TransferEligibility::eligibleCategoriesFor('u.id')
                ->whereColumn('eligible_categories.id', 'scp.category_id'));
        $eligibleCount = (clone $eligible)->distinct()->count('u.id');
        $transferredCount = (clone $eligible)
            ->join('student_category_transfers as transfers', function ($join): void {
                $join->on('transfers.student_id', '=', 'scp.student_id')->on('transfers.from_category_id', '=', 'scp.category_id');
            })->distinct()->count('u.id');

        return [
            'eligible_students' => $eligibleCount,
            'transferred_students' => $transferredCount,
            'not_transferred_students' => max(0, $eligibleCount - $transferredCount),
            'transfer_rate_percentage' => $eligibleCount ? round($transferredCount / $eligibleCount * 100, 1) : 0,
        ];
    }

    private function weeklyActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $weeks = [];
        for ($week = $from->startOfWeek(); $week->lte($to); $week = $week->addWeek()) {
            $key = $week->toDateString();
            $weeks[$key] = [
                'week_start' => $key,
                'week_end' => $week->endOfWeek()->toDateString(),
                'active_students' => 0,
                'lessons_completed' => 0,
                'realizations_submitted' => 0,
            ];
        }

        $activeWeekExpression = $this->weekStartExpression('p.last_watched_at');
        $this->addWeeklyCounts($weeks, DB::table('student_lesson_progress as p')
            ->join('users as u', 'u.id', '=', 'p.student_id')->where('u.role', 'student')
            ->whereBetween('p.last_watched_at', [$from->startOfDay(), $to->endOfDay()])
            ->whereNotNull('p.last_watched_at')->selectRaw("{$activeWeekExpression} as week_start")
            ->selectRaw('COUNT(DISTINCT p.student_id) as amount')->groupBy('week_start')->get(), 'active_students');
        $completionWeekExpression = $this->weekStartExpression('p.completed_at');
        $this->addWeeklyCounts($weeks, DB::table('student_lesson_progress as p')
            ->join('users as u', 'u.id', '=', 'p.student_id')->where('u.role', 'student')
            ->where('p.is_completed', true)->whereBetween('p.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw("{$completionWeekExpression} as week_start")->selectRaw('COUNT(*) as amount')
            ->groupBy('week_start')->get(), 'lessons_completed');
        $realizationWeekExpression = $this->weekStartExpression('r.submitted_at');
        $this->addWeeklyCounts($weeks, DB::table('student_realizations as r')
            ->join('users as u', 'u.id', '=', 'r.student_id')->where('u.role', 'student')
            ->whereBetween('r.submitted_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw("{$realizationWeekExpression} as week_start")->selectRaw('COUNT(*) as amount')
            ->groupBy('week_start')->get(), 'realizations_submitted');

        return array_values($weeks);
    }

    /** @param array<string, array<string, int|string>> $weeks */
    private function addWeeklyCounts(array &$weeks, Collection $weeklyCounts, string $field): void
    {
        foreach ($weeklyCounts as $row) {
            $weekStart = CarbonImmutable::parse($row->week_start)->toDateString();
            if (isset($weeks[$weekStart])) {
                $weeks[$weekStart][$field] = (int) $weeks[$weekStart][$field] + (int) $row->amount;
            }
        }
    }

    private function weekStartExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "date({$column}, '-' || ((cast(strftime('%w', {$column}) as integer) + 6) % 7) || ' days')"
            : "DATE_SUB(DATE({$column}), INTERVAL WEEKDAY({$column}) DAY)";
    }

    private function paceGroupPerformance(): array
    {
        $weekStart = now()->startOfWeek();
        return DB::table('pace_groups as pg')
            ->leftJoin('users as u', function ($join): void {
                $join->on('u.pace_group', '=', 'pg.code')->where('u.role', '=', 'student');
            })
            ->groupBy('pg.code', 'pg.weekly_target')->orderBy('pg.code')
            ->select(['pg.code as pace_group', 'pg.weekly_target'])
            ->selectRaw('COUNT(DISTINCT u.id) as student_count')
            ->selectRaw('COALESCE(SUM(weekly_counts.completed_count), 0) as lessons_completed_this_week')
            ->selectRaw('COUNT(DISTINCT CASE WHEN pg.weekly_target > 0 AND weekly_counts.completed_count >= pg.weekly_target THEN u.id END) as students_meeting_weekly_target')
            ->leftJoinSub(DB::table('student_lesson_progress as weekly')
                ->join('users as weekly_users', 'weekly_users.id', '=', 'weekly.student_id')
                ->where('weekly_users.role', 'student')->where('weekly.is_completed', true)
                ->where('weekly.completed_at', '>=', $weekStart)->where('weekly.completed_at', '<=', now())
                ->groupBy('weekly.student_id')->select('weekly.student_id')->selectRaw('COUNT(*) as completed_count'),
                'weekly_counts', 'weekly_counts.student_id', '=', 'u.id')
            ->get()->map(function ($row): array {
                $students = (int) $row->student_count;
                $meeting = (int) $row->students_meeting_weekly_target;
                return [
                    'pace_group' => $row->pace_group,
                    'weekly_target' => (int) $row->weekly_target,
                    'student_count' => $students,
                    'lessons_completed_this_week' => (int) $row->lessons_completed_this_week,
                    'students_meeting_weekly_target' => $meeting,
                    'target_attainment_percentage' => (int) $row->weekly_target > 0 && $students ? round($meeting / $students * 100, 1) : null,
                ];
            })->all();
    }

    private function realizationActivity(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $base = DB::table('student_realizations as r')
            ->join('users as u', 'u.id', '=', 'r.student_id')->where('u.role', 'student')
            ->join('lessons as l', 'l.id', '=', 'r.lesson_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->whereBetween('r.submitted_at', [$from->startOfDay(), $to->endOfDay()]);

        return [
            'submission_count' => (clone $base)->count('r.id'),
            'students_participating' => (clone $base)->distinct()->count('u.id'),
            'by_category_and_lesson' => (clone $base)
                ->groupBy('c.id', 'c.name', 'c.sort_order', 'l.id', 'l.title', 'l.sort_order')
                ->orderBy('c.sort_order')->orderBy('c.id')->orderBy('l.sort_order')->orderBy('l.id')
                ->select(['c.id as category_id', 'c.name as category_name', 'l.id as lesson_id', 'l.title as lesson_title'])
                ->selectRaw('COUNT(r.id) as submission_count')
                ->selectRaw('COUNT(DISTINCT u.id) as student_count')
                ->get()->map(fn ($row): array => [
                    'category_id' => (int) $row->category_id,
                    'category_name' => $row->category_name,
                    'lesson_id' => (int) $row->lesson_id,
                    'lesson_title' => $row->lesson_title,
                    'submission_count' => (int) $row->submission_count,
                    'student_count' => (int) $row->student_count,
                ])->all(),
        ];
    }
}
