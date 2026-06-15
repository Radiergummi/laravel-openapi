<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Tests\Fixtures\Models\Author;
use Radiergummi\OpenApi\Tests\Fixtures\Models\FactoryArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\SecondFactoryArticle;
use Radiergummi\OpenApi\Tests\Fixtures\Models\ThrowingFactoryArticle;

uses()->group('openapi');

function makeReader(?int $seed = 1234): ModelFactoryExampleReader
{
    return new ModelFactoryExampleReader(seed: $seed, logger: new NullLogger());
}

it('reads scalar definition values from a model factory', function (): void {
    $examples = makeReader()->examplesFor(FactoryArticle::class);

    expect($examples)->toHaveKeys(['title', 'views', 'published'])
        ->and($examples['title'])->toBeString()
        ->and($examples['views'])->toBeInt()
        ->and($examples['published'])->toBeBool();
});

it('skips non-scalar definition values', function (): void {
    $examples = makeReader()->examplesFor(FactoryArticle::class);

    // `tags` is an array in the factory definition — not representable as a scalar example.
    expect($examples)->not->toHaveKey('tags');
});

it('returns an empty map for a model without a factory', function (): void {
    expect(makeReader()->examplesFor(Author::class))->toBe([]);
});

it('disables factory examples when the seed is null', function (): void {
    expect(makeReader(seed: null)->examplesFor(FactoryArticle::class))->toBe([]);
});

it('degrades to an empty map and logs when definition() throws', function (): void {
    $logger = recordingLogger();
    $reader = new ModelFactoryExampleReader(seed: 1234, logger: $logger);

    expect($reader->examplesFor(ThrowingFactoryArticle::class))->toBe([])
        ->and($logger->records)->not->toBeEmpty();
});

it('produces identical values across repeated reads regardless of order', function (): void {
    // Read A, then B, then A again — A's map must be byte-identical across both reads, proving the
    // per-invocation reseed makes reads order-independent (B's draws between them must not drift
    // A's RNG state). Fresh readers each time so memoisation isn't what makes them match.
    $firstA = makeReader()->examplesFor(FactoryArticle::class);
    makeReader()->examplesFor(SecondFactoryArticle::class);
    $againA = makeReader()->examplesFor(FactoryArticle::class);

    expect($againA)->toBe($firstA);
});

it('draws distinct values for distinct model classes from the same seed', function (): void {
    // The two factories share an identical definition shape (title + views via fake()); mixing the
    // model class into the seed must make their drawn values differ rather than coincide.
    $first = makeReader()->examplesFor(FactoryArticle::class);
    $second = makeReader()->examplesFor(SecondFactoryArticle::class);

    expect($second['title'])->not->toBe($first['title']);
});
