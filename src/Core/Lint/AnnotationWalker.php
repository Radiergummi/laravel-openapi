<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Radiergummi\OpenApi\Core\Enums\ComponentType;

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

        if ($components === Generator::UNDEFINED || $components === null) {
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
                if ($item === Generator::UNDEFINED) {
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
            ComponentType::Schemas => $item instanceof OA\Schema && $item->schema !== Generator::UNDEFINED
                ? $item->schema
                : null,
            ComponentType::Responses => $item instanceof OA\Response && $item->response !== Generator::UNDEFINED
                ? (string) $item->response
                : null,
            ComponentType::Parameters => $item instanceof OA\Parameter && $item->parameter !== Generator::UNDEFINED
                ? $item->parameter
                : null,
            ComponentType::Examples => $item instanceof OA\Examples && $item->example !== Generator::UNDEFINED
                ? $item->example
                : null,
            ComponentType::RequestBodies => $item instanceof OA\RequestBody && $item->request !== Generator::UNDEFINED
                ? $item->request
                : null,
            ComponentType::Headers => $item instanceof OA\Header && $item->header !== Generator::UNDEFINED
                ? $item->header
                : null,
            ComponentType::SecuritySchemes => $item instanceof OA\SecurityScheme && $item->securityScheme !== Generator::UNDEFINED
                ? $item->securityScheme
                : null,
            ComponentType::Links => $item instanceof OA\Link && $item->link !== Generator::UNDEFINED
                ? $item->link
                : null,
            ComponentType::Callbacks => $item instanceof OA\PathItem && $item->path !== Generator::UNDEFINED
                ? $item->path
                : null,
            // PathItems is not a named component type; it represents inline callback constructs
            // and has no extractable string name in the components map.
            ComponentType::PathItems => null,
        };
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

            if ($value === Generator::UNDEFINED) {
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
}
