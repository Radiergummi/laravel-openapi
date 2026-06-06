<?php

declare(strict_types=1);

/**
 * Survey baseline-metrics extractor (app-agnostic, deterministic).
 *
 * surveyMetrics() is the pure contract: (spec, lint, run, apiPrefix, published?) -> metrics array.
 * Run as a CLI it reads an app's captured artifacts and prints the per-app metrics JSON.
 *
 * Usage: php metrics.php <app-dir> --prefix=/api [--published=<spec.json|spec.yaml>]
 *   <app-dir> holds generated-spec.json, lint.json, run.json (written by run.sh).
 */
function survey_refName(string $ref): ?string
{
    return preg_match('#/components/schemas/(.+)$#', $ref, $m) ? $m[1] : null;
}

/** Substantive-2xx test — mirrors completeness.php. */
function survey_substantive(mixed $schema, array $components, array $seen = []): bool
{
    if (!is_array($schema)) {
        return false;
    }

    if (isset($schema['$ref'])) {
        $name = survey_refName($schema['$ref']);

        if ($name === null || isset($seen[$name])) {
            return false;
        }

        $seen[$name] = true;

        return survey_substantive($components[$name] ?? [], $components, $seen);
    }

    foreach (['allOf', 'oneOf', 'anyOf'] as $key) {
        if (is_array($schema[$key] ?? null)) {
            foreach ($schema[$key] as $branch) {
                if (survey_substantive($branch, $components, $seen)) {
                    return true;
                }
            }
        }
    }

    if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
        return survey_substantive($schema['items'], $components, $seen);
    }

    $properties = $schema['properties'] ?? null;

    if (is_array($properties) && $properties !== []) {
        if (count($properties) === 1 && isset($properties['data'])) {
            return survey_substantive($properties['data'], $components, $seen);
        }

        return true;
    }

    if (isset($schema['additionalProperties']) && $schema['additionalProperties'] !== false) {
        return true;
    }

    $type = $schema['type'] ?? null;
    $scalars = ['string', 'integer', 'number', 'boolean'];

    if (is_string($type) && in_array($type, $scalars, true)) {
        return true;
    }

    return is_array($type) && array_intersect($type, $scalars) !== [];
}

/** Count properties of a request-body schema, following one $ref hop. */
function survey_requestPropertyCount(array $schema, array $components): int
{
    if (isset($schema['$ref'])) {
        $name = survey_refName($schema['$ref']);
        $schema = $name !== null ? ($components[$name] ?? []) : [];
    }

    return is_array($schema['properties'] ?? null) ? count($schema['properties']) : 0;
}

/** Normalise a spec into a set of "METHOD path" keys with {param} collapsed. */
function survey_operationKeys(array $spec): array
{
    $methods = ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'];
    $keys = [];

    foreach (($spec['paths'] ?? []) as $path => $item) {
        if (!is_array($item)) {
            continue;
        }

        $norm = preg_replace('/\{[^}]+\}/', '{}', (string) $path);

        foreach ($methods as $method) {
            if (isset($item[$method])) {
                $keys[strtoupper($method) . ' ' . $norm] = true;
            }
        }
    }

    return array_keys($keys);
}

/**
 * @param array{generateExit:int,lintExit:int,generateStderr:bool,bootOutcome:string,routesIntrospected?:int} $run
 */
function surveyMetrics(array $spec, array $lint, array $run, string $apiPrefix = '/api', ?array $published = null): array
{
    $components = $spec['components']['schemas'] ?? [];
    $verbs = ['get', 'post', 'put', 'patch', 'delete'];
    $bodyVerbs = ['post', 'put', 'patch'];

    $paths = count($spec['paths'] ?? []);
    $operations = 0;
    $apiOperations = 0;
    $responseSchemas = 0;
    $requestBodies = 0;
    $maxRequestProperties = 0;
    $complete = 0;

    foreach (($spec['paths'] ?? []) as $path => $methods) {
        if (!is_array($methods)) {
            continue;
        }

        foreach ($methods as $method => $op) {
            $method = strtolower((string) $method);

            if (!in_array($method, $verbs, true) || !is_array($op)) {
                continue;
            }

            $operations++;
            $underPrefix = str_starts_with((string) $path, $apiPrefix);

            if (!$underPrefix) {
                continue;
            }

            $apiOperations++;

            $hasBody = false;

            foreach (($op['requestBody']['content'] ?? []) as $media) {
                if (isset($media['schema']) && is_array($media['schema'])) {
                    $hasBody = true;
                    $maxRequestProperties = max($maxRequestProperties, survey_requestPropertyCount($media['schema'], $components));
                }
            }

            if ($hasBody) {
                $requestBodies++;
            }

            $hasResponse = false;

            foreach (($op['responses'] ?? []) as $code => $response) {
                if (!preg_match('/^2/', (string) $code) || !is_array($response)) {
                    continue;
                }

                $content = $response['content'] ?? null;

                if (!is_array($content) || $content === []) {
                    $hasResponse = true; // explicit no-content 2xx

                    break;
                }

                foreach ($content as $media) {
                    if (isset($media['schema']) && survey_substantive($media['schema'], $components)) {
                        $hasResponse = true;

                        break 2;
                    }
                }
            }

            if ($hasResponse) {
                $responseSchemas++;
            }

            if ($hasResponse && (!in_array($method, $bodyVerbs, true) || $hasBody)) {
                $complete++;
            }
        }
    }

    $byRule = [];
    $byLevel = [];

    foreach (($lint['findings'] ?? []) as $finding) {
        $rule = (string) ($finding['rule_id'] ?? 'unknown');
        $level = (int) ($finding['level'] ?? 0);
        $byRule[$rule] = ($byRule[$rule] ?? 0) + 1;
        $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
    }

    $metrics = [
        'paths' => $paths,
        'operations' => $operations,
        'apiOperations' => $apiOperations,
        'responseSchemas' => $responseSchemas,
        'requestBodies' => $requestBodies,
        'maxRequestProperties' => $maxRequestProperties,
        'componentSchemas' => count($components),
        'completenessPercent' => $apiOperations > 0 ? round(100 * $complete / $apiOperations, 1) : 0.0,
        'lintFindings' => [
            'total' => count($lint['findings'] ?? []),
            'byLevel' => $byLevel,
            'byRule' => $byRule,
        ],
        'crash' => [
            'generateExit' => $run['generateExit'],
            'lintExit' => $run['lintExit'],
            'generateStderr' => $run['generateStderr'],
            'bootOutcome' => $run['bootOutcome'],
            'routesIntrospected' => $run['routesIntrospected'] ?? null,
        ],
    ];

    if ($published !== null) {
        $ours = survey_operationKeys($spec);
        $theirs = survey_operationKeys($published);
        $intersection = count(array_intersect($ours, $theirs));
        $metrics['coverage'] = [
            'publishedOps' => count($theirs),
            'ours' => count($ours),
            'intersection' => $intersection,
            'covPercent' => count($theirs) > 0 ? round(100 * $intersection / count($theirs), 1) : 0.0,
        ];
    }

    return $metrics;
}

// CLI entry — only when invoked directly, so the file is safe to require in tests.
if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === realpath(__FILE__)) {
    $appDir = $argv[1] ?? null;
    $prefix = '/api';
    $publishedPath = null;

    foreach (array_slice($argv, 2) as $arg) {
        if (str_starts_with($arg, '--prefix=')) {
            $prefix = substr($arg, 9);
        } elseif (str_starts_with($arg, '--published=')) {
            $publishedPath = substr($arg, 12);
        }
    }

    if ($appDir === null || !is_dir($appDir)) {
        fwrite(STDERR, "usage: metrics.php <app-dir> [--prefix=/api] [--published=<spec>]\n");
        exit(2);
    }

    $spec = json_decode((string) @file_get_contents("$appDir/generated-spec.json"), true) ?: ['paths' => []];
    $lint = json_decode((string) @file_get_contents("$appDir/lint.json"), true) ?: ['findings' => []];
    $run = json_decode((string) @file_get_contents("$appDir/run.json"), true) ?: [
        'generateExit' => -1, 'lintExit' => -1, 'generateStderr' => true, 'bootOutcome' => 'unknown',
    ];

    $published = null;

    if ($publishedPath !== null && is_file($publishedPath)) {
        $raw = (string) file_get_contents($publishedPath);
        $published = json_decode($raw, true);

        if (!is_array($published)) {
            require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
            $published = (array) Symfony\Component\Yaml\Yaml::parse($raw);
        }
    }

    echo json_encode(surveyMetrics($spec, $lint, $run, $prefix, $published), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
