<?php

namespace App\Services;

use RuntimeException;

/** Builds bounded crops only from timestamps on the transcript that was reviewed. */
class BoundedWindowPlanner
{
    public function plan(string $transcript, array $segments, float $maxWindowSeconds = 45.0): array
    {
        $tokens = $this->split($transcript);
        if ($tokens === [] || $segments === [] || !is_finite($maxWindowSeconds) || $maxWindowSeconds < 1) {
            throw new RuntimeException('Reliable timestamped transcript anchors are unavailable.');
        }
        $tokenKeys = array_map($this->tokenKey(...), $tokens);
        $anchored = [];
        $cursor = 0;
        $lastStart = -INF;
        $lastEnd = -INF;
        foreach ($segments as $index => $segment) {
            if (!is_array($segment) || !is_string($segment['text'] ?? null)
                || !is_numeric($segment['start'] ?? null) || !is_numeric($segment['end'] ?? null)) {
                throw new RuntimeException('Reliable timestamped transcript anchors are unavailable.');
            }
            $start = (float) $segment['start'];
            $end = (float) $segment['end'];
            $segmentTokens = $this->split($segment['text']);
            if (!is_finite($start) || !is_finite($end) || $start < 0 || $end <= $start
                || $start < $lastStart || $end <= $lastEnd || ($end - $start) > $maxWindowSeconds) {
                throw new RuntimeException('Reliable timestamped transcript anchors are unavailable.');
            }
            if ($segmentTokens === [] || $cursor + count($segmentTokens) > count($tokens)) {
                throw new RuntimeException('Timestamped transcript segments do not match the approved transcript.');
            }
            foreach ($segmentTokens as $local => $word) {
                if ($this->tokenKey($word) !== $tokenKeys[$cursor + $local]) {
                    throw new RuntimeException('Timestamped transcript segments do not match the approved transcript.');
                }
            }
            $anchored[] = [
                'start' => $start, 'end' => $end, 'token_start' => $cursor,
                'token_end' => $cursor + count($segmentTokens), 'segment_index' => $index,
            ];
            $cursor += count($segmentTokens);
            $lastStart = $start;
            $lastEnd = $end;
        }
        if ($cursor !== count($tokens)) {
            throw new RuntimeException('Timestamped transcript segments do not cover the approved transcript.');
        }

        $windows = [];
        $first = 0;
        $count = count($anchored);
        while ($first < $count) {
            $last = $first;
            while ($last + 1 < $count && $anchored[$last + 1]['end'] - $anchored[$first]['start'] <= $maxWindowSeconds) $last++;
            $window = [
                'token_start' => $anchored[$first]['token_start'],
                'token_end' => $anchored[$last]['token_end'],
                'audio_start' => $anchored[$first]['start'],
                'audio_end' => $anchored[$last]['end'],
            ];
            if ($window['token_end'] <= $window['token_start'] || $window['audio_end'] <= $window['audio_start']) {
                throw new RuntimeException('A transcript window has invalid boundaries.');
            }
            $windows[] = $window;
            if ($last === $count - 1) break;
            // One complete timestamped segment appears in both adjacent windows.
            $first = max($first + 1, $last);
        }

        foreach ($windows as $index => $window) {
            if ($index === 0) continue;
            $previous = $windows[$index - 1];
            if ($window['token_start'] >= $previous['token_end'] || $window['audio_start'] >= $previous['audio_end']) {
                throw new RuntimeException('Overlapping timestamped windows could not be established safely.');
            }
        }
        return [
            'anchor_source' => 'timestamped_transcription_segments',
            'token_count' => count($tokens),
            'segment_count' => count($anchored),
            'windows' => $windows,
        ];
    }

    private function split(string $text): array
    {
        return preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    private function tokenKey(string $token): string
    {
        return mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $token) ?? '', 'UTF-8');
    }
}
