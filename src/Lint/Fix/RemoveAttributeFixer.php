<?php


declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Fix;

use Closure;
use Override;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\Param;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use ReflectionAttribute;
use ReflectionException;
use ReflectionMethod;
use ReflectionProperty;
use Throwable;

use function array_values;
use function assert;
use function class_basename;
use function class_exists;
use function count;
use function is_a;
use function is_int;
use function is_string;
use function sprintf;
use function strcasecmp;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Removes the attribute(s) named in a finding from the member's source file.
 *
 * Deletes either every later duplicate of one discriminator value ({@see RemoveMode::Dedupe}) or
 * all matching attributes ({@see RemoveMode::RemoveAll}). Reflection identifies which attributes
 * to remove; php-parser locates them by aligning reflection attributes 1:1 with AST nodes in
 * declaration order. When reflection and AST disagree or the file won't parse, yields nothing.
 */
final readonly class RemoveAttributeFixer implements Fixer
{
    public const string CONTEXT_DISCRIMINATOR = 'fixDiscriminator';

    /**
     * @param class-string                  $attribute     Attribute class to match (subclasses
     *                                                     included), e.g., `Tag::class`.
     * @param null|Closure(object): ?string $discriminator Extracts the comparable identity of an
     *                                                     attribute instance; required for
     *                                                     {@see RemoveMode::Dedupe}.
     */
    public function __construct(
        private string $attribute,
        private MemberKind $member,
        private RemoveMode $mode,
        private ?Closure $discriminator = null,
    ) {}

    /**
     * Convenience over {@see contextFor()} for operation-level rules.
     *
     * @return array<string, string>
     */
    public static function contextForOperation(?ActionDescriptor $descriptor, ?string $discriminator = null): array
    {
        return self::contextFor(
            $descriptor?->controller?->getName(),
            $descriptor?->method?->getName(),
            $discriminator,
        );
    }

    /**
     * Builds the finding context a {@see FixableRule} must stamp to let this fixer locate the member.
     * Returns an empty array when class or member isn't known.
     *
     * @return array<string, string>
     */
    public static function contextFor(
        ?string $class,
        ?string $member,
        ?string $discriminator = null,
    ): array {
        if ($class === null || $member === null) {
            return [];
        }

        $context = [
            Finding::CONTEXT_SOURCE_CLASS => $class,
            Finding::CONTEXT_SOURCE_MEMBER => $member,
        ];

        if ($discriminator !== null) {
            $context[self::CONTEXT_DISCRIMINATOR] = $discriminator;
        }

        return $context;
    }

    /**
     * @return iterable<Fix>
     */
    #[Override]
    public function fix(Finding $finding, FixContext $context): iterable
    {
        $class = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;
        $member = $finding->context[Finding::CONTEXT_SOURCE_MEMBER] ?? null;

        if (!is_string($class) || !is_string($member)) {
            return [];
        }

        // The file is the one declaring the member, not the finding's location: for field rules the
        // finding points at the controller, while the attribute lives in the payload class.
        [$file, $reflected] = $this->reflectMember($class, $member);

        if ($file === null || $reflected === []) {
            return [];
        }

        $remove = $this->selectIndices($reflected, $finding);

        if ($remove === []) {
            return [];
        }

        $astAttributes = $this->locateAstAttributes(
            $context,
            $file,
            $class,
            $member,
        );

        // A mismatch means reflection and the parsed source disagree about the member's attributes
        // (unparseable file, eval'd class, …). Refuse to guess.
        if (count($astAttributes) !== count($reflected)) {
            return [];
        }

        $source = $context->source($file);
        $fixes = [];

        foreach ($remove as $index) {
            $operation = $this->buildOperation($source, $astAttributes, $index);

            if ($operation === null) {
                continue;
            }

            $fixes[] = new Fix(
                file: $file,
                description: sprintf(
                    'Remove %s #[%s] attribute on %s::%s',
                    $this->mode === RemoveMode::Dedupe ? 'duplicate' : 'no-op',
                    class_basename($reflected[$index]->getName()),
                    $class,
                    $member,
                ),
                ruleId: $finding->ruleId,
                operation: $operation,
            );
        }

        return $fixes;
    }

    /**
     * The declaring file and all attributes (in source order) of the target member.
     * Returns `[null, []]` when the member cannot be reflected or has no source file.
     *
     * @return array{0: ?string, 1: list<ReflectionAttribute<object>>}
     */
    private function reflectMember(string $class, string $member): array
    {
        if (!class_exists($class)) {
            return [null, []];
        }

        try {
            if ($this->member === MemberKind::Method) {
                $reflector = new ReflectionMethod($class, $member);
                $file = $reflector->getFileName();
            } else {
                $reflector = new ReflectionProperty($class, $member);
                $file = $reflector->getDeclaringClass()->getFileName();
            }
        } catch (ReflectionException) {
            return [null, []];
        }

        return [$file ?: null, array_values($reflector->getAttributes())];
    }

    /**
     * The positions (within the full, unfiltered attribute list) of the attributes to remove.
     *
     * @param list<ReflectionAttribute<object>> $reflected
     *
     * @return list<int>
     */
    private function selectIndices(array $reflected, Finding $finding): array
    {
        /** @var list<int> $matching */
        $matching = [];

        foreach ($reflected as $index => $attribute) {
            if (is_a($attribute->getName(), $this->attribute, true)) {
                $matching[] = $index;
            }
        }

        if ($matching === []) {
            return [];
        }

        if ($this->mode === RemoveMode::RemoveAll) {
            return $matching;
        }

        return $this->selectDuplicates($reflected, $matching, $finding);
    }

    /**
     * Keeps the first occurrence of the discriminator value; returns positions of later duplicates.
     * Scoped to the finding's value so unrelated duplicates are left for their own findings.
     *
     * @param list<ReflectionAttribute<object>> $reflected
     * @param list<int>                         $matching
     *
     * @return list<int>
     */
    private function selectDuplicates(array $reflected, array $matching, Finding $finding): array
    {
        if ($this->discriminator === null) {
            return [];
        }

        $target = $finding->context[self::CONTEXT_DISCRIMINATOR] ?? null;
        $target = is_string($target) ? $target : null;

        /** @var array<string, int> $seen */
        $seen = [];
        $remove = [];

        foreach ($matching as $index) {
            try {
                $value = ($this->discriminator)($reflected[$index]->newInstance());
            } catch (Throwable) {
                continue;
            }

            if ($value === null || ($target !== null && $value !== $target)) {
                continue;
            }

            if (isset($seen[$value])) {
                $remove[] = $index;
            } else {
                $seen[$value] = $index;
            }
        }

        return $remove;
    }

    /**
     * The member's attribute nodes in source order, each paired with the group that owns it.
     *
     * @return list<array{attr: Attribute, group: Node\AttributeGroup}>
     */
    private function locateAstAttributes(
        FixContext $context,
        string $file,
        string $class,
        string $member,
    ): array {
        $classNode = new NodeFinder()->findFirst(
            $context->ast($file),
            static fn(Node $node): bool
                => $node instanceof ClassLike
                && $node->namespacedName?->toString() === $class,
        );

        if (!$classNode instanceof ClassLike) {
            return [];
        }

        $memberNode = $this->member === MemberKind::Method
            ? $this->findMethod($classNode, $member)
            : $this->findProperty($classNode, $member);

        if ($memberNode === null) {
            return [];
        }

        $attributes = [];

        foreach ($memberNode->attrGroups as $group) {
            foreach ($group->attrs as $attr) {
                $attributes[] = ['attr' => $attr, 'group' => $group];
            }
        }

        return $attributes;
    }

    private function findMethod(ClassLike $class, string $member): ?ClassMethod
    {
        return array_find($class->getMethods(), fn(ClassMethod $method): bool => strcasecmp($method->name->toString(), $member) === 0);
    }

    /**
     * Locate the node carrying a property's attributes: either a `Property` statement or, for a
     * promoted constructor parameter (the Spatie Data idiom), the matching `Param`.
     */
    private function findProperty(ClassLike $class, string $member): Property|Param|null
    {
        foreach ($class->getProperties() as $property) {
            if (array_any(
                $property->props,
                fn(PropertyItem $prop): bool => $prop->name->toString() === $member,
            )) {
                return $property;
            }
        }

        $constructor = $this->findMethod($class, '__construct');

        if ($constructor === null) {
            return null;
        }

        return array_find(
            $constructor->params,
            fn(Param $param): bool => $param->var instanceof Node\Expr\Variable && $param->var->name === $member,
        );
    }

    /**
     * Builds the source edit for removing the attribute at `$index`.
     * Sole-in-group attributes become {@see RemoveLines}; shared-group attributes become
     * a byte-precise {@see ModifyAttribute} that strips the adjacent comma too.
     *
     * @param list<array{attr: Attribute, group: Node\AttributeGroup}> $attributes
     */
    private function buildOperation(string $source, array $attributes, int $index): ?FixOperation
    {
        $group = $attributes[$index]['group'];

        if (count($group->attrs) === 1) {
            $start = $group->getStartFilePos();
            $end = $group->getEndFilePos() + 1;

            if ($this->occupiesWholeLines($source, $start, $end)) {
                return new RemoveLines($group->getStartLine(), $group->getEndLine());
            }

            return new ModifyAttribute($start, $end, '');
        }

        $node = $attributes[$index]['attr'];
        $position = $this->indexInGroup($group, $node);

        if ($position === null) {
            return null;
        }

        // Remove the attribute plus one adjacent comma so the group stays well-formed.
        if ($position > 0) {
            $start = $group->attrs[$position - 1]->getEndFilePos() + 1;
            $end = $node->getEndFilePos() + 1;
        } else {
            $start = $node->getStartFilePos();
            $end = $group->attrs[$position + 1]->getStartFilePos();
        }

        return new ModifyAttribute($start, $end, '');
    }

    /**
     * Whether the byte span `[$start, $end)` occupies its own lines entirely (safe to delete whole lines).
     */
    private function occupiesWholeLines(string $source, int $start, int $end): bool
    {
        $lineStart = strrpos(substr($source, 0, $start), PHP_EOL);
        $lineStart = $lineStart === false ? 0 : $lineStart + strlen(PHP_EOL);

        $newline = strpos($source, PHP_EOL, $end);
        $lineEnd = $newline === false ? strlen($source) : $newline;

        return trim(substr($source, $lineStart, $start - $lineStart)) === ''
            && trim(substr($source, $end, $lineEnd - $end)) === '';
    }

    private function indexInGroup(Node\AttributeGroup $group, Attribute $node): ?int
    {
        $key = array_find_key($group->attrs, fn(Attribute $attr): bool => $attr === $node);
        assert(is_int($key) || $key === null);

        return $key;
    }
}
