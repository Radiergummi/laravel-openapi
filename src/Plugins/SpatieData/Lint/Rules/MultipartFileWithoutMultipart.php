<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\FilePropertyChecker;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\SchemaFromDataClass;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use ReflectionException;
use ReflectionMethod;
use Spatie\LaravelData\Data;

use function sprintf;

/**
 * Contradiction guard: the generator already emits `multipart/form-data` for any Data class that
 * carries an `UploadedFile` property, so the auto-generated path can never trip this rule. It fires
 * only when a `#[RequestBody]` override forces a non-multipart media type onto an operation whose
 * Data object still carries a file — leaving a `format: binary` field under, say, `application/json`,
 * a spec that contradicts the code.
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

        if (!$this->methodAcceptsFileUploadData($operation->descriptor->method)) {
            return;
        }

        if ($operation->requestBody?->isMultipart) {
            return;
        }

        yield new Finding(
            ruleId: $this->id(),
            level: $this->level(),
            message: sprintf(
                '%s::%s() accepts an UploadedFile via a Data object, but %s %s overrides the body to a non-multipart media type — the file field will not transfer',
                $operation->descriptor->controller?->getShortName() ?? '(unknown)',
                $operation->descriptor->method->getName(),
                $operation->method->forDisplay(),
                $operation->pathUri,
            ),
            fixHint: 'Remove the non-multipart #[RequestBody] media-type override, or stop passing the UploadedFile through this Data object.',
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
    private function methodAcceptsFileUploadData(ReflectionMethod $method): bool
    {
        $dataClass = $this->scanner->candidateOfType(
            $method,
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
        return 'Data class carries a file property but a #[RequestBody] override forces a non-multipart body — the spec contradicts the code.';
    }
}
