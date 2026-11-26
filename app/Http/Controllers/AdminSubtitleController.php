<?php

namespace App\Http\Controllers;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Services\ApprovedSubtitleService;
use App\Services\SubtitleSrtCueService;
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
            'srt_text' => ['nullable', 'string', 'max:5000000', 'prohibits:srt_file'],
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
        $draftPath = $usage->result['subtitle_draft']['srt_path'] ?? null;
        $path = is_string($draftPath) && Storage::disk('local')->exists($draftPath)
            ? $draftPath
            : ($usage->result['outputs']['srt'] ?? null);
        if (!in_array($usage->status, ['succeeded', 'partial'], true)
            || ($usage->status === 'partial' && ($usage->result['review_available'] ?? false) !== true)
            || !is_string($path) || !Storage::disk('local')->exists($path)) {
            abort(404, 'The alignment SRT preview is not available.');
        }
        $contents = Storage::disk('local')->get($path);
        $expectedHash = $usage->result['subtitle_draft']['srt_sha256'] ?? null;
        if ($path === $draftPath && is_string($expectedHash) && !hash_equals($expectedHash, hash('sha256', $contents))) {
            abort(404, 'The reviewed English SRT draft failed its integrity check.');
        }
        return response()->json(['operation_id' => $usage->id, 'srt_text' => $contents, 'is_draft' => $path === $draftPath], 200, [
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function saveAlignmentDraft(Request $request, Lesson $lesson, int $operation, SubtitleSrtCueService $cueService)
    {
        $data = $request->validate(['srt_text' => ['required', 'string', 'max:5000000']]);
        $usage = AiOperationUsage::query()->where('lesson_id', $lesson->id)
            ->where('service', 'whisperx_alignment')->whereIn('status', ['succeeded', 'partial'])->findOrFail($operation);
        if ($usage->status === 'partial' && ($usage->result['review_available'] ?? false) !== true) abort(404);
        $result = is_array($usage->result) ? $usage->result : [];
        $sourcePath = $result['outputs']['srt'] ?? null;
        if (!is_string($sourcePath) || !Storage::disk('local')->exists($sourcePath)) {
            throw ValidationException::withMessages(['srt_text' => 'The original aligned SRT is unavailable.']);
        }

        $contents = $data['srt_text'];
        if (strlen($contents) > 5 * 1024 * 1024 || !mb_check_encoding($contents, 'UTF-8')) {
            throw ValidationException::withMessages(['srt_text' => 'The reviewed SRT must be UTF-8 text no larger than 5 MB.']);
        }
        try {
            $cueService->assertSameTiming($cueService->parse(Storage::disk('local')->get($sourcePath)), $cueService->parse($contents));
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['srt_text' => $exception->getMessage()]);
        }

        $path = "subtitle-drafts/{$lesson->id}/{$usage->id}/en/reviewed.srt";
        if (!Storage::disk('local')->put($path, $contents)) {
            throw ValidationException::withMessages(['srt_text' => 'The reviewed English SRT draft could not be saved privately.']);
        }
        $result['subtitle_draft'] = [
            'srt_path' => $path,
            'srt_sha256' => hash('sha256', $contents),
            'saved_at' => now()->toIso8601String(),
        ];
        $usage->result = $result;
        $usage->save();

        return response()->json(['operation_id' => $usage->id, 'saved_at' => $result['subtitle_draft']['saved_at']], 200, [
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
