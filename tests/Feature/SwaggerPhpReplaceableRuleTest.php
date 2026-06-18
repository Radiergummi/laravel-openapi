<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use LogicException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix\OaReplaceableByAttributeFixer;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaReplaceableByAttribute;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableQueryController;
use Stringable;

uses()->group('openapi', 'plugin:spatie-data');

// region Helpers

/**
 * Captures every logged message so a test can assert a skip was logged (never silently dropped).
 */
function spyLogger(): LoggerInterface
{
    return new class () extends AbstractLogger {
        /** @var list<string> */
        public array $messages = [];

        public function log(mixed $level, string|Stringable $message, array $context = []): void
        {
            $this->messages[] = (string) $message;
        }
    };
}

function replaceableRuleSetup(?LoggerInterface $logger = null): void
{
    Route::get('/replaceable-query', [ReplaceableQueryController::class, 'index']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    if ($logger !== null) {
        app()->instance(LoggerInterface::class, $logger);
    }

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [dirname(__DIR__) . '/Fixtures/SwaggerPhp'],
            $app->make(LoggerInterface::class),
        ),
    );
}

/**
 * @return list<string> the "class::member" addresses flagged by the replacement rule
 */
function replaceableFindings(LintResult $result): array
{
    $hits = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === 'migration.oa-replaceable-by-attribute') {
            $class = $finding->context['sourceClass'] ?? '(none)';
            $member = $finding->context['sourceMember'] ?? '(none)';
            $hits[] = "{$class}::{$member}";
        }
    }

    return $hits;
}

/**
 * The serialized `name` property schema of a named component, or null when absent.
 *
 * @return null|array<string, mixed>
 */
function namePropertyOf(\OpenApi\Annotations\OpenApi $document, string $schemaName): ?array
{
    foreach (is_array($document->components->schemas) ? $document->components->schemas : [] as $schema) {
        if ((string) $schema->schema !== $schemaName || !is_array($schema->properties)) {
            continue;
        }

        foreach ($schema->properties as $property) {
            if ((string) $property->property === 'name') {
                return (array) $property->jsonSerialize();
            }
        }
    }

    return null;
}

// endregion

it('flags a replaceable OA\Property attribute on a Data class under --only migration.*', function (): void {
    Route::get('/replaceable-attribute', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'attribute']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeData::class . '::name');
});

it('flags a replaceable OA\Property docblock on a Data class under --only migration.*', function (): void {
    Route::get('/replaceable-docblock', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'docblock']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableDocblockData::class . '::name');
});

it('flags a replaceable query OA\Parameter on a controller method', function (): void {
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->toContain(ReplaceableQueryController::class . '::index');
});

it('does not flag an OA\Property carrying an enum (logged, not rewritten)', function (): void {
    Route::get('/enum-property', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'enum']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->not->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\EnumPropertyData::class . '::status');
});

it('does not flag an OA\Property on a class that is neither Data nor Resource', function (): void {
    // AttributeServer is a plain (non-Data) class carrying #[OA\Property]; no field attribute can be
    // picked soundly, so the rule logs and skips it.
    Route::get('/plain-struct/{id}', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ServerController::class, 'show']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->not->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer::class . '::name');
});

it('stays off an ordinary (default-level) lint run', function (): void {
    Route::get('/replaceable-attribute', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'attribute']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(replaceableFindings($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    Route::get('/replaceable-attribute', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'attribute']);
    replaceableRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(replaceableFindings($result))->toBe([]);
});

it('expands a family glob configured in openapi.lint.enabled_rules', function (): void {
    Route::get('/replaceable-attribute', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'attribute']);
    replaceableRuleSetup();
    config()->set('openapi.lint.enabled_rules', ['migration.*']);

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(replaceableFindings($result))
        ->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeData::class . '::name');
});

it('rewrites to an attribute that reproduces the schema the annotation declared', function (): void {
    // The #[ResponseField] the fixer emits for ReplaceableAttributeData reproduces, in the generated
    // document, every key the authored #[OA\Property] declared (description / type / format). The
    // RewrittenResponseFieldData twin is the post-rewrite source; comparing its generated `name`
    // schema to the documented keys is the soundness invariant at generation granularity. (Inference
    // additionally synthesises an example; that enrichment is orthogonal to what the author declared.)
    Route::get('/rewritten', fn(): \Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\RewrittenResponseFieldData
        => throw new LogicException('Signature-only.'));
    replaceableRuleSetup();

    $document = app(OpenApiGenerator::class)->generate(app(SpecRegistry::class)->default(), 'testing');
    $rewritten = namePropertyOf($document, 'RewrittenResponseFieldData');

    expect($rewritten)->not->toBeNull()
        ->and($rewritten['description'] ?? null)->toBe('The contact email.')
        ->and($rewritten['type'] ?? null)->toBe('string')
        ->and($rewritten['format'] ?? null)->toBe('email');
});

it('rejects (and logs) a property whose attribute would not reproduce the authored schema', function (): void {
    // The authored description carries an `@example` directive the field attribute parses out of its
    // rendered description, so #[ResponseField] would not reproduce the authored schema verbatim. The
    // soundness check must reject it, and the rejection must be logged, never silently dropped.
    $logger = spyLogger();
    Route::get('/directive', fn(): \Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DirectiveDescriptionData
        => throw new LogicException('Signature-only.'));
    replaceableRuleSetup($logger);

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(replaceableFindings($result))
        ->not->toContain(\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DirectiveDescriptionData::class . '::name')
        ->and($logger->messages)
        ->toContain('migration.oa-replaceable-by-attribute: left ' . \Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DirectiveDescriptionData::class . '::name in place (the attribute would not reproduce the authored schema).');
});

it('logs (never silently drops) an enum-carrying property it leaves in place', function (): void {
    $logger = spyLogger();
    Route::get('/enum-property', [\Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\ReplaceableAttributeController::class, 'enum']);
    replaceableRuleSetup($logger);

    app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect($logger->messages)
        ->toContain('migration.oa-replaceable-by-attribute: left ' . \Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\EnumPropertyData::class . '::status in place (carries a key no scalar attribute argument expresses).');
});

it('exposes its fixer and human-readable description', function (): void {
    replaceableRuleSetup();

    $rule = app(OaReplaceableByAttribute::class);

    expect($rule->fixer())
        ->toBeInstanceOf(OaReplaceableByAttributeFixer::class)
        ->and($rule->description())->toContain('attribute');
});
