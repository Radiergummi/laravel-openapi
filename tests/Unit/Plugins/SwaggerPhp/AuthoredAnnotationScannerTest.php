<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SwaggerPhp;

use OpenApi\Annotations\Operation;
use OpenApi\Annotations\Schema;
use Radiergummi\OpenApi\Plugins\SwaggerPhp\Support\AuthoredAnnotationScanner;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\AttributeServer;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\DocblockInvoice;
use Radiergummi\OpenApi\Tests\Fixtures\SwaggerPhp\InvoiceController;

function makeScanner(?string $path = null): AuthoredAnnotationScanner
{
    return new AuthoredAnnotationScanner(
        [$path ?? dirname(__DIR__, 3) . '/Fixtures/SwaggerPhp'],
        recordingLogger(),
    );
}

it('indexes an attribute schema by its authored name', function (): void {
    $schema = makeScanner()->schemaForName('Server');

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Server');
});

it('indexes an attribute schema by its declaring class', function (): void {
    $schema = makeScanner()->schemaForClass(AttributeServer::class);

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Server');
});

it('indexes a docblock schema by its authored name', function (): void {
    $schema = makeScanner()->schemaForName('Invoice');

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Invoice');
});

it('indexes a docblock schema by its declaring class', function (): void {
    $schema = makeScanner()->schemaForClass(DocblockInvoice::class);

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->schema)->toBe('Invoice');
});

it('indexes a docblock operation by its declaring controller method', function (): void {
    $operation = makeScanner()->operationForMethod(InvoiceController::class, 'show');

    expect($operation)->toBeInstanceOf(Operation::class)
        ->and($operation->method)->toBe('get');
});

it('returns null for unknown schemas and operations', function (): void {
    $scanner = makeScanner();

    expect($scanner->schemaForName('Nonexistent'))->toBeNull()
        ->and($scanner->schemaForClass(InvoiceController::class))->toBeNull()
        ->and($scanner->operationForMethod(InvoiceController::class, 'destroy'))->toBeNull();
});

it('degrades to empty indexes when the scan path does not exist', function (): void {
    $scanner = makeScanner('/no/such/directory');

    expect($scanner->schemaForName('Server'))->toBeNull()
        ->and($scanner->operationForMethod(InvoiceController::class, 'show'))->toBeNull();
});
