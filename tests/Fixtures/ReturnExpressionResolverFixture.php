<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use Exception;
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

    /**
     * @param list<stdClass> $items
     */
    public function foreachValueRebind(array $items): stdClass
    {
        $subject = new stdClass();

        foreach ($items as $subject) {
            // reassigns $subject each iteration
        }

        return $subject;
    }

    /**
     * @param array<string, stdClass> $items
     */
    public function foreachKeyRebind(array $items): mixed
    {
        $subject = new stdClass();

        foreach ($items as $subject => $value) {
            // key target reassigns $subject to the (string) key
        }

        return $subject;
    }

    /**
     * @param list<stdClass> $items
     */
    public function arrayDestructuringRebind(array $items): stdClass
    {
        $subject = new stdClass();

        [$subject] = $items;

        return $subject;
    }

    public function referenceAliasRebind(stdClass $other): stdClass
    {
        $subject = new stdClass();

        $subject = &$other;

        return $subject;
    }

    public function incrementRebind(): int
    {
        $subject = 1;

        $subject++;

        return $subject;
    }

    public function decrementRebind(): int
    {
        $subject = 1;

        --$subject;

        return $subject;
    }

    public function catchRebind(): mixed
    {
        $subject = new stdClass();

        try {
            throw new Exception('boom');
        } catch (Exception $subject) {
            // catch capture reassigns $subject to the exception
        }

        return $subject;
    }

    public function staticRebind(): stdClass
    {
        $subject = new stdClass();

        static $subject;

        return $subject;
    }

    public function globalRebind(): stdClass
    {
        $subject = new stdClass();

        global $subject;

        return $subject;
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
