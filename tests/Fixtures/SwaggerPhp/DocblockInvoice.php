<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;

/**
 * Invoice-Ninja-shaped fixture: a model carrying an `@OA\Schema` docblock annotation.
 *
 * @OA\Schema(
 *     schema="Invoice",
 *     required={"id"},
 *
 *     @OA\Property(property="id", type="integer"),
 *     @OA\Property(property="amount", type="number", format="float"),
 *     @OA\Property(
 *         property="lines",
 *         type="array",
 *
 *         @OA\Items(ref="#/components/schemas/InvoiceLine"),
 *     ),
 * )
 */
class DocblockInvoice {}
