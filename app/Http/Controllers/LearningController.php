<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\User;
use Illuminate\Http\Request;

class LearningController extends Controller
{
    public function index(Request $request)
    {
        $studentId = $request->user()->role === 'student' ? $request->user()->id : null;
        $categories = Category::query()->where('is_active', true)->with(['lessons' => function ($query) use ($studentId) {
            $query->where('is_published', true)->with(['progress' => function ($progress) use ($studentId) {
                $progress->when($studentId, fn ($q) => $q->where('student_id', $studentId));
            }]);
        }])->orderBy('sort_order')->get()->map(function (Category $category) {
            $completed = $category->lessons->filter(fn ($lesson) => $lesson->progress->contains('is_completed', true))->count();
            return ['id' => $category->id, 'name' => $category->name, 'description' => $category->description, 'completed_lessons' => $completed, 'total_lessons' => $category->lessons->count(), 'progress_percentage' => $category->lessons->count() ? round($completed / $category->lessons->count() * 100, 1) : 0, 'lessons' => $category->lessons->map(function ($lesson) {
                $progress = $lesson->progress->first();
                return ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'video_url' => $lesson->video_url, 'duration_seconds' => $lesson->duration_seconds, 'sort_order' => $lesson->sort_order, 'progress' => $progress ? ['last_position_seconds' => $progress->last_position_seconds, 'progress_percentage' => $progress->progress_percentage, 'is_completed' => $progress->is_completed, 'last_watched_at' => $progress->last_watched_at] : null];
            })];
        });
        return response()->json(['categories' => $categories]);
    }

    public function saveProgress(Request $request, Lesson $lesson)
    {
        abort_unless($lesson->is_published, 404);
        $data = $request->validate(['position_seconds' => ['required', 'integer', 'min:0']]);
        $duration = $lesson->duration_seconds;
        abort_if(!$duration, 422, 'Set the lesson duration before recording progress.');
        $position = min($data['position_seconds'], $duration);
        $percentage = round(min(100, $position / $duration * 100), 2);
        $complete = $percentage >= 90;
        $now = now();
        $progress = StudentLessonProgress::firstOrNew(['student_id' => $request->user()->id, 'lesson_id' => $lesson->id]);
        $progress->fill(['last_position_seconds' => $position, 'progress_percentage' => $percentage, 'started_at' => $progress->started_at ?? $now, 'last_watched_at' => $now, 'is_completed' => $progress->is_completed || $complete, 'completed_at' => $progress->completed_at ?? ($complete ? $now : null)]);
        $progress->save();
        $categoryId = $lesson->category_id;
        $categoryProgress = \App\Models\StudentCategoryProgress::firstOrNew(['student_id' => $request->user()->id, 'category_id' => $categoryId]);
        $categoryProgress->started_at ??= $now;
        $publishedCount = Lesson::where('category_id', $categoryId)->where('is_published', true)->count();
        $completedCount = StudentLessonProgress::where('student_id', $request->user()->id)->whereHas('lesson', fn ($q) => $q->where('category_id', $categoryId)->where('is_published', true))->where('is_completed', true)->count();
        if ($publishedCount > 0 && $completedCount === $publishedCount) $categoryProgress->completed_at ??= $now;
        $categoryProgress->save();
        return response()->json(['progress' => $progress->fresh()]);
    }

    public function students()
    {
        return response()->json(['students' => User::where('role', 'student')->select('id', 'name', 'email', 'created_at')->withCount(['lessonProgress as completed_lessons_count' => fn ($q) => $q->where('is_completed', true)])->orderBy('name')->paginate(25)]);
    }

    public function adminProgress()
    {
        return response()->json(['progress' => StudentLessonProgress::with(['student:id,name,email', 'lesson:id,title,category_id', 'lesson.category:id,name'])->latest('last_watched_at')->paginate(50)]);
    }
}
