<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;

interface Finalizable
{
    /** @return iterable<Finding> */
    public function finalize(LintContext $context): iterable;
}
