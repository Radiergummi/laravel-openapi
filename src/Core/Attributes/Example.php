<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Attaches a named example payload to the operation's request body.
 *
 * Examples are the single biggest UX win for tools like Scalar — they let
 * consumers see a concrete request without having to read the schema. Multiple
 * attributes may be stacked on one method; each becomes one entry under the
 * media type's `examples` map keyed by `$name`.
 *
 * Values are passed verbatim into the spec, so they must be PHP attribute
 * constants (scalars, arrays of scalars, enum cases). For response examples
 * see {@see ResponseExample}.
 *
 * ```php
 * #[OpenApi\Example(
 *     name: 'minimal',
 *     summary: 'Bare-minimum payload',
 *     value: ['name' => 'Aerospace Q1'],
 * )]
 * #[OpenApi\Example(
 *     name: 'full',
 *     value: ['name' => 'Aerospace Q1', 'tags' => ['priority']],
 * )]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Example extends BaseExample {}
