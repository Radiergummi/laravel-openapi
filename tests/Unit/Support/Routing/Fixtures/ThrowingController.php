<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures;

use InvalidArgumentException;
use LogicException;
use RuntimeException;

final class ThrowingController
{
    /**
     * @throws InvalidArgumentException when input is bad
     * @throws RuntimeException
     */
    public function multiple(): void {}

    /**
     * @throws LogicException|RuntimeException
     */
    public function compound(): void {}

    public function noDocblock(): void {}

    /**
     * No throws here.
     */
    public function noThrows(): void {}
}
