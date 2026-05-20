# Vanilla

Plain Laravel controllers, no opinionated stack. Open `Http/FlightController.php`
first — every endpoint is annotated with `#[Tag]`, `#[Response]`, and PHPDoc
`@throws`, which is how you teach the generator about a method when no
FormRequest/Data class is doing it for you.

Notice in `openapi.yaml`:

- `flights.show` has its operationId overridden via `#[Operation(operationId: 'flights.show')]`.
- `DELETE /flights/{flight}` uses `#[IgnoreLint('operation.summary-missing', reason: ...)]`
  to demonstrate suppression-with-reason against a deliberately missing docblock summary.
- `@throws ModelNotFoundException` becomes a `404` response on each per-id endpoint
  (`show`, `update`, `destroy`, `bookings.index`); `@throws FlightOverbookedException`
  on `BookingController::store` becomes a `409` via the exception-response map.
- `#[QueryParam]` is present on `index` (for `page` / `per_page`) but is not yet
  consumed by the Core resolver — those parameters do not appear in the YAML.
  Tracked as a follow-up.
