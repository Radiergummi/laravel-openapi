# Fractal

Output shapes are declared with `league/fractal` transformers, annotated with
the `FractalPlugin`'s `#[TransformerField]` and `#[FractalResponse]` attributes.

The plugin ships **commented out** in `config/openapi.php`. This flavor's
`ExampleServiceProvider` enables it at boot by appending it to
`openapi.plugins`.

Open `Http/Transformers/FlightTransformer.php` first — every key returned by
`transform()` is declared once via a class-level `#[TransformerField]`, which
gives the generator a schema without forcing it to read the runtime body.

Notice in `openapi.yaml`:

- `Http/FlightController.php::index` injects a `League\Fractal\Manager` and is
  bound to the transformer via `#[FractalResponse(transformer: ...,
  paginated: true)]`. The envelope is the standard Fractal `{data, meta.pagination}`
  shape — see `meta.pagination` on the 200 response.
- `Http/FlightController.php::show` is bound via `#[FractalResponse(transformer: ...)]`
  (single item). The 200 response is a bare `{data: $ref}`.
- `FlightTransformer` itself appears once in `components.schemas` and is `$ref`d
  by every response that uses it.
- The `fractal.response-unbound` lint rule keys off an injected `Manager`
  parameter to flag undocumented Fractal endpoints — both routes here satisfy
  that signal, so the rule stays silent.
