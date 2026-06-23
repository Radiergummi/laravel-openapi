<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Illuminate\Console\Command;
use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Contracts\Container\BindingResolutionException;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use Radiergummi\OpenApi\Support\Diagnostics\PluginHintInspector;
use Radiergummi\OpenApi\Support\Generator\OpenApiGenerationOrchestrator;
use Radiergummi\OpenApi\Support\Inclusion\InclusionEvaluator;
use Radiergummi\OpenApi\Support\Routing\RouteIntrospector;
use Radiergummi\OpenApi\Support\Spec\SpecDefinition;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Throwable;
use UnexpectedValueException;

use function app;
use function array_filter;
use function array_values;
use function config;
use function count;
use function dirname;
use function file_put_contents;
use function fwrite;
use function in_array;
use function is_string;
use function is_writable;
use function realpath;

class GenerateCommand extends Command
{
    /**
     * @var list<string>
     */
    private const array SUPPORTED_FORMATS = ['yaml', 'json'];

    protected $signature = 'openapi:generate
        {spec? : Name of the spec to generate. Omit to generate every defined spec.}
        {--output= : Override output path. Requires a single spec target. Use "-" for stdout.}
        {--format=yaml : Output format: yaml or json.}
        {--explain : Print one (route × spec) decision line per route on stderr.}
        {--no-validate : Skip the swagger-php validation pass (faster; the document is machine-built and normally valid).}';

    protected $description = 'Generate an OpenAPI 3.1 document from the application\'s route definitions';

    /**
     * @throws \InvalidArgumentException
     * @throws BindingResolutionException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws RuntimeException
     * @throws UnsupportedException
     */
    public function handle(
        OpenApiGenerationOrchestrator $orchestrator,
        SpecRegistry $registry,
        InclusionEvaluator $evaluator,
        RouteIntrospector $introspector,
        PluginHintInspector $pluginHints,
    ): int {
        $specName = $this->argument('spec');
        $outputOverride = $this->option('output');
        $explain = (bool) $this->option('explain');
        $validate = !(bool) $this->option('no-validate');
        $format = (string) $this->option('format');

        if (!in_array($format, self::SUPPORTED_FORMATS, true)) {
            $this->components->error("Unsupported --format value '{$format}'. Use 'yaml' or 'json'.");

            return self::FAILURE;
        }

        $targets = $specName === null ? $registry->all() : [$registry->get((string) $specName)];

        if ($outputOverride !== null && count($targets) > 1) {
            $this->components->error('--output= requires a single spec target. Pass the spec name positionally.');

            return self::FAILURE;
        }

        if ($explain) {
            $this->emitExplain($evaluator, $introspector, $registry->all());
        }

        $this->emitPluginHints($pluginHints);

        foreach ($targets as $spec) {
            $document = $orchestrator->generateOne($spec->name, app()->environment());

            if ($validate && !$this->validate($document)) {
                return self::FAILURE;
            }

            $content = $this->serialise($document);
            $path = $outputOverride !== null ? (string) $outputOverride : $spec->outputPath;

            try {
                $this->writeOutput($path, $content);
            } catch (Throwable $exception) {
                $this->components->error(
                    "Failed to write OpenAPI file for spec '{$spec->name}': {$exception->getMessage()}",
                );

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
     * Prints an advisory once per applicable integration package, on stderr so the document a
     * subsequent `--output=-` writes to stdout stays parseable. Advisory only: never auto-enables.
     *
     * @throws \InvalidArgumentException
     */
    private function emitPluginHints(PluginHintInspector $pluginHints): void
    {
        $enabledPlugins = array_values(array_filter(
            (array) config('openapi.plugins', []),
            static fn(mixed $plugin): bool => is_string($plugin),
        ));

        $hints = $pluginHints->hints($enabledPlugins);

        if ($hints === []) {
            return;
        }

        $components = new Factory($this->errorOutput());

        foreach ($hints as $hint) {
            $components->warn($hint);
        }
    }

    /**
     * Wraps the command's error stream in an {@see OutputStyle} so console-component output lands on
     * stderr. Falls back to the regular output when no separate error stream is available.
     */
    private function errorOutput(): OutputStyle
    {
        $output = $this->output->getOutput();
        $errorStream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        return new OutputStyle($this->input, $errorStream);
    }

    /**
     * @param list<SpecDefinition> $specs
     *
     * @throws ReflectionException
     * @throws UnexpectedValueException
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
                $method = $descriptor->httpMethod?->forDisplay() ?? '?';
                $uri = $descriptor->route->uri();
                fwrite(STDERR, "[{$spec->name}] {$mark} {$method} {$uri}  {$decision->summary}" . PHP_EOL);
            }
        }
    }

    /**
     * @throws \InvalidArgumentException
     * @throws InvalidArgumentException
     */
    private function validate(OA\OpenApi $openapi): bool
    {
        $context = new Context();
        $analysis = new Analysis([], $context);
        $analysis->openapi = $openapi;

        $valid = $analysis->validate();

        if (!$valid) {
            $this->components->error('OpenAPI validation failed. The document may be incomplete.');
        }

        return $valid;
    }

    private function serialise(OA\OpenApi $openapi): string
    {
        return $this->option('format') === 'json'
            ? $openapi->toJson()
            : $openapi->toYaml();
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

        if (!is_writable(dirname($path))) {
            throw new RuntimeException("Output directory is not writable: {$path}");
        }

        file_put_contents($path, $content);
    }

    // endregion
}
