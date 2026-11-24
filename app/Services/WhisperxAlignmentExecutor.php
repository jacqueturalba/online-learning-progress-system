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

    public function align(int $lessonId, int $operationId, string $audioRelativePath, string $transcript, float $timelineOffsetSeconds): array
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

            $exitCode = $this->processes->run($processArguments, (int) config('whisperx.timeout', 7200));
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
