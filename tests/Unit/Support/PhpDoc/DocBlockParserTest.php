<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\PhpDoc;

use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Radiergummi\OpenApi\Support\PhpDoc\DocBlockParser;

function makeDocBlockParser(): DocBlockParser
{
    return DocBlockParser::create();
}

it('exposes the @return type node', function (): void {
    $comment = "/**\n * @return Foo<Bar>\n */";

    $type = makeDocBlockParser()->parse($comment)->returnType();

    expect($type)->toBeInstanceOf(GenericTypeNode::class);
});

it('returns null when there is no @return tag', function (): void {
    $comment = "/**\n * @throws RuntimeException\n */";

    expect(makeDocBlockParser()->parse($comment)->returnType())->toBeNull();
});

it('exposes every @throws type node in order', function (): void {
    $comment = "/**\n * @throws A\n * @throws B|C\n */";

    $types = makeDocBlockParser()->parse($comment)->throwsTypes();

    expect($types)->toHaveCount(2)
        ->and($types[0])->toBeInstanceOf(IdentifierTypeNode::class)
        ->and($types[1])->toBeInstanceOf(UnionTypeNode::class);
});

it('exposes raw value nodes for arbitrary tags', function (): void {
    $comment = "/**\n * @param Foo \$bar a thing\n */";

    expect(makeDocBlockParser()->parse($comment)->tagValues('@param'))->toHaveCount(1);
});

it('exposes the @deprecated reason text', function (): void {
    $comment = "/**\n * @deprecated Use bar() instead.\n */";

    expect(makeDocBlockParser()->parse($comment)->deprecation())->toBe('Use bar() instead.');
});

it('returns an empty string for a bare @deprecated tag', function (): void {
    $comment = "/**\n * @deprecated\n */";

    expect(makeDocBlockParser()->parse($comment)->deprecation())->toBe('');
});

it('returns null when there is no @deprecated tag', function (): void {
    $comment = "/**\n * @return Foo\n */";

    expect(makeDocBlockParser()->parse($comment)->deprecation())->toBeNull();
});

it('returns an empty doc block for an unparseable comment', function (): void {
    $parsed = makeDocBlockParser()->parse('not a doc comment');

    expect($parsed->returnType())->toBeNull()
        ->and($parsed->throwsTypes())->toBe([]);
});

it('memoises identical comments', function (): void {
    $parser = makeDocBlockParser();
    $comment = "/**\n * @return Foo<Bar>\n */";

    expect($parser->parse($comment))->toBe($parser->parse($comment));
});
