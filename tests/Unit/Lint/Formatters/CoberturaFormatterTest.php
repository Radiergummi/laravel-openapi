<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\CoverageSummary;
use Radiergummi\OpenApi\Lint\Formatters\CoberturaFormatter;
use Radiergummi\OpenApi\Lint\LintResult;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

function renderCobertura(CoverageSummary $coverage): SimpleXMLElement
{
    $output = new BufferedOutput();
    new CoberturaFormatter()->render(new LintResult(findings: [], level: 1, exitCode: 0, coverage: $coverage), $output);

    return new SimpleXMLElement($output->fetch());
}

function coverageWith(array $perOperation, string $version = '9.9.9'): CoverageSummary
{
    return new CoverageSummary(
        generatorVersion: $version,
        level: 1,
        totalOperations: count($perOperation),
        coveredOperations: 0,
        coveragePercent: 0.0,
        unattributedFindings: 0,
        perTag: [],
        perOperation: $perOperation,
    );
}

it('emits a Cobertura class per source file with line hits from the covered flag', function (): void {
    $xml = renderCobertura(coverageWith([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => 'app/A.php', 'line' => 20, 'covered' => false],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
    ]));

    $classes = [];

    foreach ($xml->xpath('//class') ?: [] as $class) {
        $classes[(string) $class['filename']] = $class;
    }

    expect(array_keys($classes))->toEqualCanonicalizing(['app/A.php', 'app/B.php']);

    $aLines = [];

    foreach ($classes['app/A.php']->lines->line as $line) {
        $aLines[(int) $line['number']] = (int) $line['hits'];
    }

    expect($aLines)->toBe([10 => 1, 20 => 0]);
});

it('excludes operations with no source file or line (lossy-attribution footgun)', function (): void {
    $xml = renderCobertura(coverageWith([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => null, 'line' => 7, 'covered' => true],
        ['file' => 'app/C.php', 'line' => null, 'covered' => true],
    ]));

    $filenames = array_map(static fn(SimpleXMLElement $c): string => (string) $c['filename'], $xml->xpath('//class') ?: []);

    expect($filenames)->toBe(['app/A.php']);
});

it('marks a shared line uncovered when any operation on it is uncovered (conservative)', function (): void {
    $xml = renderCobertura(coverageWith([
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => false],
    ]));

    $lines = array_map(
        static fn(SimpleXMLElement $l): array => ['number' => (int) $l['number'], 'hits' => (int) $l['hits']],
        $xml->xpath('//class[@filename="app/B.php"]/lines/line') ?: [],
    );

    expect($lines)->toBe([['number' => 5, 'hits' => 0]]);
});

it('sets the root line-rate to covered-lines over valid-lines and stamps the generator version', function (): void {
    // distinct lines: A:10 (covered), A:20 (uncovered), B:5 (uncovered via collision) → 1/3
    $xml = renderCobertura(coverageWith([
        ['file' => 'app/A.php', 'line' => 10, 'covered' => true],
        ['file' => 'app/A.php', 'line' => 20, 'covered' => false],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => true],
        ['file' => 'app/B.php', 'line' => 5, 'covered' => false],
    ], version: '1.4.2'));

    expect(round((float) $xml['line-rate'], 4))->toBe(0.3333)
        ->and((int) $xml['lines-valid'])->toBe(3)
        ->and((int) $xml['lines-covered'])->toBe(1)
        ->and((string) $xml['version'])->toContain('1.4.2');
});

it('emits a valid empty coverage document when there is nothing to attribute', function (): void {
    $xml = renderCobertura(coverageWith([]));

    expect($xml->getName())->toBe('coverage')
        ->and((int) $xml['lines-valid'])->toBe(0)
        ->and($xml->xpath('//class'))->toBe([]);
});

it('emits a valid empty coverage document when coverage is null', function (): void {
    $output = new BufferedOutput();
    $result = new LintResult(findings: [], level: 1, exitCode: 0, coverage: null);

    new CoberturaFormatter()->render($result, $output);

    $xml = new SimpleXMLElement($output->fetch());

    expect($xml->getName())->toBe('coverage')
        ->and((int) $xml['lines-valid'])->toBe(0)
        ->and($xml->xpath('//class'))->toBe([]);
});
