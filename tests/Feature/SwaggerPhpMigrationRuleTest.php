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
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DivergentRefParentData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\EssentialAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\PlainStructData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantAnnotationController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantAttributeData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RedundantDocblockData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RefChildData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RefParentData;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController;

uses()->group('openapi', 'plugin:spatie-data');

// region Helpers

function migrationRuleSetup(): void
{
    Route::get('/redundant-attribute', [RedundantAnnotationController::class, 'attribute']);
    Route::get('/redundant-docblock', [RedundantAnnotationController::class, 'docblock']);

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
 * @return list<string> the source classes flagged by the migration rule
 */
function migrationFindingClasses(LintResult $result): array
{
    $classes = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === 'migration.oa-redundant-with-inference') {
            $classes[] = $finding->context['sourceClass'] ?? '(none)';
        }
    }

    return $classes;
}

/**
 * The 200 response JSON schema `$ref` for a path, or null.
 */
function responseRefFor(OA\OpenApi $document, string $needle): ?string
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

it('leaves the affected operations unchanged once the redundant annotations are gone', function (): void {
    // The "same API surface" invariant: removing the redundant annotations yields the inferred view,
    // and the flagged operations' response schemas are identical between the harvested document and
    // the pre-merge operation retained off the same single generation.
    migrationRuleSetup();

    $harvested = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    $inference = retainedInferenceView();

    expect(inferredResponseRef($inference->operationForRoute('get', 'redundant-attribute')))
        ->not
        ->toBeNull()
        ->toBe(responseRefFor($harvested, 'redundant-attribute'))
        ->and(inferredResponseRef($inference->operationForRoute('get', 'redundant-docblock')))
        ->not
        ->toBeNull()
        ->toBe(responseRefFor($harvested, 'redundant-docblock'));
});

it('flags an attribute-shape annotation inference reproduces, under --only migration.*', function (): void {
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->toContain(RedundantAttributeData::class);
});

it('flags a docblock-shape annotation inference reproduces, under --only migration.*', function (): void {
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->toContain(RedundantDocblockData::class);
});

it('stays off an ordinary (default-level) lint run', function (): void {
    // Migration rules are level 4 (cleanup tier), so a default-level lint never runs them — the
    // expensive inference-only generation stays unpaid until explicitly requested.
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(migrationFindingClasses($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(migrationFindingClasses($result))->toBe([]);
});

it('does not flag an annotation inference cannot reproduce', function (): void {
    // AttributeServer is a plain class: inference produces no component schema for it, so its
    // authored #[OA\Schema] is essential and must not be flagged.
    Route::get('/servers/{id}', [ServerController::class, 'show']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->not->toContain(AttributeServer::class);
});

it('does not flag a class that has no authored annotation', function (): void {
    // Inference produces a schema for PlainStructData, but there is nothing authored to remove.
    Route::get('/plain', [RedundantAnnotationController::class, 'plain']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->not->toContain(PlainStructData::class);
});

it('keeps an annotation carrying a description inference cannot derive', function (): void {
    // Inference reproduces the shape but not the human-written description, so it does not subsume
    // the authored schema — the annotation stays.
    Route::get('/essential', [RedundantAnnotationController::class, 'essential']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->not->toContain(EssentialAttributeData::class);
});

it('does not flag a schema another authored annotation still references by name', function (): void {
    // RefParentData's authored schema $refs RefChild; removing RefChild's annotation would dangle
    // that reference, so the rule must leave it in place.
    Route::get('/ref-parent', [RedundantAnnotationController::class, 'refParent']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->not->toContain(RefChildData::class);
});

it('flags a parent whose $ref the author named differently but whose target inference reproduces', function (): void {
    // RefParentData's authored child $ref is `RefChild`; convention names the same class `RefChildData`.
    // The oracle now follows both refs to their (equivalent) target schemas, so the parent is flagged.
    Route::get('/ref-parent', [RedundantAnnotationController::class, 'refParent']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->toContain(RefParentData::class);
});

it('does not flag a parent whose divergently-named $ref target genuinely differs', function (): void {
    // The authored child target (`DivergentChild`) carries a description inference cannot reproduce,
    // so following the parent's $ref fails subsumption and the parent is kept.
    Route::get('/divergent-ref-parent', [RedundantAnnotationController::class, 'divergentRefParent']);
    migrationRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(migrationFindingClasses($result))
        ->not->toContain(DivergentRefParentData::class);
});

it('expands a family glob configured in openapi.lint.enabled_rules', function (): void {
    // The config-side allowlist (not just CLI --only) must expand `migration.*` to the family.
    migrationRuleSetup();
    config()->set('openapi.lint.enabled_rules', ['migration.*']);

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(migrationFindingClasses($result))
        ->toContain(RedundantAttributeData::class);
});

it('exposes its fixer and human-readable description', function (): void {
    migrationRuleSetup();

    $rule = app(OaRedundantWithInference::class);

    expect($rule->fixer())
        ->toBeInstanceOf(RedundantOaAnnotationFixer::class)
        ->and($rule->description)->toContain('inference');
});
