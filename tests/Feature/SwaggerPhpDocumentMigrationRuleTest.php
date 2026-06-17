<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintOptions;
use Radiergummi\OpenApi\Lint\LintResult;
use Radiergummi\OpenApi\Lint\LintRunner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpDocument\DocumentAnnotatedController;

uses()->group('openapi');

// region Helpers

const DOCUMENT_RULE_ID = 'migration.document-annotation-in-config';

function documentRuleSetup(?string $fixturePath = null): void
{
    Route::get('/flights', [DocumentAnnotatedController::class, 'index']);

    config()->set('openapi.plugins', [...(array) config('openapi.plugins', []), SwaggerPhpPlugin::class]);

    app()->scoped(
        AuthoredAnnotationScanner::class,
        static fn($app): AuthoredAnnotationScanner => new AuthoredAnnotationScanner(
            [$fixturePath ?? dirname(__DIR__) . '/Fixtures/SwaggerPhpDocument'],
            $app->make(LoggerInterface::class),
        ),
    );
}

/**
 * @return list<Finding>
 */
function documentFindings(LintResult $result): array
{
    $findings = [];

    foreach ($result->findings as $finding) {
        if ($finding->ruleId === DOCUMENT_RULE_ID) {
            $findings[] = $finding;
        }
    }

    return $findings;
}

function documentFindingFor(LintResult $result, string $configKey): ?Finding
{
    foreach (documentFindings($result) as $finding) {
        if (str_contains($finding->message, $configKey)) {
            return $finding;
        }
    }

    return null;
}

// endregion

it('flags every authored document-level annotation kind under --only migration.*', function (): void {
    documentRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(documentFindings($result))->toHaveCount(4);
});

it('maps @OA\\Info to the openapi.info key with a paste-ready snippet', function (): void {
    documentRuleSetup();

    $finding = documentFindingFor(app(LintRunner::class)->run(new LintOptions(only: ['migration.*'])), 'openapi.info');

    expect($finding)->not->toBeNull()
        ->and($finding->fixHint)->toContain(<<<'PHP'
            'info' => [
                'title' => 'Flights API',
                'version' => '2.1.0',
                'description' => 'Book and manage flights.',
            ],
            PHP);
});

it('maps @OA\\Server to the openapi.servers key with a paste-ready snippet', function (): void {
    documentRuleSetup();

    $finding = documentFindingFor(app(LintRunner::class)->run(new LintOptions(only: ['migration.*'])), 'openapi.servers');

    expect($finding)->not->toBeNull()
        ->and($finding->fixHint)->toContain(<<<'PHP'
            'servers' => [
                [
                    'url' => 'https://api.example.com',
                    'description' => 'Production',
                ],
            ],
            PHP);
});

it('maps @OA\\SecurityScheme to openapi.security_schemes keyed by name', function (): void {
    documentRuleSetup();

    $finding = documentFindingFor(
        app(LintRunner::class)->run(new LintOptions(only: ['migration.*'])),
        'openapi.security_schemes',
    );

    expect($finding)->not->toBeNull()
        ->and($finding->message)->toContain('bearerAuth')
        ->and($finding->fixHint)->toContain(<<<'PHP'
            'security_schemes' => [
                'bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'bearerFormat' => 'JWT',
                ],
            ],
            PHP);
});

it('maps a root @OA\\Tag to openapi.tags keyed by name', function (): void {
    documentRuleSetup();

    $finding = documentFindingFor(app(LintRunner::class)->run(new LintOptions(only: ['migration.*'])), 'openapi.tags');

    expect($finding)->not->toBeNull()
        ->and($finding->message)->toContain('Flights')
        ->and($finding->fixHint)->toContain(<<<'PHP'
            'tags' => [
                'Flights' => [
                    'description' => 'Flight booking and management.',
                ],
            ],
            PHP);
});

it('locates the finding at the file that declares the annotation', function (): void {
    documentRuleSetup();

    $finding = documentFindingFor(app(LintRunner::class)->run(new LintOptions(only: ['migration.*'])), 'openapi.info');

    expect($finding?->location->file)->toEndWith('DocumentAnnotatedController.php');
});

it('emits no findings for a source tree with no document-level annotations', function (): void {
    // The SwaggerPhp fixtures carry only schema/operation annotations, nothing document-level.
    documentRuleSetup(dirname(__DIR__) . '/Fixtures/SwaggerPhp');

    $result = app(LintRunner::class)->run(new LintOptions(only: ['migration.*']));

    expect(documentFindings($result))->toBe([]);
});

it('stays off an ordinary (default-level) lint run', function (): void {
    // Migration rules are level 4, so a default-level run never pays the inference-only generation.
    documentRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions());

    expect(documentFindings($result))->toBe([]);
});

it('is disabled as a family by --skip migration.*', function (): void {
    documentRuleSetup();

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max', skip: ['migration.*']));

    expect(documentFindings($result))->toBe([]);
});

it('does not run when the SwaggerPhp plugin is disabled', function (): void {
    // No documentRuleSetup(): the plugin stays off, so the rule is never registered.
    Route::get('/flights', [DocumentAnnotatedController::class, 'index']);

    $result = app(LintRunner::class)->run(new LintOptions(level: 'max'));

    expect(documentFindings($result))->toBe([]);
});
