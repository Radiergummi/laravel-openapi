<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

/**
 * Fixture Action class whose constructor carries an {@see ActionFixtureData}
 * parameter — used to verify OAPI-010 (Action pattern request body extraction).
 */
final class ActionFixture extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly ActionFixtureData $input,
    ) {}

    public function handle(): void
    {
        // Properties are consumed here so PHPStan does not flag them as write-only.
        // In practice the action is never called — it exists only for reflection tests.
        $_ = [$this->user, $this->input];
    }
}
