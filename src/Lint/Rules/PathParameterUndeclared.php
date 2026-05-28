<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Tree\ParameterNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;

use function array_map;
use function in_array;
use function ltrim;
use function preg_match_all;
use function sprintf;

/**
 * Reports path template placeholders that are not declared as path parameters on the operation.
 *
 * For example, if the path is `/users/{userId}/posts/{postId}` but only `userId` is declared as a
 * parameter, this rule will flag `postId`.
 */
final class PathParameterUndeclared implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $placeholders = $this->extractPlaceholders($operation->pathUri);

        if ($placeholders === []) {
            return;
        }

        $declaredNames = array_map(
            static fn(ParameterNode $parameter): string => $parameter->name,
            $operation->parameters,
        );

        foreach ($placeholders as $placeholder) {
            if (in_array($placeholder, $declaredNames, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Path placeholder "{%s}" on %s %s is not declared as a path parameter',
                    $placeholder,
                    $operation->method,
                    $operation->pathUri,
                ),
                fixHint: sprintf(
                    'Add a parameter with name="%s" and in="path" to this operation.',
                    $placeholder,
                ),
            );
        }
    }

    /**
     * Extract `{...}` placeholders from a path template.
     *
     * @return list<string>
     */
    private function extractPlaceholders(string $pathUri): array
    {
        if (preg_match_all("/\{([^?}]+)\??}/", $pathUri, $matches)) {
            return array_map(
                static fn(string $name): string => ltrim($name, '+#./;?&=,!@|'),
                $matches[1],
            );
        }

        return [];
    }

    #[Override]
    public function id(): string
    {
        return 'path.parameter-undeclared';
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    #[Override]
    public function description(): string
    {
        return 'Path template uses a variable not declared as a parameter.';
    }
}
