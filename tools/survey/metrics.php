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

/** Substantive-2xx test — the one home, shared by completeness.php and typedness.php. */
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

        // A JSON:API resource object carries its payload in `attributes`; `type`/`id` and the
        // other envelope members are structure, not shape. Keyed on the closed member set, so
        // an object that merely happens to have a `type` and an `id` is unaffected.
        if (isset($properties['type'], $properties['id'])) {
            $envelope = ['type', 'id', 'attributes', 'relationships', 'links', 'meta'];

            if (array_diff(array_keys($properties), $envelope) === []) {
                return isset($properties['attributes'])
                    && survey_substantive($properties['attributes'], $components, $seen);
            }
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

/**
 * Whether an action's source shape means a 2xx is *correctly* empty (no body is the right answer),
 * as opposed to a give-up empty (the action returns a body the generator could not resolve).
 */
function survey_isNoContentShape(string $shape, string $returnType): bool
{
    $returnType = strtolower(ltrim($returnType, '?'));

    if ($returnType === 'void' || $returnType === 'never') {
        return true;
    }

    $noContent = [
        'response()->noContent()',
        'return; (void)',
        'no return (void-like)',
        'void/no-body',
    ];

    return in_array($shape, $noContent, true) || str_starts_with($shape, 'scalar literal (null)');
}

/**
 * Score one operation on the two axes the survey measures: its 2xx response and its request body.
 *
 * The single home of both classifications, shared by surveyMetrics() and completeness.php so the
 * two cannot drift. `hasAnyResponse` rides along because documentedResponses counts any 2xx
 * (contentless included) and that is not recoverable from the response bucket.
 *
 * @param array<string, mixed>                          $operation
 * @param array<string, mixed>                          $components
 * @param null|array{shape: string, returnType: string} $classificationRecord
 *
 * @return array{
 *     response: 'correctlyEmpty'|'genuinelyMissing'|'substantive',
 *     body: 'documented'|'notApplicable'|'undocumentedOnWrite',
 *     hasAnyResponse: bool,
 *     hasSecurity: bool,
 * }
 */
function survey_operationOutcome(
    array $operation,
    string $method,
    array $components,
    ?array $classificationRecord,
): array {
    $hasAnyResponse = false;
    $hasAffirmativeNoContent = false;
    $hasSubstantiveResponse = false;

    foreach (($operation['responses'] ?? []) as $code => $response) {
        if (!preg_match('/^2/', (string) $code) || !is_array($response)) {
            continue;
        }

        $hasAnyResponse = true;
        $content = $response['content'] ?? null;

        if (!is_array($content) || $content === []) {
            // Only 204/205 affirm that no body is the right answer. A contentless 200/201/202 is
            // the generator's give-up path and must earn nothing on its own.
            if (in_array((string) $code, ['204', '205'], true)) {
                $hasAffirmativeNoContent = true;
            }

            continue;
        }

        foreach ($content as $media) {
            if (isset($media['schema']) && survey_substantive($media['schema'], $components)) {
                $hasSubstantiveResponse = true;

                break 2;
            }
        }
    }

    $classifiedNoContent = $classificationRecord !== null && survey_isNoContentShape(
        $classificationRecord['shape'],
        $classificationRecord['returnType'],
    );

    $hasBody = false;

    foreach (($operation['requestBody']['content'] ?? []) as $media) {
        if (isset($media['schema']) && is_array($media['schema'])) {
            $hasBody = true;

            break;
        }
    }

    return [
        'response' => match (true) {
            $hasSubstantiveResponse => 'substantive',
            $classifiedNoContent || $hasAffirmativeNoContent => 'correctlyEmpty',
            default => 'genuinelyMissing',
        },
        'body' => match (true) {
            $hasBody => 'documented',
            in_array($method, ['post', 'put', 'patch'], true) => 'undocumentedOnWrite',
            default => 'notApplicable',
        },
        'hasAnyResponse' => $hasAnyResponse,
        'hasSecurity' => array_key_exists('security', $operation),
    ];
}

/**
 * Whether an operation counts as complete under the given measurement basis.
 *
 * Classified (an action classification was supplied) credits a correctly-empty response; strict
 * cannot tell one from a give-up empty, so it credits a substantive payload only.
 *
 * @param array{response: string, body: string, hasAnyResponse: bool, hasSecurity: bool} $outcome
 */
function survey_isComplete(array $outcome, string $basis): bool
{
    return $outcome['response'] === 'substantive'
        || ($basis === 'classified' && $outcome['response'] === 'correctlyEmpty');
}

/**
 * Index classification records (from classify.php) by normalised "VERB /path" key, collapsing
 * path parameters to {} so they join the spec's operation keys.
 *
 * @param list<array<string, mixed>> $classification
 *
 * @return array<string, array{shape: string, returnType: string}>
 */
function survey_classificationIndex(array $classification): array
{
    $index = [];

    foreach ($classification as $record) {
        $uri = (string) ($record['uri'] ?? '');
        $verb = strtoupper((string) ($record['verb'] ?? ''));

        if ($uri === '' || $verb === '') {
            continue;
        }

        $key = $verb . ' ' . preg_replace('/\{[^}]+\}/', '{}', $uri);
        $index[$key] = [
            'shape' => (string) ($record['shape'] ?? 'unclassified'),
            'returnType' => (string) ($record['returnType'] ?? ''),
        ];
    }

    return $index;
}

/**
 * Maps detected third-party integration packages to the bundled plugin class-strings they enable, for
 * the "stack-enabled" coverage variant. Deterministic; unknown packages are ignored. SpatieData and
 * ApiResources are on by default and are not in this map.
 *
 * @param list<string> $detectedPackages composer package names present in the app
 *
 * @return list<string> plugin class-strings, deduped, in map order
 */
function survey_stackPlugins(array $detectedPackages): array
{
    $map = [
        'league/fractal' => 'Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin',
        'spatie/laravel-fractal' => 'Radiergummi\OpenApi\Plugins\Fractal\FractalPlugin',
        'spatie/laravel-query-builder' => 'Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin',
    ];

    $plugins = [];

    foreach ($detectedPackages as $package) {
        if (isset($map[$package]) && !in_array($map[$package], $plugins, true)) {
            $plugins[] = $map[$package];
        }
    }

    return $plugins;
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
function surveyMetrics(array $spec, array $lint, array $run, string $apiPrefix = '/api', ?array $published = null, ?array $classification = null): array
{
    $components = $spec['components']['schemas'] ?? [];
    $verbs = ['get', 'post', 'put', 'patch', 'delete'];

    $paths = count($spec['paths'] ?? []);
    $operations = 0;
    $apiOperations = 0;
    $responseSchemas = 0;
    $documentedResponses = 0;
    $requestBodies = 0;
    $maxRequestProperties = 0;
    $operationsWithSecurity = 0;
    $complete = 0;
    $bodyBuckets = ['documented' => 0, 'undocumentedOnWrite' => 0, 'notApplicable' => 0];

    // Three-way response coverage (only when an action classification is supplied — the
    // correctly-empty vs give-up-empty split needs the action's return shape, not the spec).
    $classificationIndex = $classification !== null ? survey_classificationIndex($classification) : null;
    $basis = $classificationIndex !== null ? 'classified' : 'strict';
    $covSubstantive = 0;
    $covCorrectlyEmpty = 0;
    $covGenuinelyMissing = 0;
    $covByShape = [];

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

            // maxRequestProperties needs the media schema itself, which the outcome does not
            // carry, so it keeps its own walk rather than widening the seam for one metric.
            foreach (($op['requestBody']['content'] ?? []) as $media) {
                if (isset($media['schema']) && is_array($media['schema'])) {
                    $maxRequestProperties = max($maxRequestProperties, survey_requestPropertyCount($media['schema'], $components));
                }
            }

            $normalisedPath = preg_replace('/\{[^}]+\}/', '{}', (string) $path);
            $record = $classificationIndex !== null
                ? ($classificationIndex[strtoupper($method) . ' ' . $normalisedPath] ?? null)
                : null;
            $outcome = survey_operationOutcome($op, $method, $components, $record);

            $bodyBuckets[$outcome['body']]++;

            if ($outcome['body'] === 'documented') {
                $requestBodies++;
            }

            // responseSchemas counts only substantive schemas — a contentless 2xx carries
            // none, so it lands in documentedResponses instead (see #254).
            if ($outcome['response'] === 'substantive') {
                $responseSchemas++;
            }

            if ($outcome['hasAnyResponse']) {
                $documentedResponses++;
            }

            if ($outcome['hasSecurity']) {
                $operationsWithSecurity++;
            }

            if (survey_isComplete($outcome, $basis)) {
                $complete++;
            }

            if ($classificationIndex !== null) {
                match ($outcome['response']) {
                    'substantive' => $covSubstantive++,
                    'correctlyEmpty' => $covCorrectlyEmpty++,
                    default => $covGenuinelyMissing++,
                };

                if ($outcome['response'] === 'genuinelyMissing') {
                    $shape = $record['shape'] ?? 'unclassified';
                    $covByShape[$shape] = ($covByShape[$shape] ?? 0) + 1;
                }
            }
        }
    }

    ksort($covByShape);

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
        'documentedResponses' => $documentedResponses,
        'requestBodies' => $requestBodies,
        'maxRequestProperties' => $maxRequestProperties,
        'componentSchemas' => count($components),
        'operationsWithSecurity' => $operationsWithSecurity,
        'requestBodyCoverage' => $bodyBuckets,
        'completenessPercent' => $apiOperations > 0 ? round(100 * $complete / $apiOperations, 1) : 0.0,
        'completenessBasis' => $basis,
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

    if ($classificationIndex !== null) {
        $metrics['responseCoverage'] = [
            'substantive' => $covSubstantive,
            'correctlyEmpty' => $covCorrectlyEmpty,
            'genuinelyMissing' => $covGenuinelyMissing,
            'genuinelyMissingByShape' => $covByShape,
        ];
    }

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

/** First existing path from an ordered candidate list, or null when none exist. */
function survey_firstExistingFile(array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

/**
 * Locate a Composer autoloader.
 *
 * The survey can point LIB at a git worktree, which shares the main repo's .git but has no
 * vendor/ of its own (only the main checkout is composer-installed). Prefer this checkout's
 * vendor/, then fall back to the main checkout resolved via the shared git common dir.
 */
function survey_resolveAutoloader(): ?string
{
    $candidates = [dirname(__DIR__, 2) . '/vendor/autoload.php'];

    $commonDir = trim((string) @shell_exec(
        'git -C ' . escapeshellarg(__DIR__)
        . ' rev-parse --path-format=absolute --git-common-dir 2>/dev/null',
    ));

    if ($commonDir !== '' && ($mainGitDir = realpath($commonDir)) !== false) {
        $candidates[] = dirname($mainGitDir) . '/vendor/autoload.php';
    }

    return survey_firstExistingFile($candidates);
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

    // Optional action classification (classify.php output) enables the three-way responseCoverage block.
    $classification = is_file("$appDir/classify.json")
        ? (json_decode((string) file_get_contents("$appDir/classify.json"), true) ?: null)
        : null;

    $published = null;

    if ($publishedPath !== null && is_file($publishedPath)) {
        $raw = (string) file_get_contents($publishedPath);
        $published = json_decode($raw, true);

        if (!is_array($published)) {
            $autoloader = survey_resolveAutoloader();

            if ($autoloader === null) {
                fwrite(STDERR, 'metrics.php: could not locate vendor/autoload.php to parse a YAML spec '
                    . "(is the main checkout composer-installed?)\n");
                exit(2);
            }

            require_once $autoloader;
            $published = (array) Symfony\Component\Yaml\Yaml::parse($raw);
        }

        // Empty/unparseable specs decode to []; treat as "no published spec" so the
        // coverage block is omitted rather than emitting a misleading coverage 0%.
        if (!isset($published['paths'])) {
            $published = null;
        }
    }

    $metrics = surveyMetrics($spec, $lint, $run, $prefix, $published, $classification);

    // Stack-enabled variant: the same three-way coverage measured against the spec generated with the
    // app's stack-implied plugins additionally enabled (generate-stack.php output). Reported alongside
    // the out-of-the-box numbers so a Fractal/QueryBuilder app's achievable coverage is not understated.
    if ($classification !== null && is_file("$appDir/generated-spec.stack.json")) {
        $stackSpec = json_decode((string) file_get_contents("$appDir/generated-spec.stack.json"), true);

        if (is_array($stackSpec) && isset($stackSpec['paths'])) {
            $stackMetrics = surveyMetrics($stackSpec, $lint, $run, $prefix, null, $classification);
            $metrics['responseCoverageStackEnabled'] = $stackMetrics['responseCoverage'];
        }
    }

    echo json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
}
