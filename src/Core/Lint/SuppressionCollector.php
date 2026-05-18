<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Lint;

use Radiergummi\OpenApi\Core\Attributes\IgnoreLint;
use Radiergummi\OpenApi\Core\Routing\ActionDescriptor;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_any;
use function is_a;

/**
 * Collects `#[IgnoreLint]` attributes from the controllers, actions, and
 * request-payload classes behind the linted routes.
 */
final class SuppressionCollector
{
    /**
     * @param list<class-string> $payloadClasses     Base types whose subtypes Core treats as request payloads
     * @param list<class-string> $indirectionClasses Base types whose constructors are also scanned
     *                                               (mirrors config('openapi.request_payload_indirection'))
     */
    public function __construct(
        private readonly array $payloadClasses = [],
        private readonly array $indirectionClasses = [],
    ) {}

    /**
     * @param list<ActionDescriptor> $descriptors
     *
     * @return list<SuppressionDirective>
     *
     * @throws ReflectionException
     */
    public function collect(array $descriptors): array
    {
        $directives = [];
        $seen = [];

        foreach ($descriptors as $descriptor) {
            if ($descriptor->controller !== null) {
                $this->fromClass($descriptor->controller, withProperties: false, directives: $directives, seen: $seen);
            }

            if ($descriptor->method === null) {
                continue;
            }

            $this->fromMethod($descriptor->method, directives: $directives, seen: $seen);

            foreach ($descriptor->method->getParameters() as $parameter) {
                $this->fromDataParameter($parameter, directives: $directives, seen: $seen);
            }
        }

        return $directives;
    }

    /**
     * Collect class-level directives — and, when requested, property-level
     * directives — from a class.
     *
     * @param ReflectionClass<*>         $class
     * @param list<SuppressionDirective> $directives
     * @param array<string, true>        $seen
     */
    private function fromClass(
        ReflectionClass $class,
        bool $withProperties,
        array &$directives,
        array &$seen,
    ): void {
        if (!$this->markSeen('class:' . $class->getName(), $seen)) {
            return;
        }

        $file = $class->getFileName();

        if ($file === false) {
            return;
        }

        foreach ($class->getAttributes(IgnoreLint::class) as $attribute) {
            $ignore = $attribute->newInstance();
            $directives[] = new SuppressionDirective(
                ruleId: $ignore->rule,
                reason: $ignore->reason,
                scope: SuppressionScope::ClassScope,
                file: $file,
                line: $class->getStartLine() ?: null,
                targetClass: $class->getName(),
            );
        }

        if (!$withProperties) {
            return;
        }

        foreach ($class->getProperties() as $property) {
            $declaringFile = $property->getDeclaringClass()->getFileName();

            foreach ($property->getAttributes(IgnoreLint::class) as $attribute) {
                $ignore = $attribute->newInstance();
                $directives[] = new SuppressionDirective(
                    ruleId: $ignore->rule,
                    reason: $ignore->reason,
                    scope: SuppressionScope::PropertyScope,
                    file: $declaringFile !== false ? $declaringFile : $file,
                    line: null,
                    targetClass: $property->getDeclaringClass()->getName(),
                    targetMember: $property->getName(),
                );
            }
        }
    }

    /**
     * @param list<SuppressionDirective> $directives
     * @param array<string, true>        $seen
     */
    private function fromMethod(
        ReflectionMethod $method,
        array &$directives,
        array &$seen,
    ): void {
        $key = 'method:'
            . $method->getDeclaringClass()->getName()
            . '::'
            . $method->getName();

        if (!$this->markSeen($key, $seen)) {
            return;
        }

        $file = $method->getFileName();

        if ($file === false) {
            return;
        }

        $start = $method->getStartLine() ?: null;
        $end = $method->getEndLine() ?: null;

        foreach ($method->getAttributes(IgnoreLint::class) as $attribute) {
            $ignore = $attribute->newInstance();
            $directives[] = new SuppressionDirective(
                ruleId: $ignore->rule,
                reason: $ignore->reason,
                scope: SuppressionScope::MethodScope,
                file: $file,
                line: $start,
                targetClass: $method->getDeclaringClass()->getName(),
                targetMember: $method->getName(),
                methodStartLine: $start,
                methodEndLine: $end,
            );
        }
    }

    /**
     * Descend into a parameter type-hinted as a Data class (or an Action whose
     * constructor carries one) to collect class- and property-level directives —
     * the `field.*` rules inspect exactly those Data properties.
     *
     * Direct payload parameters are collected with properties. Indirection classes
     * (Domain Actions) are not collected themselves; instead their constructor is
     * reflected and each constructor parameter that is a payload class is collected.
     *
     * @param list<SuppressionDirective> $directives
     * @param array<string, true>        $seen
     *
     * @throws ReflectionException
     */
    private function fromDataParameter(
        ReflectionParameter $parameter,
        array &$directives,
        array &$seen,
    ): void {
        $type = $parameter->getType();

        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return;
        }

        $className = $type->getName();

        $isPayload = array_any(
            $this->payloadClasses,
            static fn(string $base): bool => is_a($className, $base, allow_string: true),
        );

        if ($isPayload) {
            $this->fromClass(new ReflectionClass($className), withProperties: true, directives: $directives, seen: $seen);

            return;
        }

        // Follow the Domain Action indirection: if the parameter is an Action,
        // scan its constructor for payload-class parameters.
        $isIndirection = array_any(
            $this->indirectionClasses,
            static fn(string $base): bool => is_a($className, $base, allow_string: true),
        );

        if (!$isIndirection) {
            return;
        }

        $constructor = (new ReflectionClass($className))->getConstructor();

        if ($constructor === null) {
            return;
        }

        foreach ($constructor->getParameters() as $ctorParam) {
            $this->fromDataParameter($ctorParam, directives: $directives, seen: $seen);
        }
    }

    /**
     * Record a reflection target as visited; returns false if already seen.
     *
     * @param array<string, true> $seen
     */
    private function markSeen(string $key, array &$seen): bool
    {
        if (isset($seen[$key])) {
            return false;
        }

        $seen[$key] = true;

        return true;
    }
}
