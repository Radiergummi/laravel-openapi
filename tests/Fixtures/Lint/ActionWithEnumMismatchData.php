<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Core\Lint\Rules\FieldEnumMismatch;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

/**
 * Fixture Action whose constructor carries {@see EnumMismatchFixtureData} — used to verify that
 * {@see FieldEnumMismatch} reaches Data classes injected through Domain Actions.
 */
final class ActionWithEnumMismatchData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly EnumMismatchFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
