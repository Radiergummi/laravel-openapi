<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Fixtures;

use function array_merge;
use function count;

/**
 * Method-body shapes for the single-return-array-literal finder (issue #12).
 */
class ReturnLiteralFixture
{
    /** @return array<string, mixed> */
    public function singleLiteral(): array
    {
        return [
            'id' => 1,
            'name' => 'Widget',
        ];
    }

    /** @return array<string, mixed> */
    public function precededByStatements(): array
    {
        $threshold = 5;
        unset($threshold);

        return ['ready' => true];
    }

    /** @return array<string, mixed> */
    public function closureValueWithInnerReturn(): array
    {
        return [
            'computed' => (static function (): int {
                return 1;
            })(),
        ];
    }

    /** @return array<string, mixed> */
    public function earlyReturnGuard(bool $condition): array
    {
        if ($condition) {
            return [];
        }

        return ['id' => 1];
    }

    /** @return array<string, mixed> */
    public function variableReturn(): array
    {
        $payload = ['id' => 1];

        return $payload;
    }

    /** @return array<string, mixed> */
    public function conditionalVariableAssignment(bool $condition): array
    {
        $payload = ['id' => 1];

        if ($condition) {
            $payload = ['name' => 'Widget'];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function variableAssignedTwice(): array
    {
        $payload = ['id' => 1];
        $payload = ['name' => 'Widget'];

        return $payload;
    }

    /** @return array<string, mixed> */
    public function variableAssignedNonArray(): array
    {
        $payload = array_merge(['id' => 1], ['name' => 'Widget']);

        return $payload;
    }

    /** @return array<string, mixed> */
    public function emptyVariableReturn(): array
    {
        $payload = [];

        return $payload;
    }

    /** @return array<string, mixed> */
    public function conditionalMergeAfterLiteral(bool $condition): array
    {
        $payload = ['id' => 1];

        if ($condition) {
            $payload += ['name' => 'Widget'];
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    public function unconditionalArrayDimWrite(): array
    {
        $payload = ['id' => 1];
        $payload['name'] = 'Widget';

        return $payload;
    }

    /** @return array<string, mixed> */
    public function unconditionalMergeWrite(): array
    {
        $payload = ['id' => 1];
        $payload += ['name' => 'Widget'];

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function parameterReturn(array $payload): array
    {
        return $payload;
    }

    /** @return array<string, mixed> */
    public function mergedReturn(): array
    {
        return array_merge(['id' => 1], ['name' => 'Widget']);
    }

    /** @return array<string, mixed> */
    public function beyondStatementLimit(): array
    {
        $a = 1;
        $b = 2;
        $c = 3;
        $d = 4;
        $e = 5;
        $f = 6;
        $g = 7;
        $h = 8;
        $i = 9;
        $j = 10;
        $values = [$a, $b, $c, $d, $e, $f, $g, $h, $i, $j];

        return ['count' => count($values)];
    }

    public function noReturn(): void
    {
        // Intentionally empty.
    }
}
