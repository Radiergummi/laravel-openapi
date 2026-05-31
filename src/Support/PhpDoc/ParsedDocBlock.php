<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\PhpDoc;

use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * A parsed PHPDoc comment, exposing the tag/type nodes the library reads.
 *
 * Wraps a phpstan/phpdoc-parser {@see PhpDocNode}. Typed accessors cover the tags
 * read today; {@see tagValues()} exposes raw value nodes so future complex-shape
 * tag readers build on the same parse rather than re-tokenising.
 */
final readonly class ParsedDocBlock
{
    public function __construct(private PhpDocNode $node) {}

    public static function empty(): self
    {
        return new self(new PhpDocNode([]));
    }

    /**
     * The type node of the first `@return` tag, or null when there is none.
     */
    public function returnType(): ?TypeNode
    {
        foreach ($this->node->getReturnTagValues() as $tag) {
            return $tag->type;
        }

        return null;
    }

    /**
     * The type node of each `@throws` tag, in source order.
     *
     * @return list<TypeNode>
     */
    public function throwsTypes(): array
    {
        $types = [];

        foreach ($this->node->getThrowsTagValues() as $tag) {
            $types[] = $tag->type;
        }

        return $types;
    }

    /**
     * Raw value nodes of every tag with the given (at-prefixed) name.
     *
     * @return list<PhpDocTagValueNode>
     */
    public function tagValues(string $name): array
    {
        $values = [];

        foreach ($this->node->getTagsByName($name) as $tag) {
            $values[] = $tag->value;
        }

        return $values;
    }
}
