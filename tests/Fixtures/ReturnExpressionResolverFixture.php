<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use stdClass;

/**
 * Parse-only fixture exercising {@see \Radiergummi\OpenApi\Support\MethodBody\ReturnExpressionResolver}.
 * Actions are never invoked; each returns a variable resolved through its body.
 */
class ReturnExpressionResolverFixture
{
    public function singleAssignment(): stdClass
    {
        $value = new stdClass();

        return $value;
    }

    public function conditionalAssignment(bool $flag): stdClass
    {
        if ($flag) {
            $value = new stdClass();
        } else {
            $value = new stdClass();
        }

        return $value;
    }

    public function multipleAssignments(): stdClass
    {
        $value = new stdClass();
        $value = new stdClass();

        return $value;
    }

    /**
     * @param array<string, mixed> $value
     */
    public function elementWriteAfterAssignment(array $value): array
    {
        $value = ['a' => 1];
        $value['b'] = 2;

        return $value;
    }

    public function compoundAssignAfterAssignment(int $value): int
    {
        $value = 1;
        $value += 2;

        return $value;
    }

    public function noMatchingAssignment(stdClass $value): stdClass
    {
        return $value;
    }

    public function dynamicallyNamedVariable(string $name): mixed
    {
        $$name = new stdClass();

        return $$name;
    }

    public function returnsClosureNotThisMethod(): callable
    {
        $value = static function (): int {
            $inner = 1;

            return $inner;
        };

        return $value;
    }
}
