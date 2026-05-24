<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;

/**
 * Package-local stand-in for a "Domain Action" base class.
 *
 * The {@see PayloadParameterScanner} supports "indirection descent": when a controller parameter is
 * a subclass of a configured base class, the scanner reflects that class's constructor to find the
 * request DTO. Tests exercise that path by configuring this class as the indirection base and
 * pointing fixture actions at it.
 *
 * It is deliberately framework-agnostic — the scanner only checks `is_a()`, so no behaviour is
 * required beyond being a common ancestor.
 */
abstract class Action {}
