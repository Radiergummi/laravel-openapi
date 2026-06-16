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
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

use function count;
use function is_int;

/**
 * Flags methods that carry two or more `#[Response]` attributes with the same HTTP status code,
 * which would be silently dropped from the generated spec. Only statically resolvable integers
 * are compared.
 *
 * @implements Rule<Node>
 */
final class DuplicateResponseStatusRule implements Rule
{
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
        $responses = AttributeHelpers::attributesNamed(
            AttributeHelpers::getAttributeGroups($node),
            Response::class,
        );

        if (count($responses) < 2) {
            return [];
        }

        /** @var array<int, true> $seen */
        $seen = [];
        $errors = [];

        foreach ($responses as $attribute) {
            $statusArg = AttributeHelpers::getArgument($attribute, 'status');

            if ($statusArg === null) {
                continue;
            }

            $constants = $scope->getType($statusArg->value)->getConstantScalarValues();

            if (count($constants) !== 1) {
                continue;
            }

            $status = $constants[0];

            if (!is_int($status)) {
                continue;
            }

            if (isset($seen[$status])) {
                $errors[] = RuleErrorBuilder::message(
                    "#[Response] with status {$status} is declared more than once on this target — duplicate status codes are silently dropped.",
                )
                    ->identifier('openapi.response.duplicateStatus')
                    ->line($attribute->getStartLine())
                    ->build();

                continue;
            }

            $seen[$status] = true;
        }

        return $errors;
    }
}
