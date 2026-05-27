<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license       MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Core\Registry;

use function in_array;

/**
 * The assembled inventory of OpenAPI schema resolvers and lint rules.
 *
 * Built once at boot: core registers its built-ins, each enabled plugin registers its
 * contributions, then `config` extras are appended. Entries are class-strings; instances are
 * resolved from the container when consumed.
 */
final class OpenApiRegistry
{
    /**
     * @var list<class-string<RequestSchemaResolver>>
     */
    private array $requestSchemaResolvers = [];

    /**
     * @var list<class-string<RefSchemaResolver>>
     */
    private array $refSchemaResolvers = [];

    /**
     * @var list<class-string<QueryParameterResolver>>
     */
    private array $queryParameterResolvers = [];

    /**
     * @var list<class-string<PrimaryResponseResolver>>
     */
    private array $primaryResponseResolvers = [];

    /**
     * Base classes/interfaces whose subtypes Core treats as request-payload DTOs — the class
     * type-hinted on a controller method or injected Action, whose public properties model request
     * fields. Plugins register their payload base types here.
     *
     * @var list<class-string>
     */
    private array $payloadClasses = [];

    /**
     * @var list<class-string<ErrorResponseResolver>>
     */
    private array $errorResponseResolvers = [];

    /**
     * @var list<class-string>
     */
    private array $rules = [];

    /**
     * @var list<class-string<\Radiergummi\OpenApi\Core\Generator\Pipeline\SpecStage>>
     */
    private array $stages = [];

    /**
     * @param class-string<RequestSchemaResolver> $class
     */
    public function addRequestSchemaResolver(string $class): void
    {
        if (!in_array($class, $this->requestSchemaResolvers, strict: true)) {
            $this->requestSchemaResolvers[] = $class;
        }
    }

    /**
     * @param class-string<RefSchemaResolver> $class
     */
    public function addRefSchemaResolver(string $class): void
    {
        if (!in_array($class, $this->refSchemaResolvers, strict: true)) {
            $this->refSchemaResolvers[] = $class;
        }
    }

    /**
     * @param class-string<QueryParameterResolver> $class
     */
    public function addQueryParameterResolver(string $class): void
    {
        if (!in_array($class, $this->queryParameterResolvers, strict: true)) {
            $this->queryParameterResolvers[] = $class;
        }
    }

    /**
     * @param class-string<PrimaryResponseResolver> $class
     */
    public function addPrimaryResponseResolver(string $class): void
    {
        if (!in_array($class, $this->primaryResponseResolvers, strict: true)) {
            $this->primaryResponseResolvers[] = $class;
        }
    }

    /**
     * @param class-string $class
     */
    public function addPayloadClass(string $class): void
    {
        if (!in_array($class, $this->payloadClasses, strict: true)) {
            $this->payloadClasses[] = $class;
        }
    }

    /**
     * @param class-string<ErrorResponseResolver> $class
     */
    public function addErrorResponseResolver(string $class): void
    {
        if (!in_array($class, $this->errorResponseResolvers, strict: true)) {
            $this->errorResponseResolvers[] = $class;
        }
    }

    /**
     * @param class-string $class
     */
    public function addRule(string $class): void
    {
        if (!in_array($class, $this->rules, strict: true)) {
            $this->rules[] = $class;
        }
    }

    /**
     * @param class-string<\Radiergummi\OpenApi\Core\Generator\Pipeline\SpecStage> $class
     */
    public function addStage(string $class): void
    {
        if (!in_array($class, $this->stages, strict: true)) {
            $this->stages[] = $class;
        }
    }

    /**
     * @return list<class-string<\Radiergummi\OpenApi\Core\Generator\Pipeline\SpecStage>>
     */
    public function stages(): array
    {
        return $this->stages;
    }

    /**
     * Returns the list of all registered request schema resolvers.
     *
     * @return list<class-string<RequestSchemaResolver>>
     */
    public function requestSchemaResolvers(): array
    {
        return $this->requestSchemaResolvers;
    }

    /**
     * Returns the list of all registered ref schema resolvers.
     *
     * @return list<class-string<RefSchemaResolver>>
     */
    public function refSchemaResolvers(): array
    {
        return $this->refSchemaResolvers;
    }

    /**
     * Returns the list of all registered query parameter resolvers.
     *
     * @return list<class-string<QueryParameterResolver>>
     */
    public function queryParameterResolvers(): array
    {
        return $this->queryParameterResolvers;
    }

    /**
     * Returns the list of all registered primary response resolvers.
     * Primary response resolvers are used to extract OpenAPI response schemas from controller
     * methods and injected Actions, i.e., the "main" response for an endpoint.
     *
     * @return list<class-string<PrimaryResponseResolver>>
     */
    public function primaryResponseResolvers(): array
    {
        return $this->primaryResponseResolvers;
    }

    /**
     * Returns the list of all registered payload classes.
     *
     * Payload classes are base classes or interfaces whose subtypes are treated as request-payload
     * DTOs by the OpenAPI core. When a controller method or injected Action is type-hinted with
     * one of these, Core introspects its public properties to generate OpenAPI request schemas.
     *
     * @return list<class-string>
     */
    public function payloadClasses(): array
    {
        return $this->payloadClasses;
    }

    /**
     * Returns the list of all registered error response resolvers.
     *
     * These are used to generate OpenAPI responses for exceptions thrown by the application.
     *
     * @return list<class-string<ErrorResponseResolver>>
     */
    public function errorResponseResolvers(): array
    {
        return $this->errorResponseResolvers;
    }

    /**
     * Returns the list of all registered lint rules.
     *
     * @return list<class-string>
     */
    public function rules(): array
    {
        return $this->rules;
    }
}
