<?php

use App\Models\Lesson;
use App\Services\LessonAudioPreparationException;
use App\Services\LessonAudioPreparationService;
use Illuminate\Support\Facades\Storage;

it('rejects a lesson without a private video before invoking media tools', function () {
    Storage::fake('local');
    $lesson = new Lesson(['video_path' => 'lesson-videos/missing.mp4']);

    expect(fn () => app(LessonAudioPreparationService::class)->prepare($lesson))
        ->toThrow(LessonAudioPreparationException::class, 'does not have an accessible private video');
});
