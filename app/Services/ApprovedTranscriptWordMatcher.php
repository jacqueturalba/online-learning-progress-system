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
        $forwardRows = [0 => $this->packCosts($previous, $band + 1)];
        $trace[0] = str_repeat("\x02", min($m, $band) + 1);
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
                $direction = $costs[0] === $best ? 0 : ($costs[1] === $best ? 1 : 2);
                $current[$j] = $best;
                $directions .= chr($direction);
            }
            $trace[$i] = $directions;
            $forwardRows[$i] = $this->packCosts($current, $band + 1);
            $previous = $current;
        }
        $globalCost = $previous[$m] ?? ($band + 1);
        if ($globalCost > $band) {
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

        $ambiguous = $this->ambiguousSourceMappings($source, $api, $forwardRows, $globalCost, $band);
        foreach ($ambiguous as $index) unset($matches[$index]);
        $missing = array_values(array_diff($missing, $ambiguous));
        sort($ambiguous);
        return [$matches, $missing, $added, $ambiguous];
    }

    /** Find source tokens whose mapping differs across globally minimal edit paths. */
    private function ambiguousSourceMappings(array $source, array $api, array $forwardRows, int $globalCost, int $band): array
    {
        $n = count($source);
        $m = count($api);
        $cap = $band + 1;
        $format = $this->costPackFormat($cap);
        $bytes = match ($format) { 'C' => 1, 'n' => 2, 'N' => 4, default => 8 };
        $ambiguous = [];
        $backNext = [];

        // Compute suffix costs one row at a time. Compact forward rows keep
        // memory proportional to the band rather than the full PHP array grid.
        for ($i = $n; $i >= 1; $i--) {
            $low = max(0, $i - $band);
            $high = min($m, $i + $band);
            $backCurrent = [];
            for ($j = $high; $j >= $low; $j--) {
                if ($i === $n && $j === $m) {
                    $backCurrent[$j] = 0;
                    continue;
                }

                $costs = [];
                if ($i < $n) $costs[] = $this->addCost($backNext[$j] ?? null, 1, $cap);
                if ($j < $m) $costs[] = $this->addCost($backCurrent[$j + 1] ?? null, 1, $cap);
                if ($i < $n && $j < $m) {
                    $costs[] = $this->addCost($backNext[$j + 1] ?? null, $source[$i] === $api[$j] ? 0 : 1, $cap);
                }
                $backCurrent[$j] = $costs === [] ? $cap : min($costs);
            }

            $sourceIndex = $i - 1;
            $previousLow = max(0, $sourceIndex - $band);
            $previousHigh = min($m, $sourceIndex + $band);
            [$packedForward, $forwardLow] = $forwardRows[$sourceIndex];
            $possibleApiIndexes = [];
            $canBeUnmatched = false;

            for ($j = $previousLow; $j <= $previousHigh; $j++) {
                $prefix = $this->unpackCost($packedForward, $j - $forwardLow, $format, $bytes);
                if ($prefix === null || $prefix >= $cap || $prefix > $globalCost) continue;

                $deleteSuffix = $backCurrent[$j] ?? null;
                if ($deleteSuffix !== null && $deleteSuffix < $cap && $prefix + 1 + $deleteSuffix === $globalCost) {
                    $canBeUnmatched = true;
                }

                if ($j >= $m) continue;
                $diagonalSuffix = $backCurrent[$j + 1] ?? null;
                if ($diagonalSuffix === null || $diagonalSuffix >= $cap) continue;
                $isMatch = $source[$sourceIndex] === $api[$j];
                $stepCost = $isMatch ? 0 : 1;
                if ($prefix + $stepCost + $diagonalSuffix !== $globalCost) continue;
                if ($isMatch) $possibleApiIndexes[$j] = true;
                else $canBeUnmatched = true;
            }

            if (count($possibleApiIndexes) > 1 || ($possibleApiIndexes !== [] && $canBeUnmatched)) {
                $ambiguous[] = $sourceIndex;
            }

            $backNext = $backCurrent;
        }

        return $ambiguous;
    }

    /** Store a narrow DP row without the overhead of a PHP hash table per cell. */
    private function packCosts(array $costs, int $cap): array
    {
        $low = $costs === [] ? 0 : array_key_first($costs);
        $format = $this->costPackFormat($cap);
        $values = array_map(static fn (int $cost): int => min($cost, $cap), array_values($costs));

        return [pack($format.'*', ...$values), $low];
    }

    private function costPackFormat(int $cap): string
    {
        return $cap <= 0xFF ? 'C' : ($cap <= 0xFFFF ? 'n' : ($cap <= 0xFFFFFFFF ? 'N' : 'J'));
    }

    private function unpackCost(string $packed, int $offset, string $format, int $bytes): ?int
    {
        if ($offset < 0 || ($offset + 1) * $bytes > strlen($packed)) return null;
        $value = unpack($format, substr($packed, $offset * $bytes, $bytes));

        return is_array($value) && isset($value[1]) ? (int) $value[1] : null;
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
