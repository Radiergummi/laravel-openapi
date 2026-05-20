# Query Builder

`spatie/laravel-query-builder` endpoints, with the optional `QueryBuilderPlugin`
opted in via this flavor's `ExampleServiceProvider`. Open
`Http/FlightController.php` and look at `index()` — the `allowedFilters()`,
`allowedSorts()`, and `allowedIncludes()` calls on the QueryBuilder are mirrored
by `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]` attributes so
the generator can document the corresponding query parameters.

Notice in `openapi.yaml`:

- Each `#[AllowedFilter]` becomes a `filter[<name>]` query parameter with the
  declared type, `enum`, and length constraints; `#[AllowedSort(['departs_at', 'number'])]`
  and `#[AllowedInclude(['bookings'])]` collapse into single `sort` and `include`
  parameters with comma-separated value semantics.
- `#[AllowedFilter('departs_after', ..., nullable: true)]` emits the
  OpenAPI 3.1 nullable form (`type: [string, "null"]`) rather than the
  deprecated `nullable: true` keyword.
- `BookingController::index` also illustrates a sortable, filterable nested
  collection (`passenger_name` filter, `created_at` sort).
