<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

/**
 * Fixture Action whose constructor carries {@see NoEffectFixtureData} — used to verify that
 * {@see FieldNoEffect} reaches Data classes injected through Domain Actions.
 */
final class ActionWithNoEffectData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly NoEffectFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
