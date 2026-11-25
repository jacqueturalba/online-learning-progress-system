<?php

namespace App\Http\Controllers;

use App\Models\Lesson;
use App\Services\ApprovedSubtitleService;
use App\Services\SubtitleSrtCueService;
use App\Support\StudentLessonAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class StudentSubtitleController extends Controller
{
    private const LANGUAGES = ['en' => 'English', 'fil' => 'Filipino'];

    public function index(Request $request, Lesson $lesson, ApprovedSubtitleService $subtitles)
    {
        $this->authorizeLesson($request, $lesson);
        $tracks = [];

        foreach (self::LANGUAGES as $language => $label) {
            if ($subtitles->currentApprovedVersionForLesson($lesson, $language) === null) continue;
            $tracks[] = [
                'language' => $language,
                'label' => $label,
                'url' => route('lessons.subtitles.track', [$lesson->id, $language], false),
            ];
        }

        return response()->json(['lesson_id' => $lesson->id, 'tracks' => $tracks], 200, [
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function track(
        Request $request,
        Lesson $lesson,
        string $language,
        ApprovedSubtitleService $subtitles,
        SubtitleSrtCueService $cues,
    ) {
        $this->authorizeLesson($request, $lesson);
        abort_unless(array_key_exists($language, self::LANGUAGES), 404);

        $approved = $subtitles->currentApprovedVersionForLesson($lesson, $language);
        abort_unless($approved !== null, 404);

        $path = $approved['version']['path'] ?? null;
        abort_unless(is_string($path) && Storage::disk('local')->exists($path), 404);

        try {
            $parsed = $cues->parse(Storage::disk('local')->get($path));
        } catch (RuntimeException) {
            abort(404, 'The approved subtitle track is unavailable.');
        }

        $webVtt = $this->renderWebVtt($parsed);

        return response($webVtt, 200, [
            'Content-Type' => 'text/vtt; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="lesson-'.$lesson->id.'-'.$language.'.vtt"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
            'Cross-Origin-Resource-Policy' => 'same-origin',
        ]);
    }

    private function authorizeLesson(Request $request, Lesson $lesson): void
    {
        abort_unless($request->user()?->role === 'student', 403);
        abort_unless(StudentLessonAccess::allows((int) $request->user()->id, (int) $lesson->id), 404);
    }

    private function renderWebVtt(array $cues): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($cues as $cue) {
            $lines[] = str_replace(',', '.', $cue['start']).' --> '.str_replace(',', '.', $cue['end']);
            $lines[] = htmlspecialchars($cue['text'], ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
