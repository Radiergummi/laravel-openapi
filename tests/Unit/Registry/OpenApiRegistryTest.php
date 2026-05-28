<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Registry;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Contracts\Registry\ErrorResponseContributor;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

uses()->group('openapi');

it('stores and returns stage class-strings in registration order', function (): void {
    $registry = new OpenApiRegistry();

    $registry->addStage(FirstRegistryStage::class);
    $registry->addStage(SecondRegistryStage::class);
    $registry->addStage(FirstRegistryStage::class); // duplicate registration is a no-op

    expect($registry->stages())->toBe([FirstRegistryStage::class, SecondRegistryStage::class]);
});

it('returns an empty array when no stages have been registered', function (): void {
    $registry = new OpenApiRegistry();

    expect($registry->stages())->toBe([]);
});

class FirstRegistryStage implements SpecStage
{
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void {}
}

class SecondRegistryStage implements SpecStage
{
    public function apply(OA\OpenApi $doc, GenerationContext $ctx): void {}
}

it('registers and returns error response contributors', function (): void {
    $registry = new OpenApiRegistry();

    $registry->addErrorResponseContributor(FakeContributorA::class);
    $registry->addErrorResponseContributor(FakeContributorB::class);
    // Duplicate is ignored, matching the resolver behaviour:
    $registry->addErrorResponseContributor(FakeContributorA::class);

    expect($registry->errorResponseContributors())
        ->toBe([FakeContributorA::class, FakeContributorB::class]);
});

it('returns an empty array when no contributors have been registered', function (): void {
    $registry = new OpenApiRegistry();

    expect($registry->errorResponseContributors())->toBe([]);
});

class FakeContributorA implements ErrorResponseContributor
{
    public function contribute(ActionDescriptor $descriptor): array
    {
        return [];
    }
}

class FakeContributorB implements ErrorResponseContributor
{
    public function contribute(ActionDescriptor $descriptor): array
    {
        return [];
    }
}
