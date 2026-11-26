<?php

namespace App\Services;

/** Uses overlapping approved transcript text and the measured chunk boundary only. */
class SubtitleChunkOverlapDeduplicator
{
    public function duplicatePrefixLength(string $previousApprovedText, string $currentApprovedText): int
    {
        $previous = $this->keys($previousApprovedText);
        $current = $this->keys($currentApprovedText);
        $limit = min(count($previous), count($current), 80);
        for ($length = $limit; $length > 0; $length--) {
            if (array_slice($previous, -$length) === array_slice($current, 0, $length)) return $length;
        }
        return 0;
    }

    private function keys(string $text): array
    {
        $tokens = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_map(fn (string $token): string => mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $token) ?? '', 'UTF-8'), $tokens);
    }
}
