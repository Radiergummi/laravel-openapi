<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules;

use Radiergummi\OpenApi\Core\Extractors\PayloadParameterScanner;
use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\LintContext;
use Radiergummi\OpenApi\Core\Lint\Rules\Rule;
use Radiergummi\OpenApi\Core\Lint\Rules\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Core\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Plugins\SpatieData\FilePropertyChecker;
use Override;
use ReflectionException;
use Spatie\LaravelData\Data;

use function sprintf;

/**
 * Reports when a controller method accepts a Data object containing an
 * `UploadedFile` property, but the corresponding OpenAPI operation does not
 * declare a `multipart/form-data` request body.
 *
 * File-property detection is delegated to {@see SchemaFromDataClass::hasFileProperties()},
 * which uses the same TypeInfo-based traversal as schema generation and caches results
 * per Data-class FQCN so deep nested checks are not repeated.
 */
final readonly class MultipartFileWithoutMultipart implements Rule, OperationRuleVisitor
{
    public function __construct(
        private FilePropertyChecker $schemaBuilder,
        private PayloadParameterScanner $scanner,
    ) {}

    /**
     * @return iterable<Finding>
     *
     * @throws ReflectionException
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        if ($operation->webhook) {
            return;
        }

        if ($operation->descriptor === null || $operation->descriptor->method === null) {
            return;
        }

        if (!$this->methodAcceptsFileUploadData($operation)) {
            return;
        }

        if ($operation->requestBody?->isMultipart()) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s::%s() accepts an UploadedFile via a Data object, but %s %s does not declare multipart/form-data',
                $operation->descriptor->controller?->getShortName() ?? '(unknown)',
                $operation->descriptor->method->getName(),
                $operation->method,
                $operation->pathUri,
            ),
            fixHint: 'Add a multipart/form-data request body to the operation, or use #[RequestBody] with the correct media type.',
        );
    }

    /**
     * Check whether the method (or a Domain Action it injects) carries a Data
     * subclass with an UploadedFile property.
     *
     * Uses {@see PayloadParameterScanner} so Action-indirected Data classes
     * (controller → CreateXAction → constructor → CreateXData) are reached the
     * same way the schema resolver finds them.
     *
     * @throws ReflectionException
     */
    private function methodAcceptsFileUploadData(OperationNode $operation): bool
    {
        $dataClass = $this->scanner->candidateOfType(
            $operation->descriptor->method,
            Data::class,
        );

        return $dataClass !== null && $this->schemaBuilder->hasFileProperties($dataClass);
    }

    #[Override]
    public function id(): string
    {
        return 'multipart.file-without-multipart';
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }

    #[Override]
    public function description(): string
    {
        return "Data class has a file property but the request body isn't multipart/form-data — produces an incorrect spec.";
    }
}
