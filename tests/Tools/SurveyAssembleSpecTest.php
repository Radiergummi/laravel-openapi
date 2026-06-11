<?php

declare(strict_types=1);

require_once __DIR__ . '/../../.claude/skills/survey/bin/survey-assemble-spec';

it('merges a split published spec into one document', function (): void {
    $spec = surveyAssembleSpec(__DIR__ . '/../Fixtures/survey/published');

    expect($spec['paths'])->toHaveKey('/api/health')
        ->and($spec['paths']['/api/health']['get']['summary'])->toBe('Health check')
        ->and($spec['components']['securitySchemes'])->toHaveKey('bearerAuth');
});

it('passes through a single JSON spec file without modification', function (): void {
    $spec = surveyAssembleSpec(__DIR__ . '/../Fixtures/survey/spec.json');

    expect($spec)->toHaveKey('paths')
        ->and($spec['paths'])->toHaveKey('/api/users')
        ->and($spec['components']['schemas']['User']['properties']['id']['type'])->toBe('integer');
});
