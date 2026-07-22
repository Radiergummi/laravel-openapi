<?php

/**
 * Full-spec completeness scoreboard (app-agnostic).
 *
 * A presenter over metrics.php: it prints the response-axis score, the request-body bucket, and
 * the operations still incomplete, under an API prefix. The percentage is response-axis-only and
 * its basis is printed: `classified` when an action classification is available (a correctly-empty
 * response counts), `strict` when none is (only a substantive payload counts).
 *
 * A classification is read from `--classify=<file>`, or auto-detected as a `classify.json` sitting
 * beside the spec; the path in use is printed.
 *
 * Usage: php completeness.php <generated-spec.json> [--prefix=/api] [--classify=<classify.json>]
 */

declare(strict_types=1);

require_once __DIR__ . '/metrics.php';

$specPath = $argv[1] ?? null;
$prefix = '/api';
$classifyPath = null;

foreach (array_slice($argv, 2) as $argument) {
    if (str_starts_with($argument, '--prefix=')) {
        $prefix = substr($argument, 9);
    } elseif (str_starts_with($argument, '--classify=')) {
        $classifyPath = substr($argument, 11);
    }
}

if (!$specPath || !is_file($specPath)) {
    fwrite(STDERR, "usage: completeness.php <spec.json> [--prefix=/api] [--classify=<classify.json>]\n");
    exit(2);
}

// An explicit --classify wins; otherwise mirror metrics.php's app-dir pickup, so a corpus-backed
// run scores classified without the caller passing anything.
if ($classifyPath === null && is_file(dirname($specPath) . '/classify.json')) {
    $classifyPath = dirname($specPath) . '/classify.json';
}

$classification = null;

if ($classifyPath !== null && is_file($classifyPath)) {
    $classification = json_decode((string) file_get_contents($classifyPath), true) ?: [];
    printf("classification: %s\n", $classifyPath);
}

$spec = json_decode((string) file_get_contents($specPath), true);
$components = $spec['components']['schemas'] ?? [];
$run = ['generateExit' => 0, 'lintExit' => 0, 'generateStderr' => false, 'bootOutcome' => 'booted'];
$metrics = surveyMetrics($spec, ['findings' => []], $run, $prefix, null, $classification);
$basis = $metrics['completenessBasis'];
$classificationIndex = $classification !== null ? survey_classificationIndex($classification) : null;

$complete = 0;
$rows = [];

foreach (($spec['paths'] ?? []) as $path => $methods) {
    if (!str_starts_with((string) $path, $prefix) || !is_array($methods)) {
        continue;
    }

    foreach ($methods as $method => $operation) {
        $method = strtolower((string) $method);

        if (!in_array($method, ['get', 'post', 'put', 'patch', 'delete'], true) || !is_array($operation)) {
            continue;
        }

        $key = strtoupper($method) . ' ' . preg_replace('/\{[^}]+\}/', '{}', (string) $path);
        $record = $classificationIndex !== null ? ($classificationIndex[$key] ?? null) : null;
        $outcome = survey_operationOutcome($operation, $method, $components, $record);

        if (survey_isComplete($outcome, $basis)) {
            $complete++;

            continue;
        }

        $rows[] = sprintf(
            '  INCOMPLETE %-6s %-52s resp=%s body=%s',
            strtoupper($method),
            $path,
            $outcome['response'],
            match ($outcome['body']) {
                'documented' => 'documented',
                'undocumentedOnWrite' => 'undocumented',
                default => '-',
            },
        );
    }
}

printf(
    "%s ops: %d  complete: %d (%.1f%%, basis: %s)  no-security: %d\n",
    $prefix,
    $metrics['apiOperations'],
    $complete,
    $metrics['completenessPercent'],
    $basis,
    $metrics['apiOperations'] - $metrics['operationsWithSecurity'],
);
printf(
    "request body: documented: %d  undocumented-on-write: %d  not-applicable: %d\n",
    $metrics['requestBodyCoverage']['documented'],
    $metrics['requestBodyCoverage']['undocumentedOnWrite'],
    $metrics['requestBodyCoverage']['notApplicable'],
);

// The three-way split needs the action's return shape, so it exists only under a classification.
if (isset($metrics['responseCoverage'])) {
    $coverage = $metrics['responseCoverage'];
    printf(
        "response coverage: substantive: %d  correctly-empty: %d  genuinely-missing: %d\n",
        $coverage['substantive'],
        $coverage['correctlyEmpty'],
        $coverage['genuinelyMissing'],
    );

    foreach ($coverage['genuinelyMissingByShape'] as $shape => $count) {
        printf("  genuinely-missing %4d  %s\n", $count, $shape);
    }
}

foreach ($rows as $row) {
    echo $row . "\n";
}
