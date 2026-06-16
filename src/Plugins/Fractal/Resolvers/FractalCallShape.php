<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\Fractal\Resolvers;

use Radiergummi\OpenApi\Plugins\Fractal\Support\Serializer;

/**
 * A matched Fractal call shape: the transformer to document, whether the envelope is a collection,
 * and the serializer it runs (null when an unrecognised serializer was named, which refuses).
 *
 * @internal
 */
final readonly class FractalCallShape
{
    /**
     * @param class-string $transformerClass
     */
    public function __construct(
        public string $transformerClass,
        public bool $collection,
        public ?Serializer $serializer,
    ) {}
}
