<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Lint;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Enums\ComponentType;

use function is_array;
use function property_exists;
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

            if (Generator::isDefault($value)) {
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
     * Collect all component names defined in `#/components/…`, keyed by component type value.
     *
     * PathItems is skipped — it is not a real component type but an inline callback
     * construct and would cause warnings if resolved from `$components`.
     *
     * @return array<string, list<string>>
     */
    public static function collectDefinedComponents(OA\OpenApi $spec): array
    {
        $components = $spec->components;

        if (Generator::isDefault($components) || $components === null) {
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
                if (Generator::isDefault($item)) {
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
            ComponentType::Schemas => $item instanceof OA\Schema && !Generator::isDefault($item->schema)
                ? $item->schema
                : null,
            ComponentType::Responses => $item instanceof OA\Response && !Generator::isDefault($item->response)
                ? (string) $item->response
                : null,
            ComponentType::Parameters => $item instanceof OA\Parameter && !Generator::isDefault($item->parameter)
                ? $item->parameter
                : null,
            ComponentType::Examples => $item instanceof OA\Examples && !Generator::isDefault($item->example)
                ? $item->example
                : null,
            ComponentType::RequestBodies => $item instanceof OA\RequestBody && !Generator::isDefault($item->request)
                ? $item->request
                : null,
            ComponentType::Headers => $item instanceof OA\Header && !Generator::isDefault($item->header)
                ? $item->header
                : null,
            ComponentType::SecuritySchemes => $item instanceof OA\SecurityScheme && !Generator::isDefault($item->securityScheme)
                ? $item->securityScheme
                : null,
            ComponentType::Links => $item instanceof OA\Link && !Generator::isDefault($item->link)
                ? $item->link
                : null,
            ComponentType::Callbacks => $item instanceof OA\PathItem && !Generator::isDefault($item->path)
                ? $item->path
                : null,
            // PathItems is not a named component type; it represents inline callback constructs
            // and has no extractable string name in the components map.
            ComponentType::PathItems => null,
        };
    }
}
