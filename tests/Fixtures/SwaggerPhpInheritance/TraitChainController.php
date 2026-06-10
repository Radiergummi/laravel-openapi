<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

use Illuminate\Routing\Controller;

/**
 * Test fixture — uses {@see InnerReportTrait} both transitively (via {@see OuterReportTrait}) and
 * directly, so the scanner reaches the inner trait twice: once through the recursion and once on
 * the second pass (hitting the re-visit dedup). The handler's `@OA\Get` is declared on the inner
 * trait, so matching it requires the ancestry walk.
 */
final class TraitChainController extends Controller
{
    use OuterReportTrait;
    use InnerReportTrait;
}
