<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision;

use Spatie\LaravelData\Data;

/**
 * Test fixture — a convention-derived component named `Invoice` (Spatie `Data`), returned by
 * {@see ConventionInvoiceController}. Collides by name with the hand-authored
 * {@see AuthoredInvoiceSchema} `@OA\Schema(schema="Invoice")`.
 */
final class Invoice extends Data
{
    public function __construct(
        public int $id,
        public int $amount_cents,
    ) {}
}
