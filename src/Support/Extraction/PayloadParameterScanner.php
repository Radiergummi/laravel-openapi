<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Extraction;

use Illuminate\Container\Attributes\Config;
use Illuminate\Container\Attributes\Scoped;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

use function is_a;

/**
 * Scans a controller method's parameters for injected class-strings.
 *
 * {@see directCandidates()} returns what the method literally injects; {@see candidates()} also
 * descends into constructors of any class matching a configured indirection base (e.g. Domain
 * Action objects that wrap a FormRequest).
 *
 * @internal
 */
#[Scoped]
final class PayloadParameterScanner
{
    /** @var array<string, list<class-string>> */
    private array $memo = [];

    /** @var array<string, list<class-string>> */
    private array $directMemo = [];

    /**
     * @param list<class-string> $indirectionClasses Base class-strings whose constructors
     *                                               are also scanned for the payload class.
     *                                               An empty list disables descent entirely.
     */
    public function __construct(
        #[Config('openapi.request_payload_indirection', [])]
        private readonly array $indirectionClasses = [],
    ) {}

    /**
     * Returns the first candidate class-string that is a subtype of `$base`, or null.
     *
     * @template T of object
     *
     * @param class-string<T> $base
     *
     * @return null|class-string<T>
     */
    public function candidateOfType(ReflectionMethod $method, string $base): ?string
    {
        return array_find(
            $this->candidates($method),
            fn(string $class) => is_a($class, $base, allow_string: true),
        );
    }

    /**
     * Direct parameters first, then indirection constructor parameters, preserving declaration order.
     *
     * @return list<class-string>
     */
    public function candidates(ReflectionMethod $method): array
    {
        return $this->memo[$method->class . '::' . $method->name] ??= [
            ...$this->directCandidates($method),
            ...$this->indirectCandidates($method),
        ];
    }

    /**
     * What the method literally injects, with no indirection descent.
     *
     * @return list<class-string>
     */
    public function directCandidates(ReflectionMethod $method): array
    {
        return $this->directMemo[$method->class . '::' . $method->name] ??= $this->scanDirect($method);
    }

    /**
     * @return list<class-string>
     */
    private function scanDirect(ReflectionMethod $method): array
    {
        /** @var list<class-string> $direct */
        $direct = [];

        foreach ($method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                continue;
            }

            /** @var class-string $className */
            $className = $type->getName();
            $direct[] = $className;
        }

        return $direct;
    }

    /**
     * @return list<class-string>
     */
    private function indirectCandidates(ReflectionMethod $method): array
    {
        if ($this->indirectionClasses === []) {
            return [];
        }

        /** @var list<class-string> $fromIndirection */
        $fromIndirection = [];

        foreach ($this->directCandidates($method) as $className) {
            foreach ($this->indirectionClasses as $base) {
                if (!is_a($className, $base, allow_string: true)) {
                    continue;
                }

                $constructor = new ReflectionClass($className)->getConstructor();

                if ($constructor === null) {
                    break;
                }

                foreach ($constructor->getParameters() as $ctorParam) {
                    $constructorType = $ctorParam->getType();

                    if (!$constructorType instanceof ReflectionNamedType || $constructorType->isBuiltin()) {
                        continue;
                    }

                    /** @var class-string $constructorClass */
                    $constructorClass = $constructorType->getName();
                    $fromIndirection[] = $constructorClass;
                }

                break;
            }
        }

        return $fromIndirection;
    }
}
