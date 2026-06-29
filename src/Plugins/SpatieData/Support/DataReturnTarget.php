<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use Spatie\LaravelData\Data;

/**
 * The Spatie {@see Data} class a generic-container action returns, recovered from its return
 * expression, plus whether the body yields a collection of it.
 *
 * @internal
 */
final readonly class DataReturnTarget
{
    /**
     * @param class-string<Data> $dataClass
     */
    public function __construct(
        public string $dataClass,
        public bool $isCollection,
    ) {}
}
