<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Routing;

use Illuminate\Pagination\LengthAwarePaginator;
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
        return new LengthAwarePaginator([], 0, 15);
    }

    /**
     * @return LengthAwarePaginator a page of results
     */
    public function noGeneric(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    public function noDocblock(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }

    /**
     * @return LengthAwarePaginator<int, stdClass> a keyed page
     */
    public function keyedGeneric(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, 15);
    }
}

function makeReturnTypeExtractor(): ReturnTypeExtractor
{
    return ReturnTypeExtractor::create();
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

it('returns the value type of a two-argument generic', function (): void {
    $method = new ReflectionMethod(ReturnTypeExtractorFixture::class, 'keyedGeneric');

    expect(makeReturnTypeExtractor()->genericArgument($method))->toBe('stdClass');
});
