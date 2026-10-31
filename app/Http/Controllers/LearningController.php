<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudentCategoryProgress;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LearningController extends Controller
{
    public function index(Request $request)
    {
        $studentId = $request->user()->role === 'student' ? $request->user()->id : null;
        $categories = Category::query()->where('is_active', true)->with(['lessons' => function ($query) use ($studentId) {
            $query->where('is_published', true)->with(['progress' => function ($progress) use ($studentId) {
                $progress->when($studentId, fn ($q) => $q->where('student_id', $studentId));
            }]);
        }])->orderBy('sort_order')->orderBy('id')->get()->map(function (Category $category) {
            $completed = $category->lessons->filter(fn ($lesson) => $lesson->progress->contains('is_completed', true))->count();
            return ['id' => $category->id, 'name' => $category->name, 'description' => $category->description, 'completed_lessons' => $completed, 'total_lessons' => $category->lessons->count(), 'progress_percentage' => $category->lessons->count() ? round($completed / $category->lessons->count() * 100, 1) : 0, 'lessons' => $category->lessons->map(function ($lesson) {
                $progress = $lesson->progress->first();
                return ['id' => $lesson->id, 'title' => $lesson->title, 'description' => $lesson->description, 'video_url' => $lesson->video_url, 'duration_seconds' => $lesson->duration_seconds, 'sort_order' => $lesson->sort_order, 'progress' => $progress ? ['last_position_seconds' => $progress->last_position_seconds, 'watched_seconds' => $progress->watched_seconds, 'progress_percentage' => $progress->progress_percentage, 'is_completed' => $progress->is_completed, 'last_watched_at' => $progress->last_watched_at] : null];
            })];
        });
        return response()->json(['categories' => $categories]);
    }

    public function saveProgress(Request $request, Lesson $lesson)
    {
        abort_unless($lesson->is_published && Category::query()->whereKey($lesson->category_id)->where('is_active', true)->exists(), 404);
        $data = $request->validate([
            'position_seconds' => ['required', 'integer', 'min:0'],
            'is_playing' => ['required', 'boolean'],
        ]);
        $duration = $lesson->duration_seconds;
        abort_if(!$duration || $duration < 1, 422, 'Set the lesson duration before recording progress.');
        $position = min($data['position_seconds'], $duration);
        $now = now();

        $progress = DB::transaction(function () use ($request, $lesson, $position, $duration, $data, $now) {
            $progress = StudentLessonProgress::where('student_id', $request->user()->id)
                ->where('lesson_id', $lesson->id)
                ->lockForUpdate()
                ->first();

            if (!$progress) {
                $progress = new StudentLessonProgress(['student_id' => $request->user()->id, 'lesson_id' => $lesson->id]);
            }

            $previousPosition = (int) ($progress->last_position_seconds ?? 0);
            $elapsedSeconds = $progress->last_watched_at
                ? max(0, $now->getTimestamp() - $progress->last_watched_at->getTimestamp())
                : 0;
            $positionAdvance = $position - $previousPosition;

            // Credit only plausible, forward playback observed between server-timed heartbeats.
            // A seek can update the resume pointer, but cannot accrue watch time for the jump.
            if ($progress->is_playing && $positionAdvance >= 0 && $positionAdvance <= ($elapsedSeconds * 2) + 2) {
                $progress->watched_seconds = min($duration, (int) $progress->watched_seconds + min($elapsedSeconds, $positionAdvance, 15));
            }

            $progress->last_position_seconds = $position;
            $progress->progress_percentage = round(min(100, ((int) $progress->watched_seconds / $duration) * 100), 2);
            $progress->started_at ??= $now;
            $progress->last_watched_at = $now;
            $progress->is_playing = $data['is_playing'];

            $complete = $progress->watched_seconds >= (int) ceil($duration * 0.9);
            if ($complete) {
                $progress->is_completed = true;
                $progress->completed_at ??= $now;
            }
            $progress->save();

            StudentCategoryProgress::refreshForStudentCategory($request->user()->id, $lesson->category_id);

            return $progress->fresh();
        });

        return response()->json(['progress' => $progress]);
    }

    public function students()
    {
        return response()->json(['students' => User::where('role', 'student')->select('id', 'name', 'email', 'created_at')->withCount(['lessonProgress as completed_lessons_count' => fn ($q) => $q->where('is_completed', true)])->orderBy('name')->paginate(25)]);
    }


}
