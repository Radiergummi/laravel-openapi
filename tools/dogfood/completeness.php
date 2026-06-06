<?php
/**
 * Full-spec completeness scoreboard (app-agnostic).
 *
 * Reads a generated OpenAPI document and scores each operation under an API prefix:
 * request body (where the verb takes one), a SUBSTANTIVE 2xx response, params, and
 * security. A 2xx response counts as substantive when it resolves (through $ref + a
 * single-key {data:…} envelope unwrap) to any of: an object with >=1 property, a
 * string-keyed map (additionalProperties), a scalar (string/number/boolean), an
 * array of those, OR an explicit no-content 2xx (e.g. 204). An empty {} object body
 * still does NOT count. Prints a summary line + the INCOMPLETE ops.
 *
 * Usage: php completeness.php <generated-spec.json> [--prefix=/api]
 */

declare(strict_types=1);

$specPath = $argv[1] ?? null;
$prefix = '/api';
foreach (array_slice($argv, 2) as $a) {
    if (str_starts_with($a, '--prefix=')) $prefix = substr($a, 9);
}
if (!$specPath || !is_file($specPath)) { fwrite(STDERR, "usage: completeness.php <spec.json> [--prefix=/api]\n"); exit(2); }

$spec = json_decode((string)file_get_contents($specPath), true);
$components = $spec['components']['schemas'] ?? [];

function refName(string $r): ?string { return preg_match('#/components/schemas/(.+)$#', $r, $m) ? $m[1] : null; }
function substantive($s, array $c, array $seen = []): bool {
    if (!is_array($s)) return false;
    if (isset($s['$ref'])) { $n = refName($s['$ref']); if ($n === null || isset($seen[$n])) return false; $seen[$n] = true; return substantive($c[$n] ?? [], $c, $seen); }
    foreach (['allOf', 'oneOf', 'anyOf'] as $k) if (isset($s[$k]) && is_array($s[$k])) foreach ($s[$k] as $b) if (substantive($b, $c, $seen)) return true;
    if (($s['type'] ?? null) === 'array' && isset($s['items'])) return substantive($s['items'], $c, $seen);
    $p = $s['properties'] ?? null;
    if (is_array($p) && $p) { if (count($p) === 1 && isset($p['data'])) return substantive($p['data'], $c, $seen); return true; }
    // A string-keyed map (additionalProperties) is a real payload — e.g. region/plan slug->label maps.
    if (isset($s['additionalProperties']) && $s['additionalProperties'] !== false) return true;
    // A scalar body is a real payload — incl. OAS 3.1 nullable unions like ["string","null"].
    $t = $s['type'] ?? null;
    $scalars = ['string', 'integer', 'number', 'boolean'];
    if (is_string($t) && in_array($t, $scalars, true)) return true;
    if (is_array($t) && array_intersect($t, $scalars)) return true;
    return false; // empty {} object (no properties, no additionalProperties, no scalar type) stays NON-substantive
}

$verbsBody = ['post', 'put', 'patch'];
$rows = []; $tot = 0; $complete = 0; $noResp = 0; $noBody = 0; $noSec = 0;
foreach (($spec['paths'] ?? []) as $path => $ms) {
    if (!str_starts_with($path, $prefix)) continue;
    foreach ($ms as $m => $op) {
        if (!in_array(strtolower($m), ['get', 'post', 'put', 'patch', 'delete']) || !is_array($op)) continue;
        $tot++;
        $needsBody = in_array(strtolower($m), $verbsBody);
        $hasBody = isset($op['requestBody']['content']) && (function ($c) { foreach ($c as $b) if (isset($b['schema'])) return true; return false; })($op['requestBody']['content']);
        $hasResp = false;
        foreach (($op['responses'] ?? []) as $code => $r) {
            if (!preg_match('/^2/', (string)$code)) continue;
            $content = is_array($r) ? ($r['content'] ?? null) : null;
            if (!is_array($content) || $content === []) { $hasResp = true; break; } // explicit no-content 2xx (e.g. 204) is a complete response
            foreach ($content as $b) if (isset($b['schema']) && substantive($b['schema'], $components)) { $hasResp = true; break 2; }
        }
        $hasSec = array_key_exists('security', $op);
        $ok = $hasResp && (!$needsBody || $hasBody);
        if ($ok) $complete++; if (!$hasResp) $noResp++; if ($needsBody && !$hasBody) $noBody++; if (!$hasSec) $noSec++;
        if (!$ok) $rows[] = sprintf('  INCOMPLETE %-6s %-52s resp=%d body=%s', strtoupper($m), $path, $hasResp ? 1 : 0, $needsBody ? ($hasBody ? '1' : '0') : '-');
    }
}
printf("%s ops: %d  complete: %d (%.1f%%)  missing-response: %d  missing-body: %d  no-security: %d\n", $prefix, $tot, $complete, $tot ? 100 * $complete / $tot : 0, $noResp, $noBody, $noSec);
foreach ($rows as $r) echo $r . "\n";
