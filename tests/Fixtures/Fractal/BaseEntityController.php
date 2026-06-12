<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\Fractal;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Mirrors the InvoiceNinja BaseController convention: a `$entity_transformer` property the
 * concrete controller defaults to its transformer class, plus `itemResponse()` /
 * `listResponse()` helpers every action returns through.
 */
abstract class BaseEntityController extends Controller
{
    /**
     * @var null|class-string
     */
    protected $entity_transformer;

    protected function itemResponse(mixed $item): JsonResponse
    {
        return new JsonResponse(['data' => $item]);
    }

    protected function listResponse(mixed $query): JsonResponse
    {
        return new JsonResponse(['data' => $query]);
    }
}
