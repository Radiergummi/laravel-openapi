<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance;

/**
 * Test fixture — a trait that uses another trait ({@see InnerReportTrait}), forming the
 * trait-of-trait chain the scanner's `reachableTraits()` recursion must follow.
 */
trait OuterReportTrait
{
    use InnerReportTrait;
}
