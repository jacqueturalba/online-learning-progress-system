<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Lesson;
use App\Models\StudentLessonProgress;
use App\Models\StudentCategoryProgress;
use App\Models\User;
use App\Support\StudentLessonAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class LearningController extends Controller
{
    public function index(Request $request)
    {
        $studentId = $request->user()->id;
        $categories = Category::query()->where('is_active', true)->with(['lessons' => function ($query) use ($studentId) {
            $query->where('is_published', true)->with([
                'progress' => fn ($progress) => $progress->where('student_id', $studentId),
                'realizations' => fn ($realizations) => $realizations->where('student_id', $studentId),
            ]);
        }])->orderBy('sort_order')->orderBy('id')->get();
        $canAccessNext = true;

        $categories = $categories->map(function (Category $category) use (&$canAccessNext) {
            $completed = $category->lessons->filter(fn ($lesson) => $lesson->progress->contains('is_completed', true))->count();
            $lessons = $category->lessons->map(function ($lesson) use (&$canAccessNext) {
                $progress = $lesson->progress->first();
                $hasRealization = $lesson->realizations->isNotEmpty();
                $isUnlocked = $canAccessNext;
                $canAccessNext = $canAccessNext && (bool) $progress?->is_completed && $hasRealization;

                return [
                    'id' => $lesson->id,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'video_stream_url' => $lesson->video_path && $isUnlocked ? route('lessons.video', $lesson) : null,
                    'duration_seconds' => $lesson->duration_seconds,
                    'sort_order' => $lesson->sort_order,
                    'is_unlocked' => $isUnlocked,
                    'realization_submitted' => $hasRealization,
                    'progress' => $progress ? ['last_position_seconds' => $progress->last_position_seconds, 'watched_seconds' => $progress->watched_seconds, 'progress_percentage' => $progress->progress_percentage, 'is_completed' => $progress->is_completed, 'last_watched_at' => $progress->last_watched_at] : null,
                ];
            });

            return ['id' => $category->id, 'name' => $category->name, 'description' => $category->description, 'completed_lessons' => $completed, 'total_lessons' => $lessons->count(), 'progress_percentage' => $lessons->count() ? round($completed / $lessons->count() * 100, 1) : 0, 'lessons' => $lessons];
        });
        return response()->json(['categories' => $categories]);
    }

    public function video(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->role === 'student', 403);
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);
        abort_unless($lesson->video_path && Storage::disk('local')->exists($lesson->video_path), 404);

        $path = Storage::disk('local')->path($lesson->video_path);
        $size = filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $headers = [
            'Content-Type' => Storage::disk('local')->mimeType($lesson->video_path) ?: 'application/octet-stream',
            'Accept-Ranges' => 'bytes',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline; filename="lesson-video"',
        ];
        if ($range = $request->header('Range')) {
            if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches)) {
                if ($matches[1] === '' && $matches[2] !== '') {
                    $suffix = (int) $matches[2];
                    $start = max(0, $size - $suffix);
                } else {
                    $start = (int) $matches[1];
                    if ($matches[2] !== '') $end = min($end, (int) $matches[2]);
                }
                if ($start >= $size || $end < $start) {
                    return response('', 416, ['Content-Range' => "bytes */{$size}", 'Accept-Ranges' => 'bytes']);
                }
                $status = 206;
                $headers['Content-Range'] = "bytes {$start}-{$end}/{$size}";
            }
        }
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        return response()->stream(function () use ($path, $start, $length): void {
            $file = fopen($path, 'rb');
            if ($file === false) return;
            fseek($file, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($file)) {
                $chunk = fread($file, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($file);
        }, $status, $headers);
    }

    public function saveProgress(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);
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
