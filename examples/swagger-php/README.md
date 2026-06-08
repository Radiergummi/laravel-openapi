# SwaggerPhp

Hand-authored swagger-php annotations harvested into the spec, with the
optional `SwaggerPhpPlugin` opted in via this flavor's `ExampleServiceProvider`
(which also points the harvester's scanner at this directory instead of
`app_path()`). This is the app that has *already* documented itself for
L5-Swagger / swagger-php — the plugin recovers those schemas instead of ignoring
them.

Two annotation shapes are shown:

- `Models/Aircraft.php` carries a `#[OA\Schema]` **attribute** (the Coolify
  shape). `Http/AircraftController.php::show()` returns an `Aircraft`, so the
  harvester attaches that schema as the `200` body — no inference, exactly the
  author's schema.
- `Models/Crew.php` carries an `@OA\Schema` **PHPDoc** annotation and
  `Http/CrewController.php::show()` carries a full `@OA\Get` operation
  annotation (the Invoice-Ninja shape). Parsing PHPDoc annotations requires
  `doctrine/annotations`; `#[OA\*]` attributes work without it.

Notice in `openapi.yaml`:

- Both `Aircraft` and `Crew` appear under `components.schemas` keyed by their
  **authored schema name**, with the authored property descriptions and
  examples intact.
- The library still owns each operation's skeleton (path, method, the
  `{id}` / `{aircraft}` path parameters). For the `@OA\Get`-annotated crew
  endpoint the harvester additionally adopts the authored `summary`,
  `operationId`, `tags`, and the docblock prose as the description, and merges
  the authored `200` response (`$ref: '#/components/schemas/Crew'`).
