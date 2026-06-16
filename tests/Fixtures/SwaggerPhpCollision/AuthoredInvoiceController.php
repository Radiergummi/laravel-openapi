<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Test fixture — an authored operation whose `@OA\Response` references `#/components/schemas/Invoice`,
 * the name the hand-authored {@see AuthoredInvoiceSchema} also claims.
 */
final class AuthoredInvoiceController extends Controller
{
    /**
     * @OA\Get(
     *     path="/auth/invoices/{id}",
     *     summary="Authored invoice",
     *
     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Invoice"),
     *     ),
     * )
     */
    public function show(string $id): JsonResponse
    {
        return response()->json([]);
    }
}
