<?php

declare(strict_types=1);

require_once __DIR__ . '/../../tools/survey/aggregate.php';

function surveyEntry(string $name, int $marker): array
{
    return ['name' => $name, 'metrics' => ['marker' => $marker]];
}

it('replaces every entry on a full run, in corpus order', function (): void {
    $order = ['Alpha', 'Beta', 'Gamma'];
    $existing = [surveyEntry('Alpha', 1), surveyEntry('Beta', 1), surveyEntry('Gamma', 1)];
    $fresh = [surveyEntry('Gamma', 2), surveyEntry('Alpha', 2), surveyEntry('Beta', 2)];

    $merged = surveyMergeResults($existing, $fresh, $order);

    expect(array_column($merged, 'name'))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and(array_map(static fn($e) => $e['metrics']['marker'], $merged))->toBe([2, 2, 2]);
});

it('replaces only the named app and preserves the rest on an --only backfill', function (): void {
    // The #229 scenario: a 9/11 aggregate, backfilling one app must not drop the other eight.
    $order = ['Alpha', 'Beta', 'Gamma'];
    $existing = [surveyEntry('Alpha', 1), surveyEntry('Beta', 1)];
    $fresh = [surveyEntry('Gamma', 9)]; // a single-app `--only Gamma` run

    $merged = surveyMergeResults($existing, $fresh, $order);

    expect($merged)->toHaveCount(3)
        ->and(array_column($merged, 'name'))->toBe(['Alpha', 'Beta', 'Gamma'])
        ->and($merged[0]['metrics']['marker'])->toBe(1)
        ->and($merged[2]['metrics']['marker'])->toBe(9);
});

it('builds the aggregate from scratch when there is no existing results file', function (): void {
    $merged = surveyMergeResults([], [surveyEntry('Beta', 5), surveyEntry('Alpha', 5)], ['Alpha', 'Beta']);

    expect(array_column($merged, 'name'))->toBe(['Alpha', 'Beta']);
});

it('keeps an entry whose app is no longer pinned in the corpus, at the tail', function (): void {
    $order = ['Alpha'];
    $existing = [surveyEntry('Retired', 1)];
    $fresh = [surveyEntry('Alpha', 1)];

    $merged = surveyMergeResults($existing, $fresh, $order);

    expect(array_column($merged, 'name'))->toBe(['Alpha', 'Retired']);
});

it('ignores malformed entries that carry no name', function (): void {
    $merged = surveyMergeResults([['metrics' => []]], [surveyEntry('Alpha', 1)], ['Alpha']);

    expect($merged)->toHaveCount(1)
        ->and($merged[0]['name'])->toBe('Alpha');
});
