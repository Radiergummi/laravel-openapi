<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Lint;

use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;

/**
 * Fixture controller whose method accepts an Action (not a Data class directly). The Action's
 * constructor carries {@see NoEffectFixtureData} — used to verify Action-indirection detection in
 * {@see FieldNoEffect}.
 */
final class ActionWithNoEffectDataController
{
    public function create(ActionWithNoEffectData $action): void {}
}
