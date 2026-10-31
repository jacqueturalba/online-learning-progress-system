<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\PaceGroup;
use App\Models\User;
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
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        if (!empty($filters['date_from']) && !empty($filters['date_to']) && $filters['date_to'] < $filters['date_from']) {
            throw ValidationException::withMessages(['date_to' => 'The end date must be on or after the start date.']);
        }

        $summary = $this->filteredRecords($filters)
            ->select('p.student_id')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.started_at IS NOT NULL THEN p.lesson_id END) as lessons_started')
            ->selectRaw('COUNT(DISTINCT CASE WHEN p.is_completed = 1 THEN p.lesson_id END) as lessons_completed')
            ->groupBy('p.student_id');

        $students = User::query()->where('users.role', 'student')
            ->leftJoin('pace_groups', 'pace_groups.code', '=', 'users.pace_group')
            ->leftJoinSub($summary, 'learning_summary', 'users.id', '=', 'learning_summary.student_id')
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
            ->selectSub($this->currentActivity($this->currentRecords(), 'l.title'), 'current_lesson')
            ->selectSub($this->currentActivity($this->currentRecords(), 'c.name'), 'current_category')
            ->selectSub($this->eligibilityQuery(), 'transfer_eligible')
            ->when($this->hasProgressFilters($filters), fn ($query) => $query->whereExists(
                $this->filteredRecords($filters)->whereColumn('p.student_id', 'users.id')->select('p.id'),
            ))
            ->orderBy('users.name')->orderBy('users.id')
            ->paginate(25)->withQueryString();

        $students->getCollection()->transform(function (User $student): array {
            $started = (int) $student->lessons_started;
            $completed = (int) $student->lessons_completed;
            return [
                'id' => $student->id,
                'name' => $student->name,
                'pseudocode' => $student->pseudocode,
                'pace_group' => $student->pace_group,
                'weekly_target' => (int) ($student->weekly_target ?? 0),
                'lessons_started' => $started,
                'lessons_completed' => $completed,
                'completion_percentage' => $started ? round($completed / $started * 100, 1) : 0,
                'current_lesson' => $student->current_lesson,
                'current_category' => $student->current_category,
                'transfer_eligible' => (bool) $student->transfer_eligible,
            ];
        });

        $categories = Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'deleted_at']);
        $lessons = empty($filters['category_id']) ? collect() : Lesson::withTrashed()
            ->where('category_id', $filters['category_id'])->orderBy('sort_order')->orderBy('id')->get(['id', 'category_id', 'title', 'deleted_at']);

        return response()->json([
            'students' => $students,
            'filters' => [
                'categories' => $categories,
                'lessons' => $lessons,
                'pace_groups' => app(AdminPaceGroupController::class)->options(),
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
        return DB::table('student_lesson_progress as p')
            ->join('lessons as l', 'l.id', '=', 'p.lesson_id')
            ->leftJoin('categories as c', 'c.id', '=', 'l.category_id')
            ->when($filters['category_id'] ?? null, fn ($query, $id) => $query->where('l.category_id', $id))
            ->when($filters['lesson_id'] ?? null, fn ($query, $id) => $query->where('l.id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->where('p.last_watched_at', '>=', $date.' 00:00:00'))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->where('p.last_watched_at', '<', Carbon::parse($date)->addDay()->startOfDay()));
    }

    private function currentActivity(Builder $query, string $column): Builder
    {
        return $query->whereColumn('p.student_id', 'users.id')->limit(1)->selectRaw($column);
    }

    private function eligibilityQuery(): Builder
    {
        $incompletePublishedLesson = DB::table('lessons as eligible_lessons')
            ->whereColumn('eligible_lessons.category_id', 'eligible_categories.id')
            ->where('eligible_lessons.is_published', true)->whereNull('eligible_lessons.deleted_at')
            ->whereNotExists(DB::table('student_lesson_progress as completion')
                ->whereColumn('completion.lesson_id', 'eligible_lessons.id')
                ->whereColumn('completion.student_id', 'users.id')
                ->where('completion.is_completed', true));

        return DB::table('categories as eligible_categories')
            ->where('eligible_categories.is_active', true)->whereNull('eligible_categories.deleted_at')
            ->whereExists(DB::table('lessons as published_lessons')
                ->whereColumn('published_lessons.category_id', 'eligible_categories.id')
                ->where('published_lessons.is_published', true)->whereNull('published_lessons.deleted_at'))
            ->whereNotExists($incompletePublishedLesson)
            ->limit(1)->select('eligible_categories.id');
    }

    private function hasProgressFilters(array $filters): bool
    {
        return !empty($filters['category_id']) || !empty($filters['lesson_id']) || !empty($filters['date_from']) || !empty($filters['date_to']);
    }
}
