<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint\Tree;

use OpenApi\Annotations as OA;
use OpenApi\Generator;

use function is_array;
use function is_string;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;

/**
 * Stateless converters from swagger-php annotation objects to leaf domain-tree nodes. Split out of
 * {@see SpecTreeBuilder} so the builder keeps only the stateful, component-index-aware traversal.
 * Companion to the equally stateless {@see SchemaAccessor}.
 */
final class NodeFactory
{
    /** @return list<ExampleNode> */
    public static function examplesFromParameter(OA\Parameter $parameter): array
    {
        // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
        return self::examplesFromList($parameter->examples ?? Generator::UNDEFINED);
    }

    /**
     * @return list<ExampleNode>
     */
    private static function examplesFromList(mixed $examples): array
    {
        if (!is_array($examples) || is_undefined($examples)) {
            return [];
        }

        $result = [];

        foreach ($examples as $example) {
            if (is_undefined($example)) {
                continue;
            }

            if ($example instanceof OA\Examples) {
                $result[] = self::exampleNode($example);
            }
        }

        return $result;
    }

    public static function exampleNode(OA\Examples $example): ExampleNode
    {
        return new ExampleNode(
            name: is_defined($example->example)
                ? $example->example
                : null,
            value: is_defined($example->value)
                ? $example->value
                : null,
            summary: SchemaAccessor::undefinedToNull($example->summary),
            description: SchemaAccessor::undefinedToNull($example->description),
            raw: $example,
        );
    }

    /** @return list<ExampleNode> */
    public static function examplesFromSchema(OA\Schema $schema): array
    {
        // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
        return self::examplesFromList($schema->examples ?? Generator::UNDEFINED);
    }

    public static function header(OA\Header $header): HeaderNode
    {
        // $ref'd headers are not dereferenced; the generator never emits them today (headers are
        // always inlined on responses). Add dereference here if that changes.
        return new HeaderNode(
            name: is_defined($header->header)
                ? $header->header
                : '(unknown)',
            // @phpstan-ignore nullCoalesce.property (swagger-php may leave property unset at runtime)
            schema: SchemaAccessor::extractSchemaType($header->schema ?? null),
            description: SchemaAccessor::undefinedToNull($header->description),
            required: is_defined($header->required)
            && $header->required === true,
            raw: $header,
        );
    }

    public static function link(OA\Link $link): LinkNode
    {
        $parameters = [];
        $oaParams = $link->parameters;

        if (is_array($oaParams) && is_defined($oaParams)) {
            foreach ($oaParams as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $parameters[$key] = $value;
                }
            }
        }

        return new LinkNode(
            name: is_defined($link->link)
                ? $link->link
                : '(unnamed)',
            operationId: SchemaAccessor::undefinedToNull($link->operationId),
            operationRef: SchemaAccessor::undefinedToNull($link->operationRef),
            parameters: $parameters,
            description: SchemaAccessor::undefinedToNull($link->description),
            raw: $link,
        );
    }
}
