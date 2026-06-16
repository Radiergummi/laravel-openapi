<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Support;

use ReflectionParameter;
use ReflectionProperty;

/**
 * Identity, reflection, and constructor-parameter data for one Data-class property.
 *
 * Built once per property so the per-property loop and scoped-attribute pass share a single
 * source rather than three parallel maps.
 */
final readonly class PropertyContext
{
    public function __construct(
        public string $wireName,
        public ReflectionProperty $reflection,
        public ?ReflectionParameter $ctorParam,
    ) {}
}
