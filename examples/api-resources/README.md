# API Resources

Output shapes come from Laravel's `JsonResource` classes, documented by the
`ApiResourcesPlugin` two ways: read straight from a `toArray()` array literal,
or declared with `#[ResourceField]` attributes. The plugin ships enabled by
default in `config/openapi.php`, so no extra wiring is required.

Open `Http/Resources/BookingResource.php` first — it carries **no attributes
at all**. Its `toArray()` is a single `return [...]` literal, so the generator
reads the keys directly: `$this->field` references type themselves from the
wrapped model (the `@mixin Booking` docblock), and the nested
`new FlightResource($this->whenLoaded('flight'))` becomes a `$ref` that the
`whenLoaded()` wrapper marks optional.

`Http/Resources/FlightResource.php` shows the declared form — every key in
`toArray()` is also described via a class-level `#[ResourceField]`, adding
what the literal cannot express (descriptions, formats, enum values). Declared
fields always win per field over inferred ones.

Notice in `openapi.yaml`:

- `Http/FlightController.php::index` returns an `AnonymousResourceCollection`
  wrapped in a paginator. `#[ResponseResource(FlightResource::class, collection: true)]`
  tells the generator the envelope is the standard `{data, links, meta}` shape.
- `Http/FlightController.php::show` returns `FlightResource` directly. The
  generator derives the `200 OK` response from the typed return value and
  resolves it through the plugin's `ResponseResolver`.
- `Http/BookingController.php::show` returns `BookingResource` — its component
  schema (property types, the `required` list, the optional `flight` `$ref`)
  is inferred entirely from the `toArray()` literal.
- `FlightResource` and `BookingResource` each appear once in
  `components.schemas` as reusable `$ref` targets rather than being inlined
  per operation.
- `@throws ModelNotFoundException` on the `show()` actions becomes a `404`
  response via the default exception map.
