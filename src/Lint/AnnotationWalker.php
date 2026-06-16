<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Enums\ComponentType;

use function is_array;
use function property_exists;
use function Radiergummi\OpenApi\is_defined;
use function Radiergummi\OpenApi\is_undefined;
use function spl_object_id;

/**
 * Recursively walks an OpenAPI annotation tree, invoking a visitor callback on
 * each unique annotation node. Handles circular references via object identity tracking.
 */
final class AnnotationWalker
{
    /**
     * @param callable(OA\AbstractAnnotation): void $visitor
     */
    public static function walk(OA\AbstractAnnotation $root, callable $visitor): void
    {
        $visited = [];
        self::doWalk($root, $visitor, $visited);
    }

    /**
     * @param callable(OA\AbstractAnnotation): void $visitor
     * @param array<int, true>                      $visited
     */
    private static function doWalk(
        OA\AbstractAnnotation $annotation,
        callable $visitor,
        array &$visited,
    ): void {
        $objectId = spl_object_id($annotation);

        if (isset($visited[$objectId])) {
            return;
        }

        $visited[$objectId] = true;
        $visitor($annotation);

        /** @var array<class-string, list<string>|string> $nestedMap */
        $nestedMap = $annotation::$_nested;

        foreach ($nestedMap as $nestedConfig) {
            $propertyName = is_array($nestedConfig)
                ? $nestedConfig[0]
                : $nestedConfig;

            if (!property_exists($annotation, $propertyName)) {
                continue;
            }

            $value = $annotation->{$propertyName} ?? Generator::UNDEFINED;

            if (is_undefined($value)) {
                continue;
            }

            if (is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof OA\AbstractAnnotation) {
                        self::doWalk($child, $visitor, $visited);
                    }
                }
            } elseif ($value instanceof OA\AbstractAnnotation) {
                self::doWalk($value, $visitor, $visited);
            }
        }
    }

    /**
     * Collect all component names defined in `#/components/…`, keyed by component type.
     *
     * @return array<string, list<string>>
     */
    public static function collectDefinedComponents(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if (is_undefined($components) || $components === null) {
            return [];
        }

        $defined = [];

        foreach (ComponentType::cases() as $type) {
            if ($type === ComponentType::PathItems) {
                continue;
            }

            $items = $components->{$type->value} ?? Generator::UNDEFINED;

            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                if (is_undefined($item)) {
                    continue;
                }

                $name = self::extractComponentName($item, $type);

                if ($name !== null) {
                    $defined[$type->value][] = $name;
                }
            }
        }

        return $defined;
    }

    /**
     * Extract the component name from an annotation based on its component type.
     */
    public static function extractComponentName(object $item, ComponentType $type): ?string
    {
        return match ($type) {
            ComponentType::Schemas => $item instanceof OA\Schema && is_defined($item->schema)
                ? $item->schema
                : null,
            ComponentType::Responses => $item instanceof OA\Response && is_defined($item->response)
                ? (string) $item->response
                : null,
            ComponentType::Parameters => $item instanceof OA\Parameter && is_defined($item->parameter)
                ? $item->parameter
                : null,
            ComponentType::Examples => $item instanceof OA\Examples && is_defined($item->example)
                ? $item->example
                : null,
            ComponentType::RequestBodies => $item instanceof OA\RequestBody && is_defined($item->request)
                ? $item->request
                : null,
            ComponentType::Headers => $item instanceof OA\Header && is_defined($item->header)
                ? $item->header
                : null,
            ComponentType::SecuritySchemes => $item instanceof OA\SecurityScheme && is_defined($item->securityScheme)
                ? $item->securityScheme
                : null,
            ComponentType::Links => $item instanceof OA\Link && is_defined($item->link)
                ? $item->link
                : null,
            ComponentType::Callbacks => $item instanceof OA\PathItem && is_defined($item->path)
                ? $item->path
                : null,
            // PathItems represents inline callback constructs, not named components.
            ComponentType::PathItems => null,
        };
    }
}
