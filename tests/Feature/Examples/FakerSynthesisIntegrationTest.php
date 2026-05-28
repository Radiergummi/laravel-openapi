<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use OpenApi\Annotations as OA;
use OpenApi\Generator;
use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Attributes\RequestField;
use Radiergummi\OpenApi\Core\Examples\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Core\Extraction\SchemaFromFormRequest;
use Radiergummi\OpenApi\Core\Extraction\ValidationRulesToSchema;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Support\Generator\ComponentSchemaRegistry;

uses()->group('openapi', 'examples');

// A minimal FormRequest with an email field that has no authored example.
// The synthesiser should recognise format:email and supply one.
/**
 * @return class-string<FormRequest>
 */
function fakerSynthesisMakeEmailFormRequest(): string
{
    return new class () extends FormRequest {
        public function rules(): array
        {
            return [
                'contact_email' => ['required', 'string', 'email'],
            ];
        }
    }::class;
}

function fakerSynthesisBuildSchema(bool $synthesise): OA\Schema
{
    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $registry,
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: $synthesise, seed: 1234),
        findings: new ArrayFindingsCollector(),
    );

    $builder->build(fakerSynthesisMakeEmailFormRequest());

    return $registry->all()[0];
}

/**
 * @return array<string, OA\Property>
 */
function fakerSynthesisPropertiesByName(OA\Schema $schema): array
{
    $out = [];

    foreach ((array) $schema->properties as $property) {
        if ($property instanceof OA\Property) {
            $out[$property->property] = $property;
        }
    }

    return $out;
}

// region Synthesis enabled

it('synthesises an email example for a field with format:email', function (): void {
    $schema = fakerSynthesisBuildSchema(synthesise: true);
    $props  = fakerSynthesisPropertiesByName($schema);

    expect($props)->toHaveKey('contact_email');

    $example = $props['contact_email']->example;

    expect($example)->toBeString()
        ->and($example)->toContain('@');
});

it('example is deterministic across runs with the same seed', function (): void {
    $schema1 = fakerSynthesisBuildSchema(synthesise: true);
    $props1  = fakerSynthesisPropertiesByName($schema1);

    $schema2 = fakerSynthesisBuildSchema(synthesise: true);
    $props2  = fakerSynthesisPropertiesByName($schema2);

    expect($props1['contact_email']->example)
        ->toBe($props2['contact_email']->example);
});

// endregion

// region Synthesis disabled

it('leaves example unset when synthesis is disabled', function (): void {
    $schema = fakerSynthesisBuildSchema(synthesise: false);
    $props  = fakerSynthesisPropertiesByName($schema);

    expect($props)->toHaveKey('contact_email');

    $example = $props['contact_email']->example;

    // No authored example and synthesis off — the property should carry no example.
    expect($example)->toBe(Generator::UNDEFINED);
});

// endregion

// region Authored example wins

it('does not overwrite an authored example from a #[RequestField] attribute', function (): void {
    $request = new class () extends FormRequest {
        #[RequestField(example: 'authored@example.com')]
        public const string PARAM_EMAIL = 'contact_email';

        public function rules(): array
        {
            return [
                self::PARAM_EMAIL => ['required', 'string', 'email'],
            ];
        }
    };

    $registry = new ComponentSchemaRegistry();
    $builder  = new SchemaFromFormRequest(
        rulesMapper: new ValidationRulesToSchema(),
        registry: $registry,
        logger: new NullLogger(),
        synthesiser: new FakerExampleSynthesiser(enabled: true, seed: 1234),
        findings: new ArrayFindingsCollector(),
    );

    $builder->build($request::class);

    $schema = $registry->all()[0];
    $props  = fakerSynthesisPropertiesByName($schema);

    expect($props['contact_email']->example)->toBe('authored@example.com');
});

// endregion
