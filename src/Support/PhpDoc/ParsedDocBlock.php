<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\PhpDoc;

use PHPStan\PhpDocParser\Ast\PhpDoc\DeprecatedTagValueNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocNode;
use PHPStan\PhpDocParser\Ast\PhpDoc\PhpDocTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;

/**
 * A parsed PHPDoc comment, exposing typed accessors for the tags the library reads.
 * Use {@see tagValues()} for tags not covered by the typed accessors.
 *
 * @internal
 */
final readonly class ParsedDocBlock
{
    public function __construct(private PhpDocNode $node) {}

    public static function empty(): self
    {
        return new self(new PhpDocNode([]));
    }

    /**
     * The type node of the first `return` tag, or null when there is none.
     */
    public function returnType(): ?TypeNode
    {
        foreach ($this->node->getReturnTagValues() as $tag) {
            return $tag->type;
        }

        return null;
    }

    /**
     * The type node of the first `@var` tag, or null when there is none.
     */
    public function varType(): ?TypeNode
    {
        foreach ($this->node->getVarTagValues() as $tag) {
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
     * Each `@param` tag's description text, keyed by bare parameter name (no leading `$`).
     *
     * Trimmed; entries without a description are skipped. The `@param` *type* is not exposed:
     * parameter types already come from the signature, `where` constraints, or route-model binding.
     *
     * @return array<string, string>
     */
    public function paramDescriptions(): array
    {
        $descriptions = [];

        foreach ($this->node->getParamTagValues() as $tag) {
            $name = ltrim($tag->parameterName, '$');
            $description = trim($tag->description);

            if ($name === '' || $description === '') {
                continue;
            }

            $descriptions[$name] = $description;
        }

        return $descriptions;
    }

    /**
     * Reason from a `@deprecated` tag, `''` for a bare tag, or null when absent.
     */
    public function deprecation(): ?string
    {
        foreach ($this->node->getTagsByName('@deprecated') as $tag) {
            $value = $tag->value;

            return $value instanceof DeprecatedTagValueNode ? $value->description : '';
        }

        return null;
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
