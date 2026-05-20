# Examples Suite — Design

**Date:** 2026-05-20
**Status:** Approved (brainstorming)
**Purpose:** Showcase how `radiergummi/laravel-openapi` documents real Laravel APIs across the common stack permutations colleagues actually encounter, by shipping a small, runnable suite of example apps that all expose the same flights/bookings API.

---

## Goals

- Give a viewer a one-screen mental model: *here is the code I write, here is the OpenAPI spec it produces.*
- Cover the five permutations of "how Laravel devs typically structure controllers": vanilla, FormRequest, Spatie Data, Spatie QueryBuilder, and a realistic mix.
- Keep every example **booted by a real Laravel container** (via Testbench) so the spec generation path is the production path — no special demo wiring.
- Commit the generated `openapi.yaml` alongside each example so colleagues can read code and output side-by-side without running anything.
- Catch drift: committed YAMLs are verified against fresh generation in CI.

## Non-goals

- Auth flavors (Passport / Sanctum). Deferred to a follow-up pass.
- Per-example standalone Laravel installations (`composer.json` + `vendor/` per flavor). Rejected: seven near-identical skeletons hide the interesting code.
- A hosted Swagger UI. The `openapi.yaml` file is the demo artifact.
- Eloquent factories, comprehensive seeders, or any persistence concerns beyond what the generator needs.
- JSON output. YAML only in this pass; JSON is one flag away if anyone asks.

## Shared domain

All examples expose the same two resources with the same field shape. The shape is intentionally simple — interest should be in *how each flavor expresses it*, not the domain.

**Flight**
- `id` — uuid
- `number` — string, e.g. `"LH400"`
- `origin` — string, IATA airport code (3 letters)
- `destination` — string, IATA airport code (3 letters)
- `departs_at` — ISO 8601 datetime
- `arrives_at` — ISO 8601 datetime
- `status` — enum: `scheduled | boarding | departed | arrived | cancelled`
- `aircraft_type` — string, e.g. `"A320"`

**Booking**
- `id` — uuid
- `flight_id` — uuid
- `passenger_name` — string
- `seat` — string, e.g. `"12A"`
- `created_at` — ISO 8601 datetime

## API surface

Every flavor exposes the same eight endpoints with the same URIs and methods. The request/response *body* shapes are equivalent across flavors. The **query string** of `GET /flights` and `GET /flights/{flight}/bookings` is intentionally richer in the `query-builder` and `combined` flavors — that surface area is the whole point of those flavors.

| Method | Path | Purpose |
|--------|------|---------|
| GET    | `/flights`                       | List flights (paginated; filterable in query-builder/combined flavors) |
| GET    | `/flights/{flight}`              | Show one flight |
| POST   | `/flights`                       | Create a flight |
| PATCH  | `/flights/{flight}`              | Update a flight |
| DELETE | `/flights/{flight}`              | Delete a flight |
| GET    | `/flights/{flight}/bookings`     | List bookings on a flight |
| POST   | `/flights/{flight}/bookings`     | Create a booking on a flight |
| DELETE | `/bookings/{booking}`            | Cancel a booking |

## Directory layout

```
examples/
├── README.md                       # overview + flavor table
├── generate.php                    # boots Testbench, runs openapi:generate for a flavor
├── _shared/
│   ├── Models/
│   │   ├── Flight.php              # Eloquent model, shared across flavors
│   │   └── Booking.php
│   ├── Database/
│   │   ├── migrations/
│   │   │   └── 0000_00_00_000000_create_flights_and_bookings_tables.php
│   │   └── Seeder.php              # 3 flights + a handful of bookings
│   └── TestbenchBoot.php           # the shared boot helper used by generate.php
├── vanilla/
│   ├── README.md
│   ├── ExampleServiceProvider.php
│   ├── Http/
│   │   ├── FlightController.php
│   │   └── BookingController.php
│   ├── routes/
│   │   └── api.php
│   └── openapi.yaml
├── form-requests/
│   ├── README.md
│   ├── ExampleServiceProvider.php
│   ├── Http/
│   │   ├── FlightController.php
│   │   └── BookingController.php
│   ├── Requests/
│   │   ├── StoreFlightRequest.php
│   │   ├── UpdateFlightRequest.php
│   │   └── StoreBookingRequest.php
│   ├── Resources/
│   │   ├── FlightResource.php
│   │   └── BookingResource.php
│   ├── routes/api.php
│   └── openapi.yaml
├── spatie-data/
│   ├── README.md
│   ├── ExampleServiceProvider.php
│   ├── Http/
│   │   ├── FlightController.php
│   │   └── BookingController.php
│   ├── Data/
│   │   ├── FlightData.php
│   │   ├── BookingData.php
│   │   └── FlightStatus.php        # enum
│   ├── routes/api.php
│   └── openapi.yaml
├── query-builder/
│   ├── README.md
│   ├── ExampleServiceProvider.php
│   ├── Http/
│   │   ├── FlightController.php    # uses QueryBuilder::for() on index endpoints
│   │   └── BookingController.php
│   ├── routes/api.php
│   └── openapi.yaml
└── combined/
    ├── README.md
    ├── ExampleServiceProvider.php
    ├── Http/
    │   ├── FlightController.php    # FormRequest in, Data out, QueryBuilder on index
    │   └── BookingController.php
    ├── Data/                       # output Data classes
    ├── Requests/                   # input FormRequests
    ├── routes/api.php
    └── openapi.yaml
```

**Autoloading.** Composer `autoload-dev` gets one PSR-4 entry per flavor:
- `Examples\\Shared\\` → `examples/_shared/`
- `Examples\\Vanilla\\` → `examples/vanilla/`
- `Examples\\FormRequests\\` → `examples/form-requests/`
- `Examples\\SpatieData\\` → `examples/spatie-data/`
- `Examples\\QueryBuilder\\` → `examples/query-builder/`
- `Examples\\Combined\\` → `examples/combined/`

## Flavor responsibilities

Each `ExampleServiceProvider` is the **only** plumbing file. It:
1. Loads its `routes/api.php`.
2. Returns its routes namespaced under `/examples/<flavor>` — *not* used at generation time (each flavor is generated in isolation, against a clean container), but kept so a curious developer could mount multiple flavors in one boot for comparison.

Each flavor's controllers use **only the techniques its name advertises**. No FormRequests in `vanilla/`. No QueryBuilder outside `query-builder/` and `combined/`. The point is that each spec is attributable to its technique.

### `vanilla`
- Controllers use `$request->validate([...])` for input.
- Responses are returned as plain associative arrays.
- Demonstrates: the generator's baseline coverage when given no extra hints — what does it derive from a method signature and a `return ['id' => ...]`?

### `form-requests`
- Each write endpoint has a dedicated `FormRequest` subclass with `rules()`.
- Responses are `JsonResource` subclasses with explicit `toArray()`.
- Demonstrates: core's `FormRequestRequestSchemaResolver` and how validation rules become request body schemas.

### `spatie-data`
- Inputs and outputs are Spatie `Data` classes. `FlightStatus` is a backed enum.
- Demonstrates: the SpatieData plugin end-to-end, including enum handling and nested types.

### `query-builder`
- `GET /flights` and `GET /flights/{flight}/bookings` go through `QueryBuilder::for(Flight::class)` with `AllowedFilter`s (`number`, `status`, `origin`), `AllowedSort`s (`departs_at`, `number`), and `AllowedInclude`s (`bookings`).
- Write endpoints use plain validation (kept simple — the point of this flavor is the list-endpoint surface).
- Demonstrates: the QueryBuilder plugin generating filter/sort/include query parameters.

### `combined`
- FormRequest in, Data out, QueryBuilder on the index endpoints.
- Demonstrates: the plugins compose; this is how a real production app likely looks.

## Generation runner

`examples/generate.php` is a small PHP script that:

1. Reads the flavor name from `$argv`.
2. Calls a static helper `Examples\Shared\TestbenchBoot::create($flavor): \Illuminate\Foundation\Application` which boots a Testbench `Application`, registers `OpenApiServiceProvider` plus the flavor's `ExampleServiceProvider`, runs the shared migration against an in-memory SQLite database, and seeds it.
3. Invokes the existing `openapi:generate` Artisan command on that application (via `Illuminate\Contracts\Console\Kernel::call()`), passing the positional `path` argument as `examples/<flavor>/openapi.yaml` and `--format=yaml`.
4. Exits with the command's status code.

`TestbenchBoot` is the same boot path used by `tests/TestCase.php`, factored out so the script and the verification test share it.

**Composer scripts** (added to root `composer.json`):

```json
"scripts": {
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
}
```

## Verification

One new Pest feature test, `tests/Feature/ExamplesTest.php`, parameterised over the five flavors. For each flavor:

1. Boot Testbench with the flavor's `ExampleServiceProvider` (using the same `TestbenchBoot` helper).
2. Run the generator in-memory (capture output to a string, do not write to disk).
3. Assert the captured YAML matches `examples/<flavor>/openapi.yaml` byte-for-byte. **Snapshot mismatch fails CI.**
4. Assert the captured YAML validates against the OpenAPI 3.1 JSON Schema — reusing the existing validator from `tests/Support` (the test suite already validates generated docs; this is the same call).

This guarantees the committed snapshots can't drift from the code, and that every flavor produces a spec that is structurally valid OpenAPI 3.1.

## Documentation

- `examples/README.md` — short intro, a table of flavors with one-line pitches, and links into each subdirectory. Mentions the auth flavors as "coming next."
- `examples/<flavor>/README.md` — three or four sentences per flavor: what's distinctive, which files to read first, what to notice in the generated `openapi.yaml`.

## Out of scope (explicit deferrals)

- **Passport / Sanctum flavors.** Add in a follow-up. Each needs guard/middleware config that doubles the per-flavor surface area; we want the data-shape variants landed first.
- **API Resources flavor as a separate top-level.** The `form-requests` flavor already uses `JsonResource`s for output; a dedicated "api-resources" flavor would mostly duplicate it.
- **Hosted preview.** No Swagger UI / Redoc step. The YAML file is the artifact; readers can open it in any viewer they prefer.

## Acceptance criteria

- `composer examples` regenerates every snapshot from scratch with no errors.
- `composer test` is green; the new `ExamplesTest` passes on all five flavors.
- `composer lint` reports no Pint violations in `examples/`.
- `composer analyse` passes (PHPStan level 8) for `examples/`.
- Every `examples/<flavor>/openapi.yaml` is committed and validates as OpenAPI 3.1.
- A reader can open `examples/spatie-data/` (or any flavor), read the controller + Data classes + `openapi.yaml`, and understand what the plugin did *without* running anything.
