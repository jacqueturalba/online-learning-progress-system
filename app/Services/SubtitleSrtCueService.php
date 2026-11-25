<?php

namespace App\Services;

use RuntimeException;

/** Parses and renders SRT cues while retaining the source cue indexes and timestamps. */
class SubtitleSrtCueService
{
    public function parse(string $contents): array
    {
        if (!mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException('The SRT file must use UTF-8 text encoding.');
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $blocks = preg_split('/\R\s*\R/u', trim($contents)) ?: [];
        $cues = [];
        $seen = [];
        foreach ($blocks as $block) {
            $lines = preg_split('/\R/u', trim($block)) ?: [];
            if (count($lines) < 3 || !ctype_digit(trim($lines[0]))) throw new RuntimeException('The SRT contains a malformed cue.');
            $index = (int) trim($lines[0]);
            $timing = trim($lines[1]);
            if ($index < 1 || isset($seen[$index]) || !preg_match('/^(\d{2}:\d{2}:\d{2},\d{3})\s+-->\s+(\d{2}:\d{2}:\d{2},\d{3})$/', $timing, $matches)) {
                throw new RuntimeException('The SRT contains an invalid cue index or timestamp.');
            }
            $startMs = $this->toMilliseconds($matches[1]);
            $endMs = $this->toMilliseconds($matches[2]);
            if ($endMs <= $startMs) throw new RuntimeException('An SRT cue ends before it starts.');
            $text = implode("\n", array_slice($lines, 2));
            if (trim($text) === '') throw new RuntimeException('The SRT contains an empty cue.');
            $seen[$index] = true;
            $cues[] = ['index' => $index, 'start' => $matches[1], 'end' => $matches[2], 'text' => $text];
        }
        if ($cues === []) throw new RuntimeException('The SRT contains no subtitle cues.');
        return $cues;
    }

    public function render(array $cues): string
    {
        $blocks = [];
        foreach ($cues as $cue) {
            $blocks[] = $cue['index']."\n".$cue['start'].' --> '.$cue['end']."\n".$cue['text'];
        }
        return implode("\n\n", $blocks)."\n";
    }

    public function withTranslatedText(array $source, array $translated): array
    {
        if (count($source) !== count($translated)) throw new RuntimeException('The Filipino translation must retain every English cue.');
        foreach ($source as $i => &$cue) {
            $item = $translated[$i] ?? null;
            if (!is_array($item) || (int) ($item['index'] ?? 0) !== (int) $cue['index'] || !is_string($item['text'] ?? null) || trim($item['text']) === '') {
                throw new RuntimeException('The Filipino translation returned missing or reordered subtitle cues.');
            }
            $cue['text'] = $item['text'];
        }
        unset($cue);
        return $source;
    }

    public function assertSameTiming(array $source, array $candidate): void
    {
        if (count($source) !== count($candidate)) throw new RuntimeException('The Filipino SRT must preserve every approved English cue and timestamp.');
        foreach ($source as $i => $cue) {
            $other = $candidate[$i] ?? [];
            if ((int) ($other['index'] ?? 0) !== (int) $cue['index'] || ($other['start'] ?? null) !== $cue['start'] || ($other['end'] ?? null) !== $cue['end']) {
                throw new RuntimeException('The Filipino SRT cue indexes and timestamps must match the approved English SRT exactly.');
            }
        }
    }

    private function toMilliseconds(string $timestamp): int
    {
        preg_match('/^(\d{2}):(\d{2}):(\d{2}),(\d{3})$/', $timestamp, $m);
        if ((int) $m[2] > 59 || (int) $m[3] > 59) throw new RuntimeException('The SRT contains an invalid timestamp.');
        return (((int) $m[1] * 60 + (int) $m[2]) * 60 + (int) $m[3]) * 1000 + (int) $m[4];
    }
}
