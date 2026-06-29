<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Plugins\SpatieData;

use Illuminate\Support\Collection;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\DataReturnExpressionReader;
use Radiergummi\OpenApi\Plugins\SpatieData\Support\DataReturnTarget;
use Radiergummi\OpenApi\Tests\Fixtures\ScalarOnlyData;
use ReflectionMethod;

use function collect;

uses()->group('openapi', 'plugin:spatie-data');

/** A plain class: not a Data subclass. */
class NotData
{
    /**
     * @param array<mixed> $items
     */
    public static function collect(array $items): Collection
    {
        return collect($items);
    }
}

/** Parse-only fixtures; never invoked, but kept type-correct so the suite stays PHPStan-clean. */
class ReaderFixture
{
    public function collection(): Collection
    {
        return ScalarOnlyData::collect(collect([]));
    }

    public function single(): ScalarOnlyData
    {
        return new ScalarOnlyData('name', 1);
    }

    public function assignedSingle(): ScalarOnlyData
    {
        $data = new ScalarOnlyData('name', 1);

        return $data;
    }

    public function nonData(): Collection
    {
        return NotData::collect([]);
    }

    public function degrade(): Collection
    {
        return collect([]);
    }

    public function conditional(bool $flag): ScalarOnlyData
    {
        if ($flag) {
            return new ScalarOnlyData('a', 1);
        }

        return new ScalarOnlyData('b', 2);
    }
}

function readTarget(string $method): ?DataReturnTarget
{
    return DataReturnExpressionReader::create()->read(
        new ReflectionMethod(ReaderFixture::class, $method),
    );
}

it('reads DataClass::collect() as a collection target', function (): void {
    $target = readTarget('collection');

    expect($target)->not->toBeNull()
        ->and($target->dataClass)->toBe(ScalarOnlyData::class)
        ->and($target->isCollection)->toBeTrue();
});

it('reads new DataClass() as a single target', function (): void {
    $target = readTarget('single');

    expect($target)->not->toBeNull()
        ->and($target->dataClass)->toBe(ScalarOnlyData::class)
        ->and($target->isCollection)->toBeFalse();
});

it('resolves a variable assigned a single new DataClass()', function (): void {
    $target = readTarget('assignedSingle');

    expect($target)->not->toBeNull()
        ->and($target->isCollection)->toBeFalse();
});

it('returns null when the collect() class is not a Data subclass', function (): void {
    expect(readTarget('nonData'))->toBeNull();
});

it('returns null when the body is not a recognised factory shape', function (): void {
    expect(readTarget('degrade'))->toBeNull();
});

it('returns null for a conditional / multiple-return body', function (): void {
    expect(readTarget('conditional'))->toBeNull();
});
