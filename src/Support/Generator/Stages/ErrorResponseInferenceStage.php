<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator\Stages;

use Exception;
use Illuminate\Container\Attributes\Scoped;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Override;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Enums\ComponentType;
use Radiergummi\OpenApi\Enums\HttpMethod;
use Radiergummi\OpenApi\Enums\MediaType;
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
 * @internal
 */
#[Scoped]
final readonly class ErrorResponseInferenceStage implements SpecStage
{
    /**
     * Public so {@see ErrorsResolverFailed} can reference it without a Support → Lint import.
     */
    public const string RESOLVER_FAILED_FIX_HINT
        = 'Fix the throwing ErrorResponseResolver — implementations must catch internally and'
        . ' return null on failure.';

    /** Maps HTTP status codes to stable `components.responses` component names. */
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

        // region Collect descriptors (first contributor wins per status)

        /** @var array<int, ErrorDescriptor> $byStatus */
        $byStatus = [];

        foreach ($this->contributors as $contributor) {
            foreach ($contributor->contribute($action) as $descriptor) {
                $byStatus[$descriptor->status] ??= $descriptor;
            }
        }

        // endregion

        // region Drop statuses already declared by explicit #[Response]

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
            // A literal body read from the controller wins over the resolver-chain envelope and is
            // inlined on this operation (never hoisted to a shared component): it is route-specific.
            if ($descriptor->bodySchema !== null) {
                $additions[] = $this->buildLiteralBodyResponse($descriptor);

                continue;
            }

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

    /** Literal body from the controller; inlined, never hoisted to a shared component. */
    private function buildLiteralBodyResponse(ErrorDescriptor $descriptor): OA\Response
    {
        return new OA\Response([
            'response' => (string) $descriptor->status,
            'description' => $descriptor->description,
            'content' => [MediaType::Json->schema($descriptor->bodySchema)],
        ]);
    }

    /**
     * Walks the resolver chain; first non-null wins. A thrown {@see Exception} emits a finding
     * and continues (a bad resolver must not abort the run). {@see \Error}/TypeError propagates:
     * programming bugs surface loudly rather than as silently missing bodies.
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
                        severity: Severity::Underspecified,
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
     * Builds the final response, hoisting to a named component only when the body is empty
     * (description-only). Resolver-produced bodies are inlined per operation to avoid
     * first-write-wins collisions on the shared component (e.g., two 422s with different envelopes).
     */
    private function buildResponse(
        ErrorDescriptor $descriptor,
        ?ErrorResponse $body,
        ?string $componentName,
    ): OA\Response {
        $description = $descriptor->description;
        $content = $headers = $links = null;

        if ($body !== null) {
            // OpenAPI 3.1 requires response.description to be non-empty.
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

        // Only share a named component when there is no body and the description is canonical.
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
