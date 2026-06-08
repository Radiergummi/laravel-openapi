<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Generator\Pipeline;

use OpenApi\Annotations\OpenApi;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Generator\GenerationContext;
use Radiergummi\OpenApi\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Support\Generator\SpecPipeline;
use Radiergummi\OpenApi\Support\Spec\SpecRegistry;
use stdClass;

uses()->group('openapi');

afterEach(function (): void {
    OpenApiExtensions::flush();
});

it('runs registered stages in registration order', function (): void {
    $log = new stdClass();
    $log->entries = [];

    $make = static fn(string $name): SpecStage => new class ($log, $name) implements SpecStage {
        public function __construct(private stdClass $log, private string $name) {}

        public function apply(OpenApi $document, GenerationContext $context): void
        {
            $this->log->entries[] = $this->name;
        }
    };

    $registry = new OpenApiRegistry();
    $registry->addStage(StageA::class);
    $registry->addStage(StageB::class);
    $registry->addStage(StageC::class);

    app()->bind(StageA::class, fn() => $make('a'));
    app()->bind(StageB::class, fn() => $make('b'));
    app()->bind(StageC::class, fn() => $make('c'));

    $pipeline = new SpecPipeline(registry: $registry, container: app());

    $spec = app(SpecRegistry::class)->default();
    $pipeline->run($spec, 'testing');

    expect($log->entries)->toBe(['a', 'b', 'c']);
});

it('skips excluded stages while preserving the order of the rest', function (): void {
    $log = new stdClass();
    $log->entries = [];

    $make = static fn(string $name): SpecStage => new class ($log, $name) implements SpecStage {
        public function __construct(private stdClass $log, private string $name) {}

        public function apply(OpenApi $document, GenerationContext $context): void
        {
            $this->log->entries[] = $this->name;
        }
    };

    $registry = new OpenApiRegistry();
    $registry->addStage(StageA::class);
    $registry->addStage(StageB::class);
    $registry->addStage(StageC::class);

    app()->bind(StageA::class, fn() => $make('a'));
    app()->bind(StageB::class, fn() => $make('b'));
    app()->bind(StageC::class, fn() => $make('c'));

    $pipeline = new SpecPipeline(registry: $registry, container: app());

    $spec = app(SpecRegistry::class)->default();
    $pipeline->withoutStage(StageB::class)->run($spec, 'testing');

    expect($log->entries)->toBe(['a', 'c']);
});

it('withoutStage returns a new pipeline, leaving the original intact', function (): void {
    $log = new stdClass();
    $log->entries = [];

    $make = static fn(string $name): SpecStage => new class ($log, $name) implements SpecStage {
        public function __construct(private stdClass $log, private string $name) {}

        public function apply(OpenApi $document, GenerationContext $context): void
        {
            $this->log->entries[] = $this->name;
        }
    };

    $registry = new OpenApiRegistry();
    $registry->addStage(StageA::class);
    $registry->addStage(StageB::class);

    app()->bind(StageA::class, fn() => $make('a'));
    app()->bind(StageB::class, fn() => $make('b'));

    $pipeline = new SpecPipeline(registry: $registry, container: app());

    $filtered = $pipeline->withoutStage(StageB::class);
    expect($filtered)->not->toBe($pipeline);

    $spec = app(SpecRegistry::class)->default();
    $pipeline->run($spec, 'testing');

    expect($log->entries)->toBe(['a', 'b']);
});

class StageA implements SpecStage
{
    public function apply(OpenApi $document, GenerationContext $context): void {}
}

class StageB implements SpecStage
{
    public function apply(OpenApi $document, GenerationContext $context): void {}
}

class StageC implements SpecStage
{
    public function apply(OpenApi $document, GenerationContext $context): void {}
}
