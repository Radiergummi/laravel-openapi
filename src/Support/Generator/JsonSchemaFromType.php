<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Routing\UrlRoutable;
use OpenApi\Annotations as OA;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\UuidInterface;
use ReflectionEnumBackedCase;
use Symfony\Component\TypeInfo\Type;
use Symfony\Component\TypeInfo\Type\BackedEnumType;
use Symfony\Component\TypeInfo\Type\BuiltinType;
use Symfony\Component\TypeInfo\Type\EnumType;
use Symfony\Component\TypeInfo\Type\NullableType;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\Type\UnionType;
use Symfony\Component\TypeInfo\TypeIdentifier;

use function array_filter;
use function array_map;
use function explode;
use function implode;
use function is_a;
use function is_int;
use function ltrim;
use function preg_replace;
use function Radiergummi\OpenApi\class_resource_name;
use function sprintf;
use function str_starts_with;
use function trim;

use const PHP_EOL;

/**
 * Converts a symfony/type-info {@see Type} tree into a swagger-php {@see OA\Schema}.
 *
 * Dispatch order matters because of the symfony/type-info inheritance hierarchy:
 *   - NullableType extends UnionType  → check NullableType BEFORE UnionType
 *   - BackedEnumType extends EnumType extends ObjectType → check BackedEnumType BEFORE ObjectType
 */
#[Scoped]
final readonly class JsonSchemaFromType
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    public function fromType(Type $type): OA\Schema
    {
        // NullableType MUST come before UnionType (it extends it).
        if ($type instanceof NullableType) {
            return NullableSchema::wrap($this->fromType($type->getWrappedType()));
        }

        if ($type instanceof UnionType) {
            return new OA\Schema([
                'oneOf' => array_map(
                    fn(Type $member): OA\Schema => $this->fromType($member),
                    $type->getTypes(),
                ),
            ]);
        }

        if ($type instanceof BackedEnumType) {
            /** @var class-string<BackedEnum> $className */
            $className = $type->getClassName();

            return $this->fromBackedEnumClass($className);
        }

        if ($type instanceof EnumType) {
            /** @noinspection PhpPipeOperatorCanBeUsedInspection */
            return new OA\Schema([
                'type' => 'string',
                'description' => sprintf(
                    'Values of unit enum %s are not representable as a JSON primitive.',
                    class_resource_name($type->getClassName()),
                ),
            ]);
        }

        if ($type instanceof ObjectType) {
            return $this->fromObjectType($type);
        }

        if ($type instanceof BuiltinType) {
            return $this->fromBuiltinType($type);
        }

        // Fallback for any exotic Type subclass not handled above.
        $this->logger->warning(sprintf('Unmapped Type subclass: %s', $type::class));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped type: %s', $type::class),
        ]);
    }

    /**
     * Builds an inline enum schema from a backed-enum class-string.
     *
     * Determines integer-vs-string backing via reflection so the caller does not need a
     * symfony/type-info {@see BackedEnumType} in hand — useful when the enum class name
     * comes from a cast string rather than a resolved type tree.
     *
     * @param class-string<BackedEnum> $enumClass
     */
    public function fromBackedEnumClass(string $enumClass): OA\Schema
    {
        $cases = $enumClass::cases();
        $isInt = $cases !== [] && is_int($cases[0]->value);

        $props = [
            'type' => $isInt ? 'integer' : 'string',
            'enum' => array_map(
                static fn(BackedEnum $case): int|string
                    => $isInt
                    ? (int) $case->value
                    : (string) $case->value,
                $enumClass::cases(),
            ),
        ];

        $caseDescription = $this->enumCaseDescription($enumClass);

        if ($caseDescription !== null) {
            $props['description'] = $caseDescription;
        }

        return new OA\Schema($props);
    }

    /**
     * Reads per-case PHPDoc from a BackedEnum and returns a Markdown description listing each case
     * with its doc comment summary, or null when no case carries any documentation.
     *
     * Format: "- `value`: Summary line\n- `value2`: Summary line 2"
     *
     * @param class-string<BackedEnum> $enumClass
     */
    private function enumCaseDescription(string $enumClass): ?string
    {
        $lines = [];

        foreach ($enumClass::cases() as $case) {
            $constant = new ReflectionEnumBackedCase($enumClass, $case->name);
            $doc = $constant->getDocComment();

            if ($doc === false) {
                continue;
            }

            // Strip /** … */ scaffolding and leading * from each line, then join non-empty non-tag
            // lines into a single summary sentence.
            $raw = preg_replace('/^\s*\/\*\*|\*\/\s*$/', '', $doc) ?? $doc;
            $stripped = array_map(
                static fn(string $line): string => trim(ltrim($line, " \t*")),
                explode(PHP_EOL, $raw),
            );
            $summary = implode(
                ' ',
                array_filter(
                    $stripped,
                    static fn(string $line): bool => $line !== '' && !str_starts_with($line, '@'),
                ),
            );

            if ($summary === '') {
                continue;
            }

            $lines[] = sprintf('- `%s`: %s', $case->value, $summary);
        }

        return $lines !== [] ? implode(PHP_EOL, $lines) : null;
    }

    /** @param ObjectType<class-string> $type */
    private function fromObjectType(ObjectType $type): OA\Schema
    {
        $className = $type->getClassName();

        // DateTimeInterface covers DateTime, DateTimeImmutable, Carbon, and CarbonImmutable,
        // as well as properties typed against the interface itself.
        if (is_a($className, DateTimeInterface::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string', 'format' => 'date-time']);
        }

        if (is_a($className, UuidInterface::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string', 'format' => 'uuid']);
        }

        // The binding key is documented in the parameter description, not the schema.
        if (is_a($className, UrlRoutable::class, allow_string: true)) {
            return new OA\Schema(['type' => 'string']);
        }

        $this->logger->warning(sprintf('Unmapped object type: %s', $className));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped object type: %s', class_resource_name($className)),
        ]);
    }

    /** @param BuiltinType<TypeIdentifier> $type */
    private function fromBuiltinType(BuiltinType $type): OA\Schema
    {
        return match ($type->getTypeIdentifier()) {
            TypeIdentifier::STRING => new OA\Schema(['type' => 'string']),
            TypeIdentifier::INT => new OA\Schema(['type' => 'integer']),
            TypeIdentifier::FLOAT => new OA\Schema(['type' => 'number']),
            TypeIdentifier::BOOL => new OA\Schema(['type' => 'boolean']),
            TypeIdentifier::ARRAY => new OA\Schema(['type' => 'array', 'items' => new OA\Items([])]),
            default => $this->unmappedBuiltin($type),
        };
    }

    /** @param BuiltinType<TypeIdentifier> $type */
    private function unmappedBuiltin(BuiltinType $type): OA\Schema
    {
        $name = $type->getTypeIdentifier()->value;
        $this->logger->warning(sprintf('Unmapped builtin type: %s', $name));

        return new OA\Schema([
            'type' => 'string',
            'description' => sprintf('Unmapped builtin type: %s', $name),
        ]);
    }
}
