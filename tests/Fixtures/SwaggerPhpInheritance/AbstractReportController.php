<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use Illuminate\Routing\Controller;
use OpenApi\Annotations as OA;

/**
 * Test fixture — the `@OA\Get` operation is authored on this **parent** method; the routed
 * controller {@see SalesReportController} inherits it without redeclaring. Exercises #187's
 * ancestry-walk lookup (route points at the subclass, annotation indexed under the parent).
 */
abstract class AbstractReportController extends Controller
{
    /**
     * @OA\Get(
     *     path="/reports/sales",
     *     summary="Authored on the parent controller",
     *
     *     @OA\Response(
     *         response=200,
     *         description="OK",
     *
     *         @OA\JsonContent(ref="#/components/schemas/SalesReport"),
     *     ),
     * )
     */
    public function index(): array
    {
        return [];
    }
}
