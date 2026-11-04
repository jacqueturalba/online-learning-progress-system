<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\PaceGroup;
use App\Models\User;
use App\Support\TransferEligibility;
use App\Support\HistoricalBatchScope;
use App\Models\ZoomBatch;
use App\Services\ReportXlsxExporter;
use Carbon\Carbon;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminProgressController extends Controller
{
    public function __invoke(Request $request)
    {
        $filters = $request->validate([
            'student' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'lesson_id' => ['nullable', 'integer', 'exists:lessons,id'],
            'pace_group' => ['nullable', Rule::exists('pace_groups', 'code')],
            'batch_id' => ['nullable', 'string', 'regex:/^(none|[1-9][0-9]*)$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
            'format' => ['nullable', 'in:xlsx'],
        ]);
        if (!empty($filters['date_from']) && !empty($filters['date_to']) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        $summary = $this->filteredRecords($filters)
            ->select('p.student_id', 'report_batch_membership.zoom_batch_id as batch_id', 'report_batch.name as batch_name')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.started_at IS NOT NULL THEN p.lesson_id END) as lessons_started')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.is_completed = 1 THEN p.lesson_id END) as lessons_completed')
            ->groupBy('p.student_id', 'report_batch_membership.zoom_batch_id', 'report_batch.name');

        $students = User::query()->where('users.role', 'student')
            ->leftJoin('pace_groups', 'pace_groups.code', '=', 'users.pace_group')
            ->leftJoinSub($summary, 'learning_summary', 'users.id', '=', 'learning_summary.student_id')
            ->leftJoin('zoom_batch_memberships as active_batch_membership', fn ($join) => $join->on('active_batch_membership.student_id', '=', 'users.id')->where('active_batch_membership.status', 'active'))
            ->leftJoin('zoom_batches as active_batch', 'active_batch.id', '=', 'active_batch_membership.zoom_batch_id')
            ->when(trim($filters['student'] ?? '') !== '', function ($query) use ($filters) {
                $term = trim($filters['student']);
                $query->where(fn ($students) => $students->where('users.name', 'like', $term.'%')
                    ->orWhere('users.email', 'like', $term.'%')
                    ->orWhere('users.pseudocode', '=', $term)
                    ->orWhere('users.pseudocode', 'like', $term.'%'));
            })
            ->when($filters['pace_group'] ?? null, fn ($query, $pace) => $query->where('users.pace_group', $pace))
            ->select(['users.id', 'users.name', 'users.pseudocode', 'users.pace_group', 'pace_groups.weekly_target'])
            ->selectRaw('COALESCE(learning_summary.lessons_started, 0) as lessons_started')
            ->selectRaw('COALESCE(learning_summary.lessons_completed, 0) as lessons_completed')
            ->selectRaw("CASE WHEN learning_summary.student_id IS NULL THEN active_batch_membership.zoom_batch_id ELSE learning_summary.batch_id END as batch_id")
            ->selectRaw("CASE WHEN learning_summary.student_id IS NULL THEN COALESCE(active_batch.name, 'No batch assigned') ELSE COALESCE(learning_summary.batch_name, 'No batch assigned') END as batch_name")
            ->selectSub($this->currentActivity($this->currentRecords(), 'l.title'), 'current_lesson')
            ->selectSub($this->currentActivity($this->currentRecords(), 'c.name'), 'current_category')
            ->selectSub($this->eligibilityQuery(), 'transfer_eligible')
            ->when($this->hasProgressFilters($filters), fn ($query) => $query->whereExists(
                $this->filteredRecords($filters)->whereColumn('p.student_id', 'users.id')->select('p.id'),
            ))
            ->orderBy('users.name')->orderBy('users.id')->orderBy('learning_summary.batch_id');

        if (($filters['format'] ?? null) === 'xlsx') {
            $all = $students->get()->map(fn (User $student) => $this->studentRow($student));
            return app(ReportXlsxExporter::class)->download('student-progress.xlsx', 'Student Progress', [
                ['key' => 'batch_name', 'label' => 'Batch'], ['key' => 'batch_id', 'label' => 'Batch ID', 'type' => 'number'],
                ['key' => 'name', 'label' => 'Student'], ['key' => 'pseudocode', 'label' => 'Pseudocode'],
                ['key' => 'pace_group', 'label' => 'Pace group'], ['key' => 'lessons_started', 'label' => 'Started', 'type' => 'number'],
                ['key' => 'lessons_completed', 'label' => 'Completed', 'type' => 'number'], ['key' => 'completion_percentage', 'label' => 'Completion percent', 'type' => 'number'],
                ['key' => 'current_lesson', 'label' => 'Current lesson'], ['key' => 'current_category', 'label' => 'Current category'],
                ['key' => 'transfer_eligible', 'label' => 'Transfer eligible'],
            ], $all, ['Student' => $filters['student'] ?? 'All', 'Batch ID' => $filters['batch_id'] ?? 'All', 'Category ID' => $filters['category_id'] ?? 'All', 'Lesson ID' => $filters['lesson_id'] ?? 'All', 'Pace group' => $filters['pace_group'] ?? 'All', 'From' => $filters['date_from'] ?? 'All', 'To' => $filters['date_to'] ?? 'All']);
        }

        $students = $students->paginate(25)->withQueryString();

        $students->getCollection()->transform(fn (User $student) => $this->studentRow($student));

        $categories = Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']);
        $lessons = empty($filters['category_id']) ? collect() : Lesson::withTrashed()
            ->where('category_id', $filters['category_id'])->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'title', 'deleted_at']);

        return response()->json([
            'students' => $students,
            'filters' => [
                'categories' => $categories,
                'lessons' => $lessons,
                'pace_groups' => app(AdminPaceGroupController::class)->options(),
                'batches' => ZoomBatch::withTrashed()->orderBy('name')->get(['id', 'name', 'deleted_at']),
            ],
        ]);
    }

    private function filteredRecords(array $filters): Builder
    {
        return $this->recordQuery($filters);
    }

    private function currentRecords(): Builder
    {
        return $this->recordQuery([])->whereNotNull('p.last_watched_at')
            ->orderByDesc('p.last_watched_at')->orderByDesc('p.id');
    }

    private function recordQuery(array $filters): Builder
    {
        $query = DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->leftJoin('categories as c', 'c.id', '=', 'l.category_id')
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('l.category_id', $id))
            ->when($filters['lesson_id'] ?? null, fn ($query, $id) => $query->where('l.id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('p.last_watched_at', '>=', $date.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('p.last_watched_at', '<', Carbon::parse($date)->addDay()->startOfDay()));
        HistoricalBatchScope::join($query, 'p.student_id', 'COALESCE(p.last_watched_at, p.started_at)');
        HistoricalBatchScope::filter($query, $filters['batch_id'] ?? null);
        return $query;
    }

    private function currentActivity(Builder $query, string $column): Builder
    {
        return $query->whereColumn('p.student_id', 'users.id')->limit(1)->selectRaw($column);
    }

    private function eligibilityQuery(): Builder
    {
        return TransferEligibility::eligibleCategoriesFor('users.id')
            ->limit(1)->select('eligible_categories.id');
    }

    private function hasProgressFilters(array $filters): bool
    {
        return !empty($filters['category_id']) || !empty($filters['lesson_id']) || !empty($filters['date_from']) || !empty($filters['date_to']) || !empty($filters['batch_id']);
    }

    private function studentRow(User $student): array
    {
        $started = (int) $student->lessons_started;
        $completed = (int) $student->lessons_completed;
        return ['id' => $student->id, 'name' => $student->name, 'pseudocode' => $student->pseudocode,
            'batch_id' => $student->batch_id, 'batch_name' => $student->batch_name,
            'pace_group' => $student->pace_group, 'weekly_target' => (int) ($student->weekly_target ?? 0),
            'lessons_started' => $started, 'lessons_completed' => $completed,
            'completion_percentage' => $started ? round($completed / $started * 100, 1) : 0,
            'current_lesson' => $student->current_lesson, 'current_category' => $student->current_category,
            'transfer_eligible' => (bool) $student->transfer_eligible];
    }
}
