<?php

declare(strict_types=1);

/**
 * Process-level coverage of the completeness presenter: it is a CLI script, so the only honest
 * way to test its output (and its classify.json auto-detection) is to run it.
 */
function survey_completeness_workspace(): string
{
    $dir = sys_get_temp_dir() . '/survey-completeness-' . bin2hex(random_bytes(6));
    mkdir($dir, 0o777, true);

    $substantive = ['200' => ['content' => ['application/json' => ['schema' => [
        'type' => 'object', 'properties' => ['id' => ['type' => 'integer']],
    ]]]]];

    $spec = ['paths' => [
        // A body-less write with a substantive response: unresolved on the body axis, complete.
        '/api/toggle' => ['post' => ['responses' => $substantive]],
        // The generator's give-up path.
        '/api/dynamic' => ['get' => ['responses' => ['200' => []]]],
        // Affirmative no-content.
        '/api/destroy/{id}' => ['delete' => ['security' => [['sanctum' => []]], 'responses' => ['204' => []]]],
    ]];

    file_put_contents("$dir/generated-spec.json", (string) json_encode($spec));
    file_put_contents("$dir/classify.json", (string) json_encode([
        ['uri' => '/api/dynamic', 'verb' => 'get', 'shape' => 'response()->json(<non-literal>)', 'returnType' => 'JsonResponse'],
    ]));

    return $dir;
}

/**
 * @return array{0: string, 1: int}
 */
function survey_completeness_run(string ...$arguments): array
{
    $command = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg(dirname(__DIR__, 2) . '/tools/survey/completeness.php')
        . ' ' . implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1';

    exec($command, $output, $exit);

    return [implode("\n", $output), $exit];
}

function survey_completeness_cleanup(string $dir): void
{
    array_map('unlink', array_filter(glob($dir . '/*') ?: [], 'is_file'));
    rmdir($dir);
}

it('scores strictly when no classification is reachable', function (): void {
    $dir = survey_completeness_workspace();
    unlink("$dir/classify.json");

    [$output, $exit] = survey_completeness_run("$dir/generated-spec.json", '--prefix=/api');

    expect($exit)->toBe(0)
        ->and($output)->toContain('basis: strict')
        // Only /api/toggle is substantive; the 204 earns no credit under strict.
        ->and($output)->toContain('complete: 1 (33.3%')
        ->and($output)->toContain('no-security: 2')
        ->and($output)->toContain('request body: documented: 0  undocumented-on-write: 1  not-applicable: 2')
        // The body-less write is complete on the response axis, so it is not listed.
        ->and($output)->not->toContain('/api/toggle')
        ->and($output)->toContain('INCOMPLETE GET')
        // Strict emits no three-way split.
        ->and($output)->not->toContain('response coverage:');

    survey_completeness_cleanup($dir);
});

it('picks up a classify.json sitting beside the spec and prints where it came from', function (): void {
    $dir = survey_completeness_workspace();

    [$output, $exit] = survey_completeness_run("$dir/generated-spec.json", '--prefix=/api');

    expect($exit)->toBe(0)
        ->and($output)->toContain("classification: $dir/classify.json")
        ->and($output)->toContain('basis: classified')
        // The 204 now counts: /api/toggle + /api/destroy.
        ->and($output)->toContain('complete: 2 (66.7%')
        ->and($output)->toContain('response coverage: substantive: 1  correctly-empty: 1  genuinely-missing: 1')
        ->and($output)->toContain('genuinely-missing    1  response()->json(<non-literal>)')
        ->and($output)->toContain('INCOMPLETE GET    /api/dynamic')
        ->and($output)->toContain('body=-');

    survey_completeness_cleanup($dir);
});

it('treats a zero-byte adjacent classify.json as absent and scores strictly', function (): void {
    $dir = survey_completeness_workspace();
    // A failed classify.php run leaves the redirect target truncated to zero bytes beside the spec.
    file_put_contents("$dir/classify.json", '');

    [$output, $exit] = survey_completeness_run("$dir/generated-spec.json", '--prefix=/api');

    expect($exit)->toBe(0)
        ->and($output)->toContain('basis: strict')
        // No classification is announced, and no three-way split is emitted.
        ->and($output)->not->toContain('classification:')
        ->and($output)->not->toContain('response coverage:')
        // Strict scoring: only /api/toggle is substantive.
        ->and($output)->toContain('complete: 1 (33.3%');

    survey_completeness_cleanup($dir);
});

it('lets an explicit --classify win over the adjacent file', function (): void {
    $dir = survey_completeness_workspace();
    $explicit = "$dir/other-classify.json";

    // Marks the give-up op as genuinely body-less, which the adjacent file does not.
    file_put_contents($explicit, (string) json_encode([
        ['uri' => '/api/dynamic', 'verb' => 'get', 'shape' => 'void/no-body', 'returnType' => 'void'],
    ]));

    [$output, $exit] = survey_completeness_run("$dir/generated-spec.json", '--prefix=/api', "--classify=$explicit");

    expect($exit)->toBe(0)
        ->and($output)->toContain("classification: $explicit")
        ->and($output)->toContain('response coverage: substantive: 1  correctly-empty: 2  genuinely-missing: 0')
        ->and($output)->toContain('complete: 3 (100.0%');

    survey_completeness_cleanup($dir);
});

it('exits 2 when the spec is missing', function (): void {
    [$output, $exit] = survey_completeness_run('/no/such/spec.json');

    expect($exit)->toBe(2)
        ->and($output)->toContain('usage: completeness.php');
});
