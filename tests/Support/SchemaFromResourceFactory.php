<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Support;

use Closure;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\ResourceToArrayReader;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\SchemaFromResource;
use Radiergummi\OpenApi\Plugins\ApiResources\Support\WrappedModelLocator;
use Radiergummi\OpenApi\Support\Extraction\EloquentModelToSchema;
use Radiergummi\OpenApi\Support\Extraction\ModelFactoryExampleReader;
use Radiergummi\OpenApi\Support\Extraction\PublicPropertyTypeReader;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;
use Radiergummi\OpenApi\Support\Generator\ExplicitClassSchema;
use Radiergummi\OpenApi\Support\Generator\JsonSchemaFromType;
use Radiergummi\OpenApi\Support\MethodBody\MethodBodyScanner;
use Radiergummi\OpenApi\Support\MethodBody\SingleReturnArrayLiteralFinder;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;
use Radiergummi\OpenApi\Support\Types\TypeNodeResolver;
use Symfony\Component\TypeInfo\TypeResolver\TypeResolver;

/**
 * Builds a container-free {@see SchemaFromResource} (and its toArray-reader dependency graph)
 * for unit tests.
 */
final class SchemaFromResourceFactory
{
    /**
     * @param null|Closure(): list<RefSchemaResolver> $refSchemaResolvers
     */
    public static function create(
        ComponentSchemaRegistry $registry,
        ?Closure $refSchemaResolvers = null,
    ): SchemaFromResource {
        return new SchemaFromResource(
            registry: $registry,
            refSchemaResolvers: $refSchemaResolvers ?? static fn(): array => [],
            toArrayReader: self::toArrayReader($registry),
            wrappedModelLocator: self::wrappedModelLocator(),
            modelToSchema: self::modelToSchema($registry),
            logger: new NullLogger(),
            explicitSchema: new ExplicitClassSchema(new NullLogger()),
        );
    }

    public static function toArrayReader(?ComponentSchemaRegistry $registry = null): ResourceToArrayReader
    {
        $registry ??= new ComponentSchemaRegistry();

        return new ResourceToArrayReader(
            returnLiteralFinder: new SingleReturnArrayLiteralFinder(new MethodBodyScanner()),
            wrappedModelLocator: self::wrappedModelLocator(),
            modelToSchema: self::modelToSchema($registry),
            publicPropertyTypeReader: new PublicPropertyTypeReader(
                jsonSchemaFromType: new JsonSchemaFromType(new NullLogger(), $registry),
                typeResolver: TypeResolver::create(),
                docBlockParser: DocBlockParser::create(),
                typeNodeResolver: TypeNodeResolver::create(),
            ),
        );
    }

    public static function wrappedModelLocator(): WrappedModelLocator
    {
        return new WrappedModelLocator(
            docBlockParser: DocBlockParser::create(),
            typeNodeResolver: TypeNodeResolver::create(),
        );
    }

    private static function modelToSchema(ComponentSchemaRegistry $registry): EloquentModelToSchema
    {
        $logger = new NullLogger();

        return new EloquentModelToSchema(
            registry: $registry,
            jsonSchemaFromType: new JsonSchemaFromType($logger, $registry),
            typeResolver: TypeResolver::create(),
            typeNodeResolver: TypeNodeResolver::create(),
            docBlockParser: DocBlockParser::create(),
            logger: $logger,
            factoryExampleReader: new ModelFactoryExampleReader(seed: 1234, logger: $logger),
        );
    }
}
