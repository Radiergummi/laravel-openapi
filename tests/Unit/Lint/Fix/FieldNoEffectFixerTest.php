<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Rules\FieldNoEffect;
use Radiergummi\OpenApi\Support\Extraction\PayloadParameterScanner;
use Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix\NoEffectFieldFixtureData;
use Radiergummi\OpenApi\Tests\Support\AttributeFixFixture;

uses()->group('openapi', 'lint', 'fix');

it('removes a no-op field attribute from a promoted constructor parameter', function (): void {
    $result = AttributeFixFixture::run(
        new FieldNoEffect(new PayloadParameterScanner(indirectionClasses: [])),
        NoEffectFieldFixtureData::class,
        'noEffect',
    );

    expect($result['fixes'])
        ->toHaveCount(1)
        ->and($result['after'])->toBe(
            <<<'PHP'
                <?php
                
                declare(strict_types=1);
                
                namespace Radiergummi\OpenApi\Tests\Fixtures\Lint\Fix;
                
                use Radiergummi\OpenApi\Attributes\RequestField;
                use Spatie\LaravelData\Data;
                
                final class NoEffectFieldFixtureData extends Data
                {
                    public function __construct(
                        public string $noEffect,
                    ) {}
                }
                PHP,
        );
});
