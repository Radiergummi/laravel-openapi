<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Registration;

uses()->group('openapi', 'lint');

it('every lint rule class references a fixHint argument', function (): void {
    $ruleClasses = Registration::RULES;

    expect($ruleClasses)->not->toBeEmpty();

    $missing = [];

    foreach ($ruleClasses as $ruleClass) {
        try {
            $file = new ReflectionClass($ruleClass)->getFileName();

            if ($file && file_exists($file)) {
                $source = file_get_contents($file);

                if (!str_contains($source, 'fixHint:')) {
                    $missing[] = $ruleClass;
                }
            }
        } catch (Throwable) {
            // Skip rules that can't be reflected
        }
    }

    expect($missing)->toBeEmpty(
        'Rules without fixHint: ' . implode(', ', $missing),
    );
});
