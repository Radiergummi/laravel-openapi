<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Generator;

use Radiergummi\OpenApi\Support\Spec\SpecDefinition;

/**
 * Per-run inputs shared across every stage in a single {@see SpecPipeline::run()} invocation.
 */
final readonly class GenerationContext
{
    public function __construct(
        public SpecDefinition $spec,
        public string $environment,
    ) {}
}
