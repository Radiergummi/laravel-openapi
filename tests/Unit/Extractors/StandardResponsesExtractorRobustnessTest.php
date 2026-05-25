<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Routing\Route;
use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Core\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Core\Errors\ErrorResponse;
use Radiergummi\OpenApi\Core\Extractors\StandardResponsesExtractor;
use Radiergummi\OpenApi\Core\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\FindingsCollector;
use Radiergummi\OpenApi\Core\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;

uses()->group('openapi');

function recordingFindingsCollector(): FindingsCollector
{
    return new class () implements FindingsCollector {
        /** @var list<Finding> */
        public array $findings = [];

        public function emit(Finding $finding): void
        {
            $this->findings[] = $finding;
        }
    };
}

function descriptorWithThrows(array $throws): ActionDescriptor
{
    return new ActionDescriptor(
        route: new Route(['GET'], '/widgets', static fn() => null),
        controller: null,
        method: null,
        summary: null,
        description: null,
        throws: $throws,
    );
}

// region Interface @throws acceptance

it('stores the exception class when @throws references a Throwable interface', function (): void {
    $resolver = new class () implements ErrorResponseResolver {
        public ?ErrorDescriptor $captured = null;

        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            $this->captured = $descriptor;

            return ErrorResponse::bodyless();
        }
    };

    $extractor = new StandardResponsesExtractor(
        registry: new ComponentSchemaRegistry(),
        findings: recordingFindingsCollector(),
        errorResponseResolvers: [$resolver],
        exceptionMap: [
            'Throwable' => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $extractor->extract(descriptorWithThrows([Throwable::class]));

    expect($resolver->captured)->not->toBeNull()
        ->and($resolver->captured->exceptionClass)->toBe(Throwable::class);
});

// endregion

// region Empty-string description does not clobber default

it('does not override the descriptor default description when a resolver returns an empty string', function (): void {
    $resolver = new class () implements ErrorResponseResolver {
        public function resolveErrorResponse(ErrorDescriptor $descriptor): ErrorResponse
        {
            return new ErrorResponse(
                content: [new OA\MediaType(['mediaType' => 'application/json'])],
                description: '',
            );
        }
    };

    $extractor = new StandardResponsesExtractor(
        registry: new ComponentSchemaRegistry(),
        findings: recordingFindingsCollector(),
        errorResponseResolvers: [$resolver],
        exceptionMap: [
            'RuntimeException' => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $responses = $extractor->extract(descriptorWithThrows([RuntimeException::class]));

    expect($responses)->toHaveCount(1);
    expect($responses[0]->description)->toBe('Server error');
});

// endregion

// region Resolver chain robustness

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

    $collector = recordingFindingsCollector();
    $extractor = new StandardResponsesExtractor(
        registry: new ComponentSchemaRegistry(),
        findings: $collector,
        errorResponseResolvers: [$throwing, $fallback],
        exceptionMap: [
            'RuntimeException' => ['status' => 500, 'description' => 'Server error'],
        ],
    );

    $responses = $extractor->extract(descriptorWithThrows([RuntimeException::class]));

    expect($responses)->toHaveCount(1);
    expect($collector->findings)->toHaveCount(1);
    expect($collector->findings[0]->ruleId)->toBe('errors.resolver-failed');
    expect($collector->findings[0]->message)->toContain('resolver exploded');
});

// endregion
