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
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantComponentWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpComponentMigration\RedundantComponentController;

uses()->group('openapi', 'plugin:spatie-data');

const COMPONENT_MIGRATION_RULE = 'migration.oa-redundant-component-with-inference';

// region Helpers

function componentMigrationSetup(): void
{
    Route::get('/component-redundant', [RedundantComponentController::class, 'redundant']);
    Route::get('/component-essential', [RedundantComponentController::class, 'essential']);
    Route::get('/component-param/{record}', [RedundantComponentController::class, 'param']);
    Route::get('/component-aliased', [RedundantComponentController::class, 'aliased']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [
                dirname(__DIR__) . '/Fixtures/SwaggerPhpComponentMigration',
                dirname(__DIR__) . '/Fixtures/SwaggerPhp',
            ],
            $app->make(LoggerInterface::class),
        ),
    );
}

/**
 * @return list<string> the component names flagged by the component migration rule
 */
function componentFindingNames(LintResult $result): array
{
    $names = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === COMPONENT_MIGRATION_RULE) {
            $names[] = (string) ($finding->context['componentName'] ?? '?');
        }
    }

    return $names;
}

/**
 * The 200-response JSON schema `$ref` for the GET operation whose path contains `$needle`, or null.
 *
 * Follows a `#/components/responses/*` ref to the response component body when the operation refers
 * to one (the harvested side), so the harvested ref-to-component and the inference-only inline
 * response are compared by the schema they ultimately resolve to.
 */
function componentResponseRef(OA\OpenApi $document, string $needle): ?string
{
    foreach (is_array($document->paths) ? $document->paths : [] as $pathItem) {
        if (!str_contains((string) $pathItem->path, $needle) || !$pathItem->get instanceof OA\Operation) {
            continue;
        }

        foreach (is_array($pathItem->get->responses) ? $pathItem->get->responses : [] as $response) {
            if ((string) $response->response !== '200') {
                continue;
            }

            $resolved = componentResponseRefIn(componentResponseBody($document, $response));

            if ($resolved !== null) {
                return $resolved;
            }
        }
    }

    return null;
}

/**
 * Resolves a response's `#/components/responses/*` ref to its component body, or returns the
 * response itself when it is inline.
 */
function componentResponseBody(OA\OpenApi $document, OA\Response $response): OA\Response
{
    $ref = is_string($response->ref) ? $response->ref : null;

    if ($ref === null || !str_starts_with($ref, '#/components/responses/')) {
        return $response;
    }

    $name = substr($ref, strlen('#/components/responses/'));
    $components = $document->components;
    $responses = $components instanceof OA\Components && is_array($components->responses) ? $components->responses : [];

    foreach ($responses as $component) {
        if ((string) $component->response === $name) {
            return $component;
        }
    }

    return $response;
}

function componentResponseRefIn(OA\Response $body): ?string
{
    foreach (is_array($body->content) ? $body->content : [] as $mediaType) {
        $ref = $mediaType->schema instanceof OA\Schema ? $mediaType->schema->ref : null;

        if (is_string($ref) && str_starts_with($ref, '#/')) {
            return $ref;
        }
    }

    return null;
}

// endregion

it('flags a response component inference reproduces, under --only migration.*', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(componentFindingNames($result))->toContain('PlainOk');
});

it('flags a parameter component inference reproduces, under --only migration.*', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(componentFindingNames($result))->toContain('RecordPath');
});

it('keeps a response component carrying a description inference cannot derive', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(componentFindingNames($result))->not->toContain('DescribedOk');
});

it('keeps a component still $ref-ed by another surviving authored component (dangling guard)', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    // AliasedOk is $ref-ed by AliasingResponse (a surviving component); removing it would dangle.
    expect(componentFindingNames($result))->not->toContain('AliasedOk');
});

it('does not flag an orphan component referenced by no operation', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    // OrphanOk is a definition no operation $refs; component.orphaned owns it, not this rule.
    expect(componentFindingNames($result))->not->toContain('OrphanOk');
});

it('stays off an ordinary (default-level) lint run', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(componentFindingNames($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    componentMigrationSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(componentFindingNames($result))->toBe([]);
});

it('leaves the operation referencing a removed component unchanged (same API surface)', function (): void {
    componentMigrationSetup();

    $harvested = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    $control = app(OpenApiGenerationOrchestrator::class)
        ->inferenceOnly('default', [HarvestAuthoredAnnotationsStage::class], 'testing')
        ->document;

    expect(componentResponseRef($control, 'component-redundant'))
        ->not->toBeNull()
        ->toBe(componentResponseRef($harvested, 'component-redundant'));
});

it('exposes its fixer and human-readable description', function (): void {
    componentMigrationSetup();

    $rule = app(OaRedundantComponentWithInference::class);

    expect($rule->fixer())
        ->toBeInstanceOf(RedundantOaAnnotationFixer::class)
        ->and($rule->description)->toContain('inference');
});
