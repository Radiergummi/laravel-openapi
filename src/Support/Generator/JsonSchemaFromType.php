<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Generator;

use BackedEnum;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTime;
use DateTimeImmutable;
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
use function ltrim;
use function preg_replace;
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
            $isInt = $type->getBackingType()->getTypeIdentifier() === TypeIdentifier::INT;

            $props = [
                'type' => $isInt ? 'integer' : 'string',
                'enum' => array_map(
                    static fn(BackedEnum $case): int|string
                        => $isInt
                        ? (int) $case->value
                        : (string) $case->value,
                    $className::cases(),
                ),
            ];

            $caseDescription = $this->enumCaseDescription($className);

            if ($caseDescription !== null) {
                $props['description'] = $caseDescription;
            }

            return new OA\Schema($props);
        }

        if ($type instanceof EnumType) {
            /** @noinspection PhpPipeOperatorCanBeUsedInspection */
            return new OA\Schema([
                'type' => 'string',
                'description' => sprintf(
                    'Values of unit enum %s are not representable as a JSON primitive.',
                    class_basename($type->getClassName()),
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

        if (is_a($className, DateTime::class, allow_string: true)
            || is_a($className, DateTimeImmutable::class, allow_string: true)
            || is_a($className, Carbon::class, allow_string: true)
            || is_a($className, CarbonImmutable::class, allow_string: true)
        ) {
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
            'description' => sprintf('Unmapped object type: %s', $className),
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
