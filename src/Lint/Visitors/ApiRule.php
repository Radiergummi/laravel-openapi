<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\ApiNode;

interface ApiRule extends Visitor
{
    /**
     * @return iterable<Finding>
     */
    public function checkApi(ApiNode $api, LintContext $context): iterable;
}
