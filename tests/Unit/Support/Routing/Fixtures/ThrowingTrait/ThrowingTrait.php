<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Routing\Fixtures\ThrowingTrait;

trait ThrowingTrait
{
    /**
     * @throws TraitOnlyException
     */
    public function methodFromTrait(): void {}
}
