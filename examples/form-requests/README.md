# Form Requests

Typed `FormRequest` classes for input, Eloquent `JsonResource` classes for
output. Open `Requests/StoreFlightRequest.php` first to see how each `PARAM_*`
constant carries a `#[RequestField]` so the OpenAPI schema gets the description,
example, `pattern`, `enum`, and `format` that the Laravel rules array cannot
express. Then look at `Resources/FlightResource.php` — `#[ResourceField]` lives
at class level because a JsonResource's keys come from `toArray()` rather than
typed properties.

Notice in `openapi.yaml`:

- The `StoreFlightRequest` rules become the `requestBody` schema for `POST /flights`,
  with `#[RequestField]` providing the per-property descriptions and examples.
- `JsonResource` responses emit the `{data}` envelope for single items and the
  `{data, links, meta}` envelope for `AnonymousResourceCollection` indexes.
- `POST /flights` ships both an auto-derived 200 (from the `ResponseResource`)
  and an explicit `#[Response(status: 201)]` so the spec documents the real status.
- `@throws FlightOverbookedException` on `BookingController::store` produces a
  `409` response via the exception map in `OpenApiConfig`.
- `#[Header(name: 'X-Request-Id', ...)]` on the controller adds a request-header
  parameter to every endpoint.
- `#[ResponseHeader(name: 'Location', status: 201, ...)]` on
  `FlightController::store` attaches a `Location` header to the `201 Created`
  response — scoped to the status the attribute targets.
