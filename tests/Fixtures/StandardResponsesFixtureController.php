<?php

// Fixture controller for error-response and form-request extraction tests.

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * @internal
 */
class StandardResponsesFixtureController
{
    /**
     * @throws TeapotException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function throwsTeapot(): void {}

    /**
     * @throws ModelNotFoundException
     *
     * @noinspection PhpDocRedundantThrowsInspection
     */
    public function throwsModelNotFound(): void {}

    public function throwsNothing(): void {}
}
