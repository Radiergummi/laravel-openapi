<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\Header;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use ReflectionAttribute;

use function array_merge;
use function preg_match;
use function sprintf;

/**
 * Reports header names declared via #[Header] attributes that are not valid HTTP tokens per RFC
 * 7230 §3.2.6.
 */
final class HeaderInvalidName implements Rule, OperationRuleVisitor
{
    public string $id = 'header.invalid-name';
    public Severity $severity = Severity::Degraded;
    public string $description = 'Header name contains invalid characters.';

    /** HTTP token grammar from RFC 7230 §3.2.6. */
    private const string HTTP_TOKEN_PATTERN = '/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/';

    /**
     * @return iterable<Finding>
     */
    #[Override]
    public function checkOperation(OperationNode $operation, LintContext $context): iterable
    {
        $descriptor = $operation->descriptor;

        if ($descriptor?->method === null) {
            return;
        }

        $methodAttributes = $descriptor->method->getAttributes(Header::class);
        $classAttributes = $descriptor->controller?->getAttributes(Header::class) ?? [];

        /** @var ReflectionAttribute<Header>[] $attributes */
        $attributes = array_merge($classAttributes, $methodAttributes);

        if ($attributes === []) {
            return;
        }

        foreach ($attributes as $attribute) {
            $header = $attribute->newInstance();

            if (preg_match(self::HTTP_TOKEN_PATTERN, $header->name) === 1) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id,
                severity: $this->severity,
                message: sprintf(
                    'Header name "%s" on %s::%s() is not a valid HTTP token',
                    $header->name,
                    $descriptor->controller?->getName() ?? '(unknown)',
                    $descriptor->method->getName(),
                ),
                fixHint: 'Use a valid HTTP token for the header name (alphanumerics and !#$%&\'*+-.^_`|~ only).',
            );
        }
    }



}
