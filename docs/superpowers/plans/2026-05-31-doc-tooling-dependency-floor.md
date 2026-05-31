# Lower the transitive doc-tooling dependency floor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Stop pinning the newest major of two shared low-level libraries so that in-range Laravel apps can install the package: replace the `phpdocumentor` PHPDoc stack with `phpstan/phpdoc-parser` (+ `symfony/type-info`), and — if a spike proves it cheap — widen `zircote/swagger-php` to `^5.8 || ^6`.

**Architecture:** Workstream A introduces two focused services — `Support\PhpDoc\DocBlockParser` (parses a doc comment into reusable tag/type nodes via `phpstan/phpdoc-parser`) and `Support\Types\TypeNodeResolver` (resolves a parsed type node to FQCNs via `symfony/type-info`, which reads the file's `use` map for us). The three current consumers (`ReturnTypeExtractor`, `ThrowsExtractor`, `ThrowsTransitiveMissing`) are re-backed onto these, then `phpdocumentor/reflection-docblock` (and its transitive `type-resolver`) is removed from `require`. Workstream B is a spike that pins swagger-php 5.8, runs the full gate, and only then decides whether to widen the constraint and add a standing lowest-deps CI cell.

**Tech Stack:** PHP 8.4, Laravel 12/13, Pest (Testbench), PHPStan/Larastan L8, Laravel Pint, `phpstan/phpdoc-parser ^2`, `symfony/type-info ^7.3||^8`, `zircote/swagger-php ^6` (→ widen).

**Spec:** `docs/superpowers/specs/2026-05-31-doc-tooling-dependency-floor-design.md`

---

## File structure

**Workstream A — create:**
- `src/Support/PhpDoc/DocBlockParser.php` — scoped service; parses a raw doc comment string into a `ParsedDocBlock`, memoised. Pure parser; depends only on `phpstan/phpdoc-parser`.
- `src/Support/PhpDoc/ParsedDocBlock.php` — value object over a `PhpDocNode`; typed accessors (`returnType()`, `throwsTypes()`) + generic `tagValues()` for future tags.
- `src/Support/Types/TypeNodeResolver.php` — scoped service; turns a `TypeNode` (+ a `Reflector` for context) into FQCN(s) via `symfony/type-info`. Owns the generic-value-last rule and union flattening.
- `tests/Unit/Support/PhpDoc/DocBlockParserTest.php`
- `tests/Unit/Support/Types/TypeNodeResolverTest.php`

**Workstream A — modify:**
- `src/Support/Routing/ReturnTypeExtractor.php` — re-backed onto the two services (public API unchanged).
- `src/Support/Routing/ThrowsExtractor.php` — re-backed; trait-context substitution preserved.
- `src/Lint/Rules/ThrowsTransitiveMissing.php` — re-backed onto `ThrowsExtractor` (removes duplicated `@throws` parsing).
- `src/OpenApiServiceProvider.php` — bind the two new services; drop the `DocBlockFactoryInterface` binding.
- `composer.json` — add `phpstan/phpdoc-parser`; remove `phpdocumentor/reflection-docblock`.
- `CHANGELOG.md` — `[Unreleased]` entry.

**Workstream B — modify (spike-gated):**
- `composer.json` — widen `zircote/swagger-php` to `^5.8 || ^6` (only if spike passes).
- `.github/workflows/tests.yml` — add a swagger-php-low job (only if spike passes).
- `CHANGELOG.md` — entry (widen, or recorded deferral).

---

## Task A1: `DocBlockParser` + `ParsedDocBlock`

**Files:**
- Create: `src/Support/PhpDoc/DocBlockParser.php`
- Create: `src/Support/PhpDoc/ParsedDocBlock.php`
- Create: `tests/Unit/Support/PhpDoc/DocBlockParserTest.php`
- Modify: `composer.json`

- [ ] **Step 1: Add `phpstan/phpdoc-parser` to `require`**

Run:
```bash
composer require "phpstan/phpdoc-parser:^2.0" --no-interaction
```
Expected: `composer.json` gains `"phpstan/phpdoc-parser": "^2.0"` under `require`; install is effectively a no-op (already present at 2.3.2 transitively). Confirm:
```bash
composer why phpstan/phpdoc-parser | grep radiergummi
```
Expected: a line showing `radiergummi/laravel-openapi ... requires phpstan/phpdoc-parser (^2.0)`.

- [ ] **Step 2: Write the failing test**

Create `tests/Unit/Support/PhpDoc/DocBlockParserTest.php`:
```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\PhpDoc;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;

function makeDocBlockParser(): DocBlockParser
{
    return DocBlockParser::create();
}

it('exposes the @return type node', function (): void {
    $comment = "/**\n * @return Foo<Bar>\n */";

    $type = makeDocBlockParser()->parse($comment)->returnType();

    expect($type)->toBeInstanceOf(GenericTypeNode::class);
});

it('returns null when there is no @return tag', function (): void {
    $comment = "/**\n * @throws RuntimeException\n */";

    expect(makeDocBlockParser()->parse($comment)->returnType())->toBeNull();
});

it('exposes every @throws type node in order', function (): void {
    $comment = "/**\n * @throws A\n * @throws B|C\n */";

    $types = makeDocBlockParser()->parse($comment)->throwsTypes();

    expect($types)->toHaveCount(2);
});

it('exposes raw value nodes for arbitrary tags', function (): void {
    $comment = "/**\n * @param Foo \$bar a thing\n */";

    expect(makeDocBlockParser()->parse($comment)->tagValues('@param'))->toHaveCount(1);
});

it('returns an empty doc block for an unparseable comment', function (): void {
    $parsed = makeDocBlockParser()->parse('not a doc comment');

    expect($parsed->returnType())->toBeNull()
        ->and($parsed->throwsTypes())->toBe([]);
});

it('memoises identical comments', function (): void {
    $parser = makeDocBlockParser();
    $comment = "/**\n * @return Foo<Bar>\n */";

    expect($parser->parse($comment))->toBe($parser->parse($comment));
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Support/PhpDoc/DocBlockParserTest.php`
Expected: FAIL — `Class "Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser" not found`.

- [ ] **Step 4: Create `ParsedDocBlock`**

Create `src/Support/PhpDoc/ParsedDocBlock.php`:
```php
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
```

- [ ] **Step 5: Create `DocBlockParser`**

Create `src/Support/PhpDoc/DocBlockParser.php`:
```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\PhpDoc;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Lexer\Lexer;
use PHPStan\PhpDocParser\Parser\ConstExprParser;
use PHPStan\PhpDocParser\Parser\PhpDocParser;
use PHPStan\PhpDocParser\Parser\TokenIterator;
use PHPStan\PhpDocParser\Parser\TypeParser;
use PHPStan\PhpDocParser\ParserConfig;
use Throwable;

use function array_key_exists;

/**
 * Parses raw PHPDoc comments into {@see ParsedDocBlock}s via phpstan/phpdoc-parser.
 *
 * Bound as a scoped singleton; the per-run parse cache resets between generation
 * runs under Octane.
 */
#[Scoped]
final class DocBlockParser
{
    /**
     * @var array<string, ParsedDocBlock>
     */
    private array $cache = [];

    public function __construct(
        private readonly Lexer $lexer,
        private readonly PhpDocParser $parser,
    ) {}

    public function parse(string $docComment): ParsedDocBlock
    {
        if (array_key_exists($docComment, $this->cache)) {
            return $this->cache[$docComment];
        }

        try {
            $tokens = new TokenIterator($this->lexer->tokenize($docComment));

            return $this->cache[$docComment] = new ParsedDocBlock($this->parser->parse($tokens));
        } catch (Throwable) {
            // Malformed comment — behave as "no tags", never break the pipeline.
            return $this->cache[$docComment] = ParsedDocBlock::empty();
        }
    }

    public static function create(): self
    {
        $config = new ParserConfig([]);
        $constExpr = new ConstExprParser($config);

        return new self(
            lexer: new Lexer($config),
            parser: new PhpDocParser($config, new TypeParser($config, $constExpr), $constExpr),
        );
    }
}
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Support/PhpDoc/DocBlockParserTest.php`
Expected: PASS (6 passed).

- [ ] **Step 7: Lint + analyse the new files**

Run: `vendor/bin/pint src/Support/PhpDoc && vendor/bin/phpstan analyse src/Support/PhpDoc --no-progress`
Expected: Pint clean; PHPStan 0 errors.

- [ ] **Step 8: Commit**

```bash
git add composer.json composer.lock src/Support/PhpDoc tests/Unit/Support/PhpDoc
git commit -m "feat(phpdoc): add phpstan/phpdoc-parser-backed DocBlockParser"
```

---

## Task A2: `TypeNodeResolver`

**Files:**
- Create: `src/Support/Types/TypeNodeResolver.php`
- Create: `tests/Unit/Support/Types/TypeNodeResolverTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Support/Types/TypeNodeResolverTest.php`:
```php
<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Types;

use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionMethod;
use RuntimeException;
use stdClass;

/**
 * Fixture supplies the namespace + `use` context the resolver reads.
 */
class TypeNodeResolverFixture
{
    /** @return \Illuminate\Support\Collection<int, stdClass> */
    public function generic(): void {}

    /** @return stdClass */
    public function plain(): void {}

    /** @throws RuntimeException|\LogicException */
    public function throwsUnion(): void {}
}

function makeResolverPair(): array
{
    return [DocBlockParser::create(), TypeNodeResolver::create()];
}

it('resolves the value class of a generic to an FQCN', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'generic');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBe('stdClass');
});

it('returns null when the return type is not a generic', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'plain');
    $type = $parser->parse((string) $method->getDocComment())->returnType();

    expect($resolver->genericValueClass($type, $method))->toBeNull();
});

it('flattens a union @throws node into FQCNs in order', function (): void {
    [$parser, $resolver] = makeResolverPair();
    $method = new ReflectionMethod(TypeNodeResolverFixture::class, 'throwsUnion');
    $types = $parser->parse((string) $method->getDocComment())->throwsTypes();

    expect($resolver->throwsClasses($types[0], $method))
        ->toBe(['RuntimeException', 'LogicException']);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/pest tests/Unit/Support/Types/TypeNodeResolverTest.php`
Expected: FAIL — `Class "Radiergummi\OpenApi\Support\Types\TypeNodeResolver" not found`.

- [ ] **Step 3: Create `TypeNodeResolver`**

Create `src/Support/Types/TypeNodeResolver.php`:
```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Types;

use Illuminate\Container\Attributes\Scoped;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Reflector;
use Symfony\Component\TypeInfo\Type\ObjectType;
use Symfony\Component\TypeInfo\TypeContext\TypeContext;
use Symfony\Component\TypeInfo\TypeContext\TypeContextFactory;
use Symfony\Component\TypeInfo\TypeResolver\StringTypeResolver;
use Throwable;

use function array_key_exists;
use function end;
use function ltrim;

/**
 * Resolves phpstan/phpdoc-parser type nodes to FQCNs, using symfony/type-info to
 * resolve short names against the declaring file's namespace and `use` imports.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting
 * them. Bound as a scoped singleton; the context cache resets between runs.
 */
#[Scoped]
final class TypeNodeResolver
{
    /**
     * @var array<string, ?TypeContext>
     */
    private array $contextCache = [];

    public function __construct(
        private readonly StringTypeResolver $stringResolver,
        private readonly TypeContextFactory $contextFactory,
    ) {}

    /**
     * FQCN (no leading backslash) of the *value* argument of a generic type —
     * `Foo<Key, Value>` resolves to `Value` (the last argument). Returns null
     * when the node is not a generic, or its value argument is not a plain class.
     */
    public function genericValueClass(TypeNode $node, Reflector $context): ?string
    {
        if (!$node instanceof GenericTypeNode || $node->genericTypes === []) {
            return null;
        }

        $value = end($node->genericTypes);

        return $value instanceof IdentifierTypeNode
            ? $this->resolveClass($value, $context)
            : null;
    }

    /**
     * FQCNs (no leading backslash) of every class in a (possibly union) `@throws`
     * node, in source order.
     *
     * @return list<string>
     */
    public function throwsClasses(TypeNode $node, Reflector $context): array
    {
        $classes = [];

        foreach ($this->flatten($node) as $identifier) {
            $fqcn = $this->resolveClass($identifier, $context);

            if ($fqcn !== null) {
                $classes[] = $fqcn;
            }
        }

        return $classes;
    }

    /**
     * @return iterable<IdentifierTypeNode>
     */
    private function flatten(TypeNode $node): iterable
    {
        if ($node instanceof UnionTypeNode) {
            foreach ($node->types as $inner) {
                yield from $this->flatten($inner);
            }

            return;
        }

        if ($node instanceof NullableTypeNode) {
            yield from $this->flatten($node->type);

            return;
        }

        if ($node instanceof IdentifierTypeNode) {
            yield $node;
        }
    }

    private function resolveClass(IdentifierTypeNode $node, Reflector $context): ?string
    {
        try {
            $type = $this->stringResolver->resolve($node->name, $this->contextFor($context));
        } catch (Throwable) {
            return null;
        }

        return $type instanceof ObjectType
            ? ltrim($type->getClassName(), '\\')
            : null;
    }

    private function contextFor(Reflector $context): ?TypeContext
    {
        $key = $this->cacheKey($context);

        if ($key !== null && array_key_exists($key, $this->contextCache)) {
            return $this->contextCache[$key];
        }

        $resolved = $this->contextFactory->createFromReflection($context);

        if ($key !== null) {
            $this->contextCache[$key] = $resolved;
        }

        return $resolved;
    }

    private function cacheKey(Reflector $context): ?string
    {
        return match (true) {
            $context instanceof \ReflectionClass => $context->getName(),
            $context instanceof \ReflectionMethod => $context->getDeclaringClass()->getName() . '::' . $context->getName(),
            default => null,
        };
    }

    public static function create(): self
    {
        $stringResolver = new StringTypeResolver();

        return new self(
            stringResolver: $stringResolver,
            contextFactory: new TypeContextFactory($stringResolver),
        );
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/pest tests/Unit/Support/Types/TypeNodeResolverTest.php`
Expected: PASS (3 passed).

If the generic test resolves `stdClass` but the union-throws test returns FQCNs with a leading `\` or in the wrong order, re-check `flatten()` order and the `ltrim`. If `genericValueClass` returns null for the `Collection<int, stdClass>` case, confirm phpstan parsed it as `GenericTypeNode` (it does for `Name<...>`).

- [ ] **Step 5: Lint + analyse**

Run: `vendor/bin/pint src/Support/Types tests/Unit/Support/Types && vendor/bin/phpstan analyse src/Support/Types --no-progress`
Expected: Pint clean; PHPStan 0 errors.

- [ ] **Step 6: Commit**

```bash
git add src/Support/Types tests/Unit/Support/Types
git commit -m "feat(types): add TypeNodeResolver over symfony/type-info"
```

---

## Task A3: Re-back `ReturnTypeExtractor`

**Files:**
- Modify: `src/Support/Routing/ReturnTypeExtractor.php` (full replace)
- Test (guard, unchanged): `tests/Unit/Support/Routing/ReturnTypeExtractorTest.php`

- [ ] **Step 1: Run the existing test to confirm the green baseline**

Run: `vendor/bin/pest tests/Unit/Support/Routing/ReturnTypeExtractorTest.php`
Expected: PASS (4 passed) — this is the RED→GREEN guard for the rewrite.

- [ ] **Step 2: Replace `ReturnTypeExtractor` with the re-backed implementation**

Replace the entire contents of `src/Support/Routing/ReturnTypeExtractor.php` with:
```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Support\Routing;

use Illuminate\Container\Attributes\Scoped;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use ReflectionFunctionAbstract;

use function array_key_exists;
use function spl_object_id;

/**
 * Extracts the single generic argument of an action's `@return` PHPDoc tag.
 *
 * PHP native return types cannot carry generics — `function index(): LengthAwarePaginator` has no
 * inner type. The inner type lives only in a PHPDoc `return LengthAwarePaginator<UserResource>`.
 * This reader exposes exactly that one piece of information; it never reads method bodies.
 *
 * Returned names are not verified — callers run `class_exists()` before trusting them.
 */
#[Scoped]
final class ReturnTypeExtractor
{
    /**
     * Memoised `genericArgument()` results for the lifetime of the extractor instance — the
     * extractor is bound as a scoped singleton, so the cache resets between generation runs under
     * Octane. Keyed by `spl_object_id($reflector)`; a stored `null` is a meaningful result
     * (reflector has no `@return` generic) and is distinguished from "uncached" by
     * `array_key_exists`.
     *
     * @var array<int, ?string>
     */
    private array $genericArgumentCache = [];

    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

    /**
     * Returns the FQCN (without a leading backslash) of the generic argument of
     * the at-return tag, or null when there is no docblock, no at-return tag,
     * or no generic argument.
     */
    public function genericArgument(ReflectionFunctionAbstract $reflector): ?string
    {
        $key = spl_object_id($reflector);

        if (array_key_exists($key, $this->genericArgumentCache)) {
            return $this->genericArgumentCache[$key];
        }

        $comment = $reflector->getDocComment();

        if ($comment === false || $comment === '') {
            return $this->genericArgumentCache[$key] = null;
        }

        $returnType = $this->docBlockParser->parse($comment)->returnType();

        if ($returnType === null) {
            return $this->genericArgumentCache[$key] = null;
        }

        return $this->genericArgumentCache[$key] = $this->typeNodeResolver->genericValueClass(
            $returnType,
            $reflector,
        );
    }

    public static function create(): self
    {
        return new self(
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
        );
    }
}
```

- [ ] **Step 3: Run the existing test to verify it still passes**

Run: `vendor/bin/pest tests/Unit/Support/Routing/ReturnTypeExtractorTest.php`
Expected: PASS (4 passed). If `genericArgument` returns null for the `LengthAwarePaginator<stdClass>` fixture, confirm the fixture's `use stdClass;` is read by the context (it is, via `createFromReflection`).

- [ ] **Step 4: Lint + analyse**

Run: `vendor/bin/pint src/Support/Routing/ReturnTypeExtractor.php && vendor/bin/phpstan analyse src/Support/Routing/ReturnTypeExtractor.php --no-progress`
Expected: Pint clean; PHPStan 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Support/Routing/ReturnTypeExtractor.php
git commit -m "refactor(routing): re-back ReturnTypeExtractor on phpdoc-parser"
```

---

## Task A4: Re-back `ThrowsExtractor`

**Files:**
- Modify: `src/Support/Routing/ThrowsExtractor.php` (full replace)
- Test (guard, unchanged): `tests/Unit/Support/Routing/ThrowsExtractorTest.php`

- [ ] **Step 1: Run the existing test to confirm the green baseline**

Run: `vendor/bin/pest tests/Unit/Support/Routing/ThrowsExtractorTest.php`
Expected: PASS (6 passed) — the guard, including the trait-context and closure cases.

- [ ] **Step 2: Replace `ThrowsExtractor` with the re-backed implementation**

Replace the entire contents of `src/Support/Routing/ThrowsExtractor.php` with:
```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

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
 */
#[Scoped]
final class ThrowsExtractor
{
    public function __construct(
        private readonly DocBlockParser $docBlockParser,
        private readonly TypeNodeResolver $typeNodeResolver,
    ) {}

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

    public static function create(): self
    {
        return new self(
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
        );
    }
}
```

- [ ] **Step 3: Run the existing test to verify it still passes**

Run: `vendor/bin/pest tests/Unit/Support/Routing/ThrowsExtractorTest.php`
Expected: PASS (6 passed).

Watch-points if any case is RED:
- **Trait case** expects the trait-namespaced FQCN — verify `definingTraitFor` returns the trait `ReflectionClass` and that `createFromReflection(ReflectionClass)` reads the trait file's namespace.
- **Closure case** expects `['RuntimeException']` from a context-free closure. If `createFromReflection` returns `null` and `StringTypeResolver::resolve('RuntimeException', null)` does not yield an `ObjectType`, add a minimal fallback in `TypeNodeResolver::resolveClass`: when the resolved type is not an `ObjectType` *and* the context is null, treat a non-built-in identifier name as a global class (`return ltrim($node->name, '\\')`). Only add this if the test demands it.

- [ ] **Step 4: Lint + analyse**

Run: `vendor/bin/pint src/Support/Routing/ThrowsExtractor.php && vendor/bin/phpstan analyse src/Support/Routing/ThrowsExtractor.php --no-progress`
Expected: Pint clean; PHPStan 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/Support/Routing/ThrowsExtractor.php
git commit -m "refactor(routing): re-back ThrowsExtractor on phpdoc-parser"
```

---

## Task A5: Re-back `ThrowsTransitiveMissing` lint rule

**Files:**
- Modify: `src/Lint/Rules/ThrowsTransitiveMissing.php`
- Test (guard, unchanged): `tests/Unit/Lint/Rules/ThrowsTransitiveMissingTest.php`

- [ ] **Step 1: Run the existing test to confirm the green baseline**

Run: `vendor/bin/pest tests/Unit/Lint/Rules/ThrowsTransitiveMissingTest.php`
Expected: PASS — the guard for this rule.

- [ ] **Step 2: Swap the rule's `@throws` parsing onto `ThrowsExtractor`**

In `src/Lint/Rules/ThrowsTransitiveMissing.php`:

(a) Replace the imports block — remove the four phpDocumentor `use` lines and the `Throws` tag import; add `ThrowsExtractor`. The import section becomes:
```php
use Override;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Lint\Finding;
use Radiergummi\OpenApi\Lint\LintContext;
use Radiergummi\OpenApi\Lint\Tree\OperationNode;
use Radiergummi\OpenApi\Lint\Visitors\OperationRule as OperationRuleVisitor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;
use Radiergummi\OpenApi\Support\Routing\ThrowsExtractor;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

use function array_map;
use function class_exists;
use function in_array;
use function ltrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
```

(b) Replace the two properties + constructor (current lines 48–58) with a single `ThrowsExtractor` dependency:
```php
    private ThrowsExtractor $throwsExtractor;

    public function __construct(?ThrowsExtractor $throwsExtractor = null)
    {
        $this->throwsExtractor = $throwsExtractor ?? ThrowsExtractor::create();
    }
```

(c) Replace the body of `compareThrows()` from the `$docComment` line through the end of the `foreach ($throwsTags ...)` loop (current lines 146–201) with extractor-driven logic:
```php
        $exceptionTypes = $this->throwsExtractor->extract($handleMethod);

        if ($exceptionTypes === []) {
            return;
        }

        // Normalize the controller's declared throws to bare FQCNs
        $controllerThrows = array_map(
            static fn(string $fqcn): string => ltrim($fqcn, '\\'),
            $descriptor->throws,
        );

        $actionShortName = $handleMethod->getDeclaringClass()->getShortName();
        $controllerShortName = $descriptor->controller?->getShortName() ?? '(unknown)';
        $methodName = $method->getName();

        foreach ($exceptionTypes as $exceptionType) {
            if ($exceptionType === '' || in_array($exceptionType, $controllerThrows, true)) {
                continue;
            }

            yield new Finding(
                ruleId: $this->id(),
                level: $this->level(),
                message: sprintf(
                    '%s::handle() declares @throws %s, but %s::%s() does not redeclare it',
                    $actionShortName,
                    $exceptionType,
                    $controllerShortName,
                    $methodName,
                ),
                fixHint: sprintf(
                    'Add @throws %s to %s::%s() or add a matching #[ExceptionResponse] attribute.',
                    $exceptionType,
                    $controllerShortName,
                    $methodName,
                ),
            );
        }
```
(The early `try { $handleMethod = new ReflectionMethod(...) } catch (ReflectionException) { return; }` block above this stays as-is — `ThrowsExtractor::extract()` handles the no-docblock case by returning `[]`.)

- [ ] **Step 3: Run the existing test to verify it still passes**

Run: `vendor/bin/pest tests/Unit/Lint/Rules/ThrowsTransitiveMissingTest.php`
Expected: PASS.

- [ ] **Step 4: Lint + analyse**

Run: `vendor/bin/pint src/Lint/Rules/ThrowsTransitiveMissing.php && vendor/bin/phpstan analyse src/Lint/Rules/ThrowsTransitiveMissing.php --no-progress`
Expected: Pint clean; PHPStan 0 errors (no unused imports — `ReflectionException`, `ltrim`, etc. all still referenced).

- [ ] **Step 5: Commit**

```bash
git add src/Lint/Rules/ThrowsTransitiveMissing.php
git commit -m "refactor(lint): re-back ThrowsTransitiveMissing on ThrowsExtractor"
```

---

## Task A6: Wire the new services in the service provider

**Files:**
- Modify: `src/OpenApiServiceProvider.php`

- [ ] **Step 1: Remove the phpDocumentor imports**

In `src/OpenApiServiceProvider.php`, delete these two import lines (current lines 19–20):
```php
use phpDocumentor\Reflection\DocBlockFactory;
use phpDocumentor\Reflection\DocBlockFactoryInterface;
```
Add (alongside the other `Support` imports):
```php
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
```

- [ ] **Step 2: Replace the `DocBlockFactoryInterface` binding with the two service bindings**

Replace the binding block (current lines 228–233, the comment + `$this->app->scoped(DocBlockFactoryInterface::class, ...)`) with:
```php
        // PHPDoc parsing + type resolution. ThrowsExtractor and ReturnTypeExtractor autowire
        // these via #[Scoped]; both carry per-run caches, so they are scoped (Octane-reset).
        $this->app->scoped(DocBlockParser::class, static fn(): DocBlockParser => DocBlockParser::create());
        $this->app->scoped(TypeNodeResolver::class, static fn(): TypeNodeResolver => TypeNodeResolver::create());
```

- [ ] **Step 3: Run the full suite to verify container wiring**

Run: `composer test`
Expected: PASS — the feature tests that resolve `ReturnTypeExtractor`/`ThrowsExtractor`/lint rules through the container now work end-to-end on the new services. If a binding-resolution error mentions `DocBlockParser` or `TypeNodeResolver`, confirm both `scoped()` calls are inside `register()` and the imports resolve.

- [ ] **Step 4: Lint + analyse**

Run: `vendor/bin/pint src/OpenApiServiceProvider.php && vendor/bin/phpstan analyse src/OpenApiServiceProvider.php --no-progress`
Expected: Pint clean; PHPStan 0 errors.

- [ ] **Step 5: Commit**

```bash
git add src/OpenApiServiceProvider.php
git commit -m "refactor(provider): bind DocBlockParser + TypeNodeResolver"
```

---

## Task A7: Drop `phpdocumentor/reflection-docblock` and verify the full gate

**Files:**
- Modify: `composer.json`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Confirm no remaining phpDocumentor references in `src/`**

Run: `grep -rn "phpDocumentor" src/`
Expected: NO output. If any line remains, it was missed in Tasks A3–A6 — fix before removing the dependency.

- [ ] **Step 2: Remove the dependency**

Run:
```bash
composer remove phpdocumentor/reflection-docblock --no-interaction
```
Expected: `composer.json` no longer lists `phpdocumentor/reflection-docblock` under `require`; `composer.lock` updates. (On swagger-php 6, `reflection-docblock`/`type-resolver` should now drop out of the tree entirely; on a future swagger-php 5.8 install they may return transitively — that is fine, we just no longer pin them.)

- [ ] **Step 3: Verify they are no longer direct dependencies**

Run: `composer why phpdocumentor/reflection-docblock || echo "not a direct dependency"`
Expected: either "not installed/required" or only *transitive* requirers — crucially, **no** `radiergummi/laravel-openapi` line.

- [ ] **Step 4: Run the full gate**

Run:
```bash
composer test
composer analyse
vendor/bin/pint --test
```
Expected: Pest all green; PHPStan L8 0 errors; Pint reports no violations. (Note: a pre-existing `MultiSpecRoutesTest` env flake — `MissingAppKeyException` — is unrelated to this work per the dogfooding pointer log; if it appears, confirm it reproduces on a clean checkout before treating it as a regression.)

- [ ] **Step 5: Add the CHANGELOG entry**

In `CHANGELOG.md`, under `[Unreleased]`, add:
```markdown
### Changed
- PHPDoc parsing now uses `phpstan/phpdoc-parser` with `symfony/type-info` for type
  resolution, replacing `phpdocumentor/reflection-docblock`. This removes the direct
  dependency on the `phpdocumentor` type stack (`reflection-docblock ^6` /
  `type-resolver ^2`), so apps depending on the older major of those libraries can now
  install the package. New `Support\PhpDoc\DocBlockParser` and `Support\Types\TypeNodeResolver`
  services provide a reusable foundation for parsing further PHPDoc tags.
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock CHANGELOG.md
git commit -m "deps: drop phpdocumentor/reflection-docblock for phpstan/phpdoc-parser"
```

**Workstream A is complete here.** It unblocks Invoice Ninja and Lychee on its own. Proceed to Workstream B (spike-gated).

---

## Task B8: Spike — does the suite pass on swagger-php 5.8?

**Files:** none committed in this task (spike only; revert at the end).

- [ ] **Step 1: Record the current swagger-php version**

Run: `composer show zircote/swagger-php | grep -E "^(name|versions)"`
Expected: shows the installed 6.x version. Note it for the revert.

- [ ] **Step 2: Attempt to install swagger-php 5.8 alongside the new phpdoc-parser requirement**

Run:
```bash
composer require "zircote/swagger-php:^5.8" --no-interaction 2>&1 | tee /tmp/swagger58-install.log
```
Decision input — **the phpdoc-parser coupling check:**
- If the install **fails** with a `phpstan/phpdoc-parser` conflict (swagger-php 5.8 caps it at `^1` while we require `^2`), record the exact conflict from `/tmp/swagger58-install.log`. This is the "range cannot reconcile" branch → go to Step 5 (defer). Optionally, before deferring, test whether relaxing our requirement to `phpstan/phpdoc-parser: ^1.30 || ^2.0` resolves it *and* whether `DocBlockParser`/`TypeNodeResolver` still construct under 1.x (the 1.x parser constructors differ — `new ParserConfig([])` does not exist in 1.x). If 1.x support is non-trivial, defer.
- If the install **succeeds**, continue.

- [ ] **Step 3: Run the full gate against swagger-php 5.8**

Run:
```bash
composer test
composer analyse
vendor/bin/pint --test
```
Record results. Categorize any failures:
- **Trivial / version-agnostic** (e.g. a call into `Generator::scan()`, `getProcessors()`, `Util::finder()`, or `MediaType::encoding` typing) — note the file:line and a version-agnostic fix.
- **Hard** (the object model, `toJson`/`toYaml`, `validate`, or `Generator::UNDEFINED` behaves differently, or serialization output diverges materially) — this is "real breakage".

- [ ] **Step 4: Revert the spike**

Run:
```bash
git checkout composer.json composer.lock
composer install --no-interaction
```
Expected: back on swagger-php 6.x; `git status` clean except your committed Workstream-A changes.

- [ ] **Step 5: Decide and record**

Write the outcome into the spec's decision gate. Two branches:
- **PASS** (clean, or only version-agnostic fixes, and phpdoc-parser range reconciles) → proceed to Task B9.
- **DEFER** (real breakage, or phpdoc-parser range cannot reconcile without 1.x support) → do **not** widen. Add a `CHANGELOG.md` note under `[Unreleased]`:
  ```markdown
  ### Notes
  - Widening `zircote/swagger-php` to `^5.8` was evaluated and deferred: <one-line reason
    from the spike>. Apps pinning swagger-php 5.x (e.g. Coolify) remain unable to install.
  ```
  Commit: `git add CHANGELOG.md && git commit -m "docs(changelog): record swagger-php 5.8 widen deferral"`. **Stop here** — skip Task B9.

---

## Task B9: Widen swagger-php + add a lowest-deps CI cell (only if B8 PASSED)

**Files:**
- Modify: `composer.json`
- Modify: `.github/workflows/tests.yml`
- Modify: `CHANGELOG.md`
- Apply any version-agnostic source fixes identified in B8 (with the file:line from the spike).

- [ ] **Step 1: Apply any version-agnostic fixes from the spike**

For each "trivial" item recorded in B8, apply the version-agnostic fix in `src/`. (If B8 found zero fixes needed, skip to Step 2.) After each fix, re-run the targeted test for that file.

- [ ] **Step 2: Widen the constraint**

In `composer.json`, change the `zircote/swagger-php` requirement to:
```json
"zircote/swagger-php": "^5.8 || ^6.1.2",
```
Run:
```bash
composer update zircote/swagger-php --no-interaction
```
Expected: resolves to 6.x by default (highest); `composer.lock` updates.

- [ ] **Step 3: Verify the full gate still passes on the default (6.x) resolution**

Run:
```bash
composer test && composer analyse && vendor/bin/pint --test
```
Expected: all green (this is the high-end of the range; B8 already validated the low end).

- [ ] **Step 4: Add the lowest-deps CI job**

In `.github/workflows/tests.yml`, add a second job after `test` that pins swagger-php to its lowest allowed version on one cell and runs the suite:
```yaml
  test-swagger-low:
    runs-on: ubuntu-latest
    name: PHP 8.4 · Laravel 12 · swagger-php 5.8
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
          coverage: none
      - name: Pin lowest swagger-php + Laravel 12 / Testbench 10
        run: |
          composer require --no-update --no-interaction \
            "zircote/swagger-php:^5.8" \
            "laravel/framework:12.*" \
            "orchestra/testbench:^10.0"
      - name: Install dependencies
        run: composer update --prefer-dist --no-interaction --no-progress
      - name: Verify resolved swagger-php major
        run: |
          resolved=$(composer show zircote/swagger-php | awk '/^versions / { print $4 }' | sed 's/^v//' | cut -d. -f1)
          if [ "$resolved" != "5" ]; then
            echo "::error::Expected swagger-php 5.x but composer resolved $resolved.x"
            exit 1
          fi
      - name: Run tests
        run: vendor/bin/pest --no-coverage
```

- [ ] **Step 5: Add the CHANGELOG entry**

In `CHANGELOG.md`, under `[Unreleased]` → `### Changed`, add:
```markdown
- Widened `zircote/swagger-php` support to `^5.8 || ^6`, so apps pinning swagger-php 5.x
  (e.g. those that self-generate OpenAPI from `#[OA\*]` attributes) can install the package.
  A dedicated CI job runs the full suite against swagger-php 5.8 to keep the lower bound valid.
```

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock .github/workflows/tests.yml CHANGELOG.md src/
git commit -m "deps: widen zircote/swagger-php to ^5.8 || ^6 with CI guard"
```

---

## Self-Review

**Spec coverage:**
- Drop direct phpdocumentor type stack → Tasks A1–A7 (A7 removes it). ✓
- Reusable `Support\PhpDoc\` parser → Task A1 (`DocBlockParser` + `ParsedDocBlock`, with `tagValues()` for future tags). ✓
- `Support\Types\TypeNodeResolver` via symfony/type-info → Task A2. ✓
- Re-back the three consumers, preserving caches/trait-context/closure-fallback → A3 (return), A4 (throws, trait + closure watch-points), A5 (lint rule). ✓
- Service-provider bindings → A6. ✓
- swagger-php widen, spike-first, phpdoc-parser range as go/no-go, permanent lowest-deps CI cell, ship-A-regardless fallback → B8 (spike + decision), B9 (widen + CI). ✓

**Placeholder scan:** No TBD/TODO. The only deliberately-conditional content is B8's decision branches and B9's "apply spike fixes" — both specify exact commands and the decision rule, not vague placeholders. ✓

**Type consistency:** `DocBlockParser::parse(): ParsedDocBlock`; `ParsedDocBlock::returnType(): ?TypeNode` / `throwsTypes(): list<TypeNode>` / `tagValues(string): list<PhpDocTagValueNode>`; `TypeNodeResolver::genericValueClass(TypeNode, Reflector): ?string` / `throwsClasses(TypeNode, Reflector): list<string>`. These names are used identically in A3/A4/A5 consumers and the A1/A2 tests. `::create()` factories exist on all three services and are used by the provider (A6) and tests. ✓

**Cross-task ordering note:** Feature tests that resolve the extractors through the container only pass after A6 (bindings) — that is why A3–A5 run their *unit* tests (via `::create()`) and the first full `composer test` is in A6. Intentional, not a gap.
