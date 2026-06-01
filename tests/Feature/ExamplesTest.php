<?php

declare(strict_types=1);

use Examples\Shared\Flavors;
use Examples\Shared\TestbenchBoot;
use Illuminate\Contracts\Console\Kernel;
use OpenApi\Analysis;
use OpenApi\Context;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;

/*
 * Per-flavor verification:
 *   1. Boot Testbench with the flavor's ServiceProvider.
 *   2. Generate the OpenAPI document to a temp file.
 *   3. Assert it byte-matches the committed snapshot.
 *   4. Assert it validates as OpenAPI 3.1 (swagger-php Analysis::validate()).
 *   5. Assert `openapi:lint` reports zero findings.
 */
$exampleYaml = static fn(string $flavor): string => dirname(__DIR__, 2) . "/examples/{$flavor}/openapi.yaml";

dataset('flavors', array_map(
    static fn(string $provider, string $flavor): array => [$provider, $flavor],
    Flavors::all(),
    array_keys(Flavors::all()),
));

it('produces a snapshot that matches the committed yaml', function (string $serviceProvider, string $flavor) use ($exampleYaml): void {
    $app = TestbenchBoot::boot($serviceProvider);
    $snapshot = $exampleYaml($flavor);
    $temp = tempnam(sys_get_temp_dir(), 'openapi-');

    try {
        $status = $app->make(Kernel::class)->call('openapi:generate', [
            '--output' => $temp,
            '--format' => 'yaml',
        ]);

        expect($status)->toBe(0)
            ->and(file_get_contents($temp))->toBe(file_get_contents($snapshot));
    } finally {
        @unlink($temp);
    }
})->with('flavors')->group('snapshot');

it('produces a valid OpenAPI 3.1 document', function (string $serviceProvider, string $flavor): void {
    $app = TestbenchBoot::boot($serviceProvider);

    $orchestrator = $app->make(OpenApiGenerationOrchestrator::class);
    $registry = $app->make(SpecRegistry::class);
    $specs = $registry->all();

    expect($specs)->not->toBeEmpty("flavor '{$flavor}' has no spec defined");

    foreach ($specs as $spec) {
        $document = $orchestrator->generateOne($spec->name, $app->environment());

        $analysis = new Analysis([], new Context());
        $analysis->openapi = $document;

        expect($analysis->validate())
            ->toBeTrue("spec '{$spec->name}' for flavor '{$flavor}' failed swagger-php validation");
    }
})->with('flavors');

it('lints clean', function (string $serviceProvider, string $flavor) use ($exampleYaml): void {
    $app = TestbenchBoot::boot($serviceProvider);
    config()->set('openapi.output_path', $exampleYaml($flavor));

    $status = $app->make(Kernel::class)->call('openapi:lint');

    expect($status)->toBe(0);
})->with('flavors');
