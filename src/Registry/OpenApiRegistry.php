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
 * Assembled inventory of resolvers, stages, and lint rules.
 *
 * Built once by {@see \Radiergummi\OpenApi\Support\Generator\BaselineRegistration::assemble()},
 * then sealed. Out-of-band registrations after {@see seal()} throw {@see RegistrySealedException}.
 */
final class OpenApiRegistry
{
    /** @var list<class-string<RequestSchemaResolver>> */
    public private(set) array $requestSchemaResolvers = [];

    /** @var list<class-string<RefSchemaResolver>> */
    public private(set) array $refSchemaResolvers = [];

    /** @var list<class-string<QueryParameterResolver>> */
    public private(set) array $queryParameterResolvers = [];

    /** @var list<class-string<PrimaryResponseResolver>> */
    public private(set) array $primaryResponseResolvers = [];

    /** @var list<class-string<OperationConventionResolver>> */
    public private(set) array $operationConventionResolvers = [];

    /**
     * Base classes / interfaces whose subtypes are treated as request-payload DTOs.
     *
     * @var list<class-string>
     */
    public private(set) array $payloadClasses = [];

    /** @var list<class-string<ErrorResponseResolver>> */
    public private(set) array $errorResponseResolvers = [];

    /** @var list<class-string<ErrorResponseContributor>> */
    public private(set) array $errorResponseContributors = [];

    /** @var list<class-string<Rule>> */
    public private(set) array $rules = [];

    /** @var list<class-string<SpecStage>> */
    public private(set) array $stages = [];

    /** True once {@see seal()} has been called; every `addX()` throws afterward. */
    private bool $sealed = false;

    /** @param class-string<RequestSchemaResolver> $class */
    public function addRequestSchemaResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->requestSchemaResolvers, strict: true)) {
            $this->requestSchemaResolvers[] = $class;
        }
    }

    /**
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

    /** @param class-string<RefSchemaResolver> $class */
    public function addRefSchemaResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->refSchemaResolvers, strict: true)) {
            $this->refSchemaResolvers[] = $class;
        }
    }

    /** @param class-string<QueryParameterResolver> $class */
    public function addQueryParameterResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->queryParameterResolvers, strict: true)) {
            $this->queryParameterResolvers[] = $class;
        }
    }

    /** @param class-string<PrimaryResponseResolver> $class */
    public function addPrimaryResponseResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->primaryResponseResolvers, strict: true)) {
            $this->primaryResponseResolvers[] = $class;
        }
    }

    /** @param class-string<OperationConventionResolver> $class */
    public function addOperationConventionResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->operationConventionResolvers, strict: true)) {
            $this->operationConventionResolvers[] = $class;
        }
    }

    /** @param class-string $class */
    public function addPayloadClass(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->payloadClasses, strict: true)) {
            $this->payloadClasses[] = $class;
        }
    }

    /** @param class-string<ErrorResponseResolver> $class */
    public function addErrorResponseResolver(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->errorResponseResolvers, strict: true)) {
            $this->errorResponseResolvers[] = $class;
        }
    }

    /** @param class-string<ErrorResponseContributor> $class */
    public function addErrorResponseContributor(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->errorResponseContributors, strict: true)) {
            $this->errorResponseContributors[] = $class;
        }
    }

    /** @param class-string<Rule> $class */
    public function addRule(string $class): void
    {
        $this->guardSealed();

        if (!in_array($class, $this->rules, strict: true)) {
            $this->rules[] = $class;
        }
    }

    /**
     * Stages are applied in registration order.
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
     * Seals the registry; idempotent. After this, any `addX()` throws.
     *
     * @internal
     */
    public function seal(): void
    {
        $this->sealed = true;
    }
}
