<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Beta;

use Radiergummi\OpenApi\Tests\Fixtures\Alpha\SelfRefData as AlphaSelfRefData;
use Spatie\LaravelData\Data;

/**
 * Fixture for OAPI-008: same basename as {@see AlphaSelfRefData} in a different namespace, with a
 * self-referential property.
 */
final class SelfRefData extends Data
{
    public function __construct(
        public string $value,
        public ?self $child = null,
    ) {}
}
