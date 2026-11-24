<?php

namespace App\Http\Controllers;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Services\ApprovedSubtitleService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AdminSubtitleController extends Controller
{
    public function index(Lesson $lesson, ApprovedSubtitleService $subtitles)
    {
        return response()->json([
            'lesson_id' => $lesson->id,
            'subtitles' => $subtitles->listForLesson($lesson),
            'review_sources' => $subtitles->reviewSources($lesson),
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function approve(Request $request, Lesson $lesson, int $operation, string $language, ApprovedSubtitleService $subtitles)
    {
        $request->validate([
            'srt_file' => ['nullable', 'file', 'max:5120'],
            'srt_text' => ['nullable', 'string', 'max:5000000', 'prohibited_with:srt_file'],
        ]);
        $usage = AiOperationUsage::query()->findOrFail($operation);
        try {
            $version = $subtitles->approve($lesson, $usage, $language, (int) $request->user()->id, $request->file('srt_file'), $request->input('srt_text'));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['subtitle' => $exception->getMessage()]);
        }

        return response()->json([
            'lesson_id' => $lesson->id,
            'operation_id' => $usage->id,
            'language' => $language,
            'version_id' => $version['version_id'],
            'sha256' => $version['sha256'],
            'approved_at' => $version['approved_at'],
            'download_url' => route('admin.subtitles.download', [$lesson->id, $language, $usage->id, $version['version_id']]),
        ], 201, ['Cache-Control' => 'private, no-store']);
    }

    public function previewAlignment(int $operation)
    {
        $usage = AiOperationUsage::query()->where('service', 'whisperx_alignment')->findOrFail($operation);
        $path = $usage->result['outputs']['srt'] ?? null;
        if ($usage->status !== 'succeeded' || !is_string($path) || !Storage::disk('local')->exists($path)) {
            abort(404, 'The alignment SRT preview is not available.');
        }
        return response()->json(['operation_id' => $usage->id, 'srt_text' => Storage::disk('local')->get($path)], 200, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function download(Lesson $lesson, string $language, int $operation, string $version, ApprovedSubtitleService $subtitles)
    {
        $usage = AiOperationUsage::query()->where('lesson_id', $lesson->id)->findOrFail($operation);
        try {
            $approved = $subtitles->approvedVersion($lesson, $usage, $language, $version);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response(Storage::disk('local')->get($approved['path']), 200, [
            'Content-Type' => 'application/x-subrip; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lesson-'.$lesson->id.'-'.$language.'.srt"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
