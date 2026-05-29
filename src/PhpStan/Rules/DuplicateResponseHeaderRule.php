<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleError;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\ShouldNotHappenException;
use Radiergummi\OpenApi\Attributes\ResponseHeader;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function count;
use function is_int;
use function is_string;
use function strtolower;

/**
 * Flags declarations that carry two or more `#[ResponseHeader]` attributes sharing the same
 * `(status, name)` pair. A header is keyed by both within a response, so the second declaration
 * is silently lost. Header names are compared case-insensitively to mirror HTTP semantics —
 * `X-Request-Id` and `x-request-id` collide. Pairs that don't resolve to a constant scalar are
 * skipped, including the case where the `status` argument is present but its value is not
 * statically resolvable (we can't tell which response slot it would occupy).
 *
 * One service is registered per declaration node kind (FunctionLike / ClassLike).
 *
 * @implements Rule<Node>
 */
final class DuplicateResponseHeaderRule implements Rule
{
    private const int DEFAULT_STATUS = 200;

    /**
     * @param class-string<Node> $nodeType
     */
    public function __construct(private readonly string $nodeType) {}

    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $headers = AttributeHelpers::attributesNamed(
            AttributeHelpers::getAttributeGroups($node),
            ResponseHeader::class,
        );

        if (count($headers) < 2) {
            return [];
        }

        /** @var array<string, true> $seen */
        $seen = [];
        $errors = [];

        foreach ($headers as $attribute) {
            $name = self::resolveName($attribute, $scope);

            if ($name === null) {
                continue;
            }

            $status = self::resolveStatus($attribute, $scope);

            if ($status === null) {
                continue;
            }

            $key = $status . ':' . strtolower($name);

            if (isset($seen[$key])) {
                $errors[] = RuleErrorBuilder::message(
                    "#[ResponseHeader] '{$name}' for status {$status} is declared more than once on this target — the duplicate is silently dropped.",
                )
                    ->identifier('openapi.responseHeader.duplicate')
                    ->line($attribute->getStartLine())
                    ->build();

                continue;
            }

            $seen[$key] = true;
        }

        return $errors;
    }

    private static function resolveName(Node\Attribute $attribute, Scope $scope): ?string
    {
        $argument = AttributeHelpers::getArgument($attribute, 'name');

        if ($argument === null) {
            return null;
        }

        $constants = $scope->getType($argument->value)->getConstantScalarValues();

        if (count($constants) !== 1 || !is_string($constants[0])) {
            return null;
        }

        return $constants[0];
    }

    /**
     * Resolves the `status` argument to a constant integer. Returns the default 200 when the
     * argument is absent, but null when it is present yet not statically resolvable — that case
     * is genuine "unknown", not the default, and we must not collapse the two together (or
     * unrelated `status: $dynamic` declarations would falsely dedupe against each other).
     */
    private static function resolveStatus(Node\Attribute $attribute, Scope $scope): ?int
    {
        $statusArg = AttributeHelpers::getArgument($attribute, 'status');

        if ($statusArg === null) {
            return self::DEFAULT_STATUS;
        }

        $constants = $scope->getType($statusArg->value)->getConstantScalarValues();

        if (count($constants) !== 1 || !is_int($constants[0])) {
            return null;
        }

        return $constants[0];
    }
}
