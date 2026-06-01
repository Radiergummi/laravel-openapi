<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

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
            // Mirror the generator: only action-level Response attributes produce output, and an
            // inline `schema:` wins over `ref:` (see OperationBuilder::buildResponseFromAttribute).
            // Flagging class-level attributes or refs shadowed by an inline schema is a false
            // positive — those refs are never resolved, so no body is dropped.
            foreach ($descriptor->actionAttributes(Response::class) as $reflectionAttribute) {
                $response = $reflectionAttribute->newInstance();
                $ref = $response->ref;

                if ($ref === null || $response->schema !== null || $this->isResolvable($ref)) {
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

    #[Override]
    public function level(): int
    {
        return 0;
    }
}
