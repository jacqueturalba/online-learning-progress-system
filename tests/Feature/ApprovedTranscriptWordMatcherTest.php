<?php

use App\Services\ApprovedTranscriptWordMatcher;

function matcherTimedWords(array $words): array
{
    return array_map(fn (string $word, int $index): array => [
        'word' => $word, 'start' => $index * 0.02, 'end' => $index * 0.02 + 0.01,
    ], $words, array_keys($words));
}

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

it('preserves every token mapping for exact repeated-word sequences', function () {
    $words = ['the', 'and', 'the', 'and', 'the.'];

    $result = (new ApprovedTranscriptWordMatcher())->align(implode(' ', $words), matcherTimedWords($words), 1);

    expect($result['ambiguous_words'])->toBe([])
        ->and($result['successfully_aligned_words'])->toBe(5)
        ->and(array_column($result['words'], 'api_word_index'))->toBe([0, 1, 2, 3, 4]);
});

it('retains source order while reporting inserted API words and missing approved words', function () {
    $matcher = new ApprovedTranscriptWordMatcher();
    $inserted = $matcher->align('the word one the word two', matcherTimedWords(['the', 'word', 'one', 'extra', 'the', 'word', 'two']), 1);
    $missing = $matcher->align('the word one extra the word two', matcherTimedWords(['the', 'word', 'one', 'the', 'word', 'two']), 1);

    expect($inserted['added_api_words'])->toHaveCount(1)
        ->and($inserted['added_api_words'][0]['word'])->toBe('extra')
        ->and(array_column($inserted['words'], 'api_word_index'))->toBe([0, 1, 2, 4, 5, 6])
        ->and($inserted['ambiguous_words'])->toBe([])
        ->and($missing['missing_words'])->toContain(['source_token_index' => 3, 'word' => 'extra', 'reason' => 'missing_api_word'])
        ->and($missing['added_api_words'])->toBe([])
        ->and($missing['ambiguous_words'])->toBe([]);
});

it('does not mark repeated words ambiguous when neighboring insertions or deletions have anchored mappings', function () {
    $matcher = new ApprovedTranscriptWordMatcher();
    $inserted = $matcher->align('go go start go finish', matcherTimedWords(['go', 'extra', 'go', 'start', 'go', 'finish']), 1);
    $deleted = $matcher->align('go extra go start go finish', matcherTimedWords(['go', 'go', 'start', 'go', 'finish']), 1);

    expect($inserted['ambiguous_words'])->toBe([])
        ->and(array_column($inserted['words'], 'api_word_index'))->toBe([0, 2, 3, 4, 5])
        ->and($inserted['added_api_words'][0]['word'])->toBe('extra')
        ->and($deleted['ambiguous_words'])->toBe([])
        ->and($deleted['missing_words'])->toContain(['source_token_index' => 1, 'word' => 'extra', 'reason' => 'missing_api_word'])
        ->and(array_column($deleted['words'], 'api_word_index'))->toBe([0, null, 1, 2, 3, 4]);
});

it('rejects only source tokens whose mappings vary across globally optimal alignments', function () {
    $result = (new ApprovedTranscriptWordMatcher())->align('go go stop', matcherTimedWords(['go', 'stop']), 1);

    expect(array_column($result['ambiguous_words'], 'source_token_index'))->toBe([0, 1])
        ->and($result['words'][0]['reason'])->toBe('ambiguous_match')
        ->and($result['words'][1]['reason'])->toBe('ambiguous_match')
        ->and($result['words'][2]['api_word_index'])->toBe(1)
        ->and($result['successfully_aligned_words'])->toBe(1);
});

it('matches a synthetic 4300-token transcript with repeated common words efficiently', function () {
    $source = [];
    for ($index = 0; $index < 1075; $index++) {
        $source[] = 'the';
        $source[] = 'word'.$index;
        $source[] = 'and';
        $source[] = 'end'.$index;
    }
    $api = $source;
    array_splice($api, 2150, 0, ['um']);
    $started = hrtime(true);

    $result = (new ApprovedTranscriptWordMatcher())->align(implode(' ', $source), matcherTimedWords($api), 100);

    $elapsedMs = (hrtime(true) - $started) / 1_000_000;
    expect($result['total_words'])->toBe(4300)
        ->and($result['successfully_aligned_words'])->toBe(4300)
        ->and($result['added_api_words'])->toHaveCount(1)
        ->and($result['added_api_words'][0]['word'])->toBe('um')
        ->and($result['ambiguous_words'])->toBe([])
        ->and($elapsedMs)->toBeLessThan(30000);
});
