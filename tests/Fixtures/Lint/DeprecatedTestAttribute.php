<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Attribute;
use Radiergummi\OpenApi\Lint\Rules\DeprecatedAttribute;

/**
 * Fixture attribute for testing the {@see DeprecatedAttribute} rule.
 *
 * This attribute carries at-deprecated to trigger a finding when the rule is configured to scan
 * the {@see Radiergummi\OpenApi\Tests\Fixtures\Lint} namespace.
 *
 * @deprecated Use FreshTestAttribute instead
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class DeprecatedTestAttribute {}
