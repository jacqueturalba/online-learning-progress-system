<?php

use App\Jobs\PrepareLessonAudioForSubtitles;
use App\Models\AiOperationUsage;
use App\Models\Category;
use App\Models\Lesson;
use App\Models\User;
use App\Services\FiveMinuteSubtitleRecoveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function fiveMinuteRecoveryFixture(): array
{
    Storage::fake('local');
    $category = Category::create(['name' => 'Recovery '.str()->uuid(), 'sort_order' => 1, 'is_active' => true]);
    $videoPath = 'lesson-videos/recovery-fixture.mp4';
    $video = 'unchanged test video bytes';
    Storage::disk('local')->put($videoPath, $video);
    $lesson = Lesson::create(['category_id' => $category->id, 'title' => 'Recovered sample', 'sort_order' => 1,
        'duration_seconds' => 2, 'video_path' => $videoPath]);
    $videoHash = hash('sha256', $video);
    $preparation = AiOperationUsage::query()->create([
        'service' => 'audio_preparation', 'model' => 'ffmpeg-local', 'lesson_id' => $lesson->id,
        'status' => 'queued', 'idempotency_key' => 'audio-prep-'.$lesson->id.'-'.substr($videoHash, 0, 32),
        'request_fingerprint' => hash('sha256', 'prep request'), 'result' => ['video_sha256' => $videoHash],
    ]);

    $workspace = 'ai-audio-preparation/recovery-fixture';
    $transcript = "Hello world!";
    $words = [
        ['text' => 'Hello', 'start' => 0.2, 'end' => 0.7, 'score' => 0.8, 'status' => 'aligned'],
        ['text' => 'world!', 'start' => 0.8, 'end' => 1.5, 'score' => 0.9, 'status' => 'aligned'],
    ];
    $optimizedWords = $words;
    $optimizedWords[0]['score'] = 0.81;
    $srt = "1\n00:00:00,200 --> 00:00:01,500\nHello world!\n";
    $report = json_encode(['total_words' => 2, 'successfully_aligned_words' => 2, 'missing_or_unreliable_words' => 0,
        'alignment_duration_seconds' => 1.0, 'audio_duration_seconds' => 2.0, 'processing_device' => 'cpu',
        'model_used' => 'WAV2VEC2_ASR_BASE_960H', 'warnings' => [], 'errors' => [],
        'timeline' => 'Relative to the supplied audio chunk; no original timeline offset is applied.'], JSON_THROW_ON_ERROR);
    $optimizedReportData = json_decode($report, true, flags: JSON_THROW_ON_ERROR);
    $optimizedReportData['alignment_duration_seconds'] = 1.1;
    $files = [
        'audio' => ['path' => $workspace.'/audio.mp3', 'contents' => 'audio source bytes'],
        'chunk' => ['path' => $workspace.'/chunk-001.mp3', 'contents' => 'different chunk bytes'],
        'transcript' => ['path' => $workspace.'/transcript.txt', 'contents' => $transcript],
        'aligned_json' => ['path' => $workspace.'/alignment-output/aligned.json', 'contents' => json_encode(['language' => 'en', 'audio_duration_seconds' => 2.0, 'words' => $words], JSON_THROW_ON_ERROR)],
        'aligned_srt' => ['path' => $workspace.'/alignment-output/aligned.srt', 'contents' => $srt],
        'alignment_report' => ['path' => $workspace.'/alignment-output/alignment-report.json', 'contents' => $report],
        'optimized_json' => ['path' => $workspace.'/alignment-output-optimized/aligned.json', 'contents' => json_encode(['language' => 'en', 'audio_duration_seconds' => 2.0, 'words' => $optimizedWords], JSON_THROW_ON_ERROR)],
        'optimized_srt' => ['path' => $workspace.'/alignment-output-optimized/aligned.srt', 'contents' => $srt],
        'optimized_report' => ['path' => $workspace.'/alignment-output-optimized/alignment-report.json', 'contents' => json_encode($optimizedReportData, JSON_THROW_ON_ERROR)],
    ];
    $fileManifest = [];
    foreach ($files as $key => $file) {
        Storage::disk('local')->put($file['path'], $file['contents']);
        $fileManifest[$key] = ['path' => $file['path'], 'sha256' => hash('sha256', $file['contents'])];
    }

    $job = (new PrepareLessonAudioForSubtitles($preparation->id))
        ->onConnection('whisperx')->onQueue('whisperx-alignment')->afterCommit();
    $payload = json_encode([
        'uuid' => (string) str()->uuid(),
        'displayName' => PrepareLessonAudioForSubtitles::class,
        'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
        'data' => ['commandName' => PrepareLessonAudioForSubtitles::class, 'command' => serialize($job)],
    ], JSON_THROW_ON_ERROR);
    $now = now()->timestamp;
    $jobRowId = DB::table('jobs')->insertGetId([
        'queue' => 'whisperx-alignment', 'payload' => $payload, 'attempts' => 0,
        'reserved_at' => null, 'available_at' => $now, 'created_at' => $now,
    ]);

    return [[
        'lesson_id' => $lesson->id, 'preparation_operation_id' => $preparation->id, 'queue_job_id' => $jobRowId,
        'connection' => 'whisperx', 'queue' => 'whisperx-alignment', 'video_path' => $videoPath,
        'video_sha256' => $videoHash, 'preparation_idempotency_key' => $preparation->idempotency_key,
        'workspace' => $workspace, 'expected_word_count' => 2, 'expected_cue_count' => 1,
        'expected_audio_duration_seconds' => 2.0, 'files' => $fileManifest,
    ], $lesson, $preparation, $files];
}

it('registers recovered transcript and alignment as pending admin drafts and cancels only the exact untouched queue job', function () {
    [$manifest, $lesson, $preparation, $files] = fiveMinuteRecoveryFixture();

    $result = app(FiveMinuteSubtitleRecoveryService::class)->recover($manifest);

    expect($result['queue_job_cancelled'])->toBeTrue()
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($preparation->fresh()->status)->toBe('cancelled')
        ->and($preparation->fresh()->error_code)->toBe('RecoverySuperseded');
    $transcript = AiOperationUsage::query()->where('service', 'transcription')->firstOrFail();
    $alignment = AiOperationUsage::query()->where('service', 'whisperx_alignment')->firstOrFail();
    expect($transcript->status)->toBe('succeeded')
        ->and($transcript->result['text'])->toBe('Hello world!')
        ->and($transcript->result['transcript_review']['status'])->toBe('pending_review')
        ->and($transcript->result['recovery']['recovered_artifact'])->toBeTrue()
        ->and($transcript->result['recovery']['alignment_input_association'])->toBe('unverified')
        ->and($transcript->result['recovery']['source_audio_candidates']['audio']['sha256'])->not->toBe($transcript->result['recovery']['source_audio_candidates']['chunk']['sha256'])
        ->and($alignment->status)->toBe('succeeded')
        ->and($alignment->result['approval_statuses']['en'])->toBe('pending_review')
        ->and($alignment->result['outputs']['srt'])->toBe($manifest['files']['aligned_srt']['path'])
        ->and($alignment->result['recovery']['source_srt_sha256'])->toBe(hash('sha256', $files['aligned_srt']['contents']))
        ->and((float) $alignment->result['timeline_offset_seconds'])->toBe(0.0);

    $admin = User::create(['name' => 'Recovery Admin', 'email' => 'recovery-'.str()->uuid().'@example.test',
        'password' => 'StrongPass123!', 'role' => 'admin', 'email_verified_at' => now()]);
    $this->actingAs($admin)->getJson('/api/admin/lessons/'.$lesson->id.'/subtitle-workflow')
        ->assertOk()->assertJsonPath('transcripts.0.recovered_artifact', true)
        ->assertJsonPath('transcripts.0.approved', false)
        ->assertJsonPath('alignments.0.recovered_artifact', true)
        ->assertJsonPath('alignments.0.approved', false)
        ->assertJsonPath('alignments.0.recovery_details.alignment_input_association', 'unverified');
    $this->getJson('/api/admin/whisperx-alignments/'.$alignment->id.'/preview')
        ->assertOk()->assertJsonPath('srt_text', $files['aligned_srt']['contents']);

    $this->putJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/draft', ['text' => 'Hello world!'])->assertOk();
    $this->postJson('/api/admin/lessons/'.$lesson->id.'/transcripts/'.$transcript->id.'/approve', ['language' => 'en'])
        ->assertOk()->assertJsonPath('review_status', 'approved');
    $approval = $this->postJson('/api/admin/lessons/'.$lesson->id.'/subtitles/'.$alignment->id.'/en/approve')->assertCreated();
    $alignment->refresh();
    expect($alignment->result['approval_statuses']['en'])->toBe('approved')
        ->and($alignment->result['subtitle_versions']['en']['current_approved_version_id'])->toBe($approval->json('version_id'))
        ->and(Storage::disk('local')->get($alignment->result['subtitle_versions']['en']['versions'][0]['path']))->toBe($files['aligned_srt']['contents']);
});

it('is idempotent and refuses to cancel if the queued row has been reserved or attempted', function () {
    [$manifest, , $preparation] = fiveMinuteRecoveryFixture();
    $service = app(FiveMinuteSubtitleRecoveryService::class);
    $first = $service->recover($manifest);
    $second = $service->recover($manifest);
    expect($first['queue_job_cancelled'])->toBeTrue()->and($second['queue_job_cancelled'])->toBeFalse()
        ->and(AiOperationUsage::query()->where('service', 'transcription')->count())->toBe(1)
        ->and(AiOperationUsage::query()->where('service', 'whisperx_alignment')->count())->toBe(1);

    [$changedManifest, , $changedOperation] = fiveMinuteRecoveryFixture();
    DB::table('jobs')->where('id', $changedManifest['queue_job_id'])->update(['attempts' => 1, 'reserved_at' => now()->timestamp]);
    expect(fn () => $service->recover($changedManifest))->toThrow(RuntimeException::class, 'no longer unreserved')
        ->and($changedOperation->fresh()->status)->toBe('queued')
        ->and(DB::table('jobs')->where('id', $changedManifest['queue_job_id'])->exists())->toBeTrue()
        ->and(AiOperationUsage::query()->where('service', 'transcription')->count())->toBe(1);
});

it('rejects changed source hashes without modifying the queued operation or job', function () {
    [$manifest, , $preparation] = fiveMinuteRecoveryFixture();
    $manifest['files']['transcript']['sha256'] = str_repeat('0', 64);

    expect(fn () => app(FiveMinuteSubtitleRecoveryService::class)->recover($manifest))
        ->toThrow(RuntimeException::class, 'changed after inspection');
    expect($preparation->fresh()->status)->toBe('queued')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(AiOperationUsage::query()->where('service', 'transcription')->count())->toBe(0)
        ->and(AiOperationUsage::query()->where('service', 'whisperx_alignment')->count())->toBe(0);
});

it('refuses to create recovery records when a legacy transcript record has no idempotency key', function () {
    [$manifest, , $preparation] = fiveMinuteRecoveryFixture();
    AiOperationUsage::query()->create([
        'service' => 'transcription', 'model' => 'legacy-transcription', 'lesson_id' => $manifest['lesson_id'],
        'status' => 'succeeded', 'request_fingerprint' => hash('sha256', 'legacy'), 'result' => ['text' => 'Existing data'],
    ]);

    expect(fn () => app(FiveMinuteSubtitleRecoveryService::class)->recover($manifest))
        ->toThrow(RuntimeException::class, 'avoid duplicates');
    expect($preparation->fresh()->status)->toBe('queued')
        ->and(DB::table('jobs')->count())->toBe(1)
        ->and(AiOperationUsage::query()->where('service', 'whisperx_alignment')->count())->toBe(0);
});
