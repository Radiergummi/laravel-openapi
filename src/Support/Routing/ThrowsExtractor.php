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
 * Returned names are not verified, so callers run `class_exists()` before trusting them.
 *
 * @internal
 */
#[Scoped]
final readonly class ThrowsExtractor
{
    public function __construct(
        private DocBlockParser $docBlockParser,
        private TypeNodeResolver $typeNodeResolver,
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

        $comment = $reflector->getDocComment() ?: null;

        if ($comment === null) {
            return [];
        }

        // Use the defining trait as context so bare names resolve against the trait's `use` statements.
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
     * Returns the trait that lexically defines the method, or null for direct/inherited methods.
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
     * Walks the trait hierarchy depth-first; returns the deepest trait declaring the method, or null.
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
