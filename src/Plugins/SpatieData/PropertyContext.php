<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData;

use ReflectionParameter;
use ReflectionProperty;

/**
 * Identity, reflection and constructor-parameter data for one Data-class property.
 *
 * Built once per property at the start of schema generation so the per-property loop and
 * the scoped-attribute pass work from a single source instead of three parallel maps
 * (`phpName → ctorParam`, `phpName → wireName`, `wireName → ReflectionProperty`).
 */
final readonly class PropertyContext
{
    public function __construct(
        public string $wireName,
        public ReflectionProperty $reflection,
        public ?ReflectionParameter $ctorParam,
    ) {}
}
