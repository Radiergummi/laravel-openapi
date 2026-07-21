<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Plugins\SwaggerPhp\Support;

use OpenApi\Annotations\AbstractAnnotation;
use OpenApi\Annotations\OpenApi;
use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Parameter;
use OpenApi\Annotations\Property;
use OpenApi\Annotations\Response;
use OpenApi\Annotations\Schema;
use OpenApi\Annotations\SecurityScheme;
use OpenApi\Annotations\Server;
use OpenApi\Annotations\Tag;
use OpenApi\Generator;
use OpenApi\Pipeline;
use OpenApi\Processors\AugmentTags;
use OpenApi\Processors\OperationId;
use Psr\Log\LoggerInterface;
use Radiergummi\OpenApi\Lint\AnnotationWalker;
use Radiergummi\OpenApi\Support\Generator\ComponentReference;
use ReflectionClass;
use Throwable;

use function array_fill_keys;
use function array_keys;
use function array_values;
use function class_exists;
use function is_array;
use function is_string;
use function ltrim;
use function property_exists;
use function Radiergummi\OpenApi\is_defined;

/**
 * Harvests hand-authored `#[OA\Schema]` / `@OA\Schema` definitions and operation-level `@OA`
 * annotations from a set of source paths via a single swagger-php pass.
 *
 * The scan is lazy and memoized; a failing scan degrades to empty indexes rather than throwing.
 *
 * @internal
 */
final class AuthoredAnnotationScanner
{
    private bool $scanned = false;

    /**
     * Authored component schemas keyed by their authored schema name.
     *
     * @var array<string, Schema>
     */
    private array $schemasByName = [];

    /**
     * Authored component schemas keyed by their declaring class (FQCN, no leading slash).
     *
     * @var array<string, Schema>
     */
    private array $schemasByClass = [];

    /**
     * Authored operations keyed by "declaringClass::method".
     *
     * @var array<string, Operation>
     */
    private array $operationsByMethod = [];

    /**
     * Authored `OA\Property` annotations keyed by declaring class, then by PHP property name.
     *
     * @var array<string, array<string, Property>>
     */
    private array $propertiesByClass = [];

    /**
     * Authored query `OA\Parameter` annotations keyed by "declaringClass::method".
     *
     * @var array<string, list<Parameter>>
     */
    private array $queryParametersByMethod = [];

    /**
     * Authored reusable `OA\Response` component definitions keyed by their component name.
     *
     * @var array<string, Response>
     */
    private array $responseComponentsByName = [];

    /**
     * Authored reusable `OA\Parameter` component definitions keyed by their component name.
     *
     * @var array<string, Parameter>
     */
    private array $parameterComponentsByName = [];

    /**
     * Leading-slash-free FQCN declaring each response/parameter component, keyed by component name.
     *
     * @var array<string, string>
     */
    private array $componentClassesByName = [];

    /**
     * Every `OA\Response`/`OA\Parameter` entry in the component pool, including `ref`-only aliases.
     * The dangling-ref guard walks these so a component pointed at by a surviving alias is kept.
     *
     * @var list<Parameter|Response>
     */
    private array $componentPool = [];

    private DocumentAnnotations $documentAnnotations;

    /**
     * @param list<string> $scanPaths Directories (or files) to scan for authored annotations.
     */
    public function __construct(
        private readonly array $scanPaths,
        private readonly LoggerInterface $logger,
    ) {
        $this->documentAnnotations = new DocumentAnnotations();
    }

    public function schemaForName(string $name): ?Schema
    {
        $this->scan();

        return $this->schemasByName[$name] ?? null;
    }

    /**
     * The authored reusable `OA\Response` component definition with the given name, or null.
     */
    public function responseComponentForName(string $name): ?Response
    {
        $this->scan();

        return $this->responseComponentsByName[$name] ?? null;
    }

    /**
     * The authored reusable `OA\Parameter` component definition with the given name, or null.
     */
    public function parameterComponentForName(string $name): ?Parameter
    {
        $this->scan();

        return $this->parameterComponentsByName[$name] ?? null;
    }

    /**
     * The document-level annotations the application authored (info / servers / security schemes /
     * root tags), for the document-annotation migration rule. Empty when none were authored.
     */
    public function documentAnnotations(): DocumentAnnotations
    {
        $this->scan();

        return $this->documentAnnotations;
    }

    private function scan(): void
    {
        if ($this->scanned) {
            return;
        }

        $document = $this->generate();
        $this->scanned = true;

        if ($document === null) {
            return;
        }

        $this->indexSchemas($document);
        $this->indexOperations($document);
        $this->indexResponseComponents($document);
        $this->indexParameterComponents($document);
        $this->indexDocumentAnnotations($document);
    }

    /**
     * Indexes reusable `@OA\Response(response="X")` component definitions by their component name.
     * Skips a `ref`-only entry, which is a usage (an operation pointing at a component), not a
     * definition.
     */
    private function indexResponseComponents(OpenApi $document): void
    {
        if (!is_defined($document->components) || !is_array($document->components->responses)) {
            return;
        }

        foreach ($document->components->responses as $response) {
            if (!$response instanceof Response || !is_defined($response->response)) {
                continue;
            }

            $this->componentPool[] = $response;

            // A `ref`-only entry is a usage (an alias pointing at another component), not a definition.
            if (!is_defined($response->ref)) {
                $name = (string) $response->response;
                $this->responseComponentsByName[$name] = $response;
                $this->recordComponentClass($name, $response);
            }
        }
    }

    /**
     * Indexes reusable `@OA\Parameter(parameter="Y")` component definitions by their component name.
     * Skips a `ref`-only entry (a usage, not a definition).
     */
    private function indexParameterComponents(OpenApi $document): void
    {
        if (!is_defined($document->components) || !is_array($document->components->parameters)) {
            return;
        }

        foreach ($document->components->parameters as $parameter) {
            if (!$parameter instanceof Parameter || !is_defined($parameter->parameter)) {
                continue;
            }

            $this->componentPool[] = $parameter;

            if (!is_defined($parameter->ref)) {
                $name = (string) $parameter->parameter;
                $this->parameterComponentsByName[$name] = $parameter;
                $this->recordComponentClass($name, $parameter);
            }
        }
    }

    private function recordComponentClass(string $name, Response|Parameter $component): void
    {
        $class = $this->declaringClassOf($component);

        if ($class !== null) {
            $this->componentClassesByName[$name] = $class;
        }
    }

    /**
     * Captures the document-root annotations the application authored, keeping only the kinds that
     * map to a `config/openapi.php` key. Reads from the already-parsed root, guarding each property
     * with {@see is_defined()} so swagger-php defaults never count as authored.
     */
    private function indexDocumentAnnotations(OpenApi $document): void
    {
        $servers = [];

        foreach (is_array($document->servers) ? $document->servers : [] as $server) {
            if ($server instanceof Server) {
                $servers[] = $server;
            }
        }

        $rootTags = [];

        foreach (is_array($document->tags) ? $document->tags : [] as $tag) {
            if ($tag instanceof Tag) {
                $rootTags[] = $tag;
            }
        }

        $this->documentAnnotations = new DocumentAnnotations(
            info: is_defined($document->info) ? $document->info : null,
            servers: $servers,
            securitySchemes: $this->indexSecuritySchemes($document),
            rootTags: $rootTags,
        );
    }

    /**
     * @return array<string, SecurityScheme> keyed by authored scheme name
     */
    private function indexSecuritySchemes(OpenApi $document): array
    {
        if (!is_defined($document->components) || !is_array($document->components->securitySchemes)) {
            return [];
        }

        $schemes = [];

        foreach ($document->components->securitySchemes as $scheme) {
            if ($scheme instanceof SecurityScheme && is_defined($scheme->securityScheme)) {
                $schemes[$scheme->securityScheme] = $scheme;
            }
        }

        return $schemes;
    }

    private function generate(): ?OpenApi
    {
        try {
            return new Generator($this->logger)
                ->withProcessorPipeline($this->withoutGeneratedProcessors(...))
                ->generate($this->scanPaths, validate: false);
        } catch (Throwable $exception) {
            $this->logger->warning(
                "Failed to scan for authored swagger-php annotations: {$exception->getMessage()}",
            );

            return null;
        }
    }

    /**
     * Trims two default processors from the scan pipeline: the operation-id synthesiser, so only
     * truly authored operationIds are indexed rather than swagger-php-generated hashes, and
     * AugmentTags, which prunes any root tag no operation references (that would hide an
     * authored-but-unused `@OA\Tag` the migration rule needs to see).
     *
     * The parameter carries no native type. swagger-php 6.4 hands the callback an
     * `OpenApi\Utils\Pipeline`, while 5.8 to 6.3 hand it `OpenApi\Pipeline` (which 6.4 keeps as a
     * subclass of the former), so a native type would break one end of the supported range. The
     * PHPDoc names `OpenApi\Pipeline` because that is the one class present on every version;
     * `remove()` mutates the pipeline in place, so nothing needs to be returned.
     *
     * @param Pipeline $pipeline
     */
    private function withoutGeneratedProcessors($pipeline): void
    {
        $pipeline
            ->remove(OperationId::class)
            ->remove(AugmentTags::class);
    }

    private function indexSchemas(OpenApi $document): void
    {
        /** @noinspection NotOptimalIfConditionsInspection */
        if (
            !is_defined($document->components)
            || !is_array($document->components->schemas)
        ) {
            return;
        }

        foreach ($document->components->schemas as $schema) {
            if (!$schema instanceof Schema) {
                continue;
            }

            if (is_defined($schema->schema)) {
                $this->schemasByName[$schema->schema] = $schema;
            }

            $class = $this->declaringClassOf($schema);

            if ($class !== null) {
                $this->schemasByClass[$class] = $schema;
                $this->indexProperties($schema, $class);
            }
        }
    }

    /**
     * Indexes the schema's authored `OA\Property` annotations by the PHP property they were
     * declared on. The `property` field carries the output key, which equals the PHP property name
     * for the conventional (no key-remapping) case the replacement rule targets.
     */
    private function indexProperties(Schema $schema, string $class): void
    {
        if (!is_array($schema->properties)) {
            return;
        }

        foreach ($schema->properties as $property) {
            if ($property instanceof Property && is_defined($property->property)) {
                $this->propertiesByClass[$class][$property->property] = $property;
            }
        }
    }

    /**
     * Returns the leading-slash-free FQCN that physically declares the annotation.
     *
     * Trait-declared annotations carry `_context->trait` (not `_context->class`), so they are
     * indexed under the trait name; {@see operationForMethod()} resolves them via ancestry walk.
     */
    public function declaringClassOf(Schema|Operation|Response|Parameter $annotation): ?string
    {
        $context = $annotation->_context;
        $fullyQualified = $context->fullyQualifiedName($context->class ?? $context->trait);

        return $fullyQualified === null ? null : ltrim($fullyQualified, '\\');
    }

    private function indexOperations(OpenApi $document): void
    {
        if (!is_array($document->paths)) {
            return;
        }

        foreach ($document->paths as $path) {
            foreach ($path->operations() as $operation) {
                $class = $this->declaringClassOf($operation);
                $method = $operation->_context->method;

                if ($class === null || $method === null) {
                    continue;
                }

                $key = $this->methodKey($class, $method);
                $this->operationsByMethod[$key] = $operation;
                $this->indexQueryParameters($operation, $key);
            }
        }
    }

    private function indexQueryParameters(Operation $operation, string $methodKey): void
    {
        if (!is_array($operation->parameters)) {
            return;
        }

        foreach ($operation->parameters as $parameter) {
            if ($parameter instanceof Parameter && is_defined($parameter->in) && $parameter->in === 'query') {
                $this->queryParametersByMethod[$methodKey][] = $parameter;
            }
        }
    }

    private function methodKey(string $class, string $method): string
    {
        return sprintf('%s::%s', ltrim($class, '\\'), $method);
    }

    public function schemaForClass(string $class): ?Schema
    {
        $this->scan();

        return $this->schemasByClass[ltrim($class, '\\')] ?? null;
    }

    /**
     * The authored `OA\Property` annotations on a class, keyed by PHP property name. Empty when the
     * class authored none.
     *
     * @return array<string, Property>
     */
    public function propertiesForClass(string $class): array
    {
        $this->scan();

        return $this->propertiesByClass[ltrim($class, '\\')] ?? [];
    }

    /**
     * The authored query `OA\Parameter` annotations on a controller method, resolving annotations
     * declared on a parent class or trait via the same ancestry walk as {@see operationForMethod()}.
     *
     * @return list<Parameter>
     */
    public function queryParametersForMethod(string $class, string $method): array
    {
        $this->scan();

        $exact = $this->queryParametersByMethod[$this->methodKey($class, $method)] ?? null;

        if ($exact !== null) {
            return $exact;
        }

        foreach ($this->declaringAncestry($class, $method) as $ancestor) {
            $match = $this->queryParametersByMethod[$this->methodKey($ancestor, $method)] ?? null;

            if ($match !== null) {
                return $match;
            }
        }

        return [];
    }

    public function operationForMethod(string $class, string $method): ?Operation
    {
        $this->scan();

        $exact = $this->operationsByMethod[$this->methodKey($class, $method)] ?? null;

        if ($exact !== null) {
            return $exact;
        }

        // Walk ancestry when the annotation was indexed under a parent/trait rather than the
        // concrete controller class. The exact-match above ensures this runs only on a miss.
        foreach ($this->declaringAncestry($class, $method) as $ancestor) {
            $match = $this->operationsByMethod[$this->methodKey($ancestor, $method)] ?? null;

            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * Parent classes and used traits (recursively) that declare `$method`, nearest-first.
     *
     * @return list<string>
     */
    private function declaringAncestry(string $class, string $method): array
    {
        if (!class_exists($class)) {
            return [];
        }

        $reflection = new ReflectionClass($class);
        $candidates = [];

        for ($parent = $reflection->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
            if ($parent->hasMethod($method)) {
                $candidates[$parent->getName()] = true;
            }
        }

        foreach ($this->reachableTraits($reflection) as $trait) {
            if ($trait->hasMethod($method)) {
                $candidates[$trait->getName()] = true;
            }
        }

        return array_keys($candidates);
    }

    /**
     * All traits reachable from `$class`, recursively including trait-of-trait.
     *
     * @param ReflectionClass<object> $class
     *
     * @return list<ReflectionClass<object>>
     */
    private function reachableTraits(ReflectionClass $class): array
    {
        $collected = [];

        for ($current = $class; $current !== false; $current = $current->getParentClass()) {
            foreach ($current->getTraits() as $trait) {
                $this->collectTraits($trait, $collected);
            }
        }

        return array_values($collected);
    }

    /**
     * @param ReflectionClass<object>                $trait
     * @param array<string, ReflectionClass<object>> $collected
     */
    private function collectTraits(ReflectionClass $trait, array &$collected): void
    {
        if (isset($collected[$trait->getName()])) {
            return;
        }

        $collected[$trait->getName()] = $trait;

        foreach ($trait->getTraits() as $nested) {
            $this->collectTraits($nested, $collected);
        }
    }

    /**
     * Whether `$componentName` is referenced by any authored annotation other than the schema
     * declared by `$excludingClass`. Used as a dangling-ref guard before removal.
     */
    public function isSchemaReferencedByOtherAuthored(string $componentName, string $excludingClass): bool
    {
        $this->scan();

        $target = ComponentReference::pointer($componentName);
        $ownSchema = $this->schemasByClass[ltrim($excludingClass, '\\')] ?? null;

        foreach ($this->schemasByName as $schema) {
            if ($schema !== $ownSchema && $this->referencesRef($schema, $target)) {
                return true;
            }
        }

        foreach ($this->operationsByMethod as $operation) {
            if ($this->referencesRef($operation, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The authored reusable `OA\Response` component definitions, keyed by component name.
     *
     * @return array<string, Response>
     */
    public function responseComponentDefinitions(): array
    {
        $this->scan();

        return $this->responseComponentsByName;
    }

    /**
     * The authored reusable `OA\Parameter` component definitions, keyed by component name.
     *
     * @return array<string, Parameter>
     */
    public function parameterComponentDefinitions(): array
    {
        $this->scan();

        return $this->parameterComponentsByName;
    }

    /**
     * The leading-slash-free FQCN declaring the named response/parameter component, or null.
     */
    public function componentClassFor(string $name): ?string
    {
        $this->scan();

        return $this->componentClassesByName[$name] ?? null;
    }

    /**
     * Authored operations that `$ref` the given component pointer, keyed by "declaringClass::method".
     *
     * @return array<string, Operation>
     */
    public function operationsReferencing(string $pointer): array
    {
        $this->scan();

        $matches = [];

        foreach ($this->operationsByMethod as $key => $operation) {
            if ($this->referencesRef($operation, $pointer)) {
                $matches[$key] = $operation;
            }
        }

        return $matches;
    }

    /**
     * Whether the component pointer is `$ref`-ed by an authored annotation other than the component
     * itself and the given referencing operations. Walks schemas, the other component definitions
     * and aliases (so a transitive component → component reference keeps the target alive), and any
     * operation not among `$excludingOperationKeys` (those are the verified-equivalent consumers the
     * rule is collapsing). Used as the dangling-ref guard before removing a component definition.
     *
     * @param list<string> $excludingOperationKeys "declaringClass::method" keys to ignore
     */
    public function isComponentReferencedByOtherAuthored(
        string $pointer,
        string $componentName,
        array $excludingOperationKeys = [],
    ): bool {
        $this->scan();

        $ownDefinition = $this->responseComponentsByName[$componentName]
            ?? $this->parameterComponentsByName[$componentName]
            ?? null;

        foreach ($this->schemasByName as $schema) {
            if ($this->referencesRef($schema, $pointer)) {
                return true;
            }
        }

        foreach ($this->componentPool as $component) {
            if ($component !== $ownDefinition && $this->referencesRef($component, $pointer)) {
                return true;
            }
        }

        $excluded = array_fill_keys($excludingOperationKeys, true);

        foreach ($this->operationsByMethod as $key => $operation) {
            if (!isset($excluded[$key]) && $this->referencesRef($operation, $pointer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `$target` appears as a `$ref` anywhere in the annotation's object tree.
     */
    private function referencesRef(AbstractAnnotation $node, string $target): bool
    {
        $found = false;

        AnnotationWalker::walk($node, static function (AbstractAnnotation $annotation) use ($target, &$found): void {
            if (property_exists($annotation, 'ref') && is_string($annotation->ref) && $annotation->ref === $target) {
                $found = true;
            }
        });

        return $found;
    }
}
