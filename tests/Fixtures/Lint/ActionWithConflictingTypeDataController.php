<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Lint\Rules\FieldConflictingType;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly).
 * The Action's constructor carries {@see ConflictingTypeFixtureData}, which has a
 * mismatched #[RequestField] type — used to verify Action-indirection detection in
 * {@see FieldConflictingType}.
 */
final class ActionWithConflictingTypeDataController
{
    public function create(ActionWithConflictingTypeData $action): void {}
}
