<?php

namespace App\Http\Controllers;

use App\Models\Category;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminReportController extends Controller
{
    private const REPORTS = ['lesson_completion', 'student_completion', 'category_progress', 'realizations', 'transfers'];

    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'report' => ['required', 'string', 'in:'.implode(',', self::REPORTS)],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        if (($filters['date_from'] ?? null) && ($filters['date_to'] ?? null) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        if ($filters['report'] === 'transfers') {
            return app(AdminTransferAnalysisController::class)->index($request);
        }

        $rows = match ($filters['report']) {
            'lesson_completion' => $this->lessonCompletion($filters),
            'student_completion' => $this->studentCompletion($filters),
            'category_progress' => $this->categoryProgress($filters),
            'realizations' => $this->realizations($filters),
            default => abort(404),
        };

        return response()->json([
            'report' => $filters['report'],
            'rows' => $rows,
            'transfer_summary' => null,
            'filters' => [
                'categories' => Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']),
                'pace_groups' => app(AdminPaceGroupController::class)->options(),
            ],
        ]);
    }

    private function lessonCompletion(array $filters)
    {
        $query = DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->join('users as u', 'u.id', '=', 'p.student_id')
            ->where('u.role', 'student')->where('p.is_completed', true)->whereNotNull('p.completed_at');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'p.completed_at');

        return $query->groupBy('l.id', 'l.title', 'l.sort_order', 'l.category_id', 'c.name')
            ->orderBy('c.name')->orderBy('l.sort_order')->orderBy('l.id')
            ->select(['l.id as lesson_id', 'l.title as lesson_title', 'l.category_id', 'c.name as category_name'])
            ->selectRaw('COUNT(DISTINCT p.student_id) as completed_students')
            ->selectRaw('MAX(p.completed_at) as latest_completion')
            ->paginate(25)->withQueryString();
    }

    private function studentCompletion(array $filters)
    {
        $query = DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->join('users as u', 'u.id', '=', 'p.student_id')
            ->leftJoin('pace_groups as pg', 'pg.code', '=', 'u.pace_group')
            ->where('u.role', 'student')->where('p.is_completed', true)->whereNotNull('p.completed_at');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'p.completed_at');

        return $query->groupBy('u.id', 'u.name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target')
            ->orderBy('u.name')->orderBy('u.id')
            ->select(['u.id as student_id', 'u.name as student_name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target'])
            ->selectRaw('COUNT(DISTINCT p.lesson_id) as completed_lessons')
            ->selectRaw('MAX(p.completed_at) as last_completion')
            ->paginate(25)->withQueryString();
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
        $this->applyCategory($query, $filters, 'scp.category_id');
        $this->applyDates($query, $filters, 'scp.started_at');

        return $query->orderBy('c.sort_order')->orderBy('c.id')->orderBy('u.name')->orderBy('u.id')
            ->select(['scp.id', 'u.id as student_id', 'u.name as student_name', 'u.pseudocode', 'u.pace_group', 'pg.weekly_target', 'c.name as category_name'])
            ->selectRaw('COALESCE(cc.completed_lessons, 0) as completed_lessons')
            ->selectRaw('COALESCE(lt.total_lessons, 0) as total_lessons')
            ->selectRaw('CASE WHEN COALESCE(lt.total_lessons, 0) = 0 THEN 0 ELSE ROUND(COALESCE(cc.completed_lessons, 0) * 100.0 / lt.total_lessons, 1) END as progress_percentage')
            ->addSelect('cc.last_activity')
            ->paginate(25)->withQueryString();
    }

    private function realizations(array $filters)
    {
        $query = DB::table('student_realizations as r')
            ->join('users as u', 'u.id', '=', 'r.student_id')
            ->join('lessons as l', 'l.id', '=', 'r.lesson_id')
            ->join('categories as c', 'c.id', '=', 'l.category_id')
            ->where('u.role', 'student');
        $this->applyCategory($query, $filters, 'l.category_id');
        $this->applyDates($query, $filters, 'r.submitted_at');

        return $query->groupBy('c.id', 'c.name', 'l.id', 'l.title', 'u.id', 'u.name', 'u.pseudocode')
            ->orderBy('c.name')->orderBy('l.title')->orderBy('u.name')->orderBy('u.id')
            ->select(['c.id as category_id', 'c.name as category_name', 'l.id as lesson_id', 'l.title as lesson_title', 'u.id as student_id', 'u.name as student_name', 'u.pseudocode'])
            ->selectRaw('COUNT(r.id) as realization_count')
            ->selectRaw('MAX(r.submitted_at) as latest_submission')
            ->paginate(25)->withQueryString();
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
