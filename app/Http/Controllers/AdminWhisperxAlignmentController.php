<?php

namespace App\Http\Controllers;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use App\Services\WhisperxAlignmentService;
use App\Services\WhisperxAlignmentReconciler;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class AdminWhisperxAlignmentController extends Controller
{
    public function transcript(Lesson $lesson, int $transcript, WhisperxAlignmentService $alignments)
    {
        try {
            $operation = $alignments->transcriptVersion($lesson, $transcript);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response()->json([
            'transcript_operation_id' => $operation->id,
            'lesson_id' => $operation->lesson_id,
            'status' => $operation->status,
            'language' => $operation->result['transcript_review']['language'] ?? null,
            'review_status' => $operation->result['transcript_review']['status'] ?? 'pending_review',
            'text' => $alignments->currentTranscriptText($operation),
            'approved_text' => $operation->result['transcript_review']['approved_text'] ?? null,
            'approved_at' => $operation->result['transcript_review']['approved_at'] ?? null,
        ], 200, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function approveTranscript(Request $request, Lesson $lesson, int $transcript, WhisperxAlignmentService $alignments)
    {
        $request->validate(['language' => ['required', 'in:en']]);
        try {
            $operation = $alignments->approveEnglishTranscript($lesson, $transcript, (int) $request->user()->id);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['transcript_operation_id' => $exception->getMessage()]);
        }

        return response()->json([
            'transcript_operation_id' => $operation->id,
            'lesson_id' => $operation->lesson_id,
            'language' => 'en',
            'review_status' => 'approved',
            'approved_at' => $operation->result['transcript_review']['approved_at'],
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function queue(Request $request, Lesson $lesson, WhisperxAlignmentService $alignments)
    {
        $data = $request->validate([
            'transcript_operation_id' => ['required', 'integer', 'min:1'],
            'audio_path' => ['nullable', 'string', 'max:1024'],
        ]);

        try {
            $result = $alignments->queueAlignment(
                $lesson,
                (int) $data['transcript_operation_id'],
                $data['audio_path'] ?? null,
                (int) $request->user()->id,
            );
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['alignment' => $exception->getMessage()]);
        }

        return response()->json([
            'operation' => $alignments->safeStatus($result['usage']),
            'already_submitted' => $result['already_submitted'],
        ], $result['already_submitted'] ? 200 : 202, ['Cache-Control' => 'private, no-store']);
    }

    public function show(int $operation, WhisperxAlignmentService $alignments, WhisperxAlignmentReconciler $reconciler)
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->findOrFail($operation);
        $reconciler->reconcile($usage);
        $usage->refresh();
        return response()->json(['operation' => $alignments->safeStatus($usage)], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function srt(int $operation, WhisperxAlignmentService $alignments)
    {
        $usage = AiOperationUsage::query()->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)->findOrFail($operation);
        try {
            $contents = $alignments->srtForAdmin($usage);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return response($contents, Response::HTTP_OK, [
            'Content-Type' => 'application/x-subrip; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="lesson-'.$usage->lesson_id.'-alignment.srt"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
