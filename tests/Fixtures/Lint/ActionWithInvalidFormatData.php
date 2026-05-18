<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

/**
 * Fixture Action whose constructor carries {@see InvalidFormatFixtureData} —
 * used to verify that {@see \Radiergummi\OpenApi\Core\Lint\Rules\FieldInvalidFormat}
 * reaches Data classes injected through Domain Actions.
 */
final class ActionWithInvalidFormatData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly InvalidFormatFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
