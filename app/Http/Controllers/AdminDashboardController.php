<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentCategoryProgress;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __invoke()
    {
        $activeSince = now()->subDays(30);

        $students = User::query()->where('role', 'student');
        $categoryCounts = DB::table('student_lesson_progress as progress')
            ->join('lessons', 'lessons.id', '=', 'progress.lesson_id')
            ->join('users', 'users.id', '=', 'progress.student_id')
            ->where('users.role', 'student')
            ->select('lessons.category_id', DB::raw('COUNT(DISTINCT progress.student_id) as students_count'))
            ->groupBy('lessons.category_id')
            ->pluck('students_count', 'category_id');

        return response()->json([
            'total_students' => (clone $students)->count(),
            'active_students' => (clone $students)->whereHas('lessonProgress', fn ($query) => $query->where('last_watched_at', '>=', $activeSince))->count(),
            'total_lessons' => Lesson::count(),
            'completed_lessons' => StudentLessonProgress::query()->where('is_completed', true)
                ->whereHas('student', fn ($query) => $query->where('role', 'student'))->count(),
            'transfer_eligible_students' => StudentCategoryProgress::query()->whereNotNull('completed_at')
                ->whereHas('category', fn ($query) => $query->where('is_active', true)->whereNull('deleted_at'))
                ->distinct('student_id')->count('student_id'),
            'students_by_category' => Category::withTrashed()->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'is_active', 'deleted_at'])
                ->map(fn (Category $category) => [
                    'id' => $category->id,
                    'name' => $category->name,
                    'is_active' => $category->is_active,
                    'is_archived' => $category->trashed(),
                    'students_count' => (int) ($categoryCounts[$category->id] ?? 0),
                ]),
            'recent_activity' => StudentLessonProgress::query()
                ->whereNotNull('last_watched_at')
                ->whereHas('student', fn ($query) => $query->where('role', 'student'))
                ->with(['student:id,name', 'lesson:id,title,category_id', 'lesson.category:id,name'])
                ->latest('last_watched_at')
                ->limit(8)
                ->get()
                ->map(fn (StudentLessonProgress $progress) => [
                    'student_name' => $progress->student->name,
                    'lesson_title' => $progress->lesson->title,
                    'category_name' => $progress->lesson->category?->name,
                    'last_watched_at' => $progress->last_watched_at,
                    'is_completed' => $progress->is_completed,
                ]),
        ]);
    }
}
