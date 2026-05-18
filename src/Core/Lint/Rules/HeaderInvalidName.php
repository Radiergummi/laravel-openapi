<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\Header;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use ReflectionAttribute;

use function array_merge;
use function preg_match;
use function sprintf;

/**
 * Reports header names declared via #[Header] attributes that are not valid HTTP tokens per RFC
 * 7230 §3.2.6.
 */
final class HeaderInvalidName implements Rule, OperationRuleVisitor
{
    /**
     * HTTP token grammar from RFC 7230 §3.2.6:
     * token = 1*tchar
     * tchar = "!" / "#" / "$" / "%" / "&" / "'" / "*" / "+" / "-" / "." /
     *         "^" / "_" / "`" / "|" / "~" / DIGIT / ALPHA
     */
    private const HTTP_TOKEN_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($descriptor?->method === null) {
            return;
        }

        $methodAttributes = $descriptor->method->getAttributes(Header::class);
        $classAttributes = $descriptor->controller?->getAttributes(Header::class) ?? [];

        /** @var ReflectionAttribute[] $attributes */
        $attributes = array_merge($classAttributes, $methodAttributes);

        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute) {
            $header = $attribute->newInstance();

            if (preg_match(self::HTTP_TOKEN_PATTERN, $header->name) === 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    'Header name "%s" on %s::%s() is not a valid HTTP token',
                    $header->name,
                    $descriptor->controller?->getName() ?? '(unknown)',
                    $descriptor->method->getName(),
                ),
                fixHint: 'Use a valid HTTP token for the header name (alphanumerics and !#$%&\'*+-.^_`|~ only).',
            );
        }
    }

    #[Override]
    public function id(): string
    {
        return 'header.invalid-name';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return 'Header name contains invalid characters.';
    }
}
