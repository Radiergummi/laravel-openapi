<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly).
 * The Action's constructor carries {@see EnumMismatchFixtureData} — used to verify
 * Action-indirection detection in {@see \Radiergummi\OpenApi\Core\Lint\Rules\FieldEnumMismatch}.
 */
final class ActionWithEnumMismatchDataController
{
    public function create(ActionWithEnumMismatchData $action): void {}
}
