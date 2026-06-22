<?php

declare(strict_types=1);

namespace Radiergummi\OpenApi\Tests\Unit\Support\Extraction;

use Psr\Log\NullLogger;
use Radiergummi\OpenApi\Support\Extraction\ColumnMetadata;
use Radiergummi\OpenApi\Support\Extraction\MigrationColumnReader;

use function dirname;

uses()->group('openapi');

/** Reader over the fixture migrations directory. */
function migrationReader(): MigrationColumnReader
{
    return new MigrationColumnReader(
        migrationsDirectory: dirname(__DIR__, 3) . '/Fixtures/Migrations',
        logger: new NullLogger(),
    );
}

/** @return array<string, ColumnMetadata> */
function widgetColumns(): array
{
    return migrationReader()->columnsForTable('widgets');
}

it('reads uuid columns as format uuid', function (): void {
    expect(widgetColumns()['id']->format)->toBe('uuid')
        ->and(widgetColumns()['owner_id']->format)->toBe('uuid');
});

it('reads ipAddress as format ip and nullable', function (): void {
    $column = widgetColumns()['last_ip'];

    expect($column->format)->toBe('ip')
        ->and($column->nullable)->toBeTrue();
});

it('reads macAddress as a pattern', function (): void {
    expect(widgetColumns()['mac']->pattern)->toBe('^([0-9A-Fa-f]{2}[:-]){5}[0-9A-Fa-f]{2}$');
});

it('reads date and date-time formats', function (): void {
    expect(widgetColumns()['released_on']->format)->toBe('date')
        ->and(widgetColumns()['manufactured_at']->format)->toBe('date-time')
        ->and(widgetColumns()['shipped_at']->format)->toBe('date-time');
});

it('omits a format for time columns', function (): void {
    $column = widgetColumns()['opens_at'];

    expect($column->format)->toBeNull()
        ->and($column->type)->toBeNull();
});

it('reads year as integer', function (): void {
    expect(widgetColumns()['model_year']->type)->toBe('integer');
});

it('reads json as object', function (): void {
    expect(widgetColumns()['configuration']->type)->toBe('object');
});

it('reads decimal as number with multipleOf from scale', function (): void {
    $column = widgetColumns()['price'];

    expect($column->type)->toBe('number')
        ->and($column->multipleOf)->toBe(0.01);
});

it('reads string and char length as maxLength', function (): void {
    expect(widgetColumns()['name']->maxLength)->toBe(120)
        ->and(widgetColumns()['code']->maxLength)->toBe(8);
});

it('reads unsigned and auto-increment columns as minimum 0', function (): void {
    expect(widgetColumns()['quantity']->minimum)->toBe(0)
        ->and(widgetColumns()['serial']->minimum)->toBe(0)
        ->and(widgetColumns()['legacy_id']->minimum)->toBe(0);
});

it('reads enum and set members', function (): void {
    expect(widgetColumns()['size']->enum)->toBe(['small', 'medium', 'large'])
        ->and(widgetColumns()['flags']->enum)->toBe(['a', 'b']);
});

it('reads literal defaults', function (): void {
    expect(widgetColumns()['label']->hasDefault)->toBeTrue()
        ->and(widgetColumns()['label']->default)->toBe('unlabelled')
        ->and(widgetColumns()['weight']->default)->toBe(0)
        ->and(widgetColumns()['active']->default)->toBeTrue();
});

it('reads a column comment as description', function (): void {
    expect(widgetColumns()['notes']->description)->toBe('Free-form operator notes.');
});

it('skips a DB::raw default', function (): void {
    $column = widgetColumns()['expression'];

    expect($column->hasDefault)->toBeFalse()
        ->and($column->default)->toBeNull();
});

it('emits no signals for a plain string column', function (): void {
    $column = widgetColumns()['untouched'];

    expect($column->format)->toBeNull()
        ->and($column->maxLength)->toBeNull()
        ->and($column->type)->toBeNull();
});

it('reads added columns from a Schema::table block', function (): void {
    expect(widgetColumns()['slug']->maxLength)->toBe(64);
});

it('skips a ->change() alter chain', function (): void {
    // name is created at length 120; the alter to 250 via ->change() must not overwrite it.
    expect(widgetColumns()['name']->maxLength)->toBe(120);
});

it('skips a dynamic table name block', function (): void {
    expect(migrationReader()->columnsForTable('dynamic_table'))->toBe([]);
});

it('skips a dynamic column name but reads its literal siblings', function (): void {
    $columns = migrationReader()->columnsForTable('degrades');

    expect($columns)->not->toHaveKey('computed')
        ->and($columns)->toHaveKey('reference')
        ->and($columns['reference']->format)->toBe('uuid');
});

it('drops an enum with a non-literal member', function (): void {
    $columns = migrationReader()->columnsForTable('degrades');

    expect($columns['kind']->enum ?? null)->toBeNull();
});

it('emits nothing for an off-whitelist macro', function (): void {
    $columns = migrationReader()->columnsForTable('degrades');

    expect($columns)->not->toHaveKey('shape');
});

it('skips an unparseable migration file without throwing', function (): void {
    $directory = sys_get_temp_dir() . '/migration-reader-' . uniqid();
    mkdir($directory);
    file_put_contents($directory . '/2024_01_01_000000_broken.php', "<?php\n\nthis is not valid php {{{");
    file_put_contents(
        $directory . '/2024_01_02_000000_create_gadgets_table.php',
        "<?php\n\nuse Illuminate\\Database\\Schema\\Blueprint;\n"
        . "use Illuminate\\Support\\Facades\\Schema;\n\n"
        . "Schema::create('gadgets', function (Blueprint \$table): void {\n"
        . "    \$table->uuid('id');\n});",
    );

    $reader = new MigrationColumnReader(migrationsDirectory: $directory, logger: new NullLogger());

    // The broken file is skipped; the valid sibling still reads.
    expect($reader->columnsForTable('gadgets')['id']->format)->toBe('uuid');

    unlink($directory . '/2024_01_01_000000_broken.php');
    unlink($directory . '/2024_01_02_000000_create_gadgets_table.php');
    rmdir($directory);
});

it('returns an empty index when the migrations directory is absent', function (): void {
    $reader = new MigrationColumnReader(
        migrationsDirectory: dirname(__DIR__, 3) . '/Fixtures/Migrations/does-not-exist',
        logger: new NullLogger(),
    );

    expect($reader->columnsForTable('widgets'))->toBe([]);
});

it('parses each file once across repeated lookups', function (): void {
    $reader = migrationReader();

    $first = $reader->columnsForTable('widgets');
    $second = $reader->columnsForTable('widgets');

    // Same memoised array instance content; a second call must not re-parse.
    expect($second)->toBe($first);
});
