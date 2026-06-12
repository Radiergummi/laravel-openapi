<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

/**
 * An impostor with the same accessor surface as the request — receiver discipline must keep
 * its calls out of the query-parameter scan.
 */
final class QueryAccessorLookalike
{
    public function query(string $column): self
    {
        return $this;
    }

    public function input(string $name): string
    {
        return $name;
    }

    public function integer(string $name): int
    {
        return 0;
    }
}
