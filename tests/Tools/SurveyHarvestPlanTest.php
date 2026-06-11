<?php

declare(strict_types=1);

require_once __DIR__ . '/../../.claude/skills/survey/bin/survey-harvest-docs';

it('plans doc + deduped query-param attributes from published vs generated', function (): void {
    $published = ['paths' => ['/api/items' => ['get' => [
        'summary' => 'List items',
        'description' => 'All the items.',
        'tags' => ['Items'],
        'parameters' => [
            ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
            ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string']],
        ],
    ]]]];

    // Our generated op already declares `page` (convention) but not `sort`.
    $generated = ['paths' => ['/api/items' => ['get' => [
        'parameters' => [['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']]],
    ]]]];

    $routeMap = ['GET /api/items' => ['file' => 'app/Http/Controllers/ItemController.php', 'method' => 'index']];

    $edits = surveyHarvestPlan($published, $generated, $routeMap, '/api');

    expect($edits)->toHaveCount(1);
    $names = array_map(fn($a) => $a['name'], $edits[0]['attributes']);

    expect($edits[0]['file'])->toBe('app/Http/Controllers/ItemController.php')
        ->and($edits[0]['method'])->toBe('index')
        ->and($names)->toContain('Summary')->toContain('Description')->toContain('Tag')
        // `sort` harvested, `page` deduped away (already on the generated op)
        ->and($names)->toContain('QueryParam')
        ->and(array_filter($edits[0]['attributes'], fn($a) => $a['name'] === 'QueryParam'))
        ->toHaveCount(1);
});

it('skips ops not present in both, or not route-resolvable', function (): void {
    $published = ['paths' => ['/api/ghost' => ['get' => ['summary' => 'x']]]];
    $generated = ['paths' => []];
    $edits = surveyHarvestPlan($published, $generated, [], '/api');
    expect($edits)->toBe([]);
});

it('joins published and generated on normalised path despite differing param names', function (): void {
    $published = ['paths' => ['/api/posts/{postId}' => ['get' => ['summary' => 'Show post']]]];
    $generated = ['paths' => ['/api/posts/{id}' => ['get' => ['responses' => ['200' => []]]]]];
    $routeMap = ['GET /api/posts/{}' => ['file' => 'app/Http/Controllers/PostController.php', 'method' => 'show']];

    $edits = surveyHarvestPlan($published, $generated, $routeMap, '/api');

    expect($edits)->toHaveCount(1)
        ->and($edits[0]['method'])->toBe('show')
        ->and(array_map(fn($a) => $a['name'], $edits[0]['attributes']))->toContain('Summary');
});
