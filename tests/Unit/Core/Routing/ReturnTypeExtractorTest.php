<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Routing;

use Illuminate\Pagination\LengthAwarePaginator;
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\Types\ContextFactory;
use Radiergummi\OpenApi\Core\Routing\ReturnTypeExtractor;
use ReflectionMethod;
use stdClass;

/**
 * Fixture: its @return tags exercise the extractor. The `use` import above
 * gives the docblock context a way to resolve the short name.
 */
class ReturnTypeExtractorFixture
{
    /** @return LengthAwarePaginator<stdClass> */
    public function generic(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }

    public function noGeneric(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }

    public function noDocblock(): LengthAwarePaginator
    {
        /** @phpstan-ignore-next-line */
        return new LengthAwarePaginator([], 0, 15);
    }
}

function makeReturnTypeExtractor(): ReturnTypeExtractor
{
    return new ReturnTypeExtractor(
        DocBlockFactory::createInstance(),
        new ContextFactory(),
    );
}

it('extracts the FQCN of a generic return argument', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'generic');

    expect(makeReturnTypeExtractor()->genericArgument($method))
        ->toBe('stdClass');
});

it('returns null when the return type has no generic argument', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'noGeneric');

    expect(makeReturnTypeExtractor()->genericArgument($method))->toBeNull();
});

it('returns null when the method has no docblock', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'noDocblock');

    expect(makeReturnTypeExtractor()->genericArgument($method))->toBeNull();
});
