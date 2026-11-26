<?php

use App\Services\ApprovedTranscriptWordMatcher;

it('keeps bounded dynamic programming costs as integers when a predecessor is missing', function () {
    $matcher = new ApprovedTranscriptWordMatcher();
    $addCost = new ReflectionMethod($matcher, 'addCost');
    $unreachable = 27;
    $costs = [
        $addCost->invoke($matcher, null, 0, $unreachable),
        $addCost->invoke($matcher, null, 1, $unreachable),
        $addCost->invoke($matcher, $unreachable - 1, 1, $unreachable),
        $addCost->invoke($matcher, $unreachable, 1, $unreachable),
        $addCost->invoke($matcher, 4, 1, $unreachable),
    ];

    expect($costs)->toBe([27, 27, 27, 27, 5]);
    foreach ($costs as $cost) {
        expect($cost)->toBeInt();
    }
});

it('matches sequences at the band boundary when dynamic programming predecessors are absent', function () {
    $source = array_map(fn (int $index): string => 'token'.$index, range(0, 49));
    $api = array_map(fn (string $word): array => ['word' => $word, 'start' => 0, 'end' => 1], array_slice($source, 0, 26));

    $result = (new ApprovedTranscriptWordMatcher())->align(implode(' ', $source), $api, 1);

    expect($result['total_words'])->toBe(50)
        ->and($result['successfully_aligned_words'])->toBe(26)
        ->and($result['missing_or_unreliable_words'])->toBe(24)
        ->and($result['added_api_words'])->toBe([])
        ->and(array_column($result['words'], 'api_word_index'))
        ->toBe([...range(0, 25), ...array_fill(0, 24, null)]);
});
