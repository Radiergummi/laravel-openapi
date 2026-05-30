<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\IdentifierCase;

uses()->group('openapi', 'lint');

// --- Dot ---

it('Dot: matches a simple lowercase dot-separated identifier', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'api.v0.projects.index'))->toBe(1);
});

it('Dot: matches kebab within the last segment', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'api.v0.projects.list-active'))->toBe(1);
});

it('Dot: matches kebab in multiple segments', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'api.v0.user-accounts.list-active'))->toBe(1);
});

it('Dot: matches a kebab-only identifier with no dots', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'auth-callback'))->toBe(1);
});

it('Dot: matches the matchory auth.resolve-account pattern', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'auth.resolve-account'))->toBe(1);
});

it('Dot: rejects a snake_case identifier', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'get_mcp'))->toBe(0);
});

it('Dot: rejects a CamelCase identifier', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'apiV0ProjectsIndex'))->toBe(0);
});

it('Dot: rejects a leading hyphen', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), '-leading'))->toBe(0);
});

it('Dot: rejects a trailing dot', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'api.v0.'))->toBe(0);
});

it('Dot: rejects consecutive hyphens', function (): void {
    expect(preg_match(IdentifierCase::Dot->pattern(), 'foo--bar'))->toBe(0);
});

it('Dot: has the expected label', function (): void {
    expect(IdentifierCase::Dot->label())->toBe('dot-separated lowercase');
});

it('Dot: has the expected example', function (): void {
    expect(IdentifierCase::Dot->example())->toBe('api.v0.projects.list-active');
});

// --- Pascal ---

it('Pascal: matches a single-word PascalCase identifier', function (): void {
    expect(preg_match(IdentifierCase::Pascal->pattern(), 'Users'))->toBe(1);
});

it('Pascal: matches a multi-word PascalCase identifier', function (): void {
    expect(preg_match(IdentifierCase::Pascal->pattern(), 'ApiV0ProjectsIndex'))->toBe(1);
});

it('Pascal: rejects a lowercase-start identifier', function (): void {
    expect(preg_match(IdentifierCase::Pascal->pattern(), 'apiV0ProjectsIndex'))->toBe(0);
});

it('Pascal: rejects a dot-separated identifier', function (): void {
    expect(preg_match(IdentifierCase::Pascal->pattern(), 'Api.V0.Projects'))->toBe(0);
});

it('Pascal: rejects a kebab-case identifier', function (): void {
    expect(preg_match(IdentifierCase::Pascal->pattern(), 'Api-V0-Projects'))->toBe(0);
});

it('Pascal: has the expected label', function (): void {
    expect(IdentifierCase::Pascal->label())->toBe('PascalCase');
});

it('Pascal: has the expected example', function (): void {
    expect(IdentifierCase::Pascal->example())->toBe('ApiV0ProjectsIndex');
});

// --- Train ---

it('Train: matches a single-word Train-Case identifier', function (): void {
    expect(preg_match(IdentifierCase::Train->pattern(), 'Api'))->toBe(1);
});

it('Train: matches a multi-segment Train-Case identifier', function (): void {
    expect(preg_match(IdentifierCase::Train->pattern(), 'Api-V0-Projects-Index'))->toBe(1);
});

it('Train: rejects a lowercase-start segment', function (): void {
    expect(preg_match(IdentifierCase::Train->pattern(), 'api-V0-Projects'))->toBe(0);
});

it('Train: rejects a lowercase continuation segment', function (): void {
    expect(preg_match(IdentifierCase::Train->pattern(), 'Api-v0-Projects'))->toBe(0);
});

it('Train: rejects a kebab-case identifier', function (): void {
    expect(preg_match(IdentifierCase::Train->pattern(), 'api-v0-projects'))->toBe(0);
});

it('Train: has the expected label', function (): void {
    expect(IdentifierCase::Train->label())->toBe('Train-Case');
});

it('Train: has the expected example', function (): void {
    expect(IdentifierCase::Train->example())->toBe('Api-V0-Projects-Index');
});

// --- ScreamingSnake ---

it('ScreamingSnake: matches a single-word screaming snake identifier', function (): void {
    expect(preg_match(IdentifierCase::ScreamingSnake->pattern(), 'API'))->toBe(1);
});

it('ScreamingSnake: matches a multi-segment screaming snake identifier', function (): void {
    expect(preg_match(IdentifierCase::ScreamingSnake->pattern(), 'API_V0_PROJECTS_INDEX'))->toBe(1);
});

it('ScreamingSnake: rejects a lowercase identifier', function (): void {
    expect(preg_match(IdentifierCase::ScreamingSnake->pattern(), 'api_v0_projects'))->toBe(0);
});

it('ScreamingSnake: rejects a mixed-case identifier', function (): void {
    expect(preg_match(IdentifierCase::ScreamingSnake->pattern(), 'Api_V0_Projects'))->toBe(0);
});

it('ScreamingSnake: rejects a kebab-case identifier', function (): void {
    expect(preg_match(IdentifierCase::ScreamingSnake->pattern(), 'API-V0-PROJECTS'))->toBe(0);
});

it('ScreamingSnake: has the expected label', function (): void {
    expect(IdentifierCase::ScreamingSnake->label())->toBe('SCREAMING_SNAKE_CASE');
});

it('ScreamingSnake: has the expected example', function (): void {
    expect(IdentifierCase::ScreamingSnake->example())->toBe('API_V0_PROJECTS_INDEX');
});
