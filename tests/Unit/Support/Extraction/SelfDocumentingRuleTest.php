<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Contracts\Extraction\SelfDocumentingRule;
use Radiergummi\OpenApi\Core\Support\ValidationRulesToSchema;
use Radiergummi\OpenApi\Lint\ArrayFindingsCollector;
use Radiergummi\OpenApi\Support\Extraction\RuleDocumentation;

uses()->group('extractors', 'openapi');

final class FakeIsbnRule implements SelfDocumentingRule
{
    public function documentation(): RuleDocumentation
    {
        return new RuleDocumentation(
            description: 'ISBN-10 or ISBN-13.',
            type: 'string',
            pattern: '^(\\d{9}[\\dX]|\\d{13})$',
            minLength: 10,
            maxLength: 17,
        );
    }
}

it('applies self-documenting rule metadata to the field descriptor', function (): void {
    $collector = new ArrayFindingsCollector();
    $mapper = new ValidationRulesToSchema($collector);

    $result = $mapper->process([
        'isbn' => ['required', new FakeIsbnRule()],
    ]);

    $field = $result['fields']['isbn'];

    expect($field->required)->toBeTrue()
        ->and($field->type)->toBe('string')
        ->and($field->pattern)->toBe('^(\\d{9}[\\dX]|\\d{13})$')
        ->and($field->minLength)->toBe(10)
        ->and($field->maxLength)->toBe(17)
        ->and($field->description)->toBe('ISBN-10 or ISBN-13.');

    expect($collector->all())->toBeEmpty();
});

final class FakeIntegerRule implements SelfDocumentingRule
{
    public function documentation(): RuleDocumentation
    {
        return new RuleDocumentation(type: 'integer');
    }
}

it('does not overwrite a sibling rule\'s already-set type', function (): void {
    $collector = new ArrayFindingsCollector();
    $mapper = new ValidationRulesToSchema($collector);

    $result = $mapper->process([
        'value' => ['string', new FakeIntegerRule()],
    ]);

    expect($result['fields']['value']->type)->toBe('string');
});

final class FakeDescriptionRule implements SelfDocumentingRule
{
    public function __construct(private readonly string $text) {}

    public function documentation(): RuleDocumentation
    {
        return new RuleDocumentation(description: $this->text);
    }
}

it('appends descriptions across multiple self-documenting rules', function (): void {
    $collector = new ArrayFindingsCollector();
    $mapper = new ValidationRulesToSchema($collector);

    $result = $mapper->process([
        'thing' => [new FakeDescriptionRule('First.'), new FakeDescriptionRule('Second.')],
    ]);

    expect($result['fields']['thing']->description)
        ->toContain('First.')
        ->and($result['fields']['thing']->description)
        ->toContain('Second.');
});
