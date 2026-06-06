#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * compare.php <generated-spec> <published-spec>
 *
 * Emits a markdown coverage report comparing our generated OpenAPI document
 * against an app's published one. Path×method coverage only — schema fidelity
 * is the manual spot-check, deliberately out of scope here.
 *
 * Paths are normalised by collapsing every {param} to {} so that parameter
 * naming differences ({id} vs {user}) do not create false misses. Accepts JSON
 * or YAML on either side. YAML support comes from the library's symfony/yaml.
 *
 * LIB defaults to this repository (the tool ships inside it); override the LIB
 * env var to point at a different library checkout.
 */
$LIB = getenv('LIB') ?: dirname(__DIR__, 2);
require $LIB . '/vendor/autoload.php';

use Symfony\Component\Yaml\Yaml;

if ($argc !== 3) {
    fwrite(STDERR, "usage: compare.php <generated-spec> <published-spec>\n");
    exit(2);
}

/** @return array<string,mixed> */
function load(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "missing spec: {$path}\n");
        exit(2);
    }

    $raw = (string) file_get_contents($path);
    $trimmed = ltrim($raw);

    if ($trimmed !== '' && ($trimmed[0] === '{' || $trimmed[0] === '[')) {
        $decoded = json_decode($raw, true);

        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return (array) Yaml::parse($raw);
}

/**
 * Flatten a spec into a set of normalised "METHOD path" operation keys.
 *
 * @param array<string,mixed> $spec
 *
 * @return array<string,string> normalised key => original "METHOD path" label
 */
function operations(array $spec): array
{
    $methods = ['get', 'put', 'post', 'delete', 'patch', 'head', 'options', 'trace'];
    $ops = [];

    foreach (($spec['paths'] ?? []) as $path => $item) {
        if (!is_array($item)) {
            continue;
        }

        $norm = preg_replace('/\{[^}]+\}/', '{}', (string) $path);

        foreach ($methods as $method) {
            if (isset($item[$method])) {
                $key = strtoupper($method) . ' ' . $norm;
                $ops[$key] = strtoupper($method) . ' ' . $path;
            }
        }
    }

    return $ops;
}

/** @param array<string,mixed> $spec */
function componentCount(array $spec): int
{
    return count($spec['components']['schemas'] ?? []);
}

$gen = load($argv[1]);
$pub = load($argv[2]);

$genOps = operations($gen);
$pubOps = operations($pub);

$genKeys = array_keys($genOps);
$pubKeys = array_keys($pubOps);

$both = array_intersect($genKeys, $pubKeys);
$onlyPub = array_diff($pubKeys, $genKeys); // we are missing these
$onlyGen = array_diff($genKeys, $pubKeys); // extra / they under-document

sort($both);
sort($onlyPub);
sort($onlyGen);

$cov = count($pubKeys) > 0
    ? round(count($both) / count($pubKeys) * 100, 1)
    : 0.0;

/** @param list<string> $keys */
function section(string $heading, array $keys): string
{
    $body = $keys ? implode("\n", array_map(static fn($k) => "- $k", $keys)) : '_none_';

    return "## {$heading}\n\n{$body}\n";
}

$summary = implode("\n", [
    '# Coverage comparison',
    '',
    sprintf('- Generated (ours): **%d** operations, **%d** component schemas', count($genKeys), componentCount($gen)),
    sprintf('- Published (theirs): **%d** operations, **%d** component schemas', count($pubKeys), componentCount($pub)),
    sprintf('- In both: **%d** · Only theirs (we miss): **%d** · Only ours (extra): **%d**', count($both), count($onlyPub), count($onlyGen)),
    sprintf('- **Cov%% = %s%%** (|ours ∩ theirs| / |theirs|)', $cov),
]);

echo $summary . "\n\n";
echo section('Only in published spec (we are missing these)', $onlyPub) . "\n";
echo section('Only in our spec (extra, or they under-document)', $onlyGen) . "\n";
echo section('In both (candidates for fidelity spot-check)', $both) . "\n";
