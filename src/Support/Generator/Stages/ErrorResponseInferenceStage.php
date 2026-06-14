<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Exception;
use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Lint\Rules\ErrorsResolverFailed;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

use function is_array;
use function ksort;
use function sprintf;

/**
 * Drives the contributor chain and writes inferred error responses into each operation.
 *
 * Iterates every path item and operation in the assembled document. For each operation that
 * has a bound {@see ActionDescriptor}, it collects {@see ErrorDescriptor}s from every
 * registered {@see ErrorResponseContributor}, deduplicates by status (first contributor wins),
 * drops any status already declared by an explicit {@see OA\Response} attribute, resolves the
 * error envelope body via the {@see ErrorResponseResolver} chain, and appends the produced
 * responses to the operation.
 *
 * @internal
 */
#[Scoped]
final readonly class ErrorResponseInferenceStage implements SpecStage
{
    /**
     * Fix hint emitted with every `errors.resolver-failed` finding. Public, so the lint rule
     * stub ({@see ErrorsResolverFailed}) can reference it without forcing a Support → Lint import.
     */
    public const string RESOLVER_FAILED_FIX_HINT
        = 'Fix the throwing ErrorResponseResolver — implementations must catch internally and'
        . ' return null on failure.';

    /**
     * Maps HTTP status codes to stable `components.responses` component names.
     *
     * Derived from the description text in `config('openapi.exception_responses')`.
     */
    private const array STATUS_COMPONENT_NAMES = [
        400 => 'BadRequest',
        401 => 'Unauthorized',
        402 => 'PaymentRequired',
        403 => 'Forbidden',
        404 => 'NotFound',
        405 => 'MethodNotAllowed',
        409 => 'Conflict',
        422 => 'ValidationFailed',
        429 => 'TooManyRequests',
        500 => 'InternalServerError',
    ];

    /**
     * @param list<ErrorResponseContributor> $contributors
     * @param list<ErrorResponseResolver>    $errorResponseResolvers
     */
    public function __construct(
        private array $contributors,
        private array $errorResponseResolvers,
        private ComponentSchemaRegistry $registry,
        private FindingsCollector $findings,
    ) {}

    #[Override]
    public function apply(OA\OpenApi $document, GenerationContext $context): void
    {
        if (is_array($document->paths)) {
            $this->decorateContainers($document->paths, $context);
        }

        if (is_array($document->webhooks)) {
            $this->decorateContainers($document->webhooks, $context);
        }
    }

    /**
     * @param array<OA\PathItem|OA\Webhook> $containers
     */
    private function decorateContainers(array $containers, GenerationContext $ctx): void
    {
        foreach ($containers as $container) {
            foreach (HttpMethod::cases() as $method) {
                $operation = $container->{$method->value} ?? Generator::UNDEFINED;

                if (!$operation instanceof OA\Operation) {
                    continue;
                }

                $this->decorate($operation, $ctx);
            }
        }
    }

    private function decorate(OA\Operation $operation, GenerationContext $context): void
    {
        $action = $context->actionFor($operation);

        if ($action === null) {
            return;
        }

        // region Collect descriptors — first contributor wins per status (Precedence rule 2)

        /** @var array<int, ErrorDescriptor> $byStatus */
        $byStatus = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->contribute($action) as $descriptor) {
                $byStatus[$descriptor->status] ??= $descriptor;
            }
        }

        // endregion

        // region Drop statuses already declared by explicit #[Response] (Precedence rule 1)

        $existing = is_array($operation->responses) ? $operation->responses : [];

        foreach ($existing as $resp) {
            unset($byStatus[(int) $resp->response]);
        }

        // endregion

        if ($byStatus === []) {
            return;
        }

        ksort($byStatus);

        $additions = [];

        foreach ($byStatus as $descriptor) {
            $body = $this->resolveBody($descriptor);
            $componentName = self::STATUS_COMPONENT_NAMES[$descriptor->status] ?? null;
            $additions[] = $this->buildResponse(
                $descriptor,
                $body,
                $componentName,
            );
        }

        $operation->responses = [...$existing, ...$additions];
    }

    // region Body resolution helpers

    /**
     * Walks the resolver chain for one descriptor. First non-null wins. Returns null when every
     * resolver passes — the stage then emits a bodyless response.
     *
     * The {@see ErrorResponseResolver} contract requires implementations to catch internally and
     * return null on failure, but the stage defends against misbehaving resolvers anyway: a
     * thrown {@see Exception} emits a `errors.resolver-failed` finding and the chain continues,
     * matching the spec's promise that a single bad resolver does not abort the full generation run.
     *
     * {@see \Error}/`TypeError` — programming bugs in first-party or plugin resolver code — are
     * intentionally not caught: they propagate as a loud stack trace rather than disappearing into
     * a silently missing body, matching the policy of {@see \Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary}.
     */
    private function resolveBody(ErrorDescriptor $descriptor): ?ErrorResponse
    {
        foreach ($this->errorResponseResolvers as $resolver) {
            try {
                $body = $resolver->resolveErrorResponse($descriptor);
            } catch (Exception $e) {
                $this->findings->emit(
                    new Finding(
                        ruleId: 'errors.resolver-failed',
                        level: 2,
                        message: sprintf(
                            'Error-response resolver %s threw %s while resolving status %d: %s',
                            $resolver::class,
                            $e::class,
                            $descriptor->status,
                            $e->getMessage(),
                        ),
                        location: new FindingLocation(
                            routeName: $descriptor->action?->route->getName(),
                            routeUri: $descriptor->action?->route->uri(),
                        ),
                        fixHint: self::RESOLVER_FAILED_FIX_HINT,
                        context: [
                            'resolver' => $resolver::class,
                            'status' => $descriptor->status,
                            'exception' => $descriptor->exceptionClass,
                        ],
                    ),
                );

                continue;
            }

            if ($body !== null) {
                return $body;
            }
        }

        return null;
    }

    /**
     * Composes the resolver's body slice with the stage-owned fields: response key, default
     * description, named-component registration.
     *
     * A named component is only used when the body is empty (description-only). When a resolver
     * produces content, headers, or links, the response is inlined per operation to avoid
     * first-write-wins collisions on the shared component — e.g. two operations at 422 with
     * different resolver outputs (generic Error vs. ValidationError) would otherwise silently share
     * the first registration. The shared schemas referenced inside the content (e.g.
     * `$ref: '#/components/schemas/Error'`) are still reused; only the response wrapper is inlined.
     */
    private function buildResponse(
        ErrorDescriptor $descriptor,
        ?ErrorResponse $body,
        ?string $componentName,
    ): OA\Response {
        $description = $descriptor->description;
        $content = $headers = $links = null;

        if ($body !== null) {
            // Only override the curated default description when the resolver supplied a non-empty
            // string; OpenAPI 3.1 requires response.description to be non-empty.
            if ($body->description !== null && $body->description !== '') {
                $description = $body->description;
            }

            if ($body->content !== []) {
                $content = $body->content;
            }

            if ($body->headers !== []) {
                $headers = $body->headers;
            }

            if ($body->links !== []) {
                $links = $body->links;
            }
        }

        $hasBody = $content !== null || $headers !== null || $links !== null;

        // Share via a named response component only when the body is empty (description-only)
        // and the description is canonical for the status. Resolver-produced bodies may vary by
        // descriptor — e.g., validation vs. generic at status 422 — and route-authored
        // descriptions (an abort() message) vary by operation, so both are inlined per operation
        // to avoid first-write-wins collisions on the shared component.
        if ($componentName !== null && !$hasBody && $descriptor->shareableDescription) {
            $this->registry->registerNamedResponse(
                $componentName,
                new OA\Response([
                    'response' => $componentName,
                    'description' => $description,
                ]),
            );

            return new OA\Response([
                'response' => (string) $descriptor->status,
                'ref' => $this->registry->qualifyKey($componentName, ComponentType::Responses),
            ]);
        }

        $properties = [
            'response' => (string) $descriptor->status,
            'description' => $description,
        ];

        if ($content !== null) {
            $properties['content'] = $content;
        }

        if ($headers !== null) {
            $properties['headers'] = $headers;
        }

        if ($links !== null) {
            $properties['links'] = $links;
        }

        return new OA\Response($properties);
    }

    // endregion
}
