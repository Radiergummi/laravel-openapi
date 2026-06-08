<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\Fix\Fix;
use Radiergummi\OpenApi\Lint\Fix\FixContext;
use Radiergummi\OpenApi\Lint\Fix\Fixer;
use Radiergummi\OpenApi\Lint\Fix\ModifyAttribute;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\OaRedundantWithInference;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;
use ReflectionClass;
use ReflectionException;

use function class_exists;
use function count;
use function is_string;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Removes a class's redundant swagger-php schema annotation, whichever shape it took.
 *
 * For the **attribute** shape it deletes the class-level `#[OA\Schema]` group and every
 * `#[OA\*]` group on the class's properties (and promoted constructor parameters) — an OA group
 * sitting alone on its own line(s) is removed wholesale; one sharing a `#[A, B]` group is excised
 * byte-precisely. For the **docblock** shape it delegates to {@see DocblockAnnotationRemover}.
 *
 * When reflection and the parsed source disagree, or the offending construct can't be located, the
 * fixer yields nothing and the finding is reported as unfixed.
 *
 * @internal
 */
final readonly class RedundantOaAnnotationFixer implements Fixer
{
    public function __construct(
        private DocblockAnnotationRemover $docblockRemover = new DocblockAnnotationRemover(),
    ) {}

    /**
     * @return iterable<Fix>
     */
    public function fix(Finding $finding, FixContext $context): iterable
    {
        $class = $finding->context[Finding::CONTEXT_SOURCE_CLASS] ?? null;
        $shapeValue = $finding->context[OaRedundantWithInference::CONTEXT_SHAPE] ?? null;

        if (!is_string($class) || !is_string($shapeValue)) {
            return [];
        }

        $shape = AuthoredSchemaShape::tryFrom($shapeValue);
        $file = $this->fileFor($class);

        if ($shape === null || $file === null) {
            return [];
        }

        return match ($shape) {
            AuthoredSchemaShape::Docblock => $this->docblockRemover->remove($finding, $class, $file, $context),
            AuthoredSchemaShape::Attribute => $this->removeAttributes($finding, $class, $file, $context),
        };
    }

    /**
     * The file declaring the class, or null when it cannot be reflected.
     *
     * `$class` originates from finding context, so it is untrusted; reflection throwing on a bad
     * name is caught.
     */
    private function fileFor(string $class): ?string
    {
        if (!class_exists($class)) {
            return null;
        }

        try {
            $file = new ReflectionClass($class)->getFileName();
        } catch (ReflectionException) {
            return null;
        }

        return $file ?: null;
    }

    /**
     * @return list<Fix>
     */
    private function removeAttributes(Finding $finding, string $class, string $file, FixContext $context): array
    {
        $classNode = $context->classNode($file, $class);

        if ($classNode === null) {
            return [];
        }

        $source = $context->source($file);
        $fixes = [];

        foreach ($this->attributeGroups($classNode) as $group) {
            foreach ($this->removeOaFromGroup($source, $group) as $operation) {
                $fixes[] = new Fix(
                    file: $file,
                    description: "Remove redundant #[OA\\*] schema attribute on {$class}",
                    ruleId: $finding->ruleId,
                    operation: $operation,
                );
            }
        }

        return $fixes;
    }

    /**
     * Every attribute group declared on the class itself, on its properties, and on its promoted
     * constructor parameters — the places an `#[OA\*]` schema annotation can sit.
     *
     * @return list<AttributeGroup>
     */
    private function attributeGroups(ClassLike $class): array
    {
        $groups = [];

        foreach ($class->attrGroups as $group) {
            $groups[] = $group;
        }

        foreach ($class->getProperties() as $property) {
            foreach ($property->attrGroups as $group) {
                $groups[] = $group;
            }
        }

        $constructor = $this->constructor($class);

        if ($constructor !== null) {
            foreach ($constructor->params as $param) {
                foreach ($param->attrGroups as $group) {
                    $groups[] = $group;
                }
            }
        }

        return $groups;
    }

    private function constructor(ClassLike $class): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toLowerString() === '__construct') {
                return $method;
            }
        }

        return null;
    }

    /**
     * Build the edits that strip the swagger-php attributes from one group. When every attribute in
     * the group is an `#[OA\*]` one and it occupies whole lines, the lines are removed; otherwise
     * each contiguous run of OA attributes is excised byte-precisely, comma and all, leaving the
     * rest of the group. (There is no re-lint pass, so a mixed group with several OA attributes must
     * be cleared in this one go — see {@see \Radiergummi\OpenApi\Lint\Fix\FixRunner}.)
     *
     * @return list<ModifyAttribute|RemoveLines>
     */
    private function removeOaFromGroup(string $source, AttributeGroup $group): array
    {
        $oaIndices = [];

        foreach ($group->attrs as $index => $attr) {
            if ($this->isOaAttribute($attr)) {
                $oaIndices[] = $index;
            }
        }

        if ($oaIndices === []) {
            return [];
        }

        if (count($oaIndices) === count($group->attrs)) {
            $start = $group->getStartFilePos();
            $end = $group->getEndFilePos() + 1;

            if ($this->occupiesWholeLines($source, $start, $end)) {
                return [new RemoveLines($group->getStartLine(), $group->getEndLine())];
            }

            return [new ModifyAttribute($start, $end, '')];
        }

        // Mixed group: excise each maximal run of consecutive OA attributes, comma and all. Runs are
        // separated by surviving (non-OA) attributes, so their spans never overlap. A run with an
        // attribute after it takes the trailing comma (`OA, `); a run at the group's tail takes the
        // leading comma (`, OA`).
        $attrs = $group->attrs;
        $count = count($attrs);
        $total = count($oaIndices);
        $operations = [];
        $cursor = 0;

        while ($cursor < $total) {
            $runStart = $oaIndices[$cursor];
            $runEnd = $runStart;

            while ($cursor + 1 < $total && $oaIndices[$cursor + 1] === $runEnd + 1) {
                $runEnd = $oaIndices[++$cursor];
            }

            $cursor++;

            if ($runEnd < $count - 1) {
                $start = $attrs[$runStart]->getStartFilePos();
                $end = $attrs[$runEnd + 1]->getStartFilePos();
            } else {
                $start = $attrs[$runStart - 1]->getEndFilePos() + 1;
                $end = $attrs[$runEnd]->getEndFilePos() + 1;
            }

            $operations[] = new ModifyAttribute($start, $end, '');
        }

        return $operations;
    }

    private function isOaAttribute(Attribute $attr): bool
    {
        $resolved = $attr->name->getAttribute('resolvedName');
        $name = $resolved instanceof Node\Name ? $resolved->toString() : $attr->name->toString();

        return str_starts_with($name, OaRedundantWithInference::ATTRIBUTE_NAMESPACE);
    }

    /**
     * Whether the byte span `[$start, $end)` has nothing but whitespace on its own lines either
     * side — i.e. deleting those whole lines disturbs no other code.
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
}
