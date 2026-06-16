<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\PhpStan\Rules;

use Override;
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
 * Flags duplicate `#[ResponseHeader]` attributes sharing the same `(status, name)` pair, which
 * would silently drop the second. Header names are compared case-insensitively. Pairs that don't
 * resolve to a constant scalar are skipped. One instance is registered per node kind.
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

    #[Override]
    public function getNodeType(): string
    {
        return $this->nodeType;
    }

    /**
     * @return list<RuleError>
     *
     * @throws ShouldNotHappenException
     */
    #[Override]
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
     * Returns 200 when the `status` argument is absent, null when present but not statically
     * resolvable. Absent and unresolvable must not be collapsed: dynamic status values must not
     * falsely dedupe against each other.
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
