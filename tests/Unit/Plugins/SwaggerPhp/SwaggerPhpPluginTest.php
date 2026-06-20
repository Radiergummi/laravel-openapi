<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\DocumentAnnotationMigration;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Stages\HarvestAuthoredAnnotationsStage;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\SwaggerPhpPlugin;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;

it('registers the harvest stage', function (): void {
    $registry = new OpenApiRegistry();

    (new SwaggerPhpPlugin())->register($registry);

    expect($registry->stages)->toContain(HarvestAuthoredAnnotationsStage::class);
});

it('registers the document-annotation migration rule', function (): void {
    $registry = new OpenApiRegistry();

    (new SwaggerPhpPlugin())->register($registry);

    expect($registry->rules)->toContain(DocumentAnnotationMigration::class);
});

it('exposes the document-annotation rule at the migration (level-4) severity', function (): void {
    $rule = new DocumentAnnotationMigration(
        new AuthoredAnnotationScanner([], recordingLogger()),
    );

    expect($rule->id)->toBe('migration.document-annotation-in-config')
        ->and($rule->severity)->toBe(Severity::Improvable)
        ->and($rule->severity->value)->toBe(4);
});
