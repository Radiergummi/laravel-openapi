<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\Fractal\Attributes;

use Radiergummi\OpenApi\Plugins\Fractal\Attributes\TransformerField;

it('exposes its name and forwards schema fields to the descriptor', function (): void {
    $field = new TransformerField('title', type: 'string', maxLength: 120);

    expect($field->name)->toBe('title')
        ->and($field->type)->toBe('string')
        ->and($field->descriptor()->maxLength)->toBe(120);
});
