<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Lint\Fixtures;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final class RacOtherAttribute {}
