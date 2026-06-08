<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Registry;

use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Lint\Rule;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\OperationConventionResolver;
use Radiergummi\OpenApi\Contracts\Registry\PrimaryResponseResolver;
use Radiergummi\OpenApi\Contracts\Registry\QueryParameterResolver;
use Radiergummi\OpenApi\Contracts\Registry\RefSchemaResolver;
use Radiergummi\OpenApi\Contracts\Registry\RequestSchemaResolver;

use function in_array;

/**
 * The assembled inventory of OpenAPI schema resolvers and lint rules.
 *
 * Built once at boot: core registers its built-ins, each enabled plugin registers its
 * contributions, then `config` extras are appended. Entries are class-strings; instances are
 * resolved from the container when consumed.
 *
 * Once {@see \Radiergummi\OpenApi\Support\Generator\BaselineRegistration::assemble()} has finished
 * building the registry it calls {@see seal()}: the registry is build-once, then read-only. Sealing
 * makes that the *enforced* invariant the stage pipeline's ordering relies on — every legitimate
 * registration funnels through `assemble()` before the seal, so an out-of-band `addX()` on the
 * resolved singleton (e.g. from a service provider booting later) fails loudly instead of silently
 * appending past the terminal stages.
 */
final class OpenApiRegistry
{
    /**
     * Whether {@see seal()} has been called. Once true, every `addX()` method throws.
     */
    private bool $sealed = false;
    /**
     * All registered request schema resolvers.
     *
     * @var list<class-string<RequestSchemaResolver>>
     */
    public private(set) array $requestSchemaResolvers = [];

    /**
     * All registered ref schema resolvers.
     *
     * @var list<class-string<RefSchemaResolver>>
     */
    public private(set) array $refSchemaResolvers = [];

    /**
     * All registered query parameter resolvers.
     *
     * @var list<class-string<QueryParameterResolver>>
     */
    public private(set) array $queryParameterResolvers = [];

    /**
     * All registered primary response resolvers.
     *
     * Primary response resolvers are used to extract OpenAPI response schemas from controller
     * methods and injected Actions, i.e., the "main" response for an endpoint.
     *
     * @var list<class-string<PrimaryResponseResolver>>
     */
    public private(set) array $primaryResponseResolvers = [];

    /**
     * All registered operation convention resolvers.
     *
     * Operation convention resolvers derive conventional operation defaults — a success status
     * code and/or a default summary — from a route's Tier-0 signals (resourceful action name,
     * HTTP verb, controller name).
     *
     * @var list<class-string<OperationConventionResolver>>
     */
    public private(set) array $operationConventionResolvers = [];

    /**
     * All registered payload classes.
     *
     * Payload classes are base classes or interfaces whose subtypes are treated as request-payload
     * DTOs by the OpenAPI core. When a controller method or injected Action is type-hinted with
     * one of these, Core introspects its public properties to generate OpenAPI request schemas.
     *
     * @var list<class-string>
     */
    public private(set) array $payloadClasses = [];

    /**
     * All registered error response resolvers.
     *
     * These are used to generate OpenAPI responses for exceptions thrown by the application.
     *
     * @var list<class-string<ErrorResponseResolver>>
     */
    public private(set) array $errorResponseResolvers = [];

    /**
     * All registered error response contributors.
     *
     * @var list<class-string<ErrorResponseContributor>>
     */
    public private(set) array $errorResponseContributors = [];

    /**
     * All registered lint rules.
     *
     * @var list<class-string<Rule>>
     */
    public private(set) array $rules = [];

    /**
     * All registered spec stages.
     *
     * @var list<class-string<SpecStage>>
     */
    public private(set) array $stages = [];

    /**
     * Add a request schema resolver to the registry.
     *
     * @param class-string<RequestSchemaResolver> $class
     */
    public function addRequestSchemaResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->requestSchemaResolvers, strict: true)) {
            $this->requestSchemaResolvers[] = $class;
        }
    }

    /**
     * Add a ref schema resolver to the registry.
     *
     * @param class-string<RefSchemaResolver> $class
     */
    public function addRefSchemaResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->refSchemaResolvers, strict: true)) {
            $this->refSchemaResolvers[] = $class;
        }
    }

    /**
     * Add a query parameter resolver to the registry.
     *
     * @param class-string<QueryParameterResolver> $class
     */
    public function addQueryParameterResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->queryParameterResolvers, strict: true)) {
            $this->queryParameterResolvers[] = $class;
        }
    }

    /**
     * Add a primary response resolver to the registry.
     *
     * @param class-string<PrimaryResponseResolver> $class
     */
    public function addPrimaryResponseResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->primaryResponseResolvers, strict: true)) {
            $this->primaryResponseResolvers[] = $class;
        }
    }

    /**
     * Add an operation convention resolver to the registry.
     *
     * @param class-string<OperationConventionResolver> $class
     */
    public function addOperationConventionResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->operationConventionResolvers, strict: true)) {
            $this->operationConventionResolvers[] = $class;
        }
    }

    /**
     * Add a payload class to the registry.
     *
     * @param class-string $class
     */
    public function addPayloadClass(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->payloadClasses, strict: true)) {
            $this->payloadClasses[] = $class;
        }
    }

    /**
     * Add an error response resolver to the registry.
     *
     * @param class-string<ErrorResponseResolver> $class
     */
    public function addErrorResponseResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->errorResponseResolvers, strict: true)) {
            $this->errorResponseResolvers[] = $class;
        }
    }

    /**
     * Add an error response contributor to the registry.
     *
     * @param class-string<ErrorResponseContributor> $class
     */
    public function addErrorResponseContributor(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->errorResponseContributors, strict: true)) {
            $this->errorResponseContributors[] = $class;
        }
    }

    /**
     * Add a lint rule to the registry.
     *
     * @param class-string<Rule> $class
     */
    public function addRule(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->rules, strict: true)) {
            $this->rules[] = $class;
        }
    }

    /**
     * Add a stage to the registry.
     *
     * Stages are applied in registration order, so the order of calls to this method matters.
     *
     * @param class-string<SpecStage> $class
     *
     * @noinspection PhpDocMissingThrowsInspection
     */
    public function addStage(string $class): void
    {
        /** @noinspection PhpUnhandledExceptionInspection */
        $this->guardSealed();

        if (!in_array($class, $this->stages, strict: true)) {
            $this->stages[] = $class;
        }
    }

    /**
     * Seals the registry against further registration.
     *
     * Called once by the service provider after the factory closure has assembled every baseline,
     * plugin, and config contribution. Idempotent. After this, any `addX()` throws.
     *
     * @internal
     */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * Throws when the registry has been sealed.
     *
     * @throws RegistrySealedException
     *
     * @internal
     */
    private function guardSealed(): void
    {
        if ($this->sealed) {
            throw new RegistrySealedException(
                'The OpenApiRegistry is sealed and no longer accepts registrations. Register '
                . 'stages, resolvers, and rules from a Plugin listed in config(\'openapi.plugins\') '
                . '— the factory closure is the only window in which the registry accepts writes.',
            );
        }
    }
}
