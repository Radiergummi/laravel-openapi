# Combined

The realistic mix: typed `FormRequest` classes for input validation, Spatie
`Data` classes for output shaping, and `spatie/laravel-query-builder` for list
endpoints — all in one app. Open `Http/FlightController.php` first; it's the
headliner, showing each piece composing with the others. Then peek at
`Http/InternalController.php` and `Http/BookingController.php` for the more
specialised attributes.

Notice in `openapi.yaml`:

- `#[Security(['flights:write'])]` and `#[Security(['bookings:write'])]` produce
  per-operation `security` blocks listing OAuth scopes against the
  Passport-derived `oauth2` and `oauth2ClientCredentials` schemes. Public
  endpoints carry `#[PublicEndpoint]`, which emits `security: []` to explicitly
  opt out.
- `POST /flights` carries a `links.self` block via `#[Link]`, wiring its
  response `id` into the `flights.show` operation — `#[Operation(operationId: 'flights.show')]`
  on the show endpoint exists specifically so this link target is stable.
- `/internal/health` is registered with Laravel but absent from the spec:
  `#[Hide]` on `InternalController::health` excludes it from generation.
- `POST /bookings/{booking}/boarding-pass` becomes a `multipart/form-data`
  request body because `UploadBoardingPassRequest::rules()` includes a `file`
  rule on the `image` field.
- `flights.show` ships a curated example loaded from
  `example_payloads/flight.json` via `#[ResponseExample(file: ...)]`, which
  `ExampleFileLoader` reads at generation time.
