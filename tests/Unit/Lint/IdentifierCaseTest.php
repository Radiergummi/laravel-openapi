<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\IdentifierCase;

uses()->group('openapi', 'lint');

it('matches the expected input', function (IdentifierCase $case, string $input): void {
    expect(preg_match($case->pattern(), $input))->toBe(1);
})->with([
    'Dot: lowercase dot-separated' => [IdentifierCase::Dot, 'api.v0.projects.index'],
    'Dot: kebab in last segment' => [IdentifierCase::Dot, 'api.v0.projects.list-active'],
    'Dot: kebab in multiple segments' => [IdentifierCase::Dot, 'api.v0.user-accounts.list-active'],
    'Dot: kebab-only, no dots' => [IdentifierCase::Dot, 'auth-callback'],
    'Dot: kebab in two-segment id' => [IdentifierCase::Dot, 'auth.reset-password'],
    'Pascal: single-word' => [IdentifierCase::Pascal, 'Users'],
    'Pascal: multi-word' => [IdentifierCase::Pascal, 'ApiV0ProjectsIndex'],
    'Train: single-word' => [IdentifierCase::Train, 'Api'],
    'Train: multi-segment' => [IdentifierCase::Train, 'Api-V0-Projects-Index'],
    'ScreamingSnake: single-word' => [IdentifierCase::ScreamingSnake, 'API'],
    'ScreamingSnake: multi-segment' => [IdentifierCase::ScreamingSnake, 'API_V0_PROJECTS_INDEX'],
]);

it('rejects the unexpected input', function (IdentifierCase $case, string $input): void {
    expect(preg_match($case->pattern(), $input))->toBe(0);
})->with([
    'Dot: snake_case' => [IdentifierCase::Dot, 'get_mcp'],
    'Dot: CamelCase' => [IdentifierCase::Dot, 'apiV0ProjectsIndex'],
    'Dot: leading hyphen' => [IdentifierCase::Dot, '-leading'],
    'Dot: trailing dot' => [IdentifierCase::Dot, 'api.v0.'],
    'Dot: consecutive hyphens' => [IdentifierCase::Dot, 'foo--bar'],
    'Pascal: lowercase-start' => [IdentifierCase::Pascal, 'apiV0ProjectsIndex'],
    'Pascal: dot-separated' => [IdentifierCase::Pascal, 'Api.V0.Projects'],
    'Pascal: kebab-case' => [IdentifierCase::Pascal, 'Api-V0-Projects'],
    'Train: lowercase-start segment' => [IdentifierCase::Train, 'api-V0-Projects'],
    'Train: lowercase continuation segment' => [IdentifierCase::Train, 'Api-v0-Projects'],
    'Train: kebab-case' => [IdentifierCase::Train, 'api-v0-projects'],
    'ScreamingSnake: lowercase' => [IdentifierCase::ScreamingSnake, 'api_v0_projects'],
    'ScreamingSnake: mixed-case' => [IdentifierCase::ScreamingSnake, 'Api_V0_Projects'],
    'ScreamingSnake: kebab-case' => [IdentifierCase::ScreamingSnake, 'API-V0-PROJECTS'],
]);

it('exposes the expected label', function (IdentifierCase $case, string $label): void {
    expect($case->label())->toBe($label);
})->with([
    'Dot' => [IdentifierCase::Dot, 'dot-separated lowercase'],
    'Pascal' => [IdentifierCase::Pascal, 'PascalCase'],
    'Train' => [IdentifierCase::Train, 'Train-Case'],
    'ScreamingSnake' => [IdentifierCase::ScreamingSnake, 'SCREAMING_SNAKE_CASE'],
]);

it('exposes the expected example', function (IdentifierCase $case, string $example): void {
    expect($case->example())->toBe($example);
})->with([
    'Dot' => [IdentifierCase::Dot, 'api.v0.projects.list-active'],
    'Pascal' => [IdentifierCase::Pascal, 'ApiV0ProjectsIndex'],
    'Train' => [IdentifierCase::Train, 'Api-V0-Projects-Index'],
    'ScreamingSnake' => [IdentifierCase::ScreamingSnake, 'API_V0_PROJECTS_INDEX'],
]);
