<?php

declare(strict_types=1);

use Illuminate\Routing\Route;
use Psr\Log\AbstractLogger;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;
use Radiergummi\OpenApi\Enums\MediaType;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Extraction\RequestBodyExtractor;
use Radiergummi\OpenApi\Support\Registry\ResolvedSchema;
use Radiergummi\OpenApi\Support\Registry\ResolverFaultBoundary;

uses()->group('openapi');

// region Helpers

function requestBodyFaultDescriptor(): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route('POST', 'orders', []),
        controller: null,
        method: null,
        summary: null,
        description: null,
    );
}

// endregion

it('skips a request-schema resolver that throws and falls through to the next', function (): void {
    $logger = new class () extends AbstractLogger {
        /** @var list<string> */
        public array $records = [];

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->records[] = (string) $message;
        }
    };

    $throwing = new class () implements RequestSchemaResolver {
        public function resolveRequestSchema(ActionDescriptor $descriptor): ?ResolvedSchema
        {
            throw new RuntimeException('body resolver failed');
        }
    };

    $healthy = new class () implements RequestSchemaResolver {
        public function resolveRequestSchema(ActionDescriptor $descriptor): ResolvedSchema
        {
            return new ResolvedSchema(componentKey: 'OrderPayload', mediaType: MediaType::Json);
        }
    };

    $extractor = new RequestBodyExtractor(
        resolvers: [$throwing, $healthy],
        findings: new ArrayFindingsCollector(),
        faultBoundary: new ResolverFaultBoundary($logger),
    );

    $body = $extractor->extractFromMethod(requestBodyFaultDescriptor());

    expect($body)->not->toBeNull()
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0])->toContain('orders')
        ->and($logger->records[0])->toContain('body resolver failed');
});
