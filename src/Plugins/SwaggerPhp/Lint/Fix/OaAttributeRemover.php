<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use Radiergummi\OpenApi\Lint\Fix\ModifyAttribute;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredSchemaShape;

use function count;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Builds the edits that strip swagger-php `#[OA\*]` attributes from a single attribute group,
 * wherever it sits — on a class, a property, a promoted constructor parameter, or a controller
 * method. Shared by the schema-level and operation-level removal fixers.
 *
 * When every attribute in the group is an `#[OA\*]` one and it occupies whole lines, the lines are
 * removed; otherwise each contiguous run of OA attributes is excised byte-precisely, comma and all,
 * leaving the rest of the group. (There is no re-lint pass, so a mixed group with several OA
 * attributes must be cleared in this one go — see {@see \Radiergummi\OpenApi\Lint\Fix\FixRunner}.)
 *
 * @internal
 */
final readonly class OaAttributeRemover
{
    /**
     * @return list<ModifyAttribute|RemoveLines>
     */
    public function fixesForGroup(string $source, AttributeGroup $group): array
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

        return str_starts_with($name, AuthoredSchemaShape::ATTRIBUTE_NAMESPACE);
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
