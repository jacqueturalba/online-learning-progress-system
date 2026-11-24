<?php

namespace App\Services;

use App\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class LessonAudioPreparationService
{
    private const DISK = 'local';
    /** Prepare one private lesson video. No provider or network calls are made. */
    public function prepare(Lesson $lesson): array
    {
        $disk = Storage::disk(self::DISK);
        $videoPath = $lesson->video_path;
        if (!is_string($videoPath) || $videoPath === '' || !$disk->exists($videoPath)) {
            throw new LessonAudioPreparationException('The lesson does not have an accessible private video.');
        }

        $source = realpath($disk->path($videoPath));
        $privateRoot = realpath(config('filesystems.disks.local.root'));
        if (!$source || !$privateRoot || !str_starts_with($source, rtrim($privateRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) || !is_file($source) || !is_readable($source)) {
            throw new LessonAudioPreparationException('The lesson video is outside the private storage directory or cannot be read.');
        }

        $ffmpeg = (string) config('ai_media.ffmpeg_binary');
        $ffprobe = (string) config('ai_media.ffprobe_binary');
        $timeout = max(30, (int) config('ai_media.process_timeout_seconds', 3600));
        $this->assertExecutable($ffmpeg, 'FFmpeg', $timeout);
        $this->assertExecutable($ffprobe, 'ffprobe', $timeout);

        $probe = $this->run([$ffprobe, '-v', 'error', '-show_entries', 'format=duration:stream=codec_type,duration,start_time', '-of', 'json', $source], $timeout, 'Could not inspect the lesson video.');
        try {
            $metadata = json_decode($probe->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new LessonAudioPreparationException('ffprobe returned invalid video metadata.');
        }
        $audioStream = collect($metadata['streams'] ?? [])->first(fn ($stream) => ($stream['codec_type'] ?? null) === 'audio');
        $streamDuration = (float) ($audioStream['duration'] ?? 0);
        $duration = $streamDuration > 0 ? $streamDuration : (float) ($metadata['format']['duration'] ?? 0);
        $timelineStart = (float) ($audioStream['start_time'] ?? 0);
        if (!is_finite($duration) || $duration <= 0) {
            throw new LessonAudioPreparationException('The lesson video has no valid audio duration.');
        }

        $relativeRoot = trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\');
        if ($relativeRoot === '' || str_contains($relativeRoot, '..')) {
            throw new LessonAudioPreparationException('The configured private temporary directory is invalid.');
        }
        $workspace = $relativeRoot.'/'.str()->uuid();
        $workspaceAbsolute = $disk->path($workspace);
        if (!@mkdir($workspaceAbsolute, 0700, true) && !is_dir($workspaceAbsolute)) {
            throw new LessonAudioPreparationException('Could not create the private temporary audio directory.');
        }

        try {
            $bitrate = $this->bitrateBits((string) config('ai_media.audio_bitrate', '64k'));
            // Keep the hard ceiling below the provider upload limit even if a local env value is misconfigured.
            $maxBytes = min(20 * 1024 * 1024, (int) config('ai_media.max_chunk_bytes', 20 * 1024 * 1024));
            $overlap = min(max(0, (float) config('ai_media.overlap_seconds', 1.5)), 10.0);
            if ($maxBytes < 1024 * 1024 || $bitrate <= 0) {
                throw new LessonAudioPreparationException('Audio chunk settings must allow files of at least 1 MB and a valid bitrate.');
            }

            $estimatedAudioBytes = (int) ceil($duration * $bitrate / 8);
            $freeBytes = @disk_free_space($workspaceAbsolute);
            if ($freeBytes !== false && $freeBytes < ($estimatedAudioBytes * 2 + 16 * 1024 * 1024)) {
                throw new LessonAudioPreparationException('There is not enough free disk space for temporary audio preparation.');
            }

            $audioAbsolute = $workspaceAbsolute.DIRECTORY_SEPARATOR.'audio.mp3';
            $this->run([$ffmpeg, '-nostdin', '-hide_banner', '-v', 'error', '-y', '-i', $source, '-map', '0:a:0', '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', (string) config('ai_media.audio_bitrate', '64k'), $audioAbsolute], $timeout, 'FFmpeg could not extract audio from this video. It may be invalid or contain no audio.');
            if (!is_file($audioAbsolute) || filesize($audioAbsolute) <= 0) {
                throw new LessonAudioPreparationException('FFmpeg did not produce a usable audio file.');
            }

            // Leave headroom below the configured cap to accommodate container/encoding variance.
            $chunkDuration = max(10.0, ($maxBytes * 8 / $bitrate) * 0.8);
            $overlap = min($overlap, $chunkDuration / 5);
            $chunks = [];
            while (true) {
                $this->removeChunks($workspaceAbsolute);
                $chunks = $this->writeChunks($ffmpeg, $audioAbsolute, $workspaceAbsolute, $relativeRoot, $duration, $timelineStart, $chunkDuration, $overlap, $maxBytes, $timeout);
                if ($chunks !== false) break;
                $chunkDuration /= 2;
                if ($chunkDuration < 10) {
                    throw new LessonAudioPreparationException('FFmpeg could not create audio chunks below the configured size limit.');
                }
            }

            return [
                'workspace' => $workspace,
                'duration_seconds' => $duration,
                'timeline_start_seconds' => $timelineStart,
                'audio_path' => $workspace.'/audio.mp3',
                'chunks' => $chunks,
            ];
        } catch (Throwable $exception) {
            $this->cleanup(['workspace' => $workspace]);
            if ($exception instanceof LessonAudioPreparationException) throw $exception;
            throw new LessonAudioPreparationException('Audio preparation failed. Temporary files were removed.', previous: $exception);
        }
    }

    /** Remove all temporary artifacts generated for a preparation result. */
    public function cleanup(array $preparation): void
    {
        $workspace = $preparation['workspace'] ?? null;
        if (!is_string($workspace) || str_contains($workspace, '..') || str_starts_with($workspace, '/') || str_contains($workspace, '\\')) return;
        $base = trim((string) config('ai_media.temp_directory', 'ai-audio-preparation'), '/\\');
        if (!str_starts_with($workspace, $base.'/') || !preg_match('/^[0-9a-f-]{36}$/i', substr($workspace, strlen($base) + 1))) return;

        $disk = Storage::disk(self::DISK);
        $absolute = $disk->path($workspace);
        if (!is_dir($absolute)) return;
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($absolute, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($absolute);
    }

    private function writeChunks(string $ffmpeg, string $audio, string $workspace, string $relativeRoot, float $total, float $timelineStart, float $chunkSeconds, float $overlap, int $maxBytes, int $timeout): array|false
    {
        $chunks = [];
        $offset = 0.0;
        $index = 1;
        while ($offset < $total) {
            $length = min($chunkSeconds, $total - $offset);
            $relative = sprintf('chunk-%03d.mp3', $index);
            $output = $workspace.DIRECTORY_SEPARATOR.$relative;
            $this->run([$ffmpeg, '-nostdin', '-hide_banner', '-v', 'error', '-y', '-ss', number_format($offset, 3, '.', ''), '-i', $audio, '-t', number_format($length, 3, '.', ''), '-vn', '-ac', '1', '-ar', '16000', '-c:a', 'libmp3lame', '-b:a', (string) config('ai_media.audio_bitrate', '64k'), $output], $timeout, 'FFmpeg failed while splitting lesson audio.');
            $size = is_file($output) ? filesize($output) : 0;
            if (!$size || $size > $maxBytes) return false;
            $chunks[] = [
                'path' => $relativeRoot.'/'.basename(dirname($output)).'/'.$relative,
                'offset_seconds' => round($timelineStart + $offset, 3),
                'duration_seconds' => round(min($length, max(0, $total - $offset)), 3),
                'overlap_before_seconds' => $index === 1 ? 0.0 : round(min($overlap, $offset), 3),
                'size_bytes' => $size,
            ];
            if ($offset + $length >= $total) break;
            $offset += max(1.0, $length - $overlap);
            $index++;
        }
        return $chunks;
    }

    private function removeChunks(string $workspace): void
    {
        foreach (glob($workspace.DIRECTORY_SEPARATOR.'chunk-*.mp3') ?: [] as $file) @unlink($file);
    }

    private function assertExecutable(string $binary, string $label, int $timeout): void
    {
        if ($binary === '') throw new LessonAudioPreparationException("{$label} executable is not configured.");
        try {
            $this->run([$binary, '-version'], min($timeout, 30), "{$label} is unavailable. Check its configured executable path.");
        } catch (LessonAudioPreparationException $exception) {
            throw $exception;
        }
    }

    private function run(array $arguments, int $timeout, string $message): Process
    {
        try {
            $process = new Process($arguments);
            $process->setTimeout($timeout);
            $process->run();
        } catch (Throwable $exception) {
            throw new LessonAudioPreparationException($message.' Verify the executable path and system permissions.', previous: $exception);
        }
        if (!$process->isSuccessful()) {
            $details = trim($process->getErrorOutput());
            $suffix = $details === '' ? '' : ' '.mb_substr($details, 0, 300);
            throw new LessonAudioPreparationException($message.$suffix);
        }
        return $process;
    }

    private function bitrateBits(string $bitrate): int
    {
        if (!preg_match('/^(\d+(?:\.\d+)?)(k|m)?$/i', trim($bitrate), $matches)) return 0;
        $multiplier = match (strtolower($matches[2] ?? '')) { 'k' => 1000, 'm' => 1000000, default => 1 };
        return (int) ((float) $matches[1] * $multiplier);
    }
}
