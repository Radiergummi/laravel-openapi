<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;
use Radiergummi\OpenApi\Tests\Fixtures\Action;
use Radiergummi\OpenApi\Tests\Fixtures\Models\User;

/**
 * Fixture Action whose constructor carries {@see WrongScopeFixtureData} — used to verify that
 * {@see FieldAttributeWrongScope} reaches Data classes injected through Domain Actions (not only
 * direct parameters).
 */
final class ActionWithWrongScopeData extends Action
{
    public function __construct(
        private readonly User $user,
        private readonly WrongScopeFixtureData $input,
    ) {}

    public function handle(): void
    {
        $_ = [$this->user, $this->input];
    }
}
