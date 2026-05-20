# Spatie Data

Spatie `Data` classes drive both input and output — the SpatieData plugin
reflects on the constructor properties to build component schemas. Open
`Data/FlightData.php` first; it shows how a typed `BackedEnum` (`FlightStatus`)
becomes an `enum`-constrained string in the schema, and how `@deprecated` on the
legacy `$aircraft` property surfaces as `deprecated: true` next to it.

Notice in `openapi.yaml`:

- The Flight, Booking, and Create/Update Data classes all live under
  `components.schemas`, referenced by `$ref` from every operation that consumes
  or returns them.
- The `aircraft` property carries `deprecated: true` — derived from the PHPDoc
  `@deprecated` tag on the `FlightData` constructor parameter.
- `#[ExternalDocs(url: ..., description: ...)]` on the controller adds an
  `externalDocs` block to every operation it spans.
- `flights.show` ships a curated example payload via
  `#[ResponseExample(name: ..., value: ..., summary: ..., description: ...)]`,
  with the value sourced from constants on a `BaseExample` subclass
  (`Data/Examples/FlightDataExample.php`).
