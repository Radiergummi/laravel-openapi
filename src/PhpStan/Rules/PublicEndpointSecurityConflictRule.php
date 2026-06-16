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
use Radiergummi\OpenApi\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Attributes\Security;
use Radiergummi\OpenApi\PhpStan\Support\AttributeHelpers;

/**
 * Flags declarations that carry both `#[PublicEndpoint]` and `#[Security]`. The two are mutually
 * exclusive: `PublicEndpoint` emits `security: []`, `Security` emits a requirement. One instance
 * is registered per node kind (FunctionLike / ClassLike).
 *
 * @implements Rule<Node>
 */
final class PublicEndpointSecurityConflictRule implements Rule
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
        $attributesByFqn = AttributeHelpers::attributesByFqn(AttributeHelpers::getAttributeGroups($node));
        $publicAttrs = $attributesByFqn[PublicEndpoint::class] ?? [];
        $securityAttrs = $attributesByFqn[Security::class] ?? [];

        if ($publicAttrs === [] || $securityAttrs === []) {
            return [];
        }

        $line = min(
            $publicAttrs[0]->getStartLine(),
            $securityAttrs[0]->getStartLine(),
        );

        return [
            RuleErrorBuilder::message(
                '#[PublicEndpoint] and #[Security] cannot coexist on the same target — they contradict each other.',
            )
                ->identifier('openapi.security.publicAndSecuredConflict')
                ->line($line)
                ->build(),
        ];
    }
}
