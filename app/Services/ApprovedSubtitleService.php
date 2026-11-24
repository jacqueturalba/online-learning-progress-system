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
        return AiOperationUsage::query()->where('lesson_id', $lesson->id)->where('status', 'succeeded')
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
            ->where('status', 'succeeded')
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
            $path = $operation->result['outputs']['srt'] ?? null;
            if (!is_string($path) || !$disk->exists($path)) throw new RuntimeException('The aligned English SRT is unavailable.');
            $contents = $disk->get($path);
        } else {
            throw new RuntimeException('A timed Filipino SRT file is required for approval.');
        }

        if (!is_string($contents) || strlen($contents) > 5 * 1024 * 1024 || !$this->isSrt($contents)) {
            throw new RuntimeException('The subtitle file is not a valid timed SRT.');
        }
        if (!mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException('The SRT file must use UTF-8 text encoding.');
        if ($language === 'fil') {
            $translationText = $operation->result['subtitle_draft']['text'] ?? $operation->result['text'] ?? '';
            if (!is_string($translationText) || $this->normalizeText($this->srtCueText($contents)) !== $this->normalizeText($translationText)) {
                throw new RuntimeException('The Filipino SRT cue text must match the reviewed Filipino translation exactly.');
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

    private function assertOperationLanguage(Lesson $lesson, AiOperationUsage $operation, string $language): void
    {
        $expectedService = match ($language) {
            'en' => WhisperxAlignmentService::ALIGNMENT_SERVICE,
            'fil' => 'translation',
            default => null,
        };
        if (!$expectedService || (int) $operation->lesson_id !== (int) $lesson->id
            || $operation->service !== $expectedService || $operation->status !== 'succeeded'
            || !is_array($operation->result)) {
            throw new RuntimeException('A successful subtitle source for this lesson and language was not found.');
        }
        if ($language === 'fil' && isset($operation->result['target_language'])
            && !in_array(mb_strtolower((string) $operation->result['target_language']), ['fil', 'fil-ph', 'filipino'], true)) {
            throw new RuntimeException('This translation operation is not a Filipino subtitle version.');
        }
    }

    private function isSrt(string $contents): bool
    {
        return preg_match('/\d{2}:\d{2}:\d{2},\d{3}\s+-->\s+\d{2}:\d{2}:\d{2},\d{3}/', $contents) === 1;
    }

    private function srtCueText(string $contents): string
    {
        $lines = preg_split('/\R/u', $contents) ?: [];
        $textLines = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || ctype_digit($line) || preg_match('/^\d{2}:\d{2}:\d{2},\d{3}\s+-->/', $line)) continue;
            $textLines[] = $line;
        }
        return implode(' ', $textLines);
    }

    private function normalizeText(string $text): string
    {
        return trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    }
}
