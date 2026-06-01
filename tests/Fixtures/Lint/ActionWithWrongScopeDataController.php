<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules\FieldAttributeWrongScope;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly). The Action's
 * constructor carries {@see WrongScopeFixtureData}, which has a misplaced #[PathParam] — used
 * to verify Action-indirection detection in {@see FieldAttributeWrongScope}.
 */
final class ActionWithWrongScopeDataController
{
    public function create(ActionWithWrongScopeData $action): void {}
}
