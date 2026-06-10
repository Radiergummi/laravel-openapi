<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use OpenApi\Annotations as OA;

/**
 * @OA\Schema(
 *     schema="Widget",
 *
 *     @OA\Property(property="name", type="string"),
 * )
 */
class Widget {}
