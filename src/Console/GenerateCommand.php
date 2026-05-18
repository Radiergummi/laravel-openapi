<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Console;

use Radiergummi\OpenApi\Core\Generator\OpenApiGenerator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use OpenApi\Analysis;
use OpenApi\Annotations as OA;
use OpenApi\Context;
use ReflectionException;
use RuntimeException;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\TypeInfo\Exception\UnsupportedException;
use Throwable;

use function assert;
use function config;
use function dirname;
use function file_put_contents;
use function is_string;
use function is_writable;
use function realpath;

/**
 * OpenAPI Generate Command
 *
 * @bundle Radiergummi\OpenApi\Console
 */
#[Description('Generate an OpenAPI 3.1 document from the application\'s route definitions')]
class GenerateCommand extends Command
{
    public const string ARGUMENT_PATH = 'path';

    public const string OPTION_FORMAT = 'format';

    protected $name = 'openapi:generate';

    public function __construct(
        private readonly OpenApiGenerator $generator,
    ) {
        parent::__construct();
    }

    /**
     * @throws ReflectionException
     * @throws UnsupportedException
     */
    public function handle(): int
    {
        $openapi = $this->generator->generate();

        if (!$this->validate($openapi)) {
            return self::FAILURE;
        }

        $content = $this->serialise($openapi);

        try {
            file_put_contents($this->resolvePath(), $content);
        } catch (Throwable $exception) {
            $this->components->error("Failed to write OpenAPI file: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $path = $this->argument(self::ARGUMENT_PATH);

        if ($path !== '-') {
            $this->components->info("OpenAPI document written to {$path}");
        }

        return self::SUCCESS;
    }

    protected function configure(): void
    {
        $this->addArgument(
            self::ARGUMENT_PATH,
            InputArgument::OPTIONAL,
            'Output path. Pass "-" to print to stdout.',
            (string) config('openapi.output_path'),
        );

        $this->addOption(
            self::OPTION_FORMAT,
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: yaml or json.',
            'yaml',
        );
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

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

        if (!$valid) {
            $this->components->error('OpenAPI validation failed. The document may be incomplete.');
        }

        return $valid;
    }

    /**
     * Serialises the document to YAML or JSON depending on --format.
     *
     * @throws InvalidArgumentException
     */
    private function serialise(OA\OpenApi $openapi): string
    {
        $format = $this->option(self::OPTION_FORMAT);
        assert(is_string($format));

        return match ($format) {
            'json'  => $openapi->toJson(),
            default => $openapi->toYaml(),
        };
    }

    /**
     * @throws InvalidArgumentException
     * @throws RuntimeException
     */
    private function resolvePath(): string
    {
        $path = $this->argument(self::ARGUMENT_PATH);
        assert(is_string($path));

        if ($path === '-') {
            return 'php://stdout';
        }

        if (realpath(dirname($path)) === false) {
            throw new RuntimeException("Output directory does not exist: {$path}");
        }

        if (!is_writable(dirname($path))) {
            throw new RuntimeException("Output directory is not writable: {$path}");
        }

        return $path;
    }
}
