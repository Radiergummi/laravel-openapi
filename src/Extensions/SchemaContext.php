<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Extensions;

use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

/**
 * Context passed to every registered schema transformer.
 *
 * The source class is the PHP class that the schema was extracted from. Transformers can use it to
 * scope mutations, e.g., only add constraints when the source class implements a specific interface
 * or carries a specific attribute.
 *
 * When a schema is registered via {@see ComponentSchemaRegistry::registerNamed()} (i.e. a
 * hand-built envelope rather than a class-derived schema), {@see $sourceClass} is null.
 */
final readonly class SchemaContext
{
    public function __construct(
        /**
         * The component key under which the schema is stored in `components.schemas`.
         * E.g. `CreateProjectData`, `Projects.CreateData`.
         */
        public string $componentKey,
        /**
         * The PHP class the schema was derived from, or null for named/anonymous schemas.
         *
         * @var null|class-string
         */
        public ?string $sourceClass,
    ) {}
}
