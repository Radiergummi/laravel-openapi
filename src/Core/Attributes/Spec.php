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

use function array_values;
use function is_string;

/**
 * Pin a route to one or more named specs explicitly.
 *
 * When present, the spec partition's `match` config is ignored for this route — `#[Spec]` is
 * the definitive declaration. Global filters and `#[Hide]` / `#[Expose]` still apply.
 *
 * Forms:
 *   #[Spec]                 // ['default']  — opt out of named specs
 *   #[Spec('v1')]           // ['v1']
 *   #[Spec(['v1', 'v2'])]   // ['v1', 'v2']
 *
 * Repeatable: stacking `#[Spec('v1'), Spec('v2')]` unions to `['v1', 'v2']`. Method-level
 * attributes replace class-level attributes when the method carries any `#[Spec]`.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class Spec
{
    /** @var list<non-empty-string> */
    public array $names;

    /**
     * @param null|list<non-empty-string>|non-empty-string $name
     */
    public function __construct(array|string|null $name = null)
    {
        $this->names = match (true) {
            $name === null => ['default'],
            is_string($name) => [$name],
            default => array_values($name),
        };
    }
}
