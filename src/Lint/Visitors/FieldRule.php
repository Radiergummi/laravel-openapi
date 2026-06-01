<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Visitors;

use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\FieldNode;

interface FieldRule extends Visitor
{
    /**
     * @return iterable<Finding>
     */
    public function checkField(FieldNode $field, LintContext $context): iterable;
}
