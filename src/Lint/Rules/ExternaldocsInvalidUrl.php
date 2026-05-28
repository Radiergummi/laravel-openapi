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
use Radiergummi\OpenApi\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use ReflectionAttribute;

use function array_merge;
use function filter_var;
use function preg_match;
use function sprintf;

use const FILTER_VALIDATE_URL;

final class ExternaldocsInvalidUrl implements Rule, OperationRuleVisitor
{
    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        if ($operation->descriptor === null || $operation->descriptor->method === null) {
            return;
        }

        $methodAttributes = $operation->descriptor->method->getAttributes(ExternalDocs::class);
        $classAttributes
            = $operation->descriptor->controller?->getAttributes(ExternalDocs::class) ?? [];

        /** @var ReflectionAttribute<ExternalDocs>[] $attributes */
        $attributes = array_merge($classAttributes, $methodAttributes);

        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute) {
            $externalDocs = $attribute->newInstance();
            $url = $externalDocs->url;

            if (
                filter_var($url, FILTER_VALIDATE_URL) !== false
                && preg_match('~^https?://~i', $url) === 1
            ) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'ExternalDocs URL "%s" on %s::%s() is not a valid HTTP(S) URL',
                    $url,
                    $operation->descriptor->controller?->getName() ?? '(unknown)',
                    $operation->descriptor->method->getName(),
                ),
                fixHint: 'Provide a fully-qualified URL (e.g. https://example.com/docs).',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'externaldocs.invalid-url';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'externalDocs.url is not a valid URL.';
    }
}
