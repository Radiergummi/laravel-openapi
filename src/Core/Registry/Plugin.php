<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

/**
 * A plugin teaches the OpenAPI core about a specific package by registering extractors, schema
 * resolvers, and lint rules into the registry.
 */
interface Plugin
{
    public function register(OpenApiRegistry $registry): void;
}
