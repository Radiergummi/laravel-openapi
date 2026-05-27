<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingLocation;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Core\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;

/**
 * Reports `#[Response(ref:)]` arguments that no registered {@see RefSchemaResolver} can resolve.
 *
 * When a ref points to a class outside every registered convention (e.g. a Spatie Data class
 * while the SpatieData plugin is disabled, or a plain class no resolver recognises), the generator
 * silently drops the body: `OperationBuilder` emits the response with no content and no broken
 * `$ref` for `ref.broken` to catch. This rule surfaces that silent degradation at lint time,
 * mirroring `spec.unknown-reference` for `#[Spec]`.
 *
 * Uses the side-effect-free {@see RefSchemaResolver::canResolve()} so the check never builds or
 * registers a component schema.
 */
final readonly class ResponseRefUnresolvable implements Rule, PreBuildRule
{
    public const string ID = 'response.ref-unresolvable';

    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(private array $refSchemaResolvers) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return '#[Response(ref:)] points to a class no registered schema resolver can resolve; the response is emitted without a body schema.';
    }

    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        foreach ($descriptors as $descriptor) {
            foreach ($descriptor->attributeInstances(Response::class) as $response) {
                $ref = $response->ref;

                if ($ref === null || $this->isResolvable($ref)) {
                    continue;
                }

                $findings->emit(
                    new Finding(
                        ruleId: self::ID,
                        level: $this->level(),
                        message: "Response ref '{$ref}' on status {$response->status} cannot be resolved by any registered schema resolver; the response will be emitted without a body schema.",
                        location: FindingLocation::fromDescriptor($descriptor),
                        fixHint: 'Reference a class a registered schema resolver handles, or supply an inline `schema:` instead.',
                        context: ['ref' => $ref, 'status' => $response->status],
                    ),
                );
            }
        }
    }

    #[Override]
    public function level(): int
    {
        return 0;
    }

    /**
     * @param class-string $class
     */
    private function isResolvable(string $class): bool
    {
        foreach ($this->refSchemaResolvers as $resolver) {
            if ($resolver->canResolve($class)) {
                return true;
            }
        }

        return false;
    }
}
