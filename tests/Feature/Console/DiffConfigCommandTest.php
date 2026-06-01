<?php

declare(strict_types=1);

uses()->group('console', 'openapi');

it('reports drift when the user config differs from defaults', function (): void {
    // Override one key so it diverges from the package default ('none')
    config(['openapi.error_envelope' => 'rfc7807']);

    $this->artisan('openapi:diff:config')
        ->expectsOutputToContain('error_envelope')
        ->expectsOutputToContain('Drift between')
        ->assertExitCode(0);
});

it('exits cleanly with a friendly message when configs match', function (): void {
    // Load the package default verbatim so the user config equals the default
    config(['openapi' => require __DIR__ . '/../../../config/openapi.php']);

    $this->artisan('openapi:diff:config')
        ->expectsOutputToContain('matches')
        ->assertExitCode(0);
});
