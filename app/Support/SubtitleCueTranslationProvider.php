<?php

namespace App\Support;

interface SubtitleCueTranslationProvider
{
    /** @return array{cues: array, usage?: array, request_reference?: ?string} */
    public function translateSubtitleCues(array $configuration, array $input): array;
}
