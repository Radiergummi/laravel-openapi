<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Errors;

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class ErrorEnvelopeFixtureController
{
    /**
     * @throws ModelNotFoundException
     * @throws ValidationException
     */
    public function show(int $id): array
    {
        return ['id' => $id];
    }

    /**
     * @throws UnprocessableEntityHttpException
     */
    public function update(int $id): array
    {
        return ['id' => $id];
    }
}
