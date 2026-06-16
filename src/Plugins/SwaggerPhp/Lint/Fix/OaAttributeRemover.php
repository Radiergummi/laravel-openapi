<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Fix;

use PhpParser\Node;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use Radiergummi\OpenApi\Lint\Fix\ModifyAttribute;
use Radiergummi\OpenApi\Lint\Fix\RemoveLines;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Lint\Support\AuthoredAnnotationShape;

use function count;
use function str_starts_with;
use function strlen;
use function strpos;
use function strrpos;
use function substr;
use function trim;

use const PHP_EOL;

/**
 * Builds edits that strip `#[OA\*]` attributes from a single attribute group.
 *
 * If every attribute in the group is an OA attribute and it occupies whole lines, those lines are
 * removed; otherwise each contiguous OA run is excised byte-precisely (comma and all).
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

        // Mixed group: excise each consecutive OA run plus its comma (trailing or leading).
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

        return str_starts_with($name, AuthoredAnnotationShape::ATTRIBUTE_NAMESPACE);
    }

    /** Whether `[$start, $end)` is the only non-whitespace content on its lines. */
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
