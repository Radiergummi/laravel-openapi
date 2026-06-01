<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;

interface OperationRule extends Visitor
{
    /**
     * @return iterable<Finding>
     */
    public function checkOperation(OperationNode $operation, LintContext $context): iterable;
}
