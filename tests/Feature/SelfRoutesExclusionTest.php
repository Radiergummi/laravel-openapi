<?php

declare(strict_types=1);

uses()->group('routing', 'openapi');

it('does not document the library\'s own spec/playground routes by default', function (): void {
    // The service provider's registerRoutes() mounts openapi.spec when
    // config('openapi.routes.enabled', true) is true. With the default
    // SkipSelfRoutes filter active, the spec route must not appear in the
    // generated paths.
    $document = generateSpec();

    expect($document)->toHaveKey('paths')
        ->and(array_keys($document['paths'] ?? []))
        ->not->toContain('/api/openapi.yaml');
});

it('documents the library\'s spec route when SkipSelfRoutes is removed from the filters config', function (): void {
    // Opt-out path: removing the filter restores the route to the generated spec.
    config()->set('openapi.filters', []);

    // The InclusionEvaluator is scoped — drop the cached instance so the new
    // filter list is read on next resolution.
    app()->forgetScopedInstances();

    $document = generateSpec();

    expect(array_keys($document['paths'] ?? []))->toContain('/api/openapi.yaml');
});
