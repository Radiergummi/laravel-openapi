<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Support\Extraction\FakerExampleSynthesiser;
use Radiergummi\OpenApi\Support\Extraction\FieldDescriptor;

uses()->group('generator', 'examples', 'openapi');

beforeEach(function (): void {
    $this->synth = new FakerExampleSynthesiser(seed: 1234);
});

it('returns null when synthesis is disabled', function (): void {
    $synth = new FakerExampleSynthesiser(seed: 1234, enabled: false);

    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->format = 'email';

    expect($synth->synthesise('contact_email', $descriptor))->toBeNull();
});

it('synthesises an email when format is email', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->format = 'email';

    $value = $this->synth->synthesise('contact_email', $descriptor);

    expect($value)->toBeString()
        ->and($value)->toContain('@');
});

it('synthesises a UUID when format is uuid', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->format = 'uuid';

    $value = $this->synth->synthesise('id', $descriptor);

    expect($value)->toBeString()
        ->and($value)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('synthesises a URL when format is uri', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->format = 'uri';

    $value = $this->synth->synthesise('homepage', $descriptor);

    expect($value)->toBeString()
        ->and($value)->toStartWith('http');
});

it('synthesises an integer within minimum/maximum', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'integer';
    $descriptor->minimum = 10;
    $descriptor->maximum = 20;

    $value = $this->synth->synthesise('count', $descriptor);

    expect($value)->toBeInt()
        ->and($value)->toBeGreaterThanOrEqual(10)
        ->and($value)->toBeLessThanOrEqual(20);
});

it('picks the first enum value when enum is set', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->enum = ['pending', 'active', 'archived'];

    $value = $this->synth->synthesise('status', $descriptor);

    expect($value)->toBe('pending');
});

it('does not synthesise an email for a non-string field whose name contains email', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'boolean';

    $value = $this->synth->synthesise('email_verified', $descriptor);

    // boolean field — must not pick up the byFieldName email match
    expect($value)->toBeBool();
});

it('does not synthesise an email for an integer field whose name contains email', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'integer';

    $value = $this->synth->synthesise('email_count', $descriptor);

    expect($value)->toBeInt();
});

it('returns null for unknown types — no lorem-ipsum leakage', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    // No format, no enum, no field-name hint.

    $value = $this->synth->synthesise('opaque', $descriptor);

    expect($value)->toBeNull();
});

it('never throws from its constructor even on unusual seed input', function (): void {
    expect(fn(): FakerExampleSynthesiser => new FakerExampleSynthesiser(seed: PHP_INT_MIN))
        ->not->toThrow(Throwable::class);
});

it('produces deterministic output for the same seed', function (): void {
    $descriptor = new FieldDescriptor();
    $descriptor->type = 'string';
    $descriptor->format = 'email';

    // Capture the first result, then construct a fresh instance with the same seed and verify it
    // produces the same value. Instance creation order matters — capture before constructing the
    // second instance so that global mt_rand state from the first create() call is accounted for.
    $first = new FakerExampleSynthesiser(seed: 42);
    $firstValue = $first->synthesise('a', $descriptor);

    $second = new FakerExampleSynthesiser(seed: 42);
    $secondValue = $second->synthesise('a', $descriptor);

    expect($firstValue)->toBe($secondValue);
});
