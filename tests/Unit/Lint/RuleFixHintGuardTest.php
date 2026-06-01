<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Plugins\Core\CorePlugin;

uses()->group('openapi', 'lint');

it('every lint rule class references a fixHint argument', function (): void {
    $ruleClasses = CorePlugin::RULES;

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
