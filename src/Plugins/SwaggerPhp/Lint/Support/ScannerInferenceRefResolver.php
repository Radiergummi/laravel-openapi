<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support;

use OpenApi\Annotations as OA;
use Override;
use Radiergummi\OpenApi\Lint\InferenceView;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;

/**
 * Resolves `$ref` names against the two sides the redundancy oracle compares: the authored side via
 * the harvested {@see AuthoredAnnotationScanner}, the inferred side via the per-run
 * {@see InferenceView}. Built at lint time, since the inference view is per spec run.
 *
 * @internal
 */
final readonly class ScannerInferenceRefResolver implements SchemaRefResolver
{
    public function __construct(
        private AuthoredAnnotationScanner $scanner,
        private InferenceView $inference,
    ) {}

    #[Override]
    public function resolveAuthored(string $name): ?OA\Schema
    {
        return $this->scanner->schemaForName($name);
    }

    #[Override]
    public function resolveInferred(string $name): ?OA\Schema
    {
        return $this->inference->schemaForName($name);
    }
}
