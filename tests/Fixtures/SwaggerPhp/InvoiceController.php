<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;

/**
 * Invoice-Ninja-shaped fixture: a controller carrying an operation-level `@OA` docblock
 * whose success response references an authored schema by name.
 */
class InvoiceController
{
    /**
     * @OA\Get(
     *     path="/invoices/{id}",
     *     summary="Show an invoice.",
     *     operationId="showInvoice",
     *
     *     @OA\Response(
     *         response=200,
     *         description="The invoice",
     *
     *         @OA\JsonContent(ref="#/components/schemas/Invoice"),
     *     ),
     * )
     */
    public function show(): void {}
}
