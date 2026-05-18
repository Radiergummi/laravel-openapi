<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use RuntimeException;

/**
 * Fixture Action class for testing the {@see \Radiergummi\OpenApi\Core\Lint\Rules\ThrowsTransitiveMissing} rule.
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
