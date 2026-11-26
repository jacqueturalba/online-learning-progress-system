<?php

namespace App\Services;

/** Matches API timing tokens back to approved source tokens without replacing source text. */
class ApprovedTranscriptWordMatcher
{
    public function align(string $approvedText, array $apiWords, float $duration): array
    {
        $source = preg_split('/\s+/u', trim($approvedText), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sourceKeys = array_map($this->key(...), $source);
        $api = array_values(array_filter($apiWords, 'is_array'));
        $apiKeys = array_map(fn (array $word): string => $this->key((string) ($word['word'] ?? '')), $api);
        $matches = [];
        $missing = [];
        $added = [];
        $ambiguous = [];
        $previousStart = null;
        $previousEnd = null;
        $orderViolations = [];

        if ($source !== [] && count($source) === count($api) && $sourceKeys === $apiKeys) {
            foreach ($source as $index => $_) $matches[$index] = $index;
        } else {
            [$matches, $sequenceMissing, $added, $ambiguous] = $this->sequenceMatch($sourceKeys, $apiKeys);
            unset($sequenceMissing);
        }
        $output = [];
        foreach ($source as $index => $text) {
            $apiIndex = $matches[$index] ?? null;
            $candidate = is_int($apiIndex) ? ($api[$apiIndex] ?? []) : [];
            $start = $candidate['start'] ?? null;
            $end = $candidate['end'] ?? null;
            $valid = is_numeric($start) && is_numeric($end)
                && is_finite((float) $start) && is_finite((float) $end)
                && (float) $start >= 0 && (float) $end > (float) $start
                && (float) $end <= $duration;
            $reason = null;
            if (in_array($index, $ambiguous, true)) {
                $valid = false;
                $reason = 'ambiguous_match';
            } elseif ($apiIndex === null) {
                $valid = false;
                $reason = 'missing_api_word';
            } elseif (!$valid) {
                $reason = is_numeric($start) && is_numeric($end) && (float) $start === (float) $end
                    ? 'zero_duration_timestamp' : 'invalid_timestamp';
            }
            if ($valid && (($previousStart !== null && (float) $start < $previousStart)
                || ($previousEnd !== null && (float) $end < $previousEnd))) {
                $valid = false;
                $reason = 'timestamp_out_of_order';
                $orderViolations[] = $index;
            }
            if ($valid) { $previousStart = (float) $start; $previousEnd = (float) $end; }
            $output[] = [
                'text' => $text,
                'start' => $valid ? round((float) $start, 3) : null,
                'end' => $valid ? round((float) $end, 3) : null,
                'score' => null,
                'status' => $valid ? 'aligned' : 'missing_or_unreliable',
                'source_token_index' => $index,
                'api_word_index' => $apiIndex,
                'reason' => $reason,
            ];
            if (!$valid && $reason !== 'ambiguous_match') $missing[] = ['source_token_index' => $index, 'word' => $text, 'reason' => $reason];
        }
        $missing = array_values($missing);
        $addedRecords = array_map(fn (int $index): array => [
            'api_word_index' => $index, 'word' => (string) ($api[$index]['word'] ?? ''),
            'start' => $api[$index]['start'] ?? null, 'end' => $api[$index]['end'] ?? null,
        ], array_values(array_unique($added)));
        $ambiguousRecords = array_map(fn (int $index): array => ['source_token_index' => $index, 'word' => $source[$index]], $ambiguous);

        return [
            'words' => $output,
            'missing_words' => $missing,
            'added_api_words' => $addedRecords,
            'ambiguous_words' => $ambiguousRecords,
            'total_words' => count($source),
            'successfully_aligned_words' => count(array_filter($output, fn (array $word): bool => $word['status'] === 'aligned')),
            'missing_or_unreliable_words' => count(array_filter($output, fn (array $word): bool => $word['status'] !== 'aligned')),
            'api_word_count' => count($api),
            'timestamp_order_violations' => $orderViolations,
            'timestamps_ordered' => $orderViolations === [],
        ];
    }

    private function sequenceMatch(array $source, array $api): array
    {
        $n = count($source);
        $m = count($api);
        $band = max(24, (int) ceil(max($n, $m) * 0.12));
        // Any reachable edit distance is at most the longer sequence length;
        // keep an unreachable value just above that range so it cannot tie a
        // real path and can never overflow when an edit cost is added.
        $longerSequence = max($n, $m);
        $unreachable = $longerSequence < PHP_INT_MAX ? $longerSequence + 1 : PHP_INT_MAX;
        $trace = [];
        $previous = [];
        for ($j = 0; $j <= min($m, $band); $j++) {
            $previous[$j] = $j;
        }
        $trace[0] = str_repeat("\x02", min($m, $band) + 1);
        $ties = [];
        for ($i = 1; $i <= $n; $i++) {
            $low = max(0, $i - $band);
            $high = min($m, $i + $band);
            $current = [];
            $directions = '';
            for ($j = $low; $j <= $high; $j++) {
                if ($j === 0) {
                    $current[$j] = $i;
                    $directions .= "\x01";
                    continue;
                }
                $costs = [
                    0 => $this->addCost($previous[$j - 1] ?? null, $source[$i - 1] === $api[$j - 1] ? 0 : 1, $unreachable),
                    1 => $this->addCost($previous[$j] ?? null, 1, $unreachable),
                    2 => $this->addCost($current[$j - 1] ?? null, 1, $unreachable),
                ];
                $best = min($costs);
                $hasReachableCost = $best < $unreachable;
                if (!$hasReachableCost) $best = $band + 1;
                if ($hasReachableCost && count(array_filter($costs, fn (int $cost): bool => $cost === $best)) > 1) $ties[$i - 1] = true;
                $direction = $costs[0] === $best ? 0 : ($costs[1] === $best ? 1 : 2);
                $current[$j] = $best;
                $directions .= chr($direction);
            }
            $trace[$i] = $directions;
            $previous = $current;
        }
        if (($previous[$m] ?? ($band + 1)) > $band) {
            return [[], range(0, max(-1, $n - 1)), range(0, max(-1, $m - 1)), []];
        }

        $matches = [];
        $missing = [];
        $added = [];
        $i = $n;
        $j = $m;
        while ($i > 0 || $j > 0) {
            $low = max(0, $i - $band);
            $offset = $j - $low;
            $direction = isset($trace[$i][$offset]) ? ord($trace[$i][$offset]) : 3;
            if ($i > 0 && $j > 0 && $direction === 0) {
                if ($source[$i - 1] === $api[$j - 1]) $matches[$i - 1] = $j - 1;
                else { $missing[] = $i - 1; $added[] = $j - 1; }
                $i--; $j--;
            } elseif ($i > 0 && ($direction === 1 || $j === 0)) {
                $missing[] = $i - 1; $i--;
            } elseif ($j > 0) {
                $added[] = $j - 1; $j--;
            } else {
                break;
            }
        }
        $ambiguous = [];
        foreach (array_keys($ties) as $sourceIndex) {
            if (isset($matches[$sourceIndex])) {
                unset($matches[$sourceIndex]);
                $ambiguous[] = $sourceIndex;
            }
        }
        foreach ($ambiguous as $index) {
            foreach ($source as $candidate => $key) {
                if ($key === $source[$index] && abs($candidate - $index) <= 4 && !in_array($candidate, $ambiguous, true)) {
                    unset($matches[$candidate]);
                    $ambiguous[] = $candidate;
                }
            }
        }
        $ambiguous = array_values(array_unique($ambiguous));
        $missing = array_values(array_diff($missing, $ambiguous));
        sort($ambiguous);
        return [$matches, $missing, $added, $ambiguous];
    }

    private function addCost(?int $previous, int $increment, int $unreachable): int
    {
        if ($previous === null || $previous >= $unreachable || $increment >= $unreachable - $previous) {
            return $unreachable;
        }

        return $previous + $increment;
    }

    private function key(string $word): string
    {
        return mb_strtolower(preg_replace('/[^\pL\pN]+/u', '', $word) ?? '', 'UTF-8');
    }

}
