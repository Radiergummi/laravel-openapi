<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

/**
 * Marker interface for lint rules that inspect the application configuration and the
 * route descriptor list before any spec is built. Used for "config-soundness" checks
 * like `spec.unknown-reference` and `spec.route-orphaned`.
 *
 * Pre-build rules run once per `openapi:lint` invocation regardless of `--spec=`.
 */
interface PreBuildRule extends Visitor
{
    /**
     * @param list<ActionDescriptor> $descriptors
     */
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void;
}
