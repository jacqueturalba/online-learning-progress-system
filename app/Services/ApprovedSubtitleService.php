<?php

namespace App\Services;

use App\Models\AiOperationUsage;
use App\Models\Lesson;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ApprovedSubtitleService
{
    public function reviewSources(Lesson $lesson): array
    {
        return AiOperationUsage::query()->where('lesson_id', $lesson->id)->whereIn('status', ['succeeded', 'partial'])
            ->whereIn('service', [WhisperxAlignmentService::ALIGNMENT_SERVICE, 'translation'])
            ->orderByDesc('id')->limit(100)->get()->map(function (AiOperationUsage $operation): ?array {
                $result = is_array($operation->result) ? $operation->result : [];
                if ($operation->service === WhisperxAlignmentService::ALIGNMENT_SERVICE) {
                    if (!is_string($result['outputs']['srt'] ?? null)) return null;
                    $language = 'en';
                } else {
                    if (!is_string($result['text'] ?? null) || trim($result['text']) === '') return null;
                    $target = mb_strtolower((string) ($result['target_language'] ?? ''));
                    if ($target !== '' && !in_array($target, ['fil', 'fil-ph', 'filipino'], true)) return null;
                    $language = 'fil';
                }
                return [
                    'operation_id' => $operation->id,
                    'language' => $language,
                    'approval_status' => $result['approval_statuses'][$language] ?? 'pending_review',
                    'target_language' => $result['target_language'] ?? null,
                ];
            })->filter()->values()->all();
    }

    public function listForLesson(Lesson $lesson): array
    {
        return AiOperationUsage::query()
            ->where('lesson_id', $lesson->id)
            ->whereIn('status', ['succeeded', 'partial'])
            ->whereIn('service', [WhisperxAlignmentService::ALIGNMENT_SERVICE, 'translation'])
            ->orderByDesc('id')->get()
            ->flatMap(function (AiOperationUsage $operation): array {
                $result = is_array($operation->result) ? $operation->result : [];
                $entries = [];
                foreach (['en', 'fil'] as $language) {
                    $subtitle = $result['subtitle_versions'][$language] ?? null;
                    if (!is_array($subtitle)) continue;
                    foreach ($subtitle['versions'] ?? [] as $version) {
                        if (!is_array($version) || !is_string($version['version_id'] ?? null)) continue;
                        $entries[] = [
                            'operation_id' => $operation->id,
                            'language' => $language,
                            'version_id' => $version['version_id'],
                            'sha256' => $version['sha256'] ?? null,
                            'approved_at' => $version['approved_at'] ?? null,
                            'is_current' => ($subtitle['current_approved_version_id'] ?? null) === $version['version_id'],
                            'download_url' => route('admin.subtitles.download', [$operation->lesson_id, $language, $operation->id, $version['version_id']]),
                        ];
                    }
                }
                return $entries;
            })->values()->all();
    }

    public function approve(Lesson $lesson, AiOperationUsage $operation, string $language, int $adminId, ?UploadedFile $upload = null, ?string $srtText = null): array
    {
        $this->assertOperationLanguage($lesson, $operation, $language);
        $disk = Storage::disk('local');

        if ($upload) {
            if (strtolower($upload->getClientOriginalExtension()) !== 'srt' || $upload->getSize() > 5 * 1024 * 1024) {
                throw new RuntimeException('Upload an SRT file no larger than 5 MB.');
            }
            $contents = file_get_contents($upload->getRealPath());
        } elseif ($language === 'en' && is_string($srtText)) {
            $contents = $srtText;
        } elseif ($language === 'en') {
            $draftPath = $operation->result['subtitle_draft']['srt_path'] ?? null;
            $path = is_string($draftPath) && $disk->exists($draftPath)
                ? $draftPath
                : ($operation->result['outputs']['srt'] ?? null);
            if (!is_string($path) || !$disk->exists($path)) throw new RuntimeException('The aligned English SRT is unavailable.');
            $contents = $disk->get($path);
            $expectedHash = $path === $draftPath ? ($operation->result['subtitle_draft']['srt_sha256'] ?? null) : null;
            if (is_string($expectedHash) && !hash_equals($expectedHash, hash('sha256', $contents))) throw new RuntimeException('The reviewed English SRT draft failed its integrity check.');
        } else {
            $path = $operation->result['subtitle_draft']['srt_path'] ?? null;
            if (!is_string($path) || !$disk->exists($path)) throw new RuntimeException('A timed Filipino SRT draft is unavailable.');
            $contents = $disk->get($path);
            $expectedHash = $operation->result['subtitle_draft']['srt_sha256'] ?? null;
            if (is_string($expectedHash) && !hash_equals($expectedHash, hash('sha256', $contents))) throw new RuntimeException('The Filipino SRT draft failed its integrity check.');
        }

        if (!is_string($contents) || strlen($contents) > 5 * 1024 * 1024) throw new RuntimeException('The subtitle file is not a valid timed SRT.');
        if (!mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException('The SRT file must use UTF-8 text encoding.');
        $cueService = app(SubtitleSrtCueService::class);
        $parsedCues = $cueService->parse($contents);
        if ($language === 'fil') {
            $translationText = $operation->result['subtitle_draft']['text'] ?? $operation->result['text'] ?? '';
            if (!is_string($translationText) || $this->normalizeText(implode(' ', array_column($parsedCues, 'text'))) !== $this->normalizeText($translationText)) {
                throw new RuntimeException('The Filipino SRT cue text must match the reviewed Filipino translation exactly.');
            }
            $draftCues = $operation->result['subtitle_draft']['cues'] ?? null;
            if (is_array($draftCues)) {
                if (count($draftCues) !== count($parsedCues)) throw new RuntimeException('The Filipino SRT must preserve every reviewed cue.');
                foreach ($draftCues as $index => $cue) {
                    if ((int) ($cue['index'] ?? 0) !== (int) ($parsedCues[$index]['index'] ?? 0)
                        || $this->normalizeText((string) ($cue['text'] ?? '')) !== $this->normalizeText((string) ($parsedCues[$index]['text'] ?? ''))) {
                        throw new RuntimeException('Each Filipino SRT cue must match its corresponding reviewed cue.');
                    }
                }
            }
            if (is_numeric($operation->result['source_alignment_operation_id'] ?? null)
                && is_string($operation->result['source_english_srt_version_id'] ?? null)) {
                $alignment = AiOperationUsage::query()->where('lesson_id', $lesson->id)
                    ->where('service', WhisperxAlignmentService::ALIGNMENT_SERVICE)
                    ->find((int) $operation->result['source_alignment_operation_id']);
                if (!$alignment) throw new RuntimeException('The approved English timing source is unavailable.');
                $sourceVersion = $this->approvedVersion($lesson, $alignment, 'en', $operation->result['source_english_srt_version_id']);
                $sourceContents = $disk->get($sourceVersion['path']);
                $cueService->assertSameTiming($cueService->parse($sourceContents), $cueService->parse($contents));
            }
        }

        $hash = hash('sha256', $contents);
        $result = $operation->result ?? [];
        $existingEntry = $result['subtitle_versions'][$language] ?? [];
        $versions = is_array($existingEntry['versions'] ?? null) ? $existingEntry['versions'] : [];
        foreach ($versions as $existing) {
            if (is_array($existing) && ($existing['sha256'] ?? null) === $hash
                && is_string($existing['path'] ?? null) && $disk->exists($existing['path'])
                && hash_equals($hash, hash('sha256', $disk->get($existing['path'])))) {
                $result['subtitle_versions'][$language]['current_approved_version_id'] = $existing['version_id'];
                $result['approval_statuses'][$language] = 'approved';
                if ($language === 'en') $result['approval_status'] = 'approved';
                $operation->result = $result;
                $operation->save();
                return $existing;
            }
        }

        $versionId = (string) str()->uuid();
        $relativePath = "approved-subtitles/{$lesson->id}/{$operation->id}/{$language}/{$versionId}.srt";
        if (!$disk->put($relativePath, $contents)) throw new RuntimeException('The approved SRT could not be saved privately.');

        $version = [
            'version_id' => $versionId,
            'language' => $language,
            'path' => $relativePath,
            'sha256' => $hash,
            'approved_by' => $adminId,
            'approved_at' => now()->toIso8601String(),
        ];
        $versions[] = $version;
        $result['subtitle_versions'][$language] = [
            'versions' => $versions,
            'current_approved_version_id' => $versionId,
        ];
        $result['approval_statuses'][$language] = 'approved';
        if ($language === 'en') $result['approval_status'] = 'approved';
        $operation->result = $result;
        $operation->save();

        return $version;
    }

    public function approvedVersion(Lesson $lesson, AiOperationUsage $operation, string $language, ?string $versionId = null): array
    {
        $this->assertOperationLanguage($lesson, $operation, $language);
        $entry = $operation->result['subtitle_versions'][$language] ?? null;
        if (!is_array($entry)) throw new RuntimeException('No approved subtitle version is available.');
        $wanted = $versionId ?: ($entry['current_approved_version_id'] ?? null);
        foreach ($entry['versions'] ?? [] as $version) {
            if (is_array($version) && ($version['version_id'] ?? null) === $wanted
                && is_string($version['path'] ?? null) && Storage::disk('local')->exists($version['path'])
                && is_string($version['sha256'] ?? null)
                && hash_equals($version['sha256'], hash('sha256', Storage::disk('local')->get($version['path'])))) {
                return $version;
            }
        }
        throw new RuntimeException('The requested approved subtitle version is unavailable.');
    }

    /** Return the most recently approved current version for student playback. */
    public function currentApprovedVersionForLesson(Lesson $lesson, string $language): ?array
    {
        $service = match ($language) {
            'en' => WhisperxAlignmentService::ALIGNMENT_SERVICE,
            'fil' => 'translation',
            default => null,
        };
        if ($service === null) return null;

        $current = null;
        $currentTimestamp = -1;

        foreach (AiOperationUsage::query()->where('lesson_id', $lesson->id)
            ->where('service', $service)->whereIn('status', ['succeeded', 'partial'])->orderByDesc('id')->cursor() as $operation) {
            $result = is_array($operation->result) ? $operation->result : [];
            $approvalStatus = $result['approval_statuses'][$language]
                ?? ($language === 'en' ? ($result['approval_status'] ?? null) : null);
            if ($approvalStatus !== 'approved') continue;

            try {
                $version = $this->approvedVersion($lesson, $operation, $language);
            } catch (RuntimeException) {
                continue;
            }

            $approvedTimestamp = is_string($version['approved_at'] ?? null)
                ? (strtotime($version['approved_at']) ?: 0)
                : 0;
            if ($approvedTimestamp === 0) $approvedTimestamp = $operation->updated_at?->getTimestamp() ?? 0;

            if ($approvedTimestamp > $currentTimestamp) {
                $current = ['operation' => $operation, 'version' => $version];
                $currentTimestamp = $approvedTimestamp;
            }
        }

        return $current;
    }

    private function assertOperationLanguage(Lesson $lesson, AiOperationUsage $operation, string $language): void
    {
        $expectedService = match ($language) {
            'en' => WhisperxAlignmentService::ALIGNMENT_SERVICE,
            'fil' => 'translation',
            default => null,
        };
        if (!$expectedService || (int) $operation->lesson_id !== (int) $lesson->id
            || $operation->service !== $expectedService
            || !in_array($operation->status, ['succeeded', 'partial'], true)
            || ($operation->status === 'partial' && ($operation->result['review_available'] ?? false) !== true)
            || !is_array($operation->result)) {
            throw new RuntimeException('A successful subtitle source for this lesson and language was not found.');
        }
        if ($language === 'fil' && isset($operation->result['target_language'])
            && !in_array(mb_strtolower((string) $operation->result['target_language']), ['fil', 'fil-ph', 'filipino'], true)) {
            throw new RuntimeException('This translation operation is not a Filipino subtitle version.');
        }
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
