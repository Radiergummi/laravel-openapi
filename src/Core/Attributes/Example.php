<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Attributes;

use Attribute;

/**
 * Attaches a named example payload to the request body. Repeatable. For response examples see
 * {@see ResponseExample}. Values must be PHP attribute constants (scalars, arrays of scalars,
 * enum cases) since they're emitted verbatim into the spec.
 *
 * ```php
 * #[OpenApi\Example(name: 'minimal', value: ['name' => 'Aerospace Q1'])]
 * #[OpenApi\Example(name: 'full', value: ['name' => 'Aerospace Q1', 'tags' => ['priority']])]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Example extends BaseExample {}
