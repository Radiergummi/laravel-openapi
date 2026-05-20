# Examples

Each subdirectory is a small, runnable Laravel-shaped example exposing the same
flights + bookings API. Read the code in one, then read `openapi.yaml` next to
it — that's the showcase.

| Flavor | What it demonstrates |
|--------|----------------------|
| [`vanilla/`](vanilla/)             | Plain controllers, `$request->validate()`, authoring attributes (`#[Tag]`, `#[Response]`, `#[Operation]`, `#[IgnoreLint]`). |
| [`form-requests/`](form-requests/) | `FormRequest` validation, `JsonResource` responses, `#[ResourceField]`, `#[RequestField]`. |
| [`spatie-data/`](spatie-data/)     | Spatie `Data` classes in and out, `BackedEnum`, `BaseExample` subclass for curated payloads, `@deprecated` on a legacy property, `#[ExternalDocs]`. |
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
lints clean — so the committed YAMLs can never silently drift from the code.

## Coming next

Authentication flavors (Passport, Sanctum) are deferred to a follow-up pass.
