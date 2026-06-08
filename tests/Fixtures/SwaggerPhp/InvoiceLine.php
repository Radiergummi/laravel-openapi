<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp;

use OpenApi\Annotations as OA;

/**
 * Transitive target: referenced from {@see DocblockInvoice}'s `lines` property, so registering
 * the Invoice schema must pull this one in as well.
 *
 * @OA\Schema(
 *     schema="InvoiceLine",
 *
 *     @OA\Property(property="description", type="string"),
 * )
 */
class InvoiceLine {}
