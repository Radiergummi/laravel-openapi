<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Lint\Finding;
use Radiergummi\OpenApi\Core\Lint\Formatters\CliFormatter;
use Symfony\Component\Console\Output\BufferedOutput;

uses()->group('openapi', 'lint');

it('does not print spec headers when all findings belong to one spec', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(ruleId: 'rule.a', level: 0, message: 'msg', spec: 'default'),
            new Finding(ruleId: 'rule.b', level: 1, message: 'msg', spec: 'default'),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    expect($output->fetch())->not->toContain('── spec:');
});

it('does not print spec headers when no findings have a spec set', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(ruleId: 'rule.a', level: 0, message: 'msg'),
            new Finding(ruleId: 'rule.b', level: 1, message: 'msg'),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    expect($output->fetch())->not->toContain('── spec:');
});

it('groups findings by spec with a header per group', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(ruleId: 'rule.a', level: 0, message: 'msg a', spec: 'default'),
            new Finding(ruleId: 'rule.b', level: 0, message: 'msg b', spec: 'v1'),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('── spec: default ──')
        ->toContain('── spec: v1 ──');
});

it('labels null-spec findings as (pre-build) when mixed with named specs', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(ruleId: 'rule.a', level: 0, message: 'msg a'),
            new Finding(ruleId: 'rule.b', level: 0, message: 'msg b', spec: 'default'),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    expect($text)
        ->toContain('── spec: (pre-build) ──')
        ->toContain('── spec: default ──');
});

it('renders finding rule ids inside the correct spec group', function (): void {
    $output = new BufferedOutput();
    new CliFormatter()->render(
        findings: [
            new Finding(ruleId: 'rule.alpha', level: 0, message: 'msg', spec: 'default'),
            new Finding(ruleId: 'rule.beta', level: 0, message: 'msg', spec: 'v1'),
        ],
        level: 0,
        exitCode: 1,
        output: $output,
    );

    $text = $output->fetch();
    $defaultPos = strpos($text, '── spec: default ──');
    $v1Pos      = strpos($text, '── spec: v1 ──');
    $alphaPos   = strpos($text, 'rule.alpha');
    $betaPos    = strpos($text, 'rule.beta');

    expect($defaultPos)->not->toBeFalse()
        ->and($v1Pos)->not->toBeFalse()
        ->and($alphaPos)->toBeGreaterThan($defaultPos)
        ->and($alphaPos)->toBeLessThan($v1Pos)
        ->and($betaPos)->toBeGreaterThan($v1Pos);
});
