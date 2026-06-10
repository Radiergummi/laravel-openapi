<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

/**
 * Fixture constants for the class-constant resolution paths of the AST literal evaluator (#227).
 */
final class LiteralConstantsFixture
{
    public const string MESSAGE = 'Cannot update a user prospect.';

    public const string TITLE_RULES = 'required|string|max:120';

    public const string STATUS_RULE = 'in:draft,published';

    public const array NESTED_ARRAY = ['a' => 1, 'b' => ['c' => true, 'd' => null]];

    public const array CONTAINS_ENUM_CASE = ['status' => StatusFixtureEnum::Draft];
}
