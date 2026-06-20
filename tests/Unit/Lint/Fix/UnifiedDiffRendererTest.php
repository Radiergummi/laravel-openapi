<?php

declare(strict_types=1);

use Radiergummi\OpenApi\Lint\Fix\UnifiedDiffRenderer;

uses()->group('openapi', 'lint', 'fix');

it('renders a unified diff with a file header and hunk', function (): void {
    $diff = new UnifiedDiffRenderer()->render(
        'app/Foo.php',
        "line one\nline two\nline three\n",
        "line one\nline changed\nline three\n",
    );

    expect($diff)
        ->toContain('--- a/app/Foo.php')
        ->toContain('+++ b/app/Foo.php')
        ->toContain('@@')
        ->toContain('-line two')
        ->toContain('+line changed')
        ->toContain(' line one')
        ->toContain(' line three');
});

it('returns an empty string when contents are identical', function (): void {
    $diff = new UnifiedDiffRenderer()->render('app/Foo.php', "same\n", "same\n");

    expect($diff)->toBe('');
});

it('renders pure additions and pure deletions', function (): void {
    $added = new UnifiedDiffRenderer()->render('a.php', "x\n", "x\ny\n");
    $removed = new UnifiedDiffRenderer()->render('a.php', "x\ny\n", "x\n");

    expect($added)->toContain('+y')
        ->and($removed)->toContain('-y');
});
