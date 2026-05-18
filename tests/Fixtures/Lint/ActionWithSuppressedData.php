<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use App\Domain\Action;
use App\Models\Auth\User;

/**
 * Fixture Action whose constructor carries {@see SuppressedFixtureData} —
 * used to verify that {@see \Radiergummi\OpenApi\Core\Lint\SuppressionCollector}
 * reaches #[IgnoreLint] directives on Data classes injected through Domain Actions.
 */
final class ActionWithSuppressedData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly SuppressedFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
