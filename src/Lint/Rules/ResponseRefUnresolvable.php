<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Response;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Visitors\PreBuildRule;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

/**
 * Reports `#[Response(ref:)]` arguments that no registered {@see RefSchemaResolver} can resolve.
 *
 * When a ref class is outside every registered convention, the generator silently emits the
 * response with no body schema (no broken `$ref` for `ref.broken` to catch). This rule surfaces
 * that silent degradation at lint time, using the side-effect-free `canResolve()` check.
 */
final class ResponseRefUnresolvable implements Rule, PreBuildRule
{
    public string $id = self::ID;
    public Severity $severity = Severity::Broken;
    public string $description = '#[Response(ref:)] points to a class no registered schema resolver can resolve; the response is emitted without a body schema.';

    public const string ID = 'response.ref-unresolvable';

    /**
     * @param list<RefSchemaResolver> $refSchemaResolvers
     */
    public function __construct(private array $refSchemaResolvers) {}



    #[Override]
    public function checkConfiguration(
        SpecRegistry $specs,
        array $descriptors,
        FindingsCollector $findings,
    ): void {
        foreach ($descriptors as $descriptor) {
            // Only action-level attributes; an inline `schema:` wins over `ref:`, so flagging
            // class-level or shadowed refs would be a false positive.
            foreach ($descriptor->actionAttributes(Response::class) as $reflectionAttribute) {
                $response = $reflectionAttribute->newInstance();
                $ref = $response->ref;

                if ($ref === null || $response->schema !== null || $this->isResolvable($ref)) {
                    continue;
                }

                $findings->emit(
                    new Finding(
                        ruleId: self::ID,
                        severity: $this->severity,
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
        return array_any(
            $this->refSchemaResolvers,
            fn(RefSchemaResolver $resolver): bool => $resolver->canResolve($class),
        );
    }

}
