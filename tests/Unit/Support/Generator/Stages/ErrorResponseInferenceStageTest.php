<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Errors\ErrorResponse;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\Stages\ErrorResponseInferenceStage;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;

uses()->group('openapi');

// region Helpers

function inferenceStageSpec(): SpecDefinition
{
    return new SpecDefinition(
        name: 'default',
        info: new OA\Info(['title' => 'Test', 'version' => '0.0.0']),
        servers: [],
        tags: [],
        match: [],
        outputPath: 'openapi.yaml',
        routeUri: null,
        playgroundUri: null,
    );
}

function inferenceStageDescriptor(): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/x', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
        throws: [],
    );
}

function docWithGetOperation(OA\Get $operation): OA\OpenApi
{
    $pathItem = new OA\PathItem(['path' => '/x']);
    $pathItem->get = $operation;
    $doc = new OA\OpenApi([]);
    $doc->paths = [$pathItem];

    return $doc;
}

// endregion

// region Case 1: No bound action — operation left untouched

it('leaves an operation untouched when it has no bound action', function (): void {
    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    // Deliberately NOT binding the operation to any action.

    $stage->apply($doc, $ctx);

    expect($operation->responses)->toBe(OpenApi\Generator::UNDEFINED);
});

// endregion

// region Case 2: Single contributor, single status → response appended

it('appends a response when a single contributor returns one descriptor', function (): void {
    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 404, exceptionClass: null, description: 'Not found')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and((int) $operation->responses[0]->response)->toBe(404);
});

// endregion

// region Case 3: First contributor wins per status (Precedence rule 2)

it('uses the first contributor\'s descriptor when two contributors both return the same status', function (): void {
    $first = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 422, exceptionClass: null, description: 'First description')];
        }
    };

    $second = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 422, exceptionClass: null, description: 'Second description')];
        }
    };

    $registry = new ComponentSchemaRegistry();

    $stage = new ErrorResponseInferenceStage(
        contributors: [$first, $second],
        errorResponseResolvers: [],
        registry: $registry,
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    // One inlined $ref response for status 422; the description lives in the named
    // component registered under "ValidationFailed". Assert that it is the *first*
    // contributor's description — proving the ??= first-wins guard is effective.
    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and((int) $operation->responses[0]->response)->toBe(422);

    $namedResponses = $registry->allResponses();

    expect($namedResponses)
        ->toHaveCount(1)
        ->and($namedResponses[0]->description)->toBe('First description');
});

// endregion

// region Case 4: Explicit #[Response] wins over inferred (Precedence rule 1)

it('preserves an explicit response and drops a contributor\'s descriptor for the same status', function (): void {
    $explicit = new OA\Response(['response' => '422', 'description' => 'Explicit 422']);

    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 422, exceptionClass: null, description: 'Inferred 422')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $operation->responses = [$explicit];
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    // Still exactly one response — the explicit one. Inferred was dropped.
    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($operation->responses[0])->toBe($explicit)
        ->and($operation->responses[0]->description)->toBe('Explicit 422');
});

// endregion

// region Case 5: Envelope chain invoked — response content reflects resolver body

it('includes content from the resolver in the produced response', function (): void {
    $mediaType = new OA\MediaType(['mediaType' => 'application/json']);

    $resolver = new readonly class ($mediaType) implements ErrorResponseResolver {
        public function __construct(private OA\MediaType $mediaType) {}

        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return new ErrorResponse(content: [$this->mediaType]);
        }
    };

    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [$resolver],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($operation->responses[0]->content)->toContain($mediaType);
});

// endregion

// region Case 6: Empty-string description does not clobber default

it('does not override the descriptor default description when a resolver returns an empty string', function (): void {
    $mediaType = new OA\MediaType(['mediaType' => 'application/json']);

    $resolver = new readonly class ($mediaType) implements ErrorResponseResolver {
        public function __construct(private OA\MediaType $mediaType) {}

        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return new ErrorResponse(
                content: [$this->mediaType],
                description: '',
            );
        }
    };

    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [$resolver],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and($operation->responses[0]->description)->toBe('Server error');
});

// endregion

// region Case 7: Resolver chain robustness — throwing resolver emits finding and chain continues

it('emits a finding and continues the chain when a resolver throws', function (): void {
    $throwing = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            throw new RuntimeException('resolver exploded');
        }
    };

    $fallback = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return ErrorResponse::bodyless();
        }
    };

    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error')];
        }
    };

    $collector = new ArrayFindingsCollector();
    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [$throwing, $fallback],
        registry: new ComponentSchemaRegistry(),
        findings: $collector,
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)->toBeArray()->toHaveCount(1);
    expect($collector->all())->toHaveCount(1);
    expect($collector->all()[0]->ruleId)->toBe('errors.resolver-failed');
    expect($collector->all()[0]->message)->toContain('resolver exploded');
});

// endregion

// region Case 8: Webhook operations are decorated like path operations

it('decorates operations attached to webhooks, not just paths', function (): void {
    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error')];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Post([]);
    $webhook = new OA\Webhook(['webhook' => 'event.fired']);
    $webhook->post = $operation;
    $doc = new OA\OpenApi([]);
    $doc->webhooks = [$webhook];
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)
        ->toBeArray()
        ->toHaveCount(1)
        ->and((int) $operation->responses[0]->response)->toBe(500);
});

// endregion

// region Case 9: Multiple statuses sorted ascending

it('appends inferred responses in ascending status order', function (): void {
    $contributor = new class () implements ErrorResponseContributor {
        public function contribute(ActionDescriptor $descriptor): array
        {
            return [
                new ErrorDescriptor(status: 500, exceptionClass: null, description: 'Server error'),
                new ErrorDescriptor(status: 422, exceptionClass: null, description: 'Unprocessable'),
                new ErrorDescriptor(status: 401, exceptionClass: null, description: 'Unauthorized'),
            ];
        }
    };

    $stage = new ErrorResponseInferenceStage(
        contributors: [$contributor],
        errorResponseResolvers: [],
        registry: new ComponentSchemaRegistry(),
        findings: new ArrayFindingsCollector(),
    );

    $operation = new OA\Get([]);
    $doc = docWithGetOperation($operation);
    $ctx = new GenerationContext(inferenceStageSpec(), 'testing');
    $ctx->bindAction($operation, inferenceStageDescriptor());

    $stage->apply($doc, $ctx);

    expect($operation->responses)->toBeArray()->toHaveCount(3);

    $statuses = array_map(static fn(OA\Response $r): int => (int) $r->response, $operation->responses);

    expect($statuses)->toBe([401, 422, 500]);
});

// endregion
