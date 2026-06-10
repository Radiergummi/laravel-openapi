<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="SalesReport",
 *
 *     @OA\Property(property="total", type="integer"),
 * )
 */
class SalesReport {}
