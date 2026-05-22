<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Core\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Core\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Core\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

use function app;
use function count;
use function dirname;
use function file_put_contents;
use function is_writable;
use function realpath;

/**
 * OpenAPI Generate Command
 *
 * @bundle Radiergummi\OpenApi\Console
 */
class GenerateCommand extends Command
{
    /**
     * @deprecated Will be removed in a future release. Kept for ClearCommand backward compatibility.
     */
    public const string ARGUMENT_PATH = 'spec';

    public const string ARGUMENT_SPEC = 'spec';

    public const string OPTION_OUTPUT = 'output';

    public const string OPTION_FORMAT = 'format';

    public const string OPTION_EXPLAIN = 'explain';

    protected $name = 'openapi:generate';

    protected $description = 'Generate an OpenAPI 3.1 document from the application\'s route definitions';

    /**
     * @throws InvalidArgumentException
     */
    public function handle(
        OpenApiGenerationOrchestrator $orchestrator,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
        RouteIntrospector $introspector,
    ): int {
        $specName = $this->argument(self::ARGUMENT_SPEC);
        $outputOverride = $this->option(self::OPTION_OUTPUT);
        $explain = (bool) $this->option(self::OPTION_EXPLAIN);

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

    /**
     * @throws InvalidArgumentException
     */
    protected function configure(): void
    {
        $this->addArgument(
            self::ARGUMENT_SPEC,
            InputArgument::OPTIONAL,
            'Name of the spec to generate. Omit to generate every defined spec.',
            null,
        );

        $this->addOption(
            self::OPTION_OUTPUT,
            null,
            InputOption::VALUE_REQUIRED,
            'Override output path. Requires a single spec target. Use "-" for stdout.',
        );

        $this->addOption(
            self::OPTION_FORMAT,
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: yaml or json.',
            'yaml',
        );

        $this->addOption(
            self::OPTION_EXPLAIN,
            null,
            InputOption::VALUE_NONE,
            'Print one (route × spec) decision line per route on stderr.',
        );
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
        $format = $this->option(self::OPTION_FORMAT);

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
     * @param list<\Radiergummi\OpenApi\Core\Spec\SpecDefinition> $specs
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
                $this->line("[{$spec->name}] {$mark} {$method} {$uri}  {$decision->summary}");
            }
        }
    }

    // endregion
}
