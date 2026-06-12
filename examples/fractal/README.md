# Fractal

Output shapes are declared with `league/fractal` transformers, annotated with
the `FractalPlugin`'s `#[TransformerField]` and `#[FractalResponse]` attributes.

The plugin ships **commented out** in `config/openapi.php`. This flavor's
`ExampleServiceProvider` enables it at boot by appending it to
`openapi.plugins`.

Open `Http/Transformers/FlightTransformer.php` first — every key returned by
`transform()` is declared once via a class-level `#[TransformerField]`, which
carries descriptions, formats, and enums the runtime body cannot express.
`Http/Transformers/BookingTransformer.php` shows the attribute-free variant:
its schema is inferred entirely from the single `return [...]` literal of
`transform()` (model fetches typed from the `Booking` parameter, casts by
their JSON type, unreadable values kept as unconstrained properties).

Notice in `openapi.yaml`:

- `Http/FlightController.php::index` injects a `League\Fractal\Manager` and is
  bound to the transformer via `#[FractalResponse(transformer: ...,
  paginated: true)]`. The envelope is the standard Fractal `{data, meta.pagination}`
  shape — see `meta.pagination` on the 200 response.
- `Http/FlightController.php::show` is bound via `#[FractalResponse(transformer: ...)]`
  (single item). The 200 response is a bare `{data: $ref}`.
- `Http/FlightController.php::bookings` is bound to the attribute-free
  `BookingTransformer` — its `components.schemas` entry comes from the
  `transform()` literal; `reference` stays an unconstrained `{}` property.
- `FlightTransformer` itself appears once in `components.schemas` and is `$ref`d
  by every response that uses it.
- The `fractal.response-unbound` lint rule keys off an injected `Manager`
  parameter to flag undocumented Fractal endpoints — all routes here satisfy
  that signal, so the rule stays silent.
