<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision;

use Illuminate\Routing\Controller;

/**
 * Test fixture — its typed return makes the generator derive a convention component named
 * `Invoice` (the Spatie {@see Invoice} `Data` class).
 */
final class ConventionInvoiceController extends Controller
{
    public function show(string $id): Invoice
    {
        return new Invoice(1, 100);
    }
}
