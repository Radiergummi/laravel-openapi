<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpCollision;

/**
 * Test fixture — a hand-authored `@OA\Schema` named `Invoice` with a shape (a `number` string)
 * deliberately different from the convention {@see Invoice} component, to exercise the
 * `component.schema-name-collision` finding.
 *
 * @OA\Schema(
 *     schema="Invoice",
 *
 *     @OA\Property(property="number", type="string"),
 * )
 */
class AuthoredInvoiceSchema {}
