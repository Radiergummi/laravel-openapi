<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

/**
 * Test fixture — routed controller that inherits its `index()` handler (and the authored `@OA\Get`
 * on it) from {@see AbstractReportController}.
 */
final class SalesReportController extends AbstractReportController {}
