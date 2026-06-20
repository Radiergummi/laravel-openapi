<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\RedundantOaPropertyFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantPropertyWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyMixedData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyNamedSchemaData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantPropertyPlainSchemaData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;

uses()->group('openapi', 'plugin:spatie-data');

// region Helpers

function redundantPropertyRuleSetup(): void
{
    Route::get('/redundant-property-mixed', [RedundantPropertyController::class, 'mixed']);
    Route::get('/redundant-property-docblock', [RedundantPropertyController::class, 'docblock']);
    Route::get('/redundant-property-named', [RedundantPropertyController::class, 'namedSchema']);
    Route::get('/redundant-property-plain', [RedundantPropertyController::class, 'plainSchema']);

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
 * @return list<string> the "class::member" addresses flagged by the per-property removal rule
 */
function redundantPropertyFindings(LintResult $result): array
{
    $hits = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === 'migration.oa-redundant-property-with-inference') {
            $class = $finding->context['sourceClass'] ?? '(none)';
            $member = $finding->context['sourceMember'] ?? '(none)';
            $hits[] = "{$class}::{$member}";
        }
    }

    return $hits;
}

/**
 * The serialized property schema of a named component member, or null when absent.
 *
 * @return null|array<string, mixed>
 */
function memberPropertyOf(OA\OpenApi $document, string $schemaName, string $member): ?array
{
    foreach (is_array($document->components->schemas) ? $document->components->schemas : [] as $schema) {
        if ((string) $schema->schema !== $schemaName || !is_array($schema->properties)) {
            continue;
        }

        foreach ($schema->properties as $property) {
            if ((string) $property->property === $member) {
                return (array) $property->jsonSerialize();
            }
        }
    }

    return null;
}

// endregion

it('flags only the redundant member, leaving the load-bearing sibling, under --only migration.*', function (): void {
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(redundantPropertyFindings($result))
        ->toContain(RedundantPropertyMixedData::class . '::name')
        ->not->toContain(RedundantPropertyMixedData::class . '::role');
});

it('flags a redundant member authored as an @OA\Property docblock', function (): void {
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(redundantPropertyFindings($result))
        ->toContain(RedundantPropertyDocblockData::class . '::name');
});

it('does not flag a member on a schema another authored annotation still references by name', function (): void {
    // RedundantPropertyRefParentData's authored schema $refs RedundantPropertyNamedSchema; removing a
    // member there could mutate the harvester-emitted component, so the keep-guard skips the class.
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(redundantPropertyFindings($result))
        ->not->toContain(RedundantPropertyNamedSchemaData::class . '::label');
});

it('flags the same member shape when its schema is NOT cross-referenced (guard is not vacuous)', function (): void {
    // The no-cross-ref twin of RedundantPropertyNamedSchemaData: identical redundant `label`, but no
    // other authored annotation $refs it, so the keep-guard does not fire and the member IS flagged.
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(redundantPropertyFindings($result))
        ->toContain(RedundantPropertyPlainSchemaData::class . '::label');
});

it('does not flag a member OA\Property on a non-Data class', function (): void {
    // AttributeServer is a plain (non-Data) class carrying #[OA\Property] inside its class-level
    // #[OA\Schema], where per-member removal would mutate the emitted component, so it is out of scope.
    Route::get('/plain-struct/{id}', [ServerController::class, 'show']);
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(redundantPropertyFindings($result))
        ->not->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer::class . '::name');
});

it('stays off an ordinary (default-level) lint run', function (): void {
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(redundantPropertyFindings($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    redundantPropertyRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(redundantPropertyFindings($result))->toBe([]);
});

it('leaves the emitted component byte-identical to the inference-only control', function (): void {
    // The acceptance criterion: removing the redundant members yields the inference-only control
    // document, so the flagged class's emitted component is identical between the harvested document
    // and that control (load-bearing `role` description survives on both sides).
    redundantPropertyRuleSetup();

    $harvested = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    $control = app(OpenApiGenerationOrchestrator::class)
        ->inferenceOnly('default', [HarvestAuthoredAnnotationsStage::class], 'testing')
        ->document;

    expect(memberPropertyOf($control, 'RedundantPropertyMixedData', 'name'))
        ->not->toBeNull()
        ->toBe(memberPropertyOf($harvested, 'RedundantPropertyMixedData', 'name'))
        ->and(memberPropertyOf($control, 'RedundantPropertyMixedData', 'role'))
        ->not->toBeNull()
        ->toBe(memberPropertyOf($harvested, 'RedundantPropertyMixedData', 'role'));
});

it('exposes its fixer and human-readable description', function (): void {
    redundantPropertyRuleSetup();

    $rule = app(OaRedundantPropertyWithInference::class);

    expect($rule->fixer())
        ->toBeInstanceOf(RedundantOaPropertyFixer::class)
        ->and($rule->description)->toContain('OA\Property');
});
