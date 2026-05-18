<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\RuleCatalogRenderer;
use Radiergummi\OpenApi\Core\Lint\RuleRegistry;

it('keeps the docs/OPENAPI.md rule catalog table in sync with the registry', function (): void {
    $docs = file_get_contents(base_path('docs/OPENAPI.md'));
    expect($docs)->toContain('<!-- BEGIN: lint-rule-catalog -->');

    $start = strpos($docs, '<!-- BEGIN: lint-rule-catalog -->')
        + strlen('<!-- BEGIN: lint-rule-catalog -->');
    $end = strpos($docs, '<!-- END: lint-rule-catalog -->');
    $documented = trim(substr($docs, $start, $end - $start));

    $generated = trim(
        (new RuleCatalogRenderer())->render(app(RuleRegistry::class), 'markdown'),
    );

    expect($documented)->toBe(
        $generated,
        'docs/OPENAPI.md catalog is stale — run `php artisan openapi:lint --list --format=markdown` and paste the output between the lint-rule-catalog markers.',
    );
})->group('openapi', 'lint');
