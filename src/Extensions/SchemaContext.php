<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Extensions;

use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * Context passed to every registered schema transformer.
 *
 * `$sourceClass` is the PHP class the schema was derived from, or null for schemas registered
 * via {@see ComponentSchemaRegistry::registerNamed()} (hand-built envelopes).
 */
final readonly class SchemaContext
{
    public function __construct(
        /** Key under which the schema is stored in `components.schemas`. */
        public string $componentKey,
        /**
         * @var null|class-string
         */
        public ?string $sourceClass,
    ) {}
}
