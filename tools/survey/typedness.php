<?php

declare(strict_types=1);

/**
 * Well-typed coverage segmenter (issue #460).
 *
 * Splits an app's API operations by how its actions *return* — typed payload (an API
 * Resource / Data object / literal), genuinely no-content (void / noContent), or dynamic
 * (response()->json(), Fractal chains, multi/conditional returns) — and reports unaided
 * substantive coverage *conditioned* on that shape. This isolates the "type your returns
 * → schema for free" premise from two confounders the headline substantive% conflates:
 * correctly-empty actions (which drag substantive% down) and dynamic actions (which the
 * premise does not claim to cover unaided).
 *
 * Two coverage numbers fall out, both reproducible:
 *   - typedReturnCoverage — of actions that return a typed payload, the share the generator
 *     gave a substantive 2xx schema. This is the premise's headline ("almost automatic").
 *   - honestPercent — substantive / (apiOperations − correctlyEmpty); the whole-app figure
 *     with the correctly-empty pessimism removed. A floor for a well-typed *app*.
 *
 * The action-return shape comes from a `classify.json` artifact (per-route reflected return
 * type + shape) produced by the #413 classifier. When it is absent this tool still emits the
 * spec-only substantive figure and marks the action-typedness fields null — it never guesses
 * the shape, because spec contentless-2xx cannot tell a void action from a generator give-up.
 *
 * Usage:
 *   php typedness.php <app-dir> [--prefix=/api]      # one app: reads generated-spec.json (+ classify.json)
 *   php typedness.php --corpus <ws> [--corpus-json=<corpus.json>]   # whole corpus + per-tier rollup
 */

require_once __DIR__ . '/metrics.php';

/**
 * Bucket a classifier `shape` string into the return-shape class that drives segmentation.
 *
 * `typed` is a deliberately tight whitelist of unambiguous, statically-legible typed returns
 * (Resource/Data construction, resource:: helpers, static Resource factories, literals), so the
 * conditional coverage it feeds reads cleanly as "when you return a typed payload, do you get a
 * schema?". Everything not clearly typed-or-void (json() bodies, Fractal item/list chains,
 * multi/conditional returns, closures, reflection failures) is `dynamic`.
 */
function typedness_shape_class(string $shape): string
{
    $shape = strtolower($shape);

    if ($shape === 'no return (void-like)' || $shape === 'response()->nocontent()') {
        return 'no-content';
    }

    $typedPatterns = [
        '#^resource::#',
        '#^new \w*resource\(#',
        '#^new \w*data\(#',
        '#^static-call:\w*resource::#',
        '#^array literal#',
        '#^scalar literal#',
    ];

    foreach ($typedPatterns as $pattern) {
        if (preg_match($pattern, $shape)) {
            return 'typed';
        }
    }

    return 'dynamic';
}

/** Normalise a verb + path into the join key shared by the spec and the classifier ({param} → {}). */
function typedness_operationKey(string $verb, string $path): string
{
    return strtoupper($verb) . ' ' . preg_replace('/\{[^}]+\}/', '{}', $path);
}

/**
 * Map each in-prefix spec operation to whether its 2xx response is substantive, keyed by the
 * collapsed "VERB path" join key (see typedness_operationKey).
 *
 * @return array<string, bool>
 */
function typedness_substantiveByKey(array $spec, string $apiPrefix): array
{
    $components = $spec['components']['schemas'] ?? [];
    $verbs = ['get', 'post', 'put', 'patch', 'delete'];
    $byKey = [];

    foreach (($spec['paths'] ?? []) as $path => $methods) {
        if (!is_array($methods) || !str_starts_with((string) $path, $apiPrefix)) {
            continue;
        }

        foreach ($methods as $method => $operation) {
            $method = strtolower((string) $method);

            if (!in_array($method, $verbs, true) || !is_array($operation)) {
                continue;
            }

            $substantive = false;

            foreach (($operation['responses'] ?? []) as $code => $response) {
                if (!preg_match('/^2/', (string) $code) || !is_array($response)) {
                    continue;
                }

                foreach (($response['content'] ?? []) as $media) {
                    if (isset($media['schema']) && survey_substantive($media['schema'], $components)) {
                        $substantive = true;

                        break 2;
                    }
                }
            }

            $byKey[typedness_operationKey($method, (string) $path)] = $substantive;
        }
    }

    return $byKey;
}

/**
 * Segment one app's API operations by action-return shape and report conditional coverage.
 *
 * @param array      $spec     the generated OpenAPI document
 * @param null|array $classify the #413 classifier artifact (per-route shape), or null
 *
 * @return array<string, mixed>
 */
function typednessMetrics(array $spec, ?array $classify, string $apiPrefix = '/api'): array
{
    $substantiveByKey = typedness_substantiveByKey($spec, $apiPrefix);
    $apiOperations = count($substantiveByKey);
    $substantive = count(array_filter($substantiveByKey));

    $record = [
        'apiOperations' => $apiOperations,
        'substantiveResponses' => $substantive,
        'substantivePercent' => $apiOperations > 0 ? round(100 * $substantive / $apiOperations, 1) : 0.0,
        // Action-typedness fields stay null until a classify.json joins; spec shape alone cannot
        // separate a void action from a generator give-up, so we never infer it.
        'classified' => false,
        'typedActions' => null,
        'typedCovered' => null,
        'typedReturnCoverage' => null,
        'dynamicActions' => null,
        'dynamicCovered' => null,
        'correctlyEmptyActions' => null,
        'honestPercent' => null,
    ];

    if ($classify === null) {
        return $record;
    }

    $typed = 0;
    $typedCovered = 0;
    $dynamic = 0;
    $dynamicCovered = 0;
    $correctlyEmpty = 0;

    foreach ($classify as $route) {
        $uri = (string) ($route['uri'] ?? '');
        $verb = (string) ($route['verb'] ?? '');

        if (!str_starts_with($uri, $apiPrefix)) {
            continue;
        }

        $key = typedness_operationKey($verb, $uri);

        // Only count routes that survived into the spec, so the denominators match apiOperations.
        if (!array_key_exists($key, $substantiveByKey)) {
            continue;
        }

        $class = typedness_shape_class((string) ($route['shape'] ?? ''));

        if ($class === 'no-content') {
            $correctlyEmpty++;

            continue;
        }

        $covered = $substantiveByKey[$key];

        if ($class === 'typed') {
            $typed++;
            $typedCovered += $covered ? 1 : 0;
        } else {
            $dynamic++;
            $dynamicCovered += $covered ? 1 : 0;
        }
    }

    $bodied = $apiOperations - $correctlyEmpty;

    $record['classified'] = true;
    $record['typedActions'] = $typed;
    $record['typedCovered'] = $typedCovered;
    $record['typedReturnCoverage'] = $typed > 0 ? round(100 * $typedCovered / $typed, 1) : null;
    $record['dynamicActions'] = $dynamic;
    $record['dynamicCovered'] = $dynamicCovered;
    $record['correctlyEmptyActions'] = $correctlyEmpty;
    $record['honestPercent'] = $bodied > 0 ? round(100 * $substantive / $bodied, 1) : null;

    return $record;
}

/** Read an app's artifacts and compute its typedness record. */
function typedness_forAppDir(string $appDir, string $apiPrefix): ?array
{
    $spec = json_decode((string) @file_get_contents("$appDir/generated-spec.json"), true);

    if (!is_array($spec)) {
        return null;
    }

    $classifyRaw = json_decode((string) @file_get_contents("$appDir/classify.json"), true);
    $classify = is_array($classifyRaw) ? $classifyRaw : null;

    return typednessMetrics($spec, $classify, $apiPrefix);
}

// CLI entry — only when invoked directly, so the file is safe to require in tests.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $args = array_slice($argv, 1);
    $corpusMode = in_array('--corpus', $args, true);

    if (!$corpusMode) {
        $appDir = $args[0] ?? null;
        $prefix = '/api';

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--prefix=')) {
                $prefix = substr($arg, 9);
            }
        }

        if ($appDir === null || !is_dir($appDir)) {
            fwrite(STDERR, "usage: typedness.php <app-dir> [--prefix=/api]\n");
            exit(2);
        }

        $record = typedness_forAppDir($appDir, $prefix);

        if ($record === null) {
            fwrite(STDERR, "typedness.php: no generated-spec.json in {$appDir}\n");
            exit(1);
        }

        echo json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

        exit(0);
    }

    // --corpus <ws>: iterate the pinned corpus, group by each app's `typedness` tier.
    $workspace = null;
    $corpusJson = __DIR__ . '/corpus.json';

    foreach ($args as $i => $arg) {
        if ($arg === '--corpus') {
            $workspace = $args[$i + 1] ?? null;
        } elseif (str_starts_with($arg, '--corpus-json=')) {
            $corpusJson = substr($arg, 14);
        }
    }

    if ($workspace === null || !is_dir($workspace)) {
        fwrite(STDERR, "usage: typedness.php --corpus <ws> [--corpus-json=<corpus.json>]\n");
        exit(2);
    }

    $corpus = json_decode((string) @file_get_contents($corpusJson), true);

    if (!is_array($corpus['apps'] ?? null)) {
        fwrite(STDERR, "typedness.php: cannot read corpus apps from {$corpusJson}\n");
        exit(1);
    }

    $perApp = [];
    $tiers = [];

    foreach ($corpus['apps'] as $app) {
        $name = (string) $app['name'];
        $prefix = (string) ($app['apiPrefix'] ?? '/api');
        $tier = (string) ($app['typedness'] ?? 'unclassified');
        $record = typedness_forAppDir("$workspace/apps/$name", $prefix);

        if ($record === null) {
            continue;
        }

        $record['name'] = $name;
        $record['tier'] = $tier;
        $perApp[] = $record;

        $bucket = $tiers[$tier] ??= ['apiOperations' => 0, 'substantive' => 0, 'correctlyEmpty' => 0, 'typed' => 0, 'typedCovered' => 0];
        $bucket['apiOperations'] += $record['apiOperations'];
        $bucket['substantive'] += $record['substantiveResponses'];
        $bucket['correctlyEmpty'] += (int) ($record['correctlyEmptyActions'] ?? 0);
        $bucket['typed'] += (int) ($record['typedActions'] ?? 0);
        $bucket['typedCovered'] += (int) ($record['typedCovered'] ?? 0);
        $tiers[$tier] = $bucket;
    }

    $rollup = [];

    foreach ($tiers as $tier => $bucket) {
        $bodied = $bucket['apiOperations'] - $bucket['correctlyEmpty'];
        $rollup[$tier] = [
            'apiOperations' => $bucket['apiOperations'],
            'substantiveResponses' => $bucket['substantive'],
            'substantivePercent' => $bucket['apiOperations'] > 0 ? round(100 * $bucket['substantive'] / $bucket['apiOperations'], 1) : 0.0,
            'honestPercent' => $bodied > 0 ? round(100 * $bucket['substantive'] / $bodied, 1) : 0.0,
            'typedActions' => $bucket['typed'],
            'typedReturnCoverage' => $bucket['typed'] > 0 ? round(100 * $bucket['typedCovered'] / $bucket['typed'], 1) : null,
        ];
    }

    echo json_encode(['byTier' => $rollup, 'perApp' => $perApp], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
