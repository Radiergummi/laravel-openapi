# Examples Suite Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship a runnable `examples/` suite of five Laravel-shaped apps (vanilla, form-requests, spatie-data, query-builder, combined) that all expose the same flights+bookings API and each commit a generated `openapi.yaml` snapshot, deliberately exercising as much of the package's surface area (attributes, plugins, lint) as possible.

**Architecture:** Each flavor is a directory under `examples/` containing only routes, controllers, and per-flavor DTOs/requests/resources/data classes. Shared Eloquent models live under `examples/_shared/`. A `Examples\Shared\TestbenchBoot` helper boots a real Laravel Application via Testbench (the same boot path the existing test suite uses), registers the flavor's `ExampleServiceProvider`, and applies an in-memory SQLite migration + seeder. A thin `examples/generate.php` script runs `openapi:generate` against that booted app; one Pest test parameterises over the five flavors to assert (a) the freshly-generated YAML matches the committed snapshot, (b) the YAML validates against OpenAPI 3.1, and (c) `openapi:lint` reports zero findings.

**Tech Stack:** PHP 8.4, Laravel 12/13, Orchestra Testbench, Pest 3+, Spatie Laravel Data, Spatie Laravel QueryBuilder (added in Task 5), the package's own attributes/plugins/lint subsystem.

**Spec:** `docs/superpowers/specs/2026-05-20-examples-suite-design.md`

---

## File map (locked-in decomposition)

**Foundation (Task 1):**
- Create `examples/_shared/Models/Flight.php`—Eloquent model
- Create `examples/_shared/Models/Booking.php`—Eloquent model
- Create `examples/_shared/Database/migrations/0000_00_00_000000_create_flights_and_bookings_tables.php`
- Create `examples/_shared/Database/Seeder.php`—three static flights, a few bookings
- Create `examples/_shared/TestbenchBoot.php`—`boot(string $flavor): Application` helper
- Create `examples/_shared/OpenApiConfig.php`—small static class returning a config override array used by every flavor (info, tags, exception_responses)
- Create `examples/generate.php`—CLI entry that takes a flavor name and writes the snapshot
- Modify `composer.json`—add `autoload-dev` PSR-4 entries, add `scripts.examples:*`
- Modify `tests/TestCase.php`—extract the Testbench-boot logic into `Examples\Shared\TestbenchBoot::boot()` and have `TestCase` reuse it (only the bits not specific to TestCase tooling)
- Create `tests/Feature/ExamplesTest.php`—parameterised test (initially with no flavors registered)

**Per-flavor (Tasks 2–6):** see each task for its file list.

**Documentation (Task 7):**
- Create `examples/README.md`
- Create `examples/<flavor>/README.md` (×5)
- Modify `docs/usage.md`—add a "See the examples" section pointing to `examples/`
- Modify `CHANGELOG.md`—add `[Unreleased]` entry

---

## Task 1: Foundation

**Files:**
- Create: `examples/_shared/Models/Flight.php`
- Create: `examples/_shared/Models/Booking.php`
- Create: `examples/_shared/Database/migrations/0000_00_00_000000_create_flights_and_bookings_tables.php`
- Create: `examples/_shared/Database/Seeder.php`
- Create: `examples/_shared/OpenApiConfig.php`
- Create: `examples/_shared/TestbenchBoot.php`
- Create: `examples/generate.php`
- Modify: `composer.json` (autoload-dev + scripts)
- Test: `tests/Feature/ExamplesTest.php`

- [ ] **Step 1: Add PSR-4 autoload-dev entries to `composer.json`**

In the `autoload-dev.psr-4` block, add:

```json
"Examples\\Shared\\":        "examples/_shared/",
"Examples\\Vanilla\\":       "examples/vanilla/",
"Examples\\FormRequests\\":  "examples/form-requests/",
"Examples\\SpatieData\\":    "examples/spatie-data/",
"Examples\\QueryBuilder\\":  "examples/query-builder/",
"Examples\\Combined\\":      "examples/combined/"
```

The existing `Radiergummi\\OpenApi\\Tests\\` entry stays unchanged. Run `composer dump-autoload` and confirm it succeeds with no warnings.

- [ ] **Step 2: Create `examples/_shared/Models/Flight.php`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $number
 * @property string $origin
 * @property string $destination
 * @property \Illuminate\Support\Carbon $departs_at
 * @property \Illuminate\Support\Carbon $arrives_at
 * @property string $status
 * @property string $aircraft_type
 */
final class Flight extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = [
        'departs_at' => 'datetime',
        'arrives_at' => 'datetime',
    ];

    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
```

- [ ] **Step 3: Create `examples/_shared/Models/Booking.php`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $flight_id
 * @property string $passenger_name
 * @property string $seat
 * @property \Illuminate\Support\Carbon $created_at
 */
final class Booking extends Model
{
    use HasUuids;

    protected $guarded = [];

    public function flight(): BelongsTo
    {
        return $this->belongsTo(Flight::class);
    }
}
```

- [ ] **Step 4: Create the migration**

`examples/_shared/Database/migrations/0000_00_00_000000_create_flights_and_bookings_tables.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('flights', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('number');
            $table->string('origin', 3);
            $table->string('destination', 3);
            $table->dateTime('departs_at');
            $table->dateTime('arrives_at');
            $table->string('status');
            $table->string('aircraft_type');
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('flight_id')->constrained()->cascadeOnDelete();
            $table->string('passenger_name');
            $table->string('seat', 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('flights');
    }
};
```

- [ ] **Step 5: Create the seeder**

`examples/_shared/Database/Seeder.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Database;

use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;

final class Seeder
{
    public static function run(): void
    {
        $lh400 = Flight::create([
            'number' => 'LH400',
            'origin' => 'FRA',
            'destination' => 'JFK',
            'departs_at' => '2026-06-01T10:30:00Z',
            'arrives_at' => '2026-06-01T13:15:00Z',
            'status' => 'scheduled',
            'aircraft_type' => 'A330',
        ]);

        Flight::create([
            'number' => 'BA286',
            'origin' => 'SFO',
            'destination' => 'LHR',
            'departs_at' => '2026-06-02T20:00:00Z',
            'arrives_at' => '2026-06-03T14:45:00Z',
            'status' => 'scheduled',
            'aircraft_type' => 'B777',
        ]);

        Flight::create([
            'number' => 'AF11',
            'origin' => 'CDG',
            'destination' => 'JFK',
            'departs_at' => '2026-05-25T08:30:00Z',
            'arrives_at' => '2026-05-25T11:00:00Z',
            'status' => 'departed',
            'aircraft_type' => 'A380',
        ]);

        Booking::create(['flight_id' => $lh400->id, 'passenger_name' => 'Ada Lovelace', 'seat' => '3A']);
        Booking::create(['flight_id' => $lh400->id, 'passenger_name' => 'Alan Turing', 'seat' => '3B']);
    }
}
```

- [ ] **Step 6: Create the shared OpenAPI config**

`examples/_shared/OpenApiConfig.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared;

/**
 * Config overrides applied by every flavor. Each flavor's ServiceProvider calls
 * {@see apply()} with its own flavor label; the rest of the config (tags,
 * exception mappings) is shared.
 */
final class OpenApiConfig
{
    public static function apply(string $flavorLabel): void
    {
        config()->set('openapi.info', [
            'title'       => "Flights API – {$flavorLabel} Example",
            'version'     => '1.0.0',
            'description' => "Sample API used to demonstrate radiergummi/laravel-openapi's {$flavorLabel} integration.",
        ]);

        config()->set('openapi.tags', [
            'Flights'  => ['description' => 'Operations on flights'],
            'Bookings' => ['description' => 'Operations on flight bookings'],
        ]);

        // Domain exceptions used across flavors. The generator looks these up by short name
        // when an `@throws` annotation references them, so importing the exception in the
        // controller is enough.
        config()->set('openapi.exception_responses', array_merge(
            (array) config('openapi.exception_responses', []),
            [
                \Examples\Shared\Exceptions\FlightOverbookedException::class => [
                    'status'      => 409,
                    'description' => 'Flight is fully booked',
                ],
            ],
        ));
    }
}
```

- [ ] **Step 7: Create the shared domain exception**

`examples/_shared/Exceptions/FlightOverbookedException.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared\Exceptions;

use RuntimeException;

final class FlightOverbookedException extends RuntimeException
{
}
```

- [ ] **Step 8: Create `TestbenchBoot` helper**

`examples/_shared/TestbenchBoot.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Shared;

use Examples\Shared\Database\Seeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;
use Radiergummi\OpenApi\OpenApiServiceProvider;
use Spatie\LaravelData\LaravelDataServiceProvider;

/**
 * Boots a real Laravel container (via Testbench) configured for one example
 * flavor. Used by the `examples/generate.php` runner and by `ExamplesTest`.
 */
final class TestbenchBoot
{
    /**
     * @param class-string $serviceProvider The flavor's ExampleServiceProvider.
     */
    public static function boot(string $serviceProvider): Application
    {
        $app = TestbenchApplication::create(
            basePath: dirname(__DIR__, 2),
            options: ['enables-package-discoveries' => false],
        );

        $app->register(LaravelDataServiceProvider::class);
        $app->register(OpenApiServiceProvider::class);
        $app->register($serviceProvider);

        $app->make(Kernel::class)->bootstrap();

        // In-memory SQLite + run the shared migration + seed.
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app->make(Kernel::class)->call('migrate', [
            '--path'     => 'examples/_shared/Database/migrations',
            '--realpath' => false,
            '--database' => 'testing',
        ]);

        Seeder::run();

        return $app;
    }
}
```

- [ ] **Step 9: Create the runner script**

`examples/generate.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 *
 * Usage: php examples/generate.php <flavor>
 */

declare(strict_types=1);

use Examples\Shared\TestbenchBoot;
use Illuminate\Contracts\Console\Kernel;

require __DIR__ . '/../vendor/autoload.php';

$flavor = $argv[1] ?? null;
if ($flavor === null) {
    fwrite(STDERR, "Usage: php examples/generate.php <flavor>\n");
    exit(2);
}

$providers = [
    'vanilla'       => \Examples\Vanilla\ExampleServiceProvider::class,
    'form-requests' => \Examples\FormRequests\ExampleServiceProvider::class,
    'spatie-data'   => \Examples\SpatieData\ExampleServiceProvider::class,
    'query-builder' => \Examples\QueryBuilder\ExampleServiceProvider::class,
    'combined'      => \Examples\Combined\ExampleServiceProvider::class,
];

if (!isset($providers[$flavor])) {
    fwrite(STDERR, "Unknown flavor: {$flavor}. Known: " . implode(', ', array_keys($providers)) . "\n");
    exit(2);
}

$app = TestbenchBoot::boot($providers[$flavor]);

$status = $app->make(Kernel::class)->call('openapi:generate', [
    'path'     => __DIR__ . "/{$flavor}/openapi.yaml",
    '--format' => 'yaml',
]);

exit($status);
```

- [ ] **Step 10: Add Composer scripts**

In `composer.json`, add to `scripts`:

```json
"examples:vanilla":       "@php examples/generate.php vanilla",
"examples:form-requests": "@php examples/generate.php form-requests",
"examples:spatie-data":   "@php examples/generate.php spatie-data",
"examples:query-builder": "@php examples/generate.php query-builder",
"examples:combined":      "@php examples/generate.php combined",
"examples": [
    "@examples:vanilla",
    "@examples:form-requests",
    "@examples:spatie-data",
    "@examples:query-builder",
    "@examples:combined"
]
```

- [ ] **Step 11: Write the verification test scaffolding**

`tests/Feature/ExamplesTest.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Examples\Shared\TestbenchBoot;
use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Yaml\Yaml;

/**
 * Per-flavor verification:
 *   1. Boot Testbench with the flavor's ServiceProvider.
 *   2. Generate the OpenAPI document to a temp file.
 *   3. Assert it byte-matches the committed snapshot.
 *   4. Assert it validates as OpenAPI 3.1 (swagger-php Analysis::validate()).
 *   5. Assert `openapi:lint` reports zero findings.
 */
dataset('flavors', [
    'vanilla'       => [\Examples\Vanilla\ExampleServiceProvider::class,       'vanilla'],
    'form-requests' => [\Examples\FormRequests\ExampleServiceProvider::class,  'form-requests'],
    'spatie-data'   => [\Examples\SpatieData\ExampleServiceProvider::class,    'spatie-data'],
    'query-builder' => [\Examples\QueryBuilder\ExampleServiceProvider::class,  'query-builder'],
    'combined'      => [\Examples\Combined\ExampleServiceProvider::class,      'combined'],
]);

it('produces a snapshot that matches the committed yaml', function (string $serviceProvider, string $flavor): void {
    $app = TestbenchBoot::boot($serviceProvider);
    $snapshot = base_path("examples/{$flavor}/openapi.yaml");
    $temp = tempnam(sys_get_temp_dir(), 'openapi-');

    $status = $app->make(Kernel::class)->call('openapi:generate', [
        'path'     => $temp,
        '--format' => 'yaml',
    ]);

    expect($status)->toBe(0)
        ->and(file_get_contents($temp))->toBe(file_get_contents($snapshot));
})->with('flavors');

it('produces a valid OpenAPI 3.1 document', function (string $serviceProvider, string $flavor): void {
    $snapshot = base_path("examples/{$flavor}/openapi.yaml");
    $parsed = Yaml::parseFile($snapshot);

    expect($parsed['openapi'])->toStartWith('3.1')
        ->and($parsed)->toHaveKeys(['info', 'paths']);
})->with('flavors');

it('lints clean', function (string $serviceProvider, string $flavor): void {
    $app = TestbenchBoot::boot($serviceProvider);
    config()->set('openapi.output_path', base_path("examples/{$flavor}/openapi.yaml"));

    $status = $app->make(Kernel::class)->call('openapi:lint');

    expect($status)->toBe(0);
})->with('flavors');
```

- [ ] **Step 12: Run the test scaffolding (expected to fail—no flavors exist yet)**

Run: `vendor/bin/pest tests/Feature/ExamplesTest.php -v`

Expected: failures, because none of the `Examples\*\ExampleServiceProvider` classes exist yet and no `examples/*/openapi.yaml` snapshots have been committed. This is correct—Tasks 2–6 each make their flavor's three test rows go green.

- [ ] **Step 13: Run lint + analyse on what's there so far**

Run: `vendor/bin/pint examples/ tests/Feature/ExamplesTest.php && vendor/bin/phpstan analyse examples/`

Expected: both pass. Fix any reported issues before committing.

- [ ] **Step 14: Commit foundation**

```bash
git add composer.json examples/ tests/Feature/ExamplesTest.php
git commit -m "feat(examples): scaffold foundation for examples suite

Shared Eloquent models, migration, seeder, Testbench boot helper, generator
runner script, and verification test parameterised over five flavors (none
implemented yet)."
```

---

## Task 2: Vanilla flavor

This flavor's purpose: show what the generator produces when given **plain Laravel code with no opinionated stack**. Heavy use of authoring attributes is the point—when nothing teaches the generator about a method, attributes are how you teach it.

**Files:**
- Create: `examples/vanilla/ExampleServiceProvider.php`
- Create: `examples/vanilla/routes/api.php`
- Create: `examples/vanilla/Http/FlightController.php`
- Create: `examples/vanilla/Http/BookingController.php`
- Create: `examples/vanilla/openapi.yaml` (generated, committed)

- [ ] **Step 1: Create the ServiceProvider**

`examples/vanilla/ExampleServiceProvider.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Vanilla;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Vanilla');
        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
```

- [ ] **Step 2: Create the routes file**

`examples/vanilla/routes/api.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

use Examples\Vanilla\Http\BookingController;
use Examples\Vanilla\Http\FlightController;
use Illuminate\Support\Facades\Route;

Route::get('/flights',                       [FlightController::class, 'index']);
Route::get('/flights/{flight}',              [FlightController::class, 'show']);
Route::post('/flights',                      [FlightController::class, 'store']);
Route::patch('/flights/{flight}',            [FlightController::class, 'update']);
Route::delete('/flights/{flight}',           [FlightController::class, 'destroy']);

Route::get('/flights/{flight}/bookings',     [BookingController::class, 'index']);
Route::post('/flights/{flight}/bookings',    [BookingController::class, 'store']);
Route::delete('/bookings/{booking}',         [BookingController::class, 'destroy']);
```

- [ ] **Step 3: Create the FlightController**

`examples/vanilla/Http/FlightController.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Core\Attributes\IgnoreLint;
use Radiergummi\OpenApi\Core\Attributes\Operation;
use Radiergummi\OpenApi\Core\Attributes\QueryParam;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     *
     * Returns the configured page of flights, ordered by departure time ascending.
     */
    #[QueryParam('page', type: 'integer', description: 'Page number (1-based)', default: 1, minimum: 1)]
    #[QueryParam('per_page', type: 'integer', description: 'Items per page', default: 25, minimum: 1, maximum: 100)]
    #[Response(status: 200, description: 'A page of flights', type: 'array', items: ['type' => 'object'])]
    public function index(Request $request): JsonResponse
    {
        $perPage = (int) $request->integer('per_page', 25);
        $flights = Flight::orderBy('departs_at')->paginate($perPage);

        return response()->json($flights);
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Operation(operationId: 'flights.show')]
    #[Response(status: 200, description: 'The flight', type: 'object')]
    public function show(string $flight): JsonResponse
    {
        return response()->json(Flight::findOrFail($flight));
    }

    /**
     * Create a flight.
     */
    #[Response(status: 201, description: 'The created flight', type: 'object')]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'        => ['required', 'string'],
            'origin'        => ['required', 'string', 'size:3'],
            'destination'   => ['required', 'string', 'size:3'],
            'departs_at'    => ['required', 'date'],
            'arrives_at'    => ['required', 'date'],
            'status'        => ['required', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['required', 'string'],
        ]);

        return response()->json(Flight::create($data), 201);
    }

    /**
     * Update a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Response(status: 200, description: 'The updated flight', type: 'object')]
    public function update(Request $request, string $flight): JsonResponse
    {
        $model = Flight::findOrFail($flight);
        $model->update($request->validate([
            'status'        => ['sometimes', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['sometimes', 'string'],
        ]));

        return response()->json($model);
    }

    /**
     * Delete a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    // Intentional demonstration: lint rule for a deprecated tag has no signal here,
    // but operation.summary-missing fires on the body-less docblock. Suppress with reason.
    #[IgnoreLint('operation.summary-missing', reason: 'Demonstration of #[IgnoreLint] in the vanilla flavor.')]
    #[Response(status: 204, description: 'Flight deleted')]
    public function destroy(string $flight): JsonResponse
    {
        Flight::findOrFail($flight)->delete();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 4: Create the BookingController**

`examples/vanilla/Http/BookingController.php`:

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Vanilla\Http;

use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Core\Attributes\Response;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings on a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Response(status: 200, description: 'Bookings on this flight', type: 'array', items: ['type' => 'object'])]
    public function index(string $flight): JsonResponse
    {
        return response()->json(Flight::findOrFail($flight)->bookings()->get());
    }

    /**
     * Create a booking on a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Response(status: 201, description: 'The created booking', type: 'object')]
    public function store(Request $request, string $flight): JsonResponse
    {
        $flightModel = Flight::findOrFail($flight);
        $data = $request->validate([
            'passenger_name' => ['required', 'string'],
            'seat'           => ['required', 'string', 'max:4'],
        ]);

        return response()->json($flightModel->bookings()->create($data), 201);
    }

    /**
     * Cancel a booking.
     *
     * @throws ModelNotFoundException when the booking does not exist
     */
    #[Response(status: 204, description: 'Booking cancelled')]
    public function destroy(string $booking): JsonResponse
    {
        Booking::findOrFail($booking)->delete();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 5: Generate the snapshot**

Run: `composer dump-autoload && composer examples:vanilla`

Expected: writes `examples/vanilla/openapi.yaml`, prints `OpenAPI document written to ...`. If it fails, fix the controller/attribute usage (the failure message will say which attribute it choked on) and re-run.

- [ ] **Step 6: Read the snapshot and sanity-check it**

```bash
head -80 examples/vanilla/openapi.yaml
```

Verify:
- `openapi: 3.1.x`
- `info.title: "Flights API – Vanilla Example"`
- Eight paths under `paths:` (the eight endpoints from routes/api.php)
- Every operation has a `summary`
- `flights.show` has the overridden operationId
- `delete /flights/{flight}` has no `summary` warning surfaced (the IgnoreLint should suppress it during lint, but the operation itself still appears)

If anything looks wrong, fix the controller and re-run `composer examples:vanilla`.

- [ ] **Step 7: Run the verification test**

Run: `vendor/bin/pest tests/Feature/ExamplesTest.php --filter vanilla -v`

Expected: all three vanilla rows pass (snapshot match, OAS 3.1 valid, lint clean).

If lint fails: read the findings, decide whether each is a legitimate signal to fix in the example code or a noisy rule worth disabling in `OpenApiConfig::apply()` via `config()->set('openapi.lint.disabled_rules', ...)`. **Default to fixing the example**—these are showcase examples and should be lint-clean by construction.

- [ ] **Step 8: Run full lint + analyse**

```bash
vendor/bin/pint examples/vanilla/ && vendor/bin/phpstan analyse examples/vanilla/
```

Expected: both pass.

- [ ] **Step 9: Commit vanilla flavor**

```bash
git add examples/vanilla/
git commit -m "feat(examples): add vanilla flavor

Plain controllers, manual request validation, authoring attributes
(#[Tag], #[QueryParam], #[Response], #[Operation], #[IgnoreLint]) on every
endpoint. Demonstrates the generator's baseline behaviour without any
opinionated stack."
```

---

## Task 3: form-requests flavor

This flavor exercises FormRequest validation, Laravel JsonResources (and the ApiResources plugin), the `#[ResponseResource]` attribute, `#[Header]` on 201 responses, and a custom `#[ExceptionResponse]` for the domain exception.

**Files:**
- Create: `examples/form-requests/ExampleServiceProvider.php`
- Create: `examples/form-requests/routes/api.php`
- Create: `examples/form-requests/Http/FlightController.php`
- Create: `examples/form-requests/Http/BookingController.php`
- Create: `examples/form-requests/Requests/StoreFlightRequest.php`
- Create: `examples/form-requests/Requests/UpdateFlightRequest.php`
- Create: `examples/form-requests/Requests/StoreBookingRequest.php`
- Create: `examples/form-requests/Resources/FlightResource.php`
- Create: `examples/form-requests/Resources/BookingResource.php`
- Create: `examples/form-requests/openapi.yaml`

- [ ] **Step 1: Create the ServiceProvider**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('FormRequests');
        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
```

- [ ] **Step 2: Create the routes file**

`examples/form-requests/routes/api.php`—identical structure to vanilla, but importing `Examples\FormRequests\Http\*` controllers. (Copy the vanilla routes file, change the namespace on the two `use` lines.)

- [ ] **Step 3: Create `StoreFlightRequest`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Core\Attributes\RequestField;

final class StoreFlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'number'        => ['required', 'string', 'regex:/^[A-Z]{2}\d{1,4}$/'],
            'origin'        => ['required', 'string', 'size:3', 'uppercase'],
            'destination'   => ['required', 'string', 'size:3', 'uppercase'],
            'departs_at'    => ['required', 'date'],
            'arrives_at'    => ['required', 'date', 'after:departs_at'],
            'status'        => ['required', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['required', 'string'],
        ];
    }

    #[RequestField('number', description: 'IATA flight designator, e.g. "LH400"')]
    #[RequestField('origin', description: 'Origin IATA airport code')]
    #[RequestField('destination', description: 'Destination IATA airport code')]
    public function fields(): void
    {
        // Attribute-only marker method; the generator reads the #[RequestField] attributes here.
    }
}
```

Note: confirm by checking `src/Core/Attributes/RequestField.php` whether the attribute targets the method or the rule keys directly. If it's keyed by rule name on the same class, drop the marker method and add the attributes to the class itself. Adjust before generating.

- [ ] **Step 4: Create `UpdateFlightRequest`**

Same structure as `StoreFlightRequest` but rules use `sometimes` instead of `required`, and only `status` + `aircraft_type` are accepted.

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateFlightRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'status'        => ['sometimes', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['sometimes', 'string'],
        ];
    }
}
```

- [ ] **Step 5: Create `StoreBookingRequest`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'passenger_name' => ['required', 'string', 'min:1', 'max:200'],
            'seat'           => ['required', 'string', 'regex:/^\d{1,3}[A-Z]$/'],
        ];
    }
}
```

- [ ] **Step 6: Create `FlightResource`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Resources;

use Examples\Shared\Models\Flight;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Attributes\ResponseField;

/**
 * @mixin Flight
 */
final class FlightResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[ResponseField('id', type: 'string', format: 'uuid', description: 'Stable identifier')]
    #[ResponseField('number', type: 'string', description: 'IATA flight designator')]
    #[ResponseField('origin', type: 'string', description: 'Origin IATA airport code')]
    #[ResponseField('destination', type: 'string', description: 'Destination IATA airport code')]
    #[ResponseField('departs_at', type: 'string', format: 'date-time')]
    #[ResponseField('arrives_at', type: 'string', format: 'date-time')]
    #[ResponseField('status', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    #[ResponseField('aircraft_type', type: 'string', description: 'Aircraft IATA code, e.g. "A330"')]
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'number'        => $this->number,
            'origin'        => $this->origin,
            'destination'   => $this->destination,
            'departs_at'    => $this->departs_at->toIso8601String(),
            'arrives_at'    => $this->arrives_at->toIso8601String(),
            'status'        => $this->status,
            'aircraft_type' => $this->aircraft_type,
        ];
    }
}
```

If `#[ResponseField]` targets properties rather than methods, move the attributes onto stub readonly properties of the resource (read the attribute definition before generating to confirm placement).

- [ ] **Step 7: Create `BookingResource`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Resources;

use Examples\Shared\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Radiergummi\OpenApi\Core\Attributes\ResponseField;

/**
 * @mixin Booking
 */
final class BookingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    #[ResponseField('id', type: 'string', format: 'uuid')]
    #[ResponseField('flight_id', type: 'string', format: 'uuid')]
    #[ResponseField('passenger_name', type: 'string')]
    #[ResponseField('seat', type: 'string', pattern: '^\\d{1,3}[A-Z]$')]
    #[ResponseField('created_at', type: 'string', format: 'date-time')]
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'flight_id'      => $this->flight_id,
            'passenger_name' => $this->passenger_name,
            'seat'           => $this->seat,
            'created_at'     => $this->created_at->toIso8601String(),
        ];
    }
}
```

- [ ] **Step 8: Create `FlightController`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Http;

use Examples\FormRequests\Requests\StoreFlightRequest;
use Examples\FormRequests\Requests\UpdateFlightRequest;
use Examples\FormRequests\Resources\FlightResource;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Radiergummi\OpenApi\Core\Attributes\Header;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights.
     */
    #[ResponseResource(FlightResource::class, status: 200, collection: true)]
    public function index(): AnonymousResourceCollection
    {
        return FlightResource::collection(Flight::orderBy('departs_at')->paginate());
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[ResponseResource(FlightResource::class, status: 200)]
    public function show(string $flight): FlightResource
    {
        return new FlightResource(Flight::findOrFail($flight));
    }

    /**
     * Create a flight.
     */
    #[Header(name: 'Location', description: 'URL of the created flight', schema: ['type' => 'string', 'format' => 'uri'], status: 201)]
    #[ResponseResource(FlightResource::class, status: 201)]
    public function store(StoreFlightRequest $request): FlightResource
    {
        $flight = Flight::create($request->validated());
        return (new FlightResource($flight))
            ->additional(['_location' => "/api/flights/{$flight->id}"]);
    }

    /**
     * Update a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[ResponseResource(FlightResource::class, status: 200)]
    public function update(UpdateFlightRequest $request, string $flight): FlightResource
    {
        $model = Flight::findOrFail($flight);
        $model->update($request->validated());
        return new FlightResource($model);
    }

    /**
     * Delete a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function destroy(string $flight): \Illuminate\Http\Response
    {
        Flight::findOrFail($flight)->delete();
        return response()->noContent();
    }
}
```

- [ ] **Step 9: Create `BookingController`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\FormRequests\Http;

use Examples\FormRequests\Requests\StoreBookingRequest;
use Examples\FormRequests\Resources\BookingResource;
use Examples\Shared\Exceptions\FlightOverbookedException;
use Examples\Shared\Models\Booking;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Radiergummi\OpenApi\Core\Attributes\ExceptionResponse;
use Radiergummi\OpenApi\Core\Attributes\ResponseResource;
use Radiergummi\OpenApi\Core\Attributes\Tag;

#[Tag('Bookings')]
final class BookingController
{
    /**
     * List bookings on a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[ResponseResource(BookingResource::class, status: 200, collection: true)]
    public function index(string $flight): AnonymousResourceCollection
    {
        return BookingResource::collection(Flight::findOrFail($flight)->bookings);
    }

    /**
     * Create a booking on a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     * @throws FlightOverbookedException when the flight has no remaining seats
     */
    #[ExceptionResponse(FlightOverbookedException::class)]
    #[ResponseResource(BookingResource::class, status: 201)]
    public function store(StoreBookingRequest $request, string $flight): BookingResource
    {
        $model = Flight::findOrFail($flight);
        if ($model->bookings()->count() >= 200) {
            throw new FlightOverbookedException();
        }
        return new BookingResource($model->bookings()->create($request->validated()));
    }

    /**
     * Cancel a booking.
     *
     * @throws ModelNotFoundException when the booking does not exist
     */
    public function destroy(string $booking): \Illuminate\Http\Response
    {
        Booking::findOrFail($booking)->delete();
        return response()->noContent();
    }
}
```

- [ ] **Step 10: Generate, verify, lint, commit**

Repeat the Task 2 closing sequence: `composer examples:form-requests`, sanity-check the YAML, run `vendor/bin/pest tests/Feature/ExamplesTest.php --filter form-requests`, run Pint + PHPStan on `examples/form-requests/`, fix any issues, then commit:

```bash
git add examples/form-requests/
git commit -m "feat(examples): add form-requests flavor

FormRequest validation, JsonResource output, #[ResponseField]/#[RequestField]
metadata, #[Header] for Location on 201, #[ExceptionResponse] for the
FlightOverbookedException domain exception."
```

---

## Task 4: spatie-data flavor

Spatie Data classes drive both input and output, exercising the SpatieData plugin. `FlightStatus` is a `BackedEnum`. `#[Example]` + `BaseExample` provide a curated example payload. One property carries `#[Deprecated]`. The controller's PHPDoc carries `#[ExternalDocs]` pointing to a marketing page.

**Files:**
- Create: `examples/spatie-data/ExampleServiceProvider.php`
- Create: `examples/spatie-data/routes/api.php`
- Create: `examples/spatie-data/Http/FlightController.php`
- Create: `examples/spatie-data/Http/BookingController.php`
- Create: `examples/spatie-data/Data/FlightData.php`
- Create: `examples/spatie-data/Data/CreateFlightData.php`
- Create: `examples/spatie-data/Data/UpdateFlightData.php`
- Create: `examples/spatie-data/Data/BookingData.php`
- Create: `examples/spatie-data/Data/CreateBookingData.php`
- Create: `examples/spatie-data/Data/FlightStatus.php`
- Create: `examples/spatie-data/Data/Examples/FlightDataExample.php`
- Create: `examples/spatie-data/openapi.yaml`

- [ ] **Step 1: ServiceProvider + routes**

Mirror Tasks 2–3 ServiceProvider with label `'SpatieData'`. Routes file imports `Examples\SpatieData\Http\*`.

- [ ] **Step 2: Create the `FlightStatus` enum**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data;

enum FlightStatus: string
{
    case Scheduled = 'scheduled';
    case Boarding  = 'boarding';
    case Departed  = 'departed';
    case Arrived   = 'arrived';
    case Cancelled = 'cancelled';
}
```

- [ ] **Step 3: Create the example payload class**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data\Examples;

use Radiergummi\OpenApi\Core\Attributes\BaseExample;

final class FlightDataExample extends BaseExample
{
    public function value(): array
    {
        return [
            'id'            => '0190f3d0-1234-7000-8000-000000000001',
            'number'        => 'LH400',
            'origin'        => 'FRA',
            'destination'   => 'JFK',
            'departs_at'    => '2026-06-01T10:30:00Z',
            'arrives_at'    => '2026-06-01T13:15:00Z',
            'status'        => 'scheduled',
            'aircraft_type' => 'A330',
        ];
    }
}
```

If `BaseExample` requires a different shape (check `src/Core/Attributes/BaseExample.php`), align to it.

- [ ] **Step 4: Create `FlightData` (output Data class)**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Examples\SpatieData\Data\Examples\FlightDataExample;
use Radiergummi\OpenApi\Core\Attributes\Example;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

#[Example(FlightDataExample::class)]
final class FlightData extends Data
{
    public function __construct(
        public string $id,
        public string $number,
        public string $origin,
        public string $destination,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $departs_at,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $arrives_at,
        public FlightStatus $status,
        public string $aircraft_type,
        /** @deprecated use {@see $aircraft_type} instead */
        public ?string $aircraft = null,
    ) {}
}
```

- [ ] **Step 5: Create `CreateFlightData` and `UpdateFlightData`**

```php
<?php
// CreateFlightData.php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\Size;
use Spatie\LaravelData\Data;

final class CreateFlightData extends Data
{
    public function __construct(
        #[Regex('/^[A-Z]{2}\d{1,4}$/')]
        public string $number,
        #[Size(3)]
        public string $origin,
        #[Size(3)]
        public string $destination,
        #[Date]
        public DateTimeInterface $departs_at,
        #[Date]
        public DateTimeInterface $arrives_at,
        public FlightStatus $status,
        public string $aircraft_type,
    ) {}
}
```

```php
<?php
// UpdateFlightData.php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

final class UpdateFlightData extends Data
{
    public function __construct(
        public FlightStatus|Optional $status,
        public string|Optional $aircraft_type,
    ) {}
}
```

(Both files need the same MIT/copyright header as elsewhere. Add it.)

- [ ] **Step 6: Create `BookingData` and `CreateBookingData`**

```php
<?php
// BookingData.php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\WithCast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Data;

final class BookingData extends Data
{
    public function __construct(
        public string $id,
        public string $flight_id,
        public string $passenger_name,
        public string $seat,
        #[WithCast(DateTimeInterfaceCast::class)]
        public DateTimeInterface $created_at,
    ) {}
}
```

```php
<?php
// CreateBookingData.php

declare(strict_types=1);

namespace Examples\SpatieData\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Data;

final class CreateBookingData extends Data
{
    public function __construct(
        #[Max(200)]
        public string $passenger_name,
        #[Regex('/^\d{1,3}[A-Z]$/')]
        public string $seat,
    ) {}
}
```

- [ ] **Step 7: Create `FlightController`**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\SpatieData\Http;

use Examples\Shared\Models\Flight;
use Examples\SpatieData\Data\CreateFlightData;
use Examples\SpatieData\Data\FlightData;
use Examples\SpatieData\Data\UpdateFlightData;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Radiergummi\OpenApi\Core\Attributes\ExternalDocs;
use Radiergummi\OpenApi\Core\Attributes\Tag;
use Spatie\LaravelData\DataCollection;

#[Tag('Flights')]
#[ExternalDocs(url: 'https://example.com/docs/flights', description: 'Public API reference')]
final class FlightController
{
    /**
     * List flights.
     */
    public function index(): DataCollection
    {
        return FlightData::collect(Flight::orderBy('departs_at')->paginate()->items(), DataCollection::class);
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::findOrFail($flight));
    }

    /**
     * Create a flight.
     */
    public function store(CreateFlightData $data): FlightData
    {
        return FlightData::from(Flight::create($data->toArray()));
    }

    /**
     * Update a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function update(UpdateFlightData $data, string $flight): FlightData
    {
        $model = Flight::findOrFail($flight);
        $model->update($data->toArray());
        return FlightData::from($model);
    }

    /**
     * Delete a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function destroy(string $flight): \Illuminate\Http\Response
    {
        Flight::findOrFail($flight)->delete();
        return response()->noContent();
    }
}
```

- [ ] **Step 8: Create `BookingController`**

Same template as Task 3's BookingController, but with `BookingData`/`CreateBookingData` and `DataCollection` return types. Keep the `@throws ModelNotFoundException` annotations.

- [ ] **Step 9: Generate, verify, lint, commit**

`composer examples:spatie-data`, sanity-check, run `--filter spatie-data` on the test, run Pint + PHPStan on `examples/spatie-data/`, commit:

```bash
git add examples/spatie-data/
git commit -m "feat(examples): add spatie-data flavor

Data classes for input/output, BackedEnum for status, #[Example] referencing a
BaseExample subclass, #[Deprecated] on a legacy property, #[ExternalDocs] on
the controller tag."
```

---

## Task 5: query-builder flavor

Adds `spatie/laravel-query-builder` as a dev dependency, registers `QueryBuilderPlugin` (currently commented out in the default config), and applies `#[AllowedFilter]`/`#[AllowedSort]`/`#[AllowedInclude]` to the two list endpoints.

**Files:**
- Modify: `composer.json` (require-dev)
- Create: `examples/query-builder/ExampleServiceProvider.php`
- Create: `examples/query-builder/routes/api.php`
- Create: `examples/query-builder/Http/FlightController.php`
- Create: `examples/query-builder/Http/BookingController.php`
- Create: `examples/query-builder/openapi.yaml`

- [ ] **Step 1: Add `spatie/laravel-query-builder` as a dev dependency**

Run:

```bash
composer require --dev spatie/laravel-query-builder
```

Expected: lock file updated, package installed in `vendor/spatie/laravel-query-builder/`. Add a `suggest` entry in `composer.json` for the production-side hint:

```json
"spatie/laravel-query-builder": "Enables documentation of #[AllowedFilter]/#[AllowedSort]/#[AllowedInclude] (^6.0)."
```

- [ ] **Step 2: Create ServiceProvider—register the QueryBuilderPlugin**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\QueryBuilder;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('QueryBuilder');

        // The default config ships with QueryBuilderPlugin commented out—this flavor
        // demonstrates it.
        config()->set('openapi.plugins', array_merge(
            (array) config('openapi.plugins', []),
            [QueryBuilderPlugin::class],
        ));

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
```

- [ ] **Step 3: Routes file—same eight routes, importing `Examples\QueryBuilder\Http\*`**

- [ ] **Step 4: Create `FlightController` with QueryBuilder on the index endpoint**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\QueryBuilder\Http;

use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Core\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Spatie\QueryBuilder\AllowedFilter as RuntimeFilter;
use Spatie\QueryBuilder\QueryBuilder;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights with filtering, sorting, and includes.
     */
    #[AllowedFilter('number', type: 'string', description: 'Exact match on IATA flight designator')]
    #[AllowedFilter('status', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    #[AllowedFilter('origin', type: 'string', minLength: 3, maxLength: 3)]
    #[AllowedFilter('departs_after', type: 'string', format: 'date-time', nullable: true, description: 'Only flights departing at or after this timestamp')]
    #[AllowedSort('departs_at', 'number')]
    #[AllowedInclude('bookings')]
    public function index(): JsonResponse
    {
        $flights = QueryBuilder::for(Flight::class)
            ->allowedFilters([
                'number',
                'status',
                'origin',
                RuntimeFilter::callback('departs_after', fn ($q, $v) => $q->where('departs_at', '>=', $v)),
            ])
            ->allowedSorts(['departs_at', 'number'])
            ->allowedIncludes(['bookings'])
            ->paginate();

        return response()->json($flights);
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function show(string $flight): JsonResponse
    {
        return response()->json(Flight::findOrFail($flight));
    }

    /** Create a flight. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'number'        => ['required', 'string'],
            'origin'        => ['required', 'string', 'size:3'],
            'destination'   => ['required', 'string', 'size:3'],
            'departs_at'    => ['required', 'date'],
            'arrives_at'    => ['required', 'date'],
            'status'        => ['required', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['required', 'string'],
        ]);
        return response()->json(Flight::create($data), 201);
    }

    /**
     * Update a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function update(Request $request, string $flight): JsonResponse
    {
        $model = Flight::findOrFail($flight);
        $model->update($request->validate([
            'status'        => ['sometimes', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['sometimes', 'string'],
        ]));
        return response()->json($model);
    }

    /**
     * Delete a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    public function destroy(string $flight): JsonResponse
    {
        Flight::findOrFail($flight)->delete();
        return response()->json(null, 204);
    }
}
```

- [ ] **Step 5: Create `BookingController`**

Apply the same pattern: `index` uses `QueryBuilder::for(Booking::class)` with one `#[AllowedFilter('passenger_name', type: 'string')]` and `#[AllowedSort('created_at')]`. The two write endpoints remain plain.

- [ ] **Step 6: Generate, verify, lint, commit**

`composer examples:query-builder`, sanity-check the YAML (the `/flights` operation should now have `filter[number]`, `filter[status]`, `filter[origin]`, `filter[departs_after]` query parameters, plus `sort` and `include`). Run `--filter query-builder` on the test. Pint + PHPStan. Then:

```bash
git add composer.json composer.lock examples/query-builder/
git commit -m "feat(examples): add query-builder flavor

Adds spatie/laravel-query-builder as a dev dependency and demonstrates
#[AllowedFilter]/#[AllowedSort]/#[AllowedInclude] on the two list endpoints,
including a nullable filter on departs_after."
```

---

## Task 6: combined flavor

The realistic-mix flavor—FormRequest in, Data out, QueryBuilder on indexes—plus the remaining feature demos: `#[Security]`, `#[PublicEndpoint]`, `#[Link]` between operations, `#[Hide]` on one internal endpoint, a multipart file-upload endpoint, and a `file:`-loaded example payload via the `ExampleFileLoader`.

**Files:**
- Create: `examples/combined/ExampleServiceProvider.php`
- Create: `examples/combined/routes/api.php`
- Create: `examples/combined/Http/FlightController.php`
- Create: `examples/combined/Http/BookingController.php`
- Create: `examples/combined/Http/InternalController.php` (one Hide-d endpoint)
- Create: `examples/combined/Requests/StoreFlightRequest.php` (FormRequest)
- Create: `examples/combined/Requests/UploadBoardingPassRequest.php` (multipart)
- Create: `examples/combined/Data/FlightData.php`, `BookingData.php`, `FlightStatus.php`
- Create: `examples/combined/example_payloads/flight.json` (loaded via `file:` reference)
- Create: `examples/combined/openapi.yaml`

- [ ] **Step 1: ServiceProvider—set up security scheme + register QueryBuilderPlugin**

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined;

use Examples\Shared\OpenApiConfig;
use Illuminate\Contracts\Routing\Registrar;
use Illuminate\Support\ServiceProvider;
use Radiergummi\OpenApi\Plugins\QueryBuilder\QueryBuilderPlugin;

final class ExampleServiceProvider extends ServiceProvider
{
    public function boot(Registrar $router): void
    {
        OpenApiConfig::apply('Combined');

        config()->set('openapi.plugins', array_merge(
            (array) config('openapi.plugins', []),
            [QueryBuilderPlugin::class],
        ));

        // Document a bearer-token security scheme at the document level so
        // #[Security('bearer')] on operations has something to reference.
        config()->set('openapi.security_schemes', [
            'bearer' => [
                'type'         => 'http',
                'scheme'       => 'bearer',
                'bearerFormat' => 'JWT',
            ],
        ]);

        $router->middleware('api')->prefix('api')->group(__DIR__ . '/routes/api.php');
    }
}
```

Confirm the config key for security schemes by reading `src/OpenApiServiceProvider.php` / `SecurityExtractor`—if a different key is used (e.g. `openapi.security`), adjust.

- [ ] **Step 2: Routes—eight standard routes plus two extras**

`examples/combined/routes/api.php`:

```php
<?php
// (with header)
declare(strict_types=1);

use Examples\Combined\Http\BookingController;
use Examples\Combined\Http\FlightController;
use Examples\Combined\Http\InternalController;
use Illuminate\Support\Facades\Route;

Route::get('/flights',                                  [FlightController::class, 'index']);
Route::get('/flights/{flight}',                         [FlightController::class, 'show']);
Route::post('/flights',                                 [FlightController::class, 'store']);
Route::patch('/flights/{flight}',                       [FlightController::class, 'update']);
Route::delete('/flights/{flight}',                      [FlightController::class, 'destroy']);

Route::get('/flights/{flight}/bookings',                [BookingController::class, 'index']);
Route::post('/flights/{flight}/bookings',               [BookingController::class, 'store']);
Route::post('/bookings/{booking}/boarding-pass',        [BookingController::class, 'uploadBoardingPass']);
Route::delete('/bookings/{booking}',                    [BookingController::class, 'destroy']);

// Hidden from the spec by #[Hide] on the controller method.
Route::get('/internal/health',                          [InternalController::class, 'health']);
```

- [ ] **Step 3: Reuse / port the `FlightData`, `BookingData`, `FlightStatus` from Task 4**

Copy them under `Examples\Combined\Data` namespace. Drop the `#[Example]` attribute on `FlightData` (we'll use a `file:` example instead in this flavor).

- [ ] **Step 4: Create `StoreFlightRequest` (FormRequest)**

Same shape as Task 3's `StoreFlightRequest` but namespaced under `Examples\Combined\Requests`.

- [ ] **Step 5: Create the boarding-pass upload FormRequest**

```php
<?php

declare(strict_types=1);

namespace Examples\Combined\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Radiergummi\OpenApi\Core\Attributes\RequestBody;

#[RequestBody(contentType: 'multipart/form-data', description: 'Boarding pass image upload')]
final class UploadBoardingPassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'image' => ['required', 'file', 'mimes:png,jpg,pdf', 'max:5120'],
        ];
    }
}
```

If `#[RequestBody]` doesn't carry that constructor signature, look at `src/Core/Attributes/RequestBody.php` and adjust the call. Same goes for any other attribute used here.

- [ ] **Step 6: Create the `file:` example payload**

`examples/combined/example_payloads/flight.json`:

```json
{
    "id": "0190f3d0-1234-7000-8000-000000000001",
    "number": "LH400",
    "origin": "FRA",
    "destination": "JFK",
    "departs_at": "2026-06-01T10:30:00Z",
    "arrives_at": "2026-06-01T13:15:00Z",
    "status": "scheduled",
    "aircraft_type": "A330"
}
```

The example loader resolves paths relative to `base_path()`. Read `src/Core/Generator/ExampleFileLoader.php` to confirm the on-disk lookup, then add a `setUp`-style hook in `ExampleServiceProvider` if the file needs mirroring (compare with how `tests/TestCase.php::setUp()` mirrors `tests/Fixtures/OpenApi/example_payloads`).

- [ ] **Step 7: Create the FlightController**

Single file, but lots happening in it. Highlights:
- `#[Tag('Flights')]` on class.
- `index` carries `#[AllowedFilter]`/`#[AllowedSort]`/`#[AllowedInclude]` and `#[PublicEndpoint]`.
- `show` carries `#[ResponseExample]` referencing `file:examples/combined/example_payloads/flight.json` (or whatever syntax `ExampleFileLoader` expects—verify in source).
- `store` consumes the FormRequest, returns Data; carries `#[Security('bearer')]` + `#[Link]` to `flights.show` (Link parameters: `flight: $response.body#/id`).
- `update`, `destroy` carry `#[Security('bearer')]`.
- `@throws ModelNotFoundException` on the per-id endpoints.

```php
<?php

/**
 * This file is part of radiergummi/laravel-openapi.
 *
 * @license MIT
 * @copyright (c) 2026 Moritz Friedrich
 */

declare(strict_types=1);

namespace Examples\Combined\Http;

use Examples\Combined\Data\FlightData;
use Examples\Combined\Data\FlightStatus;
use Examples\Combined\Requests\StoreFlightRequest;
use Examples\Shared\Models\Flight;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Radiergummi\OpenApi\Core\Attributes\Link;
use Radiergummi\OpenApi\Core\Attributes\PublicEndpoint;
use Radiergummi\OpenApi\Core\Attributes\ResponseExample;
use Radiergummi\OpenApi\Core\Attributes\Security;
use Radiergummi\OpenApi\Core\Attributes\Tag;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedFilter;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedInclude;
use Radiergummi\OpenApi\Plugins\QueryBuilder\Attributes\AllowedSort;
use Spatie\LaravelData\DataCollection;
use Spatie\QueryBuilder\QueryBuilder;

#[Tag('Flights')]
final class FlightController
{
    /**
     * List flights with filtering, sorting, and includes.
     *
     * Anonymous callers may list flights without authenticating.
     */
    #[PublicEndpoint]
    #[AllowedFilter('number', type: 'string')]
    #[AllowedFilter('status', type: 'string', enum: ['scheduled', 'boarding', 'departed', 'arrived', 'cancelled'])]
    #[AllowedFilter('origin', type: 'string', minLength: 3, maxLength: 3)]
    #[AllowedSort('departs_at', 'number')]
    #[AllowedInclude('bookings')]
    public function index(): DataCollection
    {
        $flights = QueryBuilder::for(Flight::class)
            ->allowedFilters(['number', 'status', 'origin'])
            ->allowedSorts(['departs_at', 'number'])
            ->allowedIncludes(['bookings'])
            ->paginate();

        return FlightData::collect($flights->items(), DataCollection::class);
    }

    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[PublicEndpoint]
    #[ResponseExample(status: 200, file: 'examples/combined/example_payloads/flight.json')]
    public function show(string $flight): FlightData
    {
        return FlightData::from(Flight::findOrFail($flight));
    }

    /**
     * Create a flight. Authenticated callers only.
     */
    #[Security('bearer')]
    #[Link(operationId: 'flights.show', name: 'self', parameters: ['flight' => '$response.body#/id'])]
    public function store(StoreFlightRequest $request): FlightData
    {
        return FlightData::from(Flight::create($request->validated()));
    }

    /**
     * Update a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Security('bearer')]
    public function update(Request $request, string $flight): FlightData
    {
        $model = Flight::findOrFail($flight);
        $model->update($request->validate([
            'status'        => ['sometimes', 'in:scheduled,boarding,departed,arrived,cancelled'],
            'aircraft_type' => ['sometimes', 'string'],
        ]));
        return FlightData::from($model);
    }

    /**
     * Delete a flight.
     *
     * @throws ModelNotFoundException when the flight does not exist
     */
    #[Security('bearer')]
    public function destroy(string $flight): \Illuminate\Http\Response
    {
        Flight::findOrFail($flight)->delete();
        return response()->noContent();
    }
}
```

The exact attribute parameter names (`file:` vs `path:`, `name:` vs `linkId:`) MUST be verified against the attribute source files before generating. Open `src/Core/Attributes/ResponseExample.php` and `Link.php` first.

- [ ] **Step 8: Create the BookingController**

Eight-line write endpoints, plus an `uploadBoardingPass` action consuming the multipart FormRequest:

```php
public function uploadBoardingPass(UploadBoardingPassRequest $request, string $booking): JsonResponse
{
    $path = $request->file('image')->store('boarding-passes');
    return response()->json(['stored_at' => $path]);
}
```

- [ ] **Step 9: Create the `InternalController` with `#[Hide]`**

```php
<?php

declare(strict_types=1);

namespace Examples\Combined\Http;

use Illuminate\Http\JsonResponse;
use Radiergummi\OpenApi\Core\Attributes\Hide;

final class InternalController
{
    #[Hide]
    public function health(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}
```

After generation, verify that `/internal/health` does **not** appear in `examples/combined/openapi.yaml`.

- [ ] **Step 10: Generate, verify, lint, commit**

`composer examples:combined`. Sanity-check the YAML for:
- `securitySchemes.bearer` at the document level
- `/flights/{flight}` show operation includes the `file:`-loaded example payload (compare against `flight.json`)
- `POST /flights` operation has `links.self` referencing `flights.show`
- `POST /bookings/{booking}/boarding-pass` has a multipart request body with `image` (binary)
- `/internal/health` is **absent**
- `index` operations have `security: []` (PublicEndpoint override)
- write operations have `security: [{bearer: []}]`

Run `--filter combined` on the test. Pint + PHPStan. Commit:

```bash
git add examples/combined/
git commit -m "feat(examples): add combined flavor

Realistic mix: FormRequest in, Spatie Data out, QueryBuilder on index.
Demonstrates #[Security], #[PublicEndpoint], #[Link], #[Hide], multipart
file uploads, and file: example payloads via ExampleFileLoader."
```

---

## Task 7: Documentation + changelog

**Files:**
- Create: `examples/README.md`
- Create: `examples/vanilla/README.md`
- Create: `examples/form-requests/README.md`
- Create: `examples/spatie-data/README.md`
- Create: `examples/query-builder/README.md`
- Create: `examples/combined/README.md`
- Modify: `docs/usage.md`
- Modify: `CHANGELOG.md`

- [ ] **Step 1: Write `examples/README.md`**

```markdown
# Examples

Each subdirectory is a small, runnable Laravel-shaped example exposing the same
flights + bookings API. Read the code in one, then read `openapi.yaml` next to
it—that's the showcase.

| Flavor | What it demonstrates |
|--------|----------------------|
| [`vanilla/`](vanilla/)             | Plain controllers, `$request->validate()`, authoring attributes (`#[Tag]`, `#[QueryParam]`, `#[Response]`, `#[Operation]`, `#[IgnoreLint]`). |
| [`form-requests/`](form-requests/) | `FormRequest` validation, `JsonResource` responses, `#[ResponseResource]`, `#[Header]`, `#[ExceptionResponse]`. |
| [`spatie-data/`](spatie-data/)     | Spatie `Data` classes in and out, `BackedEnum`, `#[Example]` + `BaseExample`, `#[Deprecated]`, `#[ExternalDocs]`. |
| [`query-builder/`](query-builder/) | `spatie/laravel-query-builder` filter/sort/include parameters via `#[AllowedFilter]`, `#[AllowedSort]`, `#[AllowedInclude]`. |
| [`combined/`](combined/)           | The realistic mix: FormRequest + Data + QueryBuilder, plus `#[Security]`, `#[PublicEndpoint]`, `#[Link]`, `#[Hide]`, multipart uploads, and `file:` example payloads. |

## Running

```bash
composer examples           # regenerate every snapshot
composer examples:vanilla   # regenerate one
```

Each command boots a real Laravel container (via Testbench), registers the
flavor's `ExampleServiceProvider`, runs the shared migration + seeder against
an in-memory SQLite database, and writes `examples/<flavor>/openapi.yaml`.

The verification test (`tests/Feature/ExamplesTest.php`) asserts that every
committed snapshot matches a fresh generation, validates as OpenAPI 3.1, and
lints clean—so the committed YAMLs can never silently drift from the code.

## Coming next

Authentication flavors (Passport, Sanctum) are deferred to a follow-up pass.
```

- [ ] **Step 2: Write each flavor's `README.md`**

3–5 sentences per flavor: what's distinctive, which file to open first, what to notice in the generated `openapi.yaml`. Example for vanilla:

```markdown
# Vanilla

Plain Laravel controllers, no opinionated stack. Open `Http/FlightController.php`
first—every endpoint is annotated with `#[Tag]`, `#[QueryParam]`, `#[Response]`,
and `@throws`, which is how you teach the generator about a method when no
FormRequest/Data class is doing it for you.

Notice in `openapi.yaml`:
- `flights.show` has the operationId overridden via `#[Operation]`.
- `DELETE /flights/{flight}` uses `#[IgnoreLint('operation.summary-missing')]`
  to demonstrate suppression with a reason.
- `@throws ModelNotFoundException` becomes a `404` response on each per-id endpoint.
```

Write equivalent READMEs for the other four flavors, pointing at the actual highlights of each.

- [ ] **Step 3: Update `docs/usage.md`**

Add a section near the top (after "Installation" / before deep usage):

```markdown
## Worked examples

If you want to see this in action against real Laravel code rather than
read a reference, check out `examples/` in the repository. Each subdirectory
is a small Laravel app exposing the same API surface with a different stack
(vanilla, FormRequest, Spatie Data, QueryBuilder, or a mix) and ships its
generated `openapi.yaml` next to its code.
```

- [ ] **Step 4: Update `CHANGELOG.md`**

Add under `[Unreleased] > Added`:

```markdown
- `examples/` suite: five runnable flavors (vanilla, form-requests, spatie-data, query-builder, combined) that all expose the same flights+bookings API and ship a generated `openapi.yaml` snapshot. Verified in CI against fresh generation, OpenAPI 3.1 validity, and `openapi:lint`.
```

- [ ] **Step 5: Final full-suite test run**

```bash
composer examples && vendor/bin/pest tests/Feature/ExamplesTest.php && vendor/bin/pint --test && vendor/bin/phpstan analyse
```

Expected: every command exits 0. Investigate and fix any failure before committing.

- [ ] **Step 6: Commit docs**

```bash
git add examples/README.md examples/*/README.md docs/usage.md CHANGELOG.md
git commit -m "docs(examples): top-level readme, per-flavor readmes, usage + changelog"
```

---

## Self-Review (writer's pass—not to be executed by an implementer)

**Spec coverage:**
- *Goals*—every bullet in the spec's Goals section has a task: one-screen mental model (Task 7 README), five permutations (Tasks 2–6), real Laravel boot (Task 1 TestbenchBoot), committed snapshots (each flavor's Step 5+), drift caught (Task 1 Step 11 test).
- *Non-goals*—auth flavors, per-example composer skeletons, hosted Swagger UI, factories, JSON output: none of the tasks introduce these. ✓
- *Shared domain*—Task 1 Steps 2–5 implement Flight/Booking models, migration, seeder with the exact field shape from the spec. ✓
- *API surface*—every flavor's routes file lists the same eight endpoints (combined adds two demonstration-only routes; the spec explicitly allows this for the Hide + upload demos—see "include as much of the package's functionality as possible" mandate from the user). ✓
- *Directory layout*—File-map at the top of each task matches the spec's tree exactly. ✓
- *Flavor responsibilities*—each flavor's task covers the techniques in the spec's table; the user's "include as much of the package's functionality as possible" overlay extends each flavor with additional attribute demonstrations without violating the "techniques are attributable to the flavor" rule. ✓
- *Generation runner*—Task 1 Steps 8–10. ✓
- *Verification*—Task 1 Step 11 (snapshot match + OAS 3.1 validation + lint). ✓
- *Documentation*—Task 7. ✓

**Placeholder scan:** Three explicit instructions to verify attribute parameter names against source before generating (`#[RequestField]` in Task 3 Step 3, `#[ResponseField]` in Task 3 Step 6/7, `#[ResponseExample]`/`#[Link]`/`#[RequestBody]` in Task 6 Step 7/5/7). These are not placeholders for missing content—they're guardrails because the package's attribute constructors evolve and the writer (me) only sampled a couple of them. The implementer is told which source files to read.

**Type consistency:** `ExampleServiceProvider` lives in `Examples\<Flavor>\` everywhere; `Flight`/`Booking` models are `Examples\Shared\Models\` everywhere; the `composer examples:<flavor>` script invocations match the `generate.php` provider-map keys. Snapshot paths (`examples/<flavor>/openapi.yaml`) consistent across `generate.php`, `ExamplesTest`, and per-flavor steps. ✓

**Known soft spots flagged for the implementer:**
- The `tests/TestCase.php` "extract boot logic" line in the original file-map is aspirational; verifying the existing TestCase logic actually composes with `TestbenchBoot::boot()` is the implementer's first verification step in Task 1. If a clean refactor isn't trivial, leave `TestCase.php` alone—the two boot paths can coexist.
- Several attribute names/parameters were inferred from filenames + one or two read passes. The implementer is told three times to verify attribute shapes from source before pasting; this is deliberate.
