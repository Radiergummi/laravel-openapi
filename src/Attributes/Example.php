<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Attributes;

use Attribute;

/**
 * Attaches a named example payload to the request body. Repeatable. See {@see ResponseExample}
 * for response examples. Values must be PHP attribute constants (scalars, arrays, enum cases).
 *
 * ```php
 * #[OpenApi\Example(name: 'minimal', value: ['name' => 'Aerospace Q1'])]
 * #[OpenApi\Example(name: 'full', value: ['name' => 'Aerospace Q1', 'tags' => ['priority']])]
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_FUNCTION | Attribute::IS_REPEATABLE)]
final readonly class Example extends BaseExample {}
