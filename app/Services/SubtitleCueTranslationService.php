<?php

namespace App\Services;

use App\Support\SubtitleCueTranslationProvider;
use RuntimeException;

class SubtitleCueTranslationService
{
    /** Translate conservatively sized batches without changing cue identity or order. */
    public function translate(array $cues, array $configuration, array $credentials, string $model, string $language, string $key, SubtitleCueTranslationProvider $provider): array
    {
        $batches = [];
        $current = [];
        $characters = 0;
        foreach ($cues as $cue) {
            $size = mb_strlen($cue['text']);
            if ($current && (count($current) >= 20 || $characters + $size > 7000)) {
                $batches[] = $current;
                $current = [];
                $characters = 0;
            }
            $current[] = $cue;
            $characters += $size;
        }
        if ($current) $batches[] = $current;

        $translated = [];
        $usage = ['input_tokens' => 0, 'output_tokens' => 0];
        foreach ($batches as $batchIndex => $batch) {
            $start = array_search($batch[0]['index'], array_column($cues, 'index'), true);
            $before = array_slice($cues, max(0, (int) $start - 2), min(2, (int) $start));
            $after = array_slice($cues, (int) $start + count($batch), 2);
            $response = $provider->translateSubtitleCues($configuration, [
                'credentials' => $credentials, 'model' => $model, 'target_language' => $language,
                'cues' => array_map(fn ($cue) => ['index' => $cue['index'], 'text' => $cue['text']], $batch),
                'context_before' => array_map(fn ($cue) => ['index' => $cue['index'], 'text' => $cue['text']], $before),
                'context_after' => array_map(fn ($cue) => ['index' => $cue['index'], 'text' => $cue['text']], $after),
                'idempotency_key' => $key.'-b'.($batchIndex + 1),
            ]);
            $got = $response['cues'] ?? null;
            if (!is_array($got) || count($got) !== count($batch)) throw new RuntimeException('The translation provider returned a different number of cues than requested.');
            foreach ($batch as $i => $cue) {
                $item = $got[$i] ?? null;
                if (!is_array($item) || (int) ($item['index'] ?? 0) !== (int) $cue['index'] || !is_string($item['text'] ?? null) || trim($item['text']) === '') {
                    throw new RuntimeException('The translation provider returned missing, reordered, or empty subtitle cues.');
                }
                $translated[] = ['index' => $cue['index'], 'text' => $item['text']];
            }
            foreach (['input_tokens', 'output_tokens'] as $metric) $usage[$metric] += (int) ($response['usage'][$metric] ?? 0);
        }
        return ['cues' => $translated, 'usage' => array_filter($usage, fn ($value) => $value > 0)];
    }
}
