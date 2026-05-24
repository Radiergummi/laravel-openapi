# API Resources

Output shapes are declared with Laravel's `JsonResource`, annotated with the
`ApiResourcesPlugin`'s `#[ResourceField]` attribute. The plugin ships enabled
by default in `config/openapi.php`, so no extra wiring is required.

Open `Http/Resources/FlightResource.php` first — every key in `toArray()` is
declared once via a class-level `#[ResourceField]`, which gives the generator a
schema without forcing it to read the resource's runtime body.

Notice in `openapi.yaml`:

- `Http/FlightController.php::index` returns an `AnonymousResourceCollection`
  wrapped in a paginator. `#[ResponseResource(FlightResource::class, collection: true)]`
  tells the generator the envelope is the standard `{data, links, meta}` shape.
- `Http/FlightController.php::show` returns `FlightResource` directly. The
  generator derives the `200 OK` response from the typed return value and
  resolves it through the plugin's `ResponseResolver`.
- `FlightResource` itself appears once in `components.schemas` as a reusable
  `$ref` target rather than being inlined per operation.
- `@throws ModelNotFoundException` on `show()` becomes a `404` response via
  the default exception map.
