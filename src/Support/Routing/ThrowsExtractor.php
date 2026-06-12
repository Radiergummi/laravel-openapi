<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionClass;
use ReflectionMethod;
use Reflector;

use function method_exists;

/**
 * Resolves `@throws` annotations to FQCNs via phpstan/phpdoc-parser + symfony/type-info.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting them.
 *
 * @internal
 */
#[Scoped]
final class ThrowsExtractor
{
    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    public static function create(): self
    {
        return new self(
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
        );
    }

    /**
     * @return list<string>
     */
    public function extract(Reflector $reflector): array
    {
        if (!method_exists($reflector, 'getDocComment')) {
            return [];
        }

        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
            return [];
        }

        // For trait-composed methods PHP reports the using class as the declaring class, which
        // would resolve bare `@throws` names against the using class's `use` statements. Resolve
        // names against the trait's own file context instead by passing the trait's reflector.
        $context = $this->definingTraitFor($reflector) ?? $reflector;

        $fqcns = [];

        foreach ($this->docBlockParser->parse($comment)->throwsTypes() as $type) {
            foreach ($this->typeNodeResolver->throwsClasses($type, $context) as $fqcn) {
                $fqcns[] = $fqcn;
            }
        }

        return $fqcns;
    }

    /**
     * Returns the trait that lexically defines the method, when the reflector is a method
     * composed via `use TraitName`. Returns `null` for direct methods, inherited methods,
     * or non-method reflectors.
     *
     * @return null|ReflectionClass<object>
     */
    private function definingTraitFor(Reflector $reflector): ?ReflectionClass
    {
        if (!$reflector instanceof ReflectionMethod) {
            return null;
        }

        return $this->findDefiningTrait($reflector->getDeclaringClass(), $reflector->getName());
    }

    /**
     * Walks the trait hierarchy depth-first and returns the deepest trait that declares
     * a method with the given name. Returns `null` if no trait declares it.
     *
     * @param ReflectionClass<object> $class
     *
     * @return null|ReflectionClass<object>
     */
    private function findDefiningTrait(ReflectionClass $class, string $methodName): ?ReflectionClass
    {
        foreach ($class->getTraits() as $trait) {
            $deeper = $this->findDefiningTrait($trait, $methodName);

            if ($deeper !== null) {
                return $deeper;
            }

            if ($trait->hasMethod($methodName)) {
                return $trait;
            }
        }

        return null;
    }
}
