<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Radiergummi\OpenApi\Core\Routing\DocCommentParser;

uses()->group('routing', 'openapi');

beforeEach(function (): void {
    $this->parser = new DocCommentParser();
});

it('returns empty DocComment for a blank docblock', function (): void {
    $doc = $this->parser->parse('/** */');

    expect($doc->isEmpty())->toBeTrue()
        ->and($doc->summary)->toBeNull()
        ->and($doc->description)->toBeNull();
});

it('returns only summary for a single-paragraph docblock', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * Retrieves all projects.
         */
        PHPDOC);

    expect($doc->summary)->toBe('Retrieves all projects.')
        ->and($doc->description)->toBeNull();
});

it('joins multi-line summary paragraphs with a space', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * Retrieves all projects accessible to the
         * current user.
         */
        PHPDOC);

    expect($doc->summary)->toBe('Retrieves all projects accessible to the current user.')
        ->and($doc->description)->toBeNull();
});

it('splits summary and description at the first blank line', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * Short summary line.
         *
         * Longer description paragraph that explains
         * the operation in detail.
         */
        PHPDOC);

    expect($doc->summary)->toBe('Short summary line.')
        ->and($doc->description)->toBe("Longer description paragraph that explains\nthe operation in detail.");
});

it('preserves blank lines inside the description', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * Summary.
         *
         * First paragraph.
         *
         * Second paragraph.
         */
        PHPDOC);

    expect($doc->description)->toBe("First paragraph.\n\nSecond paragraph.");
});

it('strips line-leading PHPDoc tags', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * Summary text.
         *
         * @throws RuntimeException
         * @param int $foo
         */
        PHPDOC);

    expect($doc->summary)->toBe('Summary text.')
        ->and($doc->description)->toBeNull();
});

it('returns null summary/description for a docblock containing only non-throws tags', function (): void {
    $doc = $this->parser->parse(<<<'PHPDOC'
        /**
         * @param int $foo
         */
        PHPDOC);

    expect($doc->isEmpty())->toBeTrue();
});

it('returns the same DocComment instance for identical input (memoised)', function (): void {
    $comment = <<<'PHPDOC'
        /**
         * Summary line.
         *
         * Description paragraph.
         */
        PHPDOC;

    $first = $this->parser->parse($comment);
    $second = $this->parser->parse($comment);

    expect($second)->toBe($first);
});

it('does not collide caches across different inputs', function (): void {
    $a = $this->parser->parse(<<<'PHPDOC'
        /**
         * First.
         */
        PHPDOC);
    $b = $this->parser->parse(<<<'PHPDOC'
        /**
         * Second.
         */
        PHPDOC);

    expect($a->summary)->toBe('First.')
        ->and($b->summary)->toBe('Second.');
});
