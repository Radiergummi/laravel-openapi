<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Schema;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DocblockInvoice;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\InvoiceController;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhpInheritance\TraitChainController;

function makeScanner(?string $path = null): AuthoredAnnotationScanner
{
    return new AuthoredAnnotationScanner(
        [$path ?? dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp'],
        recordingLogger(),
    );
}

function componentsFixtureDir(): string
{
    return dirname(__DIR__, 3) . '/Fixtures/SwaggerPhpComponents';
}

it('indexes an attribute schema by its authored name', function (): void {
    $schema = makeScanner()->schemaForName('Server');

    expect($schema)
        ->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Server');
});

it('indexes an attribute schema by its declaring class', function (): void {
    $schema = makeScanner()->schemaForClass(AttributeServer::class);

    expect($schema)
        ->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Server');
});

it('indexes a docblock schema by its authored name', function (): void {
    $schema = makeScanner()->schemaForName('Invoice');

    expect($schema)
        ->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Invoice');
});

it('indexes a docblock schema by its declaring class', function (): void {
    $schema = makeScanner()->schemaForClass(DocblockInvoice::class);

    expect($schema)
        ->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Invoice');
});

it('indexes a docblock operation by its declaring controller method', function (): void {
    $operation = makeScanner()->operationForMethod(InvoiceController::class, 'show');

    expect($operation)
        ->toBeInstanceOf(Operation::class)
        ->and($operation->method)->toBe('get');
});

it('returns null for unknown schemas and operations', function (): void {
    $scanner = makeScanner();

    expect($scanner->schemaForName('Nonexistent'))
        ->toBeNull()
        ->and($scanner->schemaForClass(InvoiceController::class))->toBeNull()
        ->and($scanner->operationForMethod(InvoiceController::class, 'destroy'))->toBeNull();
});

it('degrades to empty indexes when the scan path does not exist', function (): void {
    $scanner = makeScanner('/no/such/directory');

    expect($scanner->schemaForName('Server'))
        ->toBeNull()
        ->and($scanner->operationForMethod(InvoiceController::class, 'show'))->toBeNull();
});

it('returns null without reflecting when the route controller class does not exist', function (): void {
    // The ancestry walk guards on class_exists() before reflecting.
    expect(makeScanner()->operationForMethod('Radiergummi\\OpenApi\\Tests\\Does\\Not\\Exist', 'index'))->toBeNull();
});

it('matches an operation authored on a trait reached through a trait-of-trait chain', function (): void {
    // TraitChainController uses InnerReportTrait both via OuterReportTrait and directly, so the
    // scanner's trait collection recurses into the inner trait and dedups the second visit.
    $operation = makeScanner(dirname(__DIR__, 3) . '/Fixtures/SwaggerPhpInheritance')
        ->operationForMethod(TraitChainController::class, 'reportIndex');

    expect($operation)
        ->toBeInstanceOf(Operation::class)
        ->and($operation->summary)->toBe('Authored in an inner trait');
});

it('indexes an authored @OA\Response component by its authored name', function (): void {
    $response = makeScanner(componentsFixtureDir())->responseComponentForName('NotFound');

    expect($response)
        ->toBeInstanceOf(\OpenApi\Annotations\Response::class)
        ->and((string) $response->response)->toBe('NotFound');
});

it('indexes an authored @OA\Parameter component by its authored name', function (): void {
    $parameter = makeScanner(componentsFixtureDir())->parameterComponentForName('PageParam');

    expect($parameter)
        ->toBeInstanceOf(\OpenApi\Annotations\Parameter::class)
        ->and((string) $parameter->parameter)->toBe('PageParam');
});

it('returns null for an unknown response/parameter component name', function (): void {
    $scanner = makeScanner(componentsFixtureDir());

    expect($scanner->responseComponentForName('NoSuchResponse'))->toBeNull()
        ->and($scanner->parameterComponentForName('NoSuchParameter'))->toBeNull();
});

it('does not index a ref-only response/parameter usage entry as a definition', function (): void {
    // swagger-php lands a ref-only @OA\Response/@OA\Parameter (name + ref, an alias pointing at
    // another component) in components.responses/.parameters; the !is_defined(...->ref) filter must
    // keep those usage entries out of the definition index, or a ref would resolve to the alias.
    $scanner = makeScanner(componentsFixtureDir());

    expect($scanner->responseComponentForName('AliasNotFound'))->toBeNull()
        ->and($scanner->parameterComponentForName('AliasParam'))->toBeNull();
});
