<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaAnnotationFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantOperationWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\OperationAnnotatedController;

uses()->group('openapi', 'plugin:spatie-data');

// region Helpers

function operationMigrationSetup(): void
{
    Route::get('/op-redundant', [OperationAnnotatedController::class, 'redundant']);
    Route::get('/op-essential', [OperationAnnotatedController::class, 'essential']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhp'],
            $app->make(LoggerInterface::class),
        ),
    );
}

/**
 * @return list<string> the "class::method" of operations flagged by the operation migration rule
 */
function operationFindingMembers(LintResult $result): array
{
    $members = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === 'migration.oa-redundant-operation-with-inference') {
            $members[] = ($finding->context['sourceClass'] ?? '?') . '::' . ($finding->context['sourceMember'] ?? '?');
        }
    }

    return $members;
}

/**
 * The 200 response JSON schema `$ref` for the GET operation whose path contains `$needle`, or null.
 */
function operationResponseRef(OA\OpenApi $document, string $needle): ?string
{
    foreach (is_array($document->paths) ? $document->paths : [] as $pathItem) {
        if (!str_contains((string) $pathItem->path, $needle) || !$pathItem->get instanceof OA\Operation) {
            continue;
        }

        foreach (is_array($pathItem->get->responses) ? $pathItem->get->responses : [] as $response) {
            if ((string) $response->response !== '200' || !is_array($response->content)) {
                continue;
            }

            foreach ($response->content as $mediaType) {
                $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

                if (is_string($ref) && str_starts_with($ref, '#/')) {
                    return $ref;
                }
            }
        }
    }

    return null;
}

// endregion

it('flags an operation annotation inference reproduces, under --only migration.*', function (): void {
    operationMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(operationFindingMembers($result))
        ->toContain(OperationAnnotatedController::class . '::redundant');
});

it('keeps an operation annotation carrying a description inference cannot derive', function (): void {
    operationMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(operationFindingMembers($result))
        ->not->toContain(OperationAnnotatedController::class . '::essential');
});

it('stays off an ordinary (default-level) lint run', function (): void {
    operationMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(operationFindingMembers($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    operationMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(operationFindingMembers($result))->toBe([]);
});

it('leaves the affected operation unchanged once the redundant annotation is gone', function (): void {
    // The "same API surface" invariant: the flagged operation's 200 response schema is identical
    // between the harvested document and the inference-only control it would collapse to.
    operationMigrationSetup();

    $harvested = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    $control = app(OpenApiGenerationOrchestrator::class)
        ->inferenceOnly('default', [HarvestAuthoredAnnotationsStage::class], 'testing')
        ->document;

    expect(operationResponseRef($control, 'op-redundant'))
        ->not->toBeNull()
        ->toBe(operationResponseRef($harvested, 'op-redundant'));
});

it('exposes its fixer and human-readable description', function (): void {
    operationMigrationSetup();

    $rule = app(OaRedundantOperationWithInference::class);

    expect($rule->fixer())
        ->toBeInstanceOf(RedundantOaAnnotationFixer::class)
        ->and($rule->description())->toContain('inference');
});
