<?php

namespace App\Console\Commands;

use App\Services\FiveMinuteSubtitleRecoveryService;
use Illuminate\Console\Command;
use Throwable;

class RecoverFiveMinuteSubtitleSample extends Command
{
    protected $signature = 'subtitles:recover-five-minute-sample {--dry-run : Validate recovery artifacts and queued-job state without changing the database}';
    protected $description = 'Register existing five-minute English subtitle files as pending-review recovery drafts';

    public function handle(FiveMinuteSubtitleRecoveryService $recovery): int
    {
        if (!app()->environment(['local', 'testing'])) {
            $this->error('This recovery command is available only in local or testing environments.');
            return self::FAILURE;
        }

        try {
            $result = $recovery->recover($this->manifest(), $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            return self::FAILURE;
        }

        if ($result['dry_run']) {
            $this->info('Recovery preflight passed. No database records or queue rows were changed.');
            $this->line('Lesson: '.$result['lesson']->id.' · '.$result['lesson']->title);
            $this->line('Queued operation: '.$result['operation']->id.' ('.$result['operation']->status.')');
            $this->line('Exact queued job: '.($result['cancel_job'] ? 'unreserved, zero attempts; eligible for conditional cancellation' : 'already cancelled by this recovery'));
            $this->line('Existing recovered transcript/alignment: '.($result['existing_transcript_id'] ?? 'will be created').' / '.($result['existing_alignment_id'] ?? 'will be created'));
            $this->line('Transcript SHA-256: '.$result['transcript_sha256']);
            $this->line('SRT SHA-256: '.$result['srt_sha256']);
            $this->line('Historical alignment input association: unverified; both audio hashes will be recorded.');
            return self::SUCCESS;
        }

        $this->info('Existing English subtitle artifacts were registered as pending-review recovery drafts.');
        $this->line('Lesson ID: '.$result['lesson_id']);
        $this->line('Preparation operation: '.$result['preparation_operation_id'].' · '.($result['queue_job_cancelled'] ? 'queued job cancelled' : 'already cancelled'));
        $this->line('Recovered transcript operation: '.$result['transcript_operation_id'].' · approval pending');
        $this->line('Recovered alignment operation: '.$result['alignment_operation_id'].' · English SRT approval pending');
        $this->line('Historical alignment input association: unverified.');
        return self::SUCCESS;
    }

    private function manifest(): array
    {
        $base = 'ai-audio-preparation/a9c435e9-f404-4efb-b930-b621bb2e467a/';
        return [
            'lesson_id' => 4,
            'preparation_operation_id' => 2,
            'queue_job_id' => 1,
            'connection' => 'whisperx',
            'queue' => 'whisperx-alignment',
            'video_path' => 'lesson-videos/WxBBSDY8MOQ6DDywun6Zq4QP4H0dhIhXMH9aavxv.mp4',
            'video_sha256' => 'f095cda58b435cf0a5bf3e4b68d39579dd609c3efcf8fceb758acf6554de1b10',
            'preparation_idempotency_key' => 'audio-prep-4-f095cda58b435cf0a5bf3e4b68d39579',
            'workspace' => $base,
            'expected_word_count' => 586,
            'expected_cue_count' => 47,
            'expected_audio_duration_seconds' => 300.278,
            'files' => [
                'audio' => ['path' => $base.'audio.mp3', 'sha256' => 'eeb3fcdac06ee90913f450a38d0f9237c9e54657d3290ba86124ab6344b1bdc6'],
                'chunk' => ['path' => $base.'chunk-001.mp3', 'sha256' => '5df5f54e7f81a56d1c397a75320e30803da2e66a2fed6be17ae05d491c277381'],
                'transcript' => ['path' => $base.'transcript.txt', 'sha256' => '4278a9eeeeb2a3122bab8b9f9a13835d479f93a50929f3bcb846aaa3a0d6d351'],
                'aligned_json' => ['path' => $base.'alignment-output/aligned.json', 'sha256' => '9a40cd35dedc2c9d1b593149ac53a1575ca0e033411fb3352b679cef2e70b5e4'],
                'aligned_srt' => ['path' => $base.'alignment-output/aligned.srt', 'sha256' => '1f2312d4d54db5d97fad8177869cff5415ff0561aa35e5047cdf8594df68a0a1'],
                'alignment_report' => ['path' => $base.'alignment-output/alignment-report.json', 'sha256' => '3fb85e275fc663a7de5cd3a217c435ad5843c5bf40e42a65f21a1527f9e1b17f'],
                'optimized_json' => ['path' => $base.'alignment-output-optimized/aligned.json', 'sha256' => 'e2fbe295f069785505d77426b0a025e468681f2710cc3262f9e033038f441dee'],
                'optimized_srt' => ['path' => $base.'alignment-output-optimized/aligned.srt', 'sha256' => '1f2312d4d54db5d97fad8177869cff5415ff0561aa35e5047cdf8594df68a0a1'],
                'optimized_report' => ['path' => $base.'alignment-output-optimized/alignment-report.json', 'sha256' => 'b1d1dcc9ba0a154b4f8c4a5edec4f77e60f28f1c71ba3649ce6b86b33033a6f3'],
            ],
        ];
    }
}
