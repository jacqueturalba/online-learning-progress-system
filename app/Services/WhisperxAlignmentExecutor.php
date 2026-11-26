<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\ExecutableFinder;
use Throwable;

class WhisperxAlignmentExecutor
{
    private const OUTPUT_FILES = ['aligned.json', 'aligned.srt', 'alignment-report.json'];

    public function __construct(private readonly WhisperxProcessRunner $processes) {}

    public function preparedAudioHash(string $relativePath): string
    {
        $path = $this->resolvePreparedAudio($relativePath);
        $hash = hash_file('sha256', $path);
        if (!is_string($hash)) throw new WhisperxAlignmentException('AudioUnavailable', 'The prepared private audio could not be fingerprinted.');
        return $hash;
    }

    public function preparedAudioPath(string $relativePath): string
    {
        return $this->resolvePreparedAudio($relativePath);
    }

    /** Run the separate bounded-window runner with timestamp anchors supplied by the source transcript. */
    public function alignBounded(int $lessonId, int $operationId, string $audioRelativePath, string $transcript, array $sourceSegments, float $timelineOffsetSeconds, ?callable $onHeartbeat = null): array
    {
        $audioPath = $this->resolvePreparedAudio($audioRelativePath);
        if (trim($transcript) === '') throw new WhisperxAlignmentException('TranscriptUnavailable', 'The approved transcript is empty.');
        if (!is_finite($timelineOffsetSeconds) || $timelineOffsetSeconds < 0) throw new WhisperxAlignmentException('InvalidTimelineOffset', 'The transcript has an invalid lesson timeline offset.');
        try { $plan = app(BoundedWindowPlanner::class)->plan($transcript, $sourceSegments); }
        catch (\Throwable $exception) { throw new WhisperxAlignmentException('BoundedAnchorsUnavailable', 'Timestamped transcript segments cannot be reconciled safely; standard WhisperX fallback is required.', $exception); }

        $disk = Storage::disk('local');
        $directory = "whisperx-alignments/{$lessonId}/{$operationId}";
        $disk->makeDirectory($directory);
        $transcriptPath = $directory.'/input-transcript.txt';
        $mapPath = $directory.'/window-map.json';
        $paths = array_map(fn (string $file): string => $directory.'/'.$file, self::OUTPUT_FILES);
        foreach ([$transcriptPath, $mapPath, ...$paths] as $path) $disk->delete($path);
        if (!$disk->put($transcriptPath, $transcript)
            || !$disk->put($mapPath, json_encode($plan, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n")) {
            throw new WhisperxAlignmentException('InputWriteFailed', 'Bounded alignment inputs could not be saved to private storage.');
        }

        $succeeded = false;
        try {
            $python = $this->resolvePythonBinary((string) config('whisperx.python_binary'));
            $runner = base_path('tools/whisperx-aligner/align_bounded_windows.py');
            if (!is_file($runner) || !is_readable($runner)) throw new WhisperxAlignmentException('RunnerUnavailable', 'The bounded WhisperX runner is unavailable.');
            $arguments = [$python, $runner, '--audio', $audioPath, '--transcript', $disk->path($transcriptPath), '--window-map', $disk->path($mapPath),
                '--output', $disk->path($directory), '--language', 'en', '--device', (string) config('whisperx.device', 'cpu')];
            $exit = $this->processes->run($arguments, (int) config('whisperx.process_timeout', config('whisperx.timeout', 7200)), $onHeartbeat);
            if ($exit !== 0) throw new WhisperxAlignmentException('BoundedWindowFailed', 'Bounded WhisperX did not complete every anchored window.');
            foreach ($paths as $path) if (!is_file($disk->path($path)) || filesize($disk->path($path)) <= 0) throw new WhisperxAlignmentException('OutputMissing', 'Bounded WhisperX did not produce all review files.');
            $aligned = json_decode($disk->get($paths[0]), true, flags: JSON_THROW_ON_ERROR);
            $report = json_decode($disk->get($paths[2]), true, flags: JSON_THROW_ON_ERROR);
            $expected = preg_split('/\s+/u', trim($transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $actual = array_column($aligned['words'] ?? [], 'text');
            if ($actual !== $expected || (int) ($report['total_words'] ?? -1) !== count($expected)) throw new WhisperxAlignmentException('OutputInvalid', 'Bounded alignment changed the approved transcript tokens or order.');
            if (($report['missing_or_unreliable_words'] ?? 1) > 0 || ($report['window_errors'] ?? []) !== []
                || ($report['timestamp_order_violations'] ?? []) !== []) {
                throw new WhisperxAlignmentException('BoundedWindowIncomplete', 'Bounded alignment left words untimed; standard WhisperX fallback is required.');
            }
            $srt = $disk->get($paths[1]);
            if (!str_contains($srt, '-->')) throw new WhisperxAlignmentException('OutputInvalid', 'Bounded WhisperX produced no timed SRT cues.');
            $report['processing_method'] = SubtitleProcessingMethod::BOUNDED;
            $report['effective_method'] = SubtitleProcessingMethod::BOUNDED;
            $report['window_plan'] = ['anchor_source' => $plan['anchor_source'], 'segment_count' => $plan['segment_count'], 'window_count' => count($plan['windows'])];
            $this->applyLessonTimelineOffset($aligned, $report, $srt, $timelineOffsetSeconds, $paths);
            $succeeded = true;
            return ['outputs' => ['aligned_json' => $paths[0], 'srt' => $paths[1], 'report' => $paths[2], 'window_map' => $mapPath],
                'total_words' => (int) $report['total_words'], 'successfully_aligned_words' => (int) $report['successfully_aligned_words'],
                'missing_or_unreliable_words' => (int) $report['missing_or_unreliable_words'], 'audio_duration_seconds' => $report['audio_duration_seconds'] ?? null,
                'low_score_word_count' => (int) ($report['low_score_word_count'] ?? 0),
                'timeline_offset_seconds' => $timelineOffsetSeconds, 'processing_method' => SubtitleProcessingMethod::BOUNDED,
                'effective_method' => SubtitleProcessingMethod::BOUNDED];
        } finally {
            $disk->delete($transcriptPath);
            if (!$succeeded) $disk->delete([$mapPath, ...$paths]);
        }
    }

    /** Save Whisper-1 timings in the same review artifacts used by the WhisperX paths. */
    public function saveWhisper1Words(int $lessonId, int $operationId, string $transcript, float $timelineOffsetSeconds, float $duration, array $mapping, string $rawResponse, array $apiUsage, ?string $requestReference, int $requestDurationMs, array $overlapInfo = []): array
    {
        if (!is_finite($duration) || $duration <= 0 || !is_finite($timelineOffsetSeconds) || $timelineOffsetSeconds < 0) {
            throw new WhisperxAlignmentException('InvalidTimingResponse', 'Whisper-1 returned an invalid audio duration or timeline offset.');
        }
        $disk = Storage::disk('local');
        $directory = "whisperx-alignments/{$lessonId}/{$operationId}";
        $disk->makeDirectory($directory);
        $paths = array_map(fn (string $file): string => $directory.'/'.$file, self::OUTPUT_FILES);
        $rawPath = $directory.'/whisper1-response.json';
        $words = $mapping['words'];
        $aligned = ['language' => 'en', 'audio_duration_seconds' => round($duration, 3), 'words' => $words];
        [$srt, $omitted] = $this->makeSrt($words);
        $report = [
            'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'effective_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'model_used' => 'whisper-1', 'audio_duration_seconds' => round($duration, 3),
            'request_duration_ms' => $requestDurationMs, 'api_usage' => $apiUsage,
            'request_reference' => $requestReference, 'total_words' => $mapping['total_words'],
            'api_word_count' => $mapping['api_word_count'], 'successfully_aligned_words' => $mapping['successfully_aligned_words'],
            'missing_or_unreliable_words' => $mapping['missing_or_unreliable_words'],
            'missing_words' => $mapping['missing_words'], 'added_api_words' => $mapping['added_api_words'],
            'ambiguous_words' => $mapping['ambiguous_words'], 'omitted_srt_words' => $omitted,
            'timestamp_order_violations' => $mapping['timestamp_order_violations'],
            'timestamps_ordered' => $mapping['timestamps_ordered'],
            'timestamps_complete' => $mapping['missing_or_unreliable_words'] === 0 && $mapping['added_api_words'] === []
                && $mapping['ambiguous_words'] === [] && ($mapping['timestamps_ordered'] ?? true) === true,
            'overlap_duplicate_word_count' => (int) ($overlapInfo['duplicate_word_count'] ?? 0),
            'chunk_overlap' => $overlapInfo,
            'warnings' => array_values(array_filter([
                isset($overlapInfo['warning']) ? $overlapInfo['warning'] : null,
                $mapping['missing_or_unreliable_words'] > 0 ? 'Some API words have missing or invalid timestamps and were omitted from timed SRT cues.' : null,
                ($mapping['timestamps_ordered'] ?? true) !== true ? 'Whisper-1 word timestamps were out of order.' : null,
            ])),
            'errors' => [], 'timeline' => 'Relative to the supplied audio chunk before Laravel offset application.',
        ];
        if (!$disk->put($paths[0], json_encode($aligned, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n")
            || !$disk->put($paths[1], $srt) || !$disk->put($paths[2], json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n")
            || !$disk->put($rawPath, $rawResponse)) {
            throw new WhisperxAlignmentException('OutputWriteFailed', 'Whisper-1 review artifacts could not be saved to private storage.');
        }
        $this->applyLessonTimelineOffset($aligned, $report, $srt, $timelineOffsetSeconds, $paths);
        return ['outputs' => ['aligned_json' => $paths[0], 'srt' => $paths[1], 'report' => $paths[2], 'raw_response' => $rawPath],
            'total_words' => $mapping['total_words'], 'successfully_aligned_words' => $mapping['successfully_aligned_words'],
            'missing_or_unreliable_words' => $mapping['missing_or_unreliable_words'], 'audio_duration_seconds' => round($duration, 3),
            'timestamps_ordered' => $mapping['timestamps_ordered'] ?? true,
            'timestamp_order_violations' => $mapping['timestamp_order_violations'] ?? [],
            'timeline_offset_seconds' => $timelineOffsetSeconds, 'processing_method' => SubtitleProcessingMethod::OPENAI_WHISPER1,
            'effective_method' => SubtitleProcessingMethod::OPENAI_WHISPER1, 'api_usage' => $apiUsage,
            'api_word_count' => $mapping['api_word_count'], 'missing_words' => $mapping['missing_words'],
            'added_api_words' => $mapping['added_api_words'], 'ambiguous_words' => $mapping['ambiguous_words'],
            'overlap_duplicate_word_count' => (int) ($overlapInfo['duplicate_word_count'] ?? 0), 'chunk_overlap' => $overlapInfo,
            'low_score_word_count' => 0,
            'request_reference' => $requestReference,
            'timestamps_complete' => $mapping['missing_or_unreliable_words'] === 0 && $mapping['added_api_words'] === []
                && $mapping['ambiguous_words'] === [] && ($mapping['timestamps_ordered'] ?? true) === true];
    }

    private function makeSrt(array $words): array
    {
        $cues = []; $group = []; $length = 0; $omitted = 0;
        $flush = function () use (&$cues, &$group, &$length, &$omitted): void {
            if ($group === []) return;
            $visible = array_values(array_filter($group, fn (array $word): bool => ($word['status'] ?? null) !== 'overlap_duplicate'
                && is_numeric($word['start'] ?? null) && is_numeric($word['end'] ?? null) && $word['end'] > $word['start']));
            $omitted += count($group) - count($visible);
            if ($visible !== []) {
                $start = min(array_column($visible, 'start')); $end = max(array_column($visible, 'end'));
                $cues[] = (count($cues) + 1)."\n".$this->formatSrtTimestamp($start).' --> '.$this->formatSrtTimestamp($end)."\n".implode(' ', array_column($visible, 'text'))."\n";
            }
            $group = []; $length = 0;
        };
        $previousEnd = null;
        foreach ($words as $word) {
            $token = (string) ($word['text'] ?? ''); $start = $word['start'] ?? null;
            $gap = is_numeric($start) && $previousEnd !== null && (float) $start - $previousEnd > 1.25;
            if ($group && ($length + mb_strlen($token) + 1 > 42 || $gap)) $flush();
            $group[] = $word; $length += mb_strlen($token) + 1;
            if (is_numeric($word['end'] ?? null)) $previousEnd = (float) $word['end'];
        }
        $flush();
        return [implode("\n", $cues), $omitted];
    }

    public function align(int $lessonId, int $operationId, string $audioRelativePath, string $transcript, float $timelineOffsetSeconds, ?callable $onHeartbeat = null): array
    {
        $audioPath = $this->resolvePreparedAudio($audioRelativePath);
        if (trim($transcript) === '') throw new WhisperxAlignmentException('TranscriptUnavailable', 'The approved transcript is empty.');
        if (!is_finite($timelineOffsetSeconds) || $timelineOffsetSeconds < 0) {
            throw new WhisperxAlignmentException('InvalidTimelineOffset', 'The transcript has an invalid lesson timeline offset.');
        }

        $disk = Storage::disk('local');
        $outputDirectory = "whisperx-alignments/{$lessonId}/{$operationId}";
        $disk->makeDirectory($outputDirectory);
        $transcriptPath = $outputDirectory.'/input-transcript.txt';
        $paths = array_map(fn (string $file): string => $outputDirectory.'/'.$file, self::OUTPUT_FILES);
        foreach ([$transcriptPath, ...$paths] as $path) $disk->delete($path);

        if (!$disk->put($transcriptPath, $transcript)) {
            throw new WhisperxAlignmentException('TranscriptWriteFailed', 'The private transcript working file could not be created.');
        }

        $succeeded = false;
        try {
            $python = $this->resolvePythonBinary((string) config('whisperx.python_binary'));
            $runner = $this->resolveRunnerPath((string) config('whisperx.script_path'));
            $outputAbsolute = $disk->path($outputDirectory);
            $processArguments = [
                $python,
                $runner,
                '--audio', $audioPath,
                '--transcript', $disk->path($transcriptPath),
                '--output', $outputAbsolute,
                '--language', 'en',
                '--device', (string) config('whisperx.device', 'cpu'),
            ];

            $exitCode = $this->processes->run($processArguments, (int) config('whisperx.process_timeout', config('whisperx.timeout', 7200)), $onHeartbeat);
            if ($exitCode !== 0) throw new WhisperxAlignmentException('ProcessFailed', 'WhisperX exited with a non-zero status.');

            foreach ($paths as $path) {
                $absolute = $disk->path($path);
                if (!is_file($absolute) || !is_readable($absolute) || filesize($absolute) <= 0) {
                    throw new WhisperxAlignmentException('OutputMissing', 'WhisperX did not produce all required review files.');
                }
            }

            try {
                $aligned = json_decode($disk->get($paths[0]), true, flags: JSON_THROW_ON_ERROR);
                $report = json_decode($disk->get($paths[2]), true, flags: JSON_THROW_ON_ERROR);
            } catch (Throwable $exception) {
                throw new WhisperxAlignmentException('OutputInvalid', 'WhisperX produced invalid JSON output.', $exception);
            }

            $expectedWords = preg_split('/\s+/u', trim($transcript), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $actualWords = array_column(is_array($aligned['words'] ?? null) ? $aligned['words'] : [], 'text');
            if (!$expectedWords || $expectedWords !== $actualWords
                || (int) ($report['total_words'] ?? -1) !== count($expectedWords)
                || !is_array($report['errors'] ?? null) || $report['errors'] !== []) {
                throw new WhisperxAlignmentException('OutputInvalid', 'WhisperX output failed transcript or report validation.');
            }

            $srt = $disk->get($paths[1]);
            if (!str_contains($srt, '-->')) throw new WhisperxAlignmentException('OutputInvalid', 'WhisperX SRT output contains no timed cues.');

            $this->applyLessonTimelineOffset($aligned, $report, $srt, $timelineOffsetSeconds, $paths);
            $succeeded = true;

            return [
                'outputs' => [
                    'aligned_json' => $paths[0],
                    'srt' => $paths[1],
                    'report' => $paths[2],
                ],
                'total_words' => (int) $report['total_words'],
                'successfully_aligned_words' => (int) ($report['successfully_aligned_words'] ?? 0),
                'missing_or_unreliable_words' => (int) ($report['missing_or_unreliable_words'] ?? 0),
                'low_score_word_count' => count(array_filter($aligned['words'] ?? [], fn ($word): bool => is_array($word) && ($word['status'] ?? null) === 'review_low_score')),
                'audio_duration_seconds' => $report['audio_duration_seconds'] ?? null,
                'timeline_offset_seconds' => $timelineOffsetSeconds,
            ];
        } finally {
            $disk->delete($transcriptPath);
            if (!$succeeded) $disk->delete($paths);
        }
    }

    private function resolvePreparedAudio(string $relativePath): string
    {
        if ($relativePath === '' || str_contains($relativePath, "\0") || str_contains($relativePath, '\\')
            || str_starts_with($relativePath, '/') || preg_match('/^[A-Za-z]:/', $relativePath)
            || in_array('..', explode('/', $relativePath), true)) {
            throw new WhisperxAlignmentException('AudioUnavailable', 'The prepared private audio path is invalid.');
        }

        $disk = Storage::disk('local');
        $privateRoot = realpath($disk->path(''));
        $tempDirectory = trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\');
        $preparedRoot = realpath($disk->path($tempDirectory));
        $audioPath = realpath($disk->path($relativePath));
        if (!$privateRoot || !$preparedRoot || !$audioPath || !is_file($audioPath) || !is_readable($audioPath)
            || !in_array(strtolower(pathinfo($audioPath, PATHINFO_EXTENSION)), ['mp3', 'wav'], true)
            || !$this->isWithin($preparedRoot, $privateRoot) || !$this->isWithin($audioPath, $preparedRoot)) {
            throw new WhisperxAlignmentException('AudioUnavailable', 'The prepared private audio file is unavailable.');
        }
        return $audioPath;
    }

    private function isWithin(string $path, string $root): bool
    {
        $prefix = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (DIRECTORY_SEPARATOR === '\\') return str_starts_with(strtolower($path), strtolower($prefix));
        return str_starts_with($path, $prefix);
    }

    private function resolvePythonBinary(string $configured): string
    {
        if ($configured === '') throw new WhisperxAlignmentException('PythonUnavailable', 'The Python executable is not configured.');
        $isPath = str_contains($configured, '/') || str_contains($configured, '\\') || preg_match('/^[A-Za-z]:/', $configured);
        if ($isPath) {
            if (!is_file($configured) || (DIRECTORY_SEPARATOR !== '\\' && !is_executable($configured))) {
                throw new WhisperxAlignmentException('PythonUnavailable', 'The configured Python executable is unavailable.');
            }
            return $configured;
        }
        $resolved = (new ExecutableFinder())->find($configured);
        if (!$resolved) throw new WhisperxAlignmentException('PythonUnavailable', 'The configured Python executable is unavailable.');
        return $resolved;
    }

    private function resolveRunnerPath(string $configured): string
    {
        if ($configured === '') throw new WhisperxAlignmentException('RunnerUnavailable', 'The WhisperX runner is not configured.');
        $isPath = str_starts_with($configured, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $configured);
        $path = $isPath ? $configured : base_path($configured);
        if (!is_file($path) || !is_readable($path)) throw new WhisperxAlignmentException('RunnerUnavailable', 'The configured WhisperX runner is unavailable.');
        return $path;
    }

    private function applyLessonTimelineOffset(array $aligned, array $report, string $srt, float $offset, array $paths): void
    {
        foreach ($aligned['words'] as &$word) {
            foreach (['start', 'end'] as $field) {
                if (isset($word[$field]) && is_numeric($word[$field])) $word[$field] = round((float) $word[$field] + $offset, 3);
            }
        }
        unset($word);
        $aligned['timeline_offset_seconds'] = $offset;
        $aligned['timestamp_scope'] = 'lesson';
        $report['timeline_offset_seconds'] = $offset;
        $report['timestamp_scope'] = 'lesson';
        $report['timeline'] = 'Laravel applied the stored chunk offset exactly once; timestamps are on the lesson timeline.';

        $shiftedSrt = preg_replace_callback(
            '/^(\d{2}:\d{2}:\d{2},\d{3}) --> (\d{2}:\d{2}:\d{2},\d{3})$/m',
            fn (array $match): string => $this->formatSrtTimestamp($this->parseSrtTimestamp($match[1]) + $offset)
                .' --> '.$this->formatSrtTimestamp($this->parseSrtTimestamp($match[2]) + $offset),
            $srt,
        );
        if (!is_string($shiftedSrt)) throw new WhisperxAlignmentException('OutputInvalid', 'The SRT timestamps could not be shifted to the lesson timeline.');
        $disk = Storage::disk('local');
        if (!$disk->put($paths[0], json_encode($aligned, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n")
            || !$disk->put($paths[1], $shiftedSrt)
            || !$disk->put($paths[2], json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)."\n")) {
            throw new WhisperxAlignmentException('OutputWriteFailed', 'Aligned review files could not be saved to private storage.');
        }
    }

    private function parseSrtTimestamp(string $value): float
    {
        [$hours, $minutes, $seconds] = array_map('intval', explode(':', str_replace(',', ':', $value)));
        return ($hours * 3600) + ($minutes * 60) + $seconds + ((int) substr($value, -3) / 1000);
    }

    private function formatSrtTimestamp(float $seconds): string
    {
        $milliseconds = max(0, (int) round($seconds * 1000));
        $hours = intdiv($milliseconds, 3_600_000);
        $milliseconds %= 3_600_000;
        $minutes = intdiv($milliseconds, 60_000);
        $milliseconds %= 60_000;
        $wholeSeconds = intdiv($milliseconds, 1000);
        $milliseconds %= 1000;
        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $wholeSeconds, $milliseconds);
    }
}
