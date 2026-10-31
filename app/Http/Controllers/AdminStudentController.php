<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\PaceGroup;
use App\Models\StudentCategoryProgress;
use App\Models\StudentLessonProgress;
use App\Models\User;
use App\Models\ZoomSession;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminStudentController extends Controller
{
    public function index(Request $request)
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:120'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'pace_group' => ['nullable', Rule::exists('pace_groups', 'code')],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $query = User::query()->where('role', 'student')->with('paceGroup')
            ->with(['categoryProgress' => fn ($progress) => $progress->with('category')->orderByDesc('updated_at')->orderByDesc('id')]);

        if ($search = trim($filters['search'] ?? '')) {
            $query->where(fn (Builder $students) => $students
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('email', 'like', '%'.$search.'%')
                ->orWhere('pseudocode', 'like', '%'.$search.'%'));
        }

        if (isset($filters['category_id'])) {
            $query->whereHas('categoryProgress', fn (Builder $progress) => $progress->where('category_id', $filters['category_id']));
        }

        if (isset($filters['pace_group'])) {
                $query->where('pace_group', $filters['pace_group']);
        }

        $students = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(20)->withQueryString();
        $studentIds = $students->getCollection()->modelKeys();
        $currentCategoryIds = $students->getCollection()->map(fn (User $student) => $student->categoryProgress->first()?->category_id)
            ->filter()->unique()->values();
        $lessonTotals = $currentCategoryIds->isEmpty() ? collect() : Lesson::query()->whereIn('category_id', $currentCategoryIds)
            ->where('is_published', true)->select('category_id')->selectRaw('COUNT(*) as lessons_count')->groupBy('category_id')->pluck('lessons_count', 'category_id');
        $completedCounts = empty($studentIds) || $currentCategoryIds->isEmpty() ? collect() : DB::table('student_lesson_progress as progress')
            ->join('lessons', 'lessons.id', '=', 'progress.lesson_id')
            ->whereIn('progress.student_id', $studentIds)
            ->whereIn('lessons.category_id', $currentCategoryIds)
            ->where('progress.is_completed', true)
            ->select('progress.student_id', 'lessons.category_id')
            ->selectRaw('COUNT(*) as lessons_count')
            ->groupBy('progress.student_id', 'lessons.category_id')
            ->get()->keyBy(fn ($row) => $row->student_id.':'.$row->category_id);

        $students->getCollection()->transform(function (User $student) use ($lessonTotals, $completedCounts): array {
            $categoryProgress = $student->categoryProgress->first();
            $category = $categoryProgress?->category;
            $total = (int) ($lessonTotals[$categoryProgress?->category_id] ?? 0);
            $completed = (int) ($completedCounts[$student->id.':'.$categoryProgress?->category_id]->lessons_count ?? 0);

            return [
                'id' => $student->id,
                'name' => $student->name,
                'email' => $student->email,
                'pseudocode' => $student->pseudocode,
                'pace_group' => $student->pace_group,
                'weekly_target' => $student->paceGroup?->weekly_target ?? 0,
                'is_active' => $student->is_active,
                'registered_at' => $student->created_at,
                'current_category' => $category ? ['id' => $category->id, 'name' => $category->name, 'is_active' => $category->is_active, 'is_archived' => $category->trashed()] : null,
                'progress' => ['completed_lessons' => $completed, 'total_lessons' => $total, 'percentage' => $total ? round($completed / $total * 100, 1) : 0],
            ];
        });

        return response()->json([
            'students' => $students,
            'categories' => Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_active', 'deleted_at']),
            'pace_groups' => app(AdminPaceGroupController::class)->options(),
        ]);
    }

    public function show(User $student)
    {
        abort_unless($student->role === 'student', 404);

        $history = StudentCategoryProgress::query()->where('student_id', $student->id)
            ->with(['category' => fn ($category) => $category->with(['lessons' => fn ($lessons) => $lessons->withTrashed()
                ->where(fn ($query) => $query->where('is_published', true)
                    ->orWhereHas('progress', fn ($progress) => $progress->where('student_id', $student->id)))
                ->with(['progress' => fn ($progress) => $progress->where('student_id', $student->id)])])])
            ->orderByDesc('updated_at')->orderByDesc('id')->get()
            ->map(function (StudentCategoryProgress $progress) use ($student): array {
                $lessons = $progress->category->lessons->map(function (Lesson $lesson): array {
                    $lessonProgress = $lesson->progress->first();
                    return [
                        'id' => $lesson->id,
                        'title' => $lesson->title,
                        'is_published' => $lesson->is_published,
                        'is_completed' => (bool) $lessonProgress?->is_completed,
                        'progress_percentage' => (float) ($lessonProgress?->progress_percentage ?? 0),
                        'last_watched_at' => $lessonProgress?->last_watched_at,
                    ];
                });
                $completed = $lessons->where('is_completed', true)->count();

                return [
                    'id' => $progress->category->id,
                    'name' => $progress->category->name,
                    'is_active' => $progress->category->is_active,
                    'is_archived' => $progress->category->trashed(),
                    'completed_at' => $progress->completed_at,
                    'completed_lessons' => $completed,
                    'total_lessons' => $lessons->count(),
                    'progress_percentage' => $lessons->count() ? round($completed / $lessons->count() * 100, 1) : 0,
                    'lessons' => $lessons->values(),
                ];
            })->values();

        $currentCategory = $history->first();
        $categoryId = $currentCategory['id'] ?? null;
        $zoomAssignments = ZoomSession::query()->where('is_active', true)->where('scheduled_at', '>=', now())
            ->where(function (Builder $query) use ($categoryId): void {
                $query->whereNull('category_id')->whereNull('lesson_id');
                if ($categoryId) {
                    $query->orWhere('category_id', $categoryId)
                        ->orWhereHas('lesson', fn (Builder $lesson) => $lesson->where('category_id', $categoryId));
                }
            })
            ->with(['category:id,name', 'lesson:id,title'])->orderBy('scheduled_at')->get()
            ->map(fn (ZoomSession $session) => [
                'id' => $session->id,
                'title' => $session->title,
                'meeting_url' => $session->meeting_url,
                'scheduled_at' => $session->scheduled_at,
                'category_name' => $session->category?->name,
                'lesson_title' => $session->lesson?->title,
            ]);

        return response()->json([
            'student' => [
                'id' => $student->id,
                'real_name' => $student->name,
                'email' => $student->email,
                'personal_information' => ['real_name' => $student->name, 'email' => $student->email],
                'pseudocode' => $student->pseudocode,
                'pace_group' => $student->pace_group,
                'weekly_target' => $student->paceGroup?->weekly_target ?? 0,
                'is_active' => $student->is_active,
                'registered_at' => $student->created_at,
                'current_category' => $currentCategory,
                'zoom_assignments' => $zoomAssignments,
                'learning_history' => $history,
            ],
            'pace_groups' => app(AdminPaceGroupController::class)->options(),
        ]);
    }

    public function updatePaceGroup(Request $request, User $student)
    {
        abort_unless($student->role === 'student', 404);

        $data = $request->validate(['pace_group' => ['required', Rule::exists('pace_groups', 'code')]]);
        $student->update($data);

        return response()->json(['pace_group' => $student->pace_group, 'weekly_target' => $student->paceGroup()->value('weekly_target')]);
    }
}
