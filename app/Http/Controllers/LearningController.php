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
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

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
        $categories = $categories->map(function (Category $category) use ($studentId) {
            $completed = $category->lessons->filter(fn ($lesson) => $lesson->progress->contains('is_completed', true))->count();
            $lessons = $category->lessons->map(function ($lesson) use ($studentId) {
                $progress = $lesson->progress->first();
                $hasRealization = $lesson->realizations->isNotEmpty();
                $isUnlocked = StudentLessonAccess::allows($studentId, $lesson->id);

                return [
                'id' => $lesson->id,
                    'thumbnail_url' => $lesson->thumbnail_path ? Storage::disk('public')->url($lesson->thumbnail_path) : null,
                    'title' => $lesson->title,
                    'description' => $lesson->description,
                    'video_stream_url' => null,
                    'has_video' => (bool) $lesson->video_path,
                    'duration_seconds' => $lesson->duration_seconds,
                    'sort_order' => $lesson->sort_order,
                    'is_unlocked' => $isUnlocked,
                    'realization_submitted' => $hasRealization,
                    'progress' => $progress ? ['last_position_seconds' => $progress->last_position_seconds, 'watched_seconds' => $progress->watched_seconds, 'progress_percentage' => $progress->progress_percentage, 'is_completed' => $progress->is_completed, 'last_watched_at' => $progress->last_watched_at] : null,
                ];
            });

            return ['id' => $category->id, 'name' => $category->name, 'description' => $category->description, 'thumbnail_url' => $category->thumbnail_path ? Storage::disk('public')->url($category->thumbnail_path) : null, 'background_color' => $category->background_color, 'enable_first_lesson_access' => $category->enable_first_lesson_access, 'unlock_all_lessons' => $category->unlock_all_lessons, 'completed_lessons' => $completed, 'total_lessons' => $lessons->count(), 'progress_percentage' => $lessons->count() ? round($completed / $lessons->count() * 100, 1) : 0, 'lessons' => $lessons];
        });
        $setting = DB::table('student_enrollment_settings')->where('student_id',$request->user()->id)->first() ?: DB::table('enrollment_settings')->where('id', 1)->first();
        $eligible = !$setting || !$setting->enabled;
        if (!$eligible && $setting->category_id && $setting->lesson_number > 0) {
            $required = Category::find($setting->category_id)?->lessons()->where('is_published', true)->orderBy('sort_order')->orderBy('id')->skip($setting->lesson_number - 1)->first();
            $eligible = $required && $request->user()->lessonProgress()->where('lesson_id', $required->id)->where('is_completed', true)->exists();
        }
        $form = $request->user()->enrollmentForms()->latest('revision')->first();
        return response()->json(['categories' => $categories, 'enrollment' => ['eligible'=>(bool)$eligible, 'requirement_enabled'=>(bool)($setting->enabled ?? false), 'category_name'=>Category::find($setting->category_id ?? 0)?->name, 'lesson_number'=>(int)($setting->lesson_number ?? 0), 'status'=>$form?->status ?? 'not_started', 'revision_reason'=>$form?->revision_reason]]);
    }

    public function myZoom(Request $request)
    {
        $student = $request->user();
        $membership = $student->zoomBatchMemberships()->where('status', 'active')->with('zoomBatch')->orderByDesc('id')->first();
        $meetings = collect();
        if ($membership) {
            $session = $membership->zoomBatch->zoomSessions()->whereNotNull('zoom_meeting_id')->orderByDesc('id')->first();
            $assignment = $session ? $student->zoomAssignments()->where('zoom_session_id', $session->id)->first() : null;
            $meetings->push([
                'id' => $session?->id ?? $membership->id,
                'pseudocode' => $student->pseudocode,
                'join_url' => $assignment?->registration_status === 'registered' ? $assignment->zoom_url : null,
                'registered_at' => $assignment?->registered_at,
                'batch_name' => $membership->zoomBatch->name,
                'topic' => $session?->title,
                'scheduled_at' => $session?->scheduled_at,
                'recurrence' => $session?->recurrence_config,
                'registration_status' => $assignment?->registration_status ?? 'pending',
                'registration_error' => $assignment?->registration_error,
            ]);
        }
        return response()->json(['pseudocode' => $student->pseudocode, 'meetings' => $meetings]);
    }

    public function video(Request $request, Lesson $lesson)
    {
        abort_unless($request->user()->role === 'student', 403);
        $this->assertOfficialPlayerRequest($request);
        $playbackToken = (string) $request->query('playback');
        $this->validPlaybackSession($request, $lesson, $playbackToken);
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
            if (preg_match('/^bytes=(\d*)-(\d*)$/', trim($range), $matches) && ($matches[1] !== '' || $matches[2] !== '')) {
                if ($matches[1] === '' && $matches[2] !== '') {
                    $suffix = (int) $matches[2];
                    if ($suffix === 0) return response('', 416, ['Content-Range' => "bytes */{$size}", 'Accept-Ranges' => 'bytes']);
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
            } else {
                return response('', 416, ['Content-Range' => "bytes */{$size}", 'Accept-Ranges' => 'bytes']);
            }
        }
        $length = $end - $start + 1;
        $headers['Content-Length'] = (string) $length;

        $sessionCacheKey = $this->playbackCacheKey($playbackToken);
        return response()->stream(function () use ($path, $start, $length, $sessionCacheKey): void {
            $file = fopen($path, 'rb');
            if ($file === false) return;
            fseek($file, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($file)) {
                $activeSession = Cache::get($sessionCacheKey);
                if (!is_array($activeSession) || ($activeSession['expires_at'] ?? 0) <= now()->timestamp) break;
                $chunk = fread($file, min(1024 * 1024, $remaining));
                if ($chunk === false || $chunk === '') break;
                echo $chunk;
                $remaining -= strlen($chunk);
            }
            fclose($file);
        }, $status, $headers);
    }

    public function startPlayback(Request $request, Lesson $lesson)
    {
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);
        abort_unless($lesson->video_path && Storage::disk('local')->exists($lesson->video_path), 404);

        $token = Str::random(64);
        $expiresAt = now()->addMinutes($this->playbackSessionMinutes());
        Cache::put($this->playbackCacheKey($token), $this->playbackSessionData($request, $lesson, $expiresAt), $expiresAt);

        return response()->json($this->playbackSessionResponse($lesson, $token, $expiresAt));
    }

    public function renewPlayback(Request $request, Lesson $lesson)
    {
        $data = $request->validate(['playback_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/']]);
        $token = $data['playback_token'];
        $this->validPlaybackSession($request, $lesson, $token);
        abort_unless(StudentLessonAccess::allows($request->user()->id, $lesson->id), 404);

        $expiresAt = now()->addMinutes($this->playbackSessionMinutes());
        Cache::put($this->playbackCacheKey($token), $this->playbackSessionData($request, $lesson, $expiresAt), $expiresAt);

        return response()->json($this->playbackSessionResponse($lesson, $token, $expiresAt));
    }

    public function revokePlayback(Request $request, Lesson $lesson)
    {
        $data = $request->validate(['playback_token' => ['required', 'string', 'size:64', 'regex:/^[A-Za-z0-9]+$/']]);
        $this->validPlaybackSession($request, $lesson, $data['playback_token']);
        Cache::forget($this->playbackCacheKey($data['playback_token']));

        return response()->json(['revoked' => true]);
    }

    private function validPlaybackSession(Request $request, Lesson $lesson, string $token): array
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{64}$/', $token), 403);
        $session = Cache::get($this->playbackCacheKey($token));
        abort_unless(is_array($session) && ($session['expires_at'] ?? 0) > now()->timestamp, 403);
        abort_unless(($session['student_id'] ?? null) === $request->user()->id, 403);
        abort_unless(($session['lesson_id'] ?? null) === $lesson->id, 403);
        $sessionHash = hash_hmac('sha256', $request->session()->token(), config('app.key'));
        abort_unless(is_string($session['session_hash'] ?? null) && hash_equals($session['session_hash'], $sessionHash), 403);

        return $session;
    }

    private function assertOfficialPlayerRequest(Request $request): void
    {
        $requestOrigin = $request->getSchemeAndHttpHost();
        $referer = $request->headers->get('referer');
        abort_unless(is_string($referer) && parse_url($referer, PHP_URL_SCHEME) && parse_url($referer, PHP_URL_HOST), 403);
        abort_unless($this->originOf($referer) === $requestOrigin, 403);

        $origin = $request->headers->get('origin');
        if (is_string($origin) && $origin !== '') {
            abort_unless($this->originOf($origin) === $requestOrigin, 403);
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if (is_string($fetchSite) && $fetchSite !== '') {
            abort_unless($fetchSite === 'same-origin', 403);
        }
        abort_unless($request->headers->get('Sec-Fetch-Dest') !== 'document', 403);
    }

    private function originOf(string $url): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($scheme) || !is_string($host)) return null;
        $port = parse_url($url, PHP_URL_PORT);
        return strtolower($scheme).'://'.strtolower($host).($port ? ':'.$port : '');
    }

    private function playbackSessionData(Request $request, Lesson $lesson, $expiresAt): array
    {
        return [
            'student_id' => $request->user()->id,
            'lesson_id' => $lesson->id,
            'session_hash' => hash_hmac('sha256', $request->session()->token(), config('app.key')),
            'expires_at' => $expiresAt->timestamp,
        ];
    }

    private function playbackSessionResponse(Lesson $lesson, string $token, $expiresAt): array
    {
        return [
            'video_url' => route('lessons.video', ['lesson' => $lesson->id, 'playback' => $token], false),
            'expires_at' => $expiresAt->timestamp * 1000,
            'expires_in_seconds' => $this->playbackSessionMinutes() * 60,
            'playback_token' => $token,
        ];
    }

    private function playbackCacheKey(string $token): string
    {
        return 'lesson-playback:'.hash('sha256', $token);
    }

    private function playbackSessionMinutes(): int
    {
        return max(120, (int) config('video.playback_session_minutes', 180));
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

            $complete = $progress->watched_seconds >= (int) ceil($duration * 0.5);
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
