<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Radiergummi\OpenApi\Contracts\Lint\Rule;

/**
 * A {@see Rule} that can mechanically resolve its own findings.
 */
interface FixableRule extends Rule
{
    public function fixer(): Fixer;
}
