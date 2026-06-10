<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use Illuminate\Routing\Controller;

/**
 * Test fixture — routed controller whose `index()` handler (and the authored `@OA\Get` on it) is
 * provided by {@see ListsWidgetsTrait}.
 */
final class WidgetController extends Controller
{
    use ListsWidgetsTrait;
}
