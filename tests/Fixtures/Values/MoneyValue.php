<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Values;

use function sprintf;

/**
 * A plain value object carrying a real `format()` method of its own: nothing types the call's
 * result, so a resource key reading it must stay unconstrained.
 */
final readonly class MoneyValue
{
    public function __construct(
        public int $cents,
    ) {}

    public function format(string $pattern): string
    {
        return sprintf($pattern, $this->cents / 100);
    }
}
