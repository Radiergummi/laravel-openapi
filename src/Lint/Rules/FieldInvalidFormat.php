<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Rules;

use Override;
use Radiergummi\OpenApi\Attributes\FieldAttribute;
use Radiergummi\OpenApi\Contracts\Lint\Severity;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use ReflectionProperty;

use function implode;
use function in_array;
use function sprintf;

final class FieldInvalidFormat extends AbstractFieldRule
{
    public string $id = 'field.invalid-format';
    public Severity $severity = Severity::Inconsistent;
    public string $description = 'format value is not a recognised OAS 3.1 format (custom formats are advisory but non-standard).';

    /** @var list<string> */
    private const array VALID_FORMATS = [
        'date-time',
        'date',
        'time',
        'email',
        'uri',
        'uuid',
        'hostname',
        'ipv4',
        'ipv6',
        'byte',
        'binary',
        'password',
        'int32',
        'int64',
        'float',
        'double',
        'duration',
        'idn-email',
        'idn-hostname',
        'iri',
        'iri-reference',
        'json-pointer',
        'regex',
        'relative-json-pointer',
        'uri-reference',
        'uri-template',
    ];


    /**
     * @return iterable<Finding>
     */
    #[Override]
    protected function inspectField(
        FieldAttribute $field,
        ReflectionProperty $property,
        OperationNode $operation,
    ): iterable {
        if ($field->format === null) {
            return;
        }

        if (in_array($field->format, self::VALID_FORMATS, true)) {
            return;
        }

        yield new Finding(
            ruleId: $this->id,
            severity: $this->severity,
            message: sprintf(
                'Property %s::$%s uses non-standard format "%s" in #[%s]; consider using a registered OAS 3.1 format',
                $property->getDeclaringClass()->getName(),
                $property->getName(),
                $field->format,
                $this->attributeName($field),
            ),
            fixHint: sprintf(
                'OAS 3.1 treats unknown formats as advisory. For project consistency, prefer one of the registered formats: %s.',
                implode(', ', self::VALID_FORMATS),
            ),
        );
    }


}
