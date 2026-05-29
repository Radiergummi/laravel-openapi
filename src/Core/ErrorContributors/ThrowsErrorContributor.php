<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\ErrorContributors;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use Override;
use Radiergummi\OpenApi\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\FindingLocation;
use Radiergummi\OpenApi\Lint\FindingsCollector;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionException;
use Throwable;

use function base_path;
use function class_basename;
use function class_exists;
use function interface_exists;
use function is_a;
use function realpath;
use function sprintf;
use function str_starts_with;
use function strtoupper;

/**
 * Infers error responses from `@throws` annotations on the action.
 *
 * Each `@throws` FQCN is checked for a {@see ExceptionResponse} attribute; otherwise it falls
 * back to `config('openapi.exception_responses')`. Unmapped exceptions emit a `throws.unmapped`
 * finding.
 */
#[Scoped]
final readonly class ThrowsErrorContributor implements ErrorResponseContributor
{
    /**
     * @param array<string, array{status: int, description: string}> $exceptionMap
     */
    public function __construct(
        private FindingsCollector $findings,
        #[Config('openapi.exception_responses', default: [])]
        private array $exceptionMap = [],
    ) {}

    /**
     * @return list<ErrorDescriptor>
     */
    #[Override]
    public function contribute(ActionDescriptor $descriptor): array
    {
        // region @throws walk
        $descriptors = [];

        foreach ($descriptor->throws as $throw) {
            $entry = $this->resolveFromAttribute($throw)
                ?? $this->matchException($throw, $this->exceptionMap);

            if ($entry === null) {
                $this->emitUnmapped($descriptor, $throw);

                continue;
            }

            $exceptionClass = (class_exists($throw) || interface_exists($throw))
            && is_a($throw, Throwable::class, true)
                ? $throw
                : null;

            $descriptors[] = new ErrorDescriptor(
                status: (int) $entry['status'],
                exceptionClass: $exceptionClass,
                description: (string) $entry['description'],
                action: $descriptor,
            );
        }

        return $descriptors;
        // endregion
    }

    /**
     * Returns null when the class cannot be loaded or carries no {@see ExceptionResponse} attribute.
     *
     * `IS_INSTANCEOF` matches user-defined subclasses of {@see ExceptionResponse}.
     *
     * @return null|array{status: int, description: string}
     */
    private function resolveFromAttribute(string $fqcn): ?array
    {
        if (!class_exists($fqcn)) {
            return null;
        }

        $attrs = new ReflectionClass($fqcn)->getAttributes(
            ExceptionResponse::class,
            ReflectionAttribute::IS_INSTANCEOF,
        );

        if ($attrs === []) {
            return null;
        }

        $attribute = $attrs[0]->newInstance();

        return ['status' => $attribute->status, 'description' => $attribute->description];
    }

    /**
     * @param array<string, array{status: int, description: string}> $map
     *
     * @return null|array{status: int, description: string}
     */
    private function matchException(string $name, array $map): ?array
    {
        if (isset($map[$name])) {
            return $map[$name];
        }

        $basename = class_basename($name);

        return $map[$basename] ?? null;
    }

    private function emitUnmapped(ActionDescriptor $descriptor, string $throw): void
    {
        $this->findings->emit(
            new Finding(
                ruleId: 'throws.unmapped',
                level: 2,
                message: sprintf(
                    'Exception %s thrown from %s %s has no mapping',
                    $throw,
                    strtoupper($descriptor->route->methods()[0] ?? 'GET'),
                    $descriptor->route->uri(),
                ),
                location: new FindingLocation(
                    file: $descriptor->method?->getFileName() ?: null,
                    line: $descriptor->method?->getStartLine() ?: null,
                    routeName: $descriptor->route->getName(),
                    routeMethod: strtoupper($descriptor->route->methods()[0] ?? 'GET'),
                    routeUri: $descriptor->route->uri(),
                ),
                fixHint: $this->buildThrowsUnmappedHint($throw),
                context: ['exception' => $throw],
            ),
        );
    }

    /**
     * Build a context-aware fix hint for the throws.unmapped finding.
     *
     * For vendor/built-in exceptions (where we can't add attributes), the hint
     * directs users to the config map only. For app exceptions, it suggests
     * either approach.
     */
    private function buildThrowsUnmappedHint(string $exception): string
    {
        $basename = class_basename($exception);

        if (!class_exists($exception) && !interface_exists($exception)) {
            return sprintf(
                'Register "%s" in config/openapi.php (exception_responses map). '
                . 'The class could not be autoloaded — verify the @throws FQCN is correct.',
                $basename,
            );
        }

        if ($this->isVendorOrBuiltin($exception)) {
            return sprintf(
                'Register "%s" in config/openapi.php (exception_responses map), '
                . "e.g.: %s => ['status' => 500, 'description' => '...'].",
                $basename,
                "\\{$exception}::class",
            );
        }

        return sprintf(
            'Add #[ExceptionResponse(status: ..., description: ...)] to %s, '
            . 'or register it in config/openapi.php (exception_responses map).',
            $basename,
        );
    }

    /**
     * Determine whether an exception class is a vendor or PHP built-in class
     * (i.e., not part of the application source).
     */
    private function isVendorOrBuiltin(string $fqcn): bool
    {
        try {
            if (!class_exists($fqcn)) {
                return true;
            }

            $file = new ReflectionClass($fqcn)->getFileName();
        } catch (ReflectionException) {
            return true;
        }

        // Built-in classes (e.g., RuntimeException) have no file.
        if ($file === false) {
            return true;
        }

        $vendorDir = realpath(base_path('vendor'));

        return $vendorDir !== false && str_starts_with(
            realpath($file) ?: $file,
            $vendorDir,
        );
    }
}
