<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Core\Generator\Pipeline;

use OpenApi\Annotations\OpenApi;
use Radiergummi\OpenApi\Core\Extensions\OpenApiExtensions;
use Radiergummi\OpenApi\Core\Generator\Pipeline\GenerationContext;
use Radiergummi\OpenApi\Core\Generator\Pipeline\SpecPipeline;
use Radiergummi\OpenApi\Core\Generator\Pipeline\SpecStage;
use Radiergummi\OpenApi\Core\Generator\Pipeline\TransformersStage;
use Radiergummi\OpenApi\Core\Registry\OpenApiRegistry;
use Radiergummi\OpenApi\Core\Spec\SpecRegistry;
use stdClass;

uses()->group('openapi');

afterEach(function (): void {
    OpenApiExtensions::flush();
});

it('runs registry stages in registration order, then the terminal stage', function (): void {
    $log = new stdClass();
    $log->entries = [];

    $make = static fn(string $name): SpecStage => new class ($log, $name) implements SpecStage {
        public function __construct(private stdClass $log, private string $name) {}

        public function apply(OpenApi $doc, GenerationContext $ctx): void
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

    OpenApiExtensions::transformDocument(static function (OpenApi $doc) use ($log): void {
        $log->entries[] = 'terminal';
    });

    $pipeline = new SpecPipeline(
        registry: $registry,
        container: app(),
        terminalStage: app(TransformersStage::class),
    );

    $spec = app(SpecRegistry::class)->default();
    $pipeline->run($spec, 'testing');

    expect($log->entries)->toBe(['a', 'b', 'c', 'terminal']);
});

class StageA implements SpecStage
{
    public function apply(OpenApi $doc, GenerationContext $ctx): void {}
}

class StageB implements SpecStage
{
    public function apply(OpenApi $doc, GenerationContext $ctx): void {}
}

class StageC implements SpecStage
{
    public function apply(OpenApi $doc, GenerationContext $ctx): void {}
}
