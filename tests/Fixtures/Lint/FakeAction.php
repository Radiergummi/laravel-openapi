<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Lint\Rules\ThrowsTransitiveMissing;
use RuntimeException;

/**
 * Fixture Action class for testing the {@see ThrowsTransitiveMissing} rule.
 */
final class FakeAction
{
    /**
     * @throws RuntimeException
     */
    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}
