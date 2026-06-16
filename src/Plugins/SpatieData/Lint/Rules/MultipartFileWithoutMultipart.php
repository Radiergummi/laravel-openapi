<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SpatieData\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\FilePropertyChecker;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use ReflectionException;
use ReflectionMethod;
use Spatie\LaravelData\Data;

use function sprintf;

/**
 * Fires when a `#[RequestBody]` override forces a non-multipart media type onto an operation
 * whose Data class carries an `UploadedFile` property, contradicting the code. The auto-generated
 * path never trips this rule; it only catches explicit overrides.
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
            severity: $this->severity(),
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
     * Whether the method (or an injected Domain Action) carries a Data subclass with a file property.
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
    public function severity(): Severity
    {
        return Severity::Degraded;
    }

    #[Override]
    public function description(): string
    {
        return 'Data class carries a file property but a #[RequestBody] override forces a non-multipart body — the spec contradicts the code.';
    }
}
