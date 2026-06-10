# Vanilla

Plain Laravel controllers, no opinionated stack. Open `Http/FlightController.php`
first — every endpoint is annotated with `#[Tag]`, `#[Response]`, and PHPDoc
`@throws`, which is how you teach the generator about a method when no
FormRequest/Data class is doing it for you.

Notice in `openapi.yaml`:

- `flights.show` has its operationId overridden via `#[Operation(operationId: 'flights.show')]`.
- `DELETE /flights/{flight}` gets a `Delete Flight` summary derived from the resource
  convention — resourceful CRUD actions (`index`/`show`/`store`/`update`/`destroy`) earn a
  default summary and their conventional success code (`store` → `201`, `destroy` → `204`)
  for free.
- `GET /flights/{flight}/manifest` uses `#[IgnoreLint('operation.summary-missing', reason: ...)]`
  to demonstrate suppression-with-reason against a deliberately missing summary — on a custom
  (non-resourceful) action, since CRUD actions now get a convention summary.
- `@throws ModelNotFoundException` becomes a `404` response on each per-id endpoint
  (`show`, `update`, `destroy`, `bookings.index`); `@throws FlightOverbookedException`
  on `BookingController::store` becomes a `409` via the exception-response map.
- The `abort_if(..., 409, 'Departed flights can no longer be cancelled.')` guard in
  `FlightController::destroy` becomes a `409` response inlined with the authored message —
  no attribute needed; contrast it with the shared `$ref` Conflict on `bookings.store`.
- `GET /status` (`StatusController::show`) carries no attribute at all: its `200` schema —
  `status: string`, `read_only: boolean`, `incidents: integer` — is inferred from the
  literal `response()->json([...])` body.
- `#[QueryParam('page', ...)]` and `#[QueryParam('per_page', ...)]` on `index`
  render as documented query parameters on `GET /flights` with their types,
  defaults, and bounds preserved.
