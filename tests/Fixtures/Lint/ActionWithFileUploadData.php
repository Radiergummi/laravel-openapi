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
 * Fixture Action whose constructor carries {@see FileUploadFixtureData} —
 * used to verify that {@see \Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\MultipartFileWithoutMultipart}
 * reaches Data classes injected through Domain Actions.
 */
final class ActionWithFileUploadData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly FileUploadFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
