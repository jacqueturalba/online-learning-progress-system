<?php

namespace App\Services;

use App\Models\SiteSetting;
use Illuminate\Validation\ValidationException;

final class SubtitleProcessingMethod
{
    public const STANDARD = 'whisperx_standard';
    public const BOUNDED = 'whisperx_bounded';
    public const OPENAI_WHISPER1 = 'openai_whisper1';
    public const SETTING_KEY = 'subtitle_processing_method';
    public const DEFAULT = self::STANDARD;

    public const OPTIONS = [
        self::STANDARD => [
            'label' => 'WhisperX standard',
            'description' => 'Existing single-call forced alignment for approved English transcripts.',
        ],
        self::BOUNDED => [
            'label' => 'WhisperX bounded windows',
            'description' => 'Aligns smaller windows using trustworthy timestamped transcript segments; falls back to standard when anchors are unavailable.',
        ],
        self::OPENAI_WHISPER1 => [
            'label' => 'OpenAI Whisper-1 transcription and word timestamps',
            'description' => 'Generates the English transcript and word timestamps together in one Whisper-1 request per prepared audio chunk.',
        ],
    ];

    public static function current(): string
    {
        $value = SiteSetting::query()->where('key', self::SETTING_KEY)->value('value');
        return is_string($value) && isset(self::OPTIONS[$value]) ? $value : self::DEFAULT;
    }

    public static function save(string $method): void
    {
        if (!isset(self::OPTIONS[$method])) {
            throw ValidationException::withMessages(['processing_method' => 'Choose a supported subtitle processing method.']);
        }
        SiteSetting::query()->updateOrCreate(['key' => self::SETTING_KEY], ['value' => $method]);
    }
}
