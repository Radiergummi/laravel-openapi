<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecDefinition;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Throwable;

use function app;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function is_writable;
use function realpath;

/**
 * OpenAPI Generate Command
 *
 * @bundle Radiergummi\OpenApi\Console
 */
#[Signature('openapi:generate
        {spec? : Name of the spec to generate. Omit to generate every defined spec.}
        {--output= : Override output path. Requires a single spec target. Use "-" for stdout.}
        {--format=yaml : Output format: yaml or json.}
        {--explain : Print one (route × spec) decision line per route on stderr.}')]
#[Description('Generate an OpenAPI 3.1 document from the application\'s route definitions')]
class GenerateCommand extends Command
{
    /**
     * @throws InvalidArgumentException
     */
    public function handle(
        OpenApiGenerationOrchestrator $orchestrator,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
        RouteIntrospector $introspector,
    ): int {
        $specName = $this->argument('spec');
        $outputOverride = $this->option('output');
        $explain = (bool) $this->option('explain');

        $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];

        if ($outputOverride !== null && count($targets) > 1) {
            $this->components->error('--output= requires a single spec target. Pass the spec name positionally.');

            return self::FAILURE;
        }

        if ($explain) {
            $this->emitExplain($evaluator, $introspector, $registry->all());
        }

        foreach ($targets as $spec) {
            $document = $orchestrator->generateOne($spec->name, app()->environment());

            if (! $this->validate($document)) {
                return self::FAILURE;
            }

            $content = $this->serialise($document);
            $path = $outputOverride !== null ? (string) $outputOverride : $spec->outputPath;

            try {
                $this->writeOutput($path, $content);
            } catch (Throwable $e) {
                $this->components->error("Failed to write OpenAPI file for spec '{$spec->name}': {$e->getMessage()}");

                return self::FAILURE;
            }

            if ($path !== '-') {
                $this->components->info("OpenAPI document for spec '{$spec->name}' written to {$path}");
            }
        }

        return self::SUCCESS;
    }

    // region Private helpers

    /**
     * Validates the generated document using swagger-php's Analysis pipeline.
     * Reports any validation errors to the console and returns false on failure.
     */
    private function validate(OA\OpenApi $openapi): bool
    {
        $context = new Context();
        $analysis = new Analysis([], $context);
        $analysis->openapi = $openapi;

        $valid = $analysis->validate();

        if (! $valid) {
            $this->components->error('OpenAPI validation failed. The document may be incomplete.');
        }

        return $valid;
    }

    /**
     * Serializes the document to YAML or JSON depending on --format.
     */
    private function serialise(OA\OpenApi $openapi): string
    {
        $format = $this->option('format');

        return match ($format) {
            'json' => $openapi->toJson(),
            default => $openapi->toYaml(),
        };
    }

    /**
     * @throws RuntimeException
     */
    private function writeOutput(string $path, string $content): void
    {
        if ($path === '-') {
            fwrite(STDOUT, $content);

            return;
        }

        if (realpath(dirname($path)) === false) {
            throw new RuntimeException("Output directory does not exist: {$path}");
        }

        if (! is_writable(dirname($path))) {
            throw new RuntimeException("Output directory is not writable: {$path}");
        }

        file_put_contents($path, $content);
    }

    /**
     * @param list<SpecDefinition> $specs
     */
    private function emitExplain(
        InclusionEvaluator $evaluator,
        RouteIntrospector $introspector,
        array $specs,
    ): void {
        foreach ($introspector->discover() as $descriptor) {
            foreach ($specs as $spec) {
                $decision = $evaluator->decide($descriptor, $spec, app()->environment());
                $mark = $decision->included ? '✓' : '✗';
                $method = $descriptor->route->methods()[0] ?? 'GET';
                $uri = $descriptor->route->uri();
                fwrite(STDERR, "[{$spec->name}] {$mark} {$method} {$uri}  {$decision->summary}" . PHP_EOL);
            }
        }
    }

    // endregion
}
