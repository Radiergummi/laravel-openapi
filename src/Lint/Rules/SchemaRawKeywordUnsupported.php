<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\RawSchema;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use ReflectionClass;
use ReflectionNamedType;

use function class_exists;
use function implode;
use function sprintf;

/**
 * Flags `#[RawSchema]` definitions that contain keywords swagger-php cannot serialise
 * ({@see ExplicitClassSchema::ACCEPTED_KEYWORDS}); without this rule they are silently dropped.
 */
final class SchemaRawKeywordUnsupported implements OperationRuleVisitor, Rule
{
    public const string ID = 'schema.raw-keyword-unsupported';

    /** @var array<class-string, true> */
    private array $seen = [];

    public function __construct(
        private readonly PayloadParameterScanner $scanner,
    ) {}

    #[Override]
    public function id(): string
    {
        return self::ID;
    }

    #[Override]
    public function description(): string
    {
        return 'A #[RawSchema] uses a keyword swagger-php cannot serialise '
            . '(if/then/else, dependentRequired/dependentSchemas, dependencies).';
    }

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $method = $operation->descriptor?->method;

        if ($method === null) {
            return;
        }

        $candidates = $this->scanner->candidates($method);

        $returnType = $method->getReturnType();

        if ($returnType instanceof ReflectionNamedType && !$returnType->isBuiltin()) {
            /** @var class-string $returnClass */
            $returnClass = $returnType->getName();
            $candidates[] = $returnClass;
        }

        foreach ($candidates as $className) {
            yield from $this->checkClass($className);
        }
    }

    /**
     * @param class-string $className
     *
     * @return iterable<Finding>
     */
    private function checkClass(string $className): iterable
    {
        if (isset($this->seen[$className]) || !class_exists($className)) {
            return;
        }

        $this->seen[$className] = true;

        $reflection = new ReflectionClass($className);
        $attributes = $reflection->getAttributes(RawSchema::class);

        if ($attributes === []) {
            return;
        }

        $unsupported = ExplicitClassSchema::unsupportedKeywords($attributes[0]->newInstance()->schema);

        if ($unsupported === []) {
            return;
        }

        yield new Finding(
            ruleId: self::ID,
            level: $this->level(),
            message: sprintf(
                '#[RawSchema] on %s uses unsupported keyword(s): %s.',
                $className,
                implode(', ', $unsupported),
            ),
            fixHint: 'swagger-php cannot serialise these keywords; remove them or express the '
            . 'shape with a supported keyword (see docs/attributes.md). Arbitrary keywords '
            . 'await the IR (#189).',
            context: [Finding::CONTEXT_SOURCE_CLASS => $className],
        );
    }

    #[Override]
    public function level(): int
    {
        return 1;
    }
}
