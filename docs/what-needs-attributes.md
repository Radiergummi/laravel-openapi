# What needs attributes, and why

The generator reads your code; it never runs it. So how much of the spec it can
build on its own depends on how much your code *says* in types, PHPDoc, and
conventional structure. Authoring attributes
([`Radiergummi\OpenApi\Attributes`](attributes.md)) cover the rest — and the
honest framing is a gradient:

- **Well-typed, conventional code → almost nothing to author.** A typed
  controller return, a typed request object, a PHPDoc'd model: the operation,
  its parameters, its request and response schemas, and its error responses all
  fall out of the signature. No attribute required.
  <!-- coverage figure: pending #460 measurement -->
- **Dynamic, implicit, or runtime-shaped code → reach for an attribute.** A
  payload assembled at runtime, a response whose shape only exists once the
  request is handled, an array keyed dynamically: there is nothing in the source
  for static analysis to read, so you state the shape with an attribute instead.

The more your code leaves implicit, the more you fill in by hand. The attributes
aren't a parallel spec language you're expected to write everywhere — they're the
escape hatch for the cases convention genuinely can't reach.

## Why convention stops where it does

Every inference the generator makes is judged on an economic-sensibility ladder
and built at the *lowest* tier that captures the idiom. The ladder is also the
boundary: it explains why some shapes are recovered and some are not.

| Tier | What it reads | In the spec? |
|---|---|---|
| **0 — reflection & signatures** | Class/method signatures, PHPDoc tags, attributes, model metadata (`$casts`, `$hidden`, `$visible`, `$appends`, migration columns), backed enums, route-model-binding types, middleware names. | Always. This is the library's whole basis. |
| **1 — bounded body scan** | A small whitelist of well-known call shapes in the first statements of a method body, with no tracking of values across calls: inline `$request->validate()`, `abort()`, `response()->json([…])`, a resource's `toArray()` literal. | Read where the shape matches the whitelist; degrades gracefully (and logs) where it doesn't. |
| **2 — full dataflow** | Tracking a value across calls, into services, through conditionals, to discover a shape that exists only at runtime. | **Refused.** |

Tier 2 is refused on purpose: chasing a value through the program is fragile,
expensive, and never complete — the moment a shape is assembled across a service
boundary or behind a condition, a static reader is guessing. Rather than emit a
confident-but-wrong schema, the generator emits nothing and leaves that case to
an attribute. **Where only Tier 2 would recover a shape, that shape is, by
definition, the attribute's job.** This is the same reason responses are
*return-type-shaped*: type your returns (or annotate them) and response fidelity
is strong; leave the shape to runtime and there is nothing in the source to read.

## What this looks like in practice

### Fully derived — no attribute

A typed return whose schema the generator can read end to end. The model's
`@property` tags and `$casts` give the component schema; the `@throws` gives the
`404`; the route gives the `{flight}` parameter:

```php
/**
 * @property string $id
 * @property string $number
 * @property Carbon $departs_at
 */
final class Flight extends Model
{
    protected $casts = ['departs_at' => 'datetime'];
}

final class FlightController
{
    /**
     * Show a single flight.
     *
     * @throws ModelNotFoundException
     */
    public function show(string $flight): Flight
    {
        return Flight::findOrFail($flight);
    }
}
```

Nothing here needs an attribute: every part of the operation traces to something
the code already states. (This is the README's hero example; see
[Auto-derivation](auto-derivation.md) for the full source map.)

### Runtime-shaped — an attribute states what the code can't

When the response shape is assembled at runtime rather than declared, there is no
type for the generator to read. State it with [`#[Response]`](attributes.md#operation-level-attributes)
(or, for a whole component body, [`#[RawSchema]`](attributes.md#replace-a-component-body-with-a-literal-schema-rawschema)):

```php
use Radiergummi\OpenApi\Attributes as OpenApi;

final class ReportController
{
    /** Export a usage report. */
    #[OpenApi\Response(status: 200, description: 'The assembled report', schema: [
        'type' => 'object',
        'properties' => [
            'generated_at' => ['type' => 'string', 'format' => 'date-time'],
            'rows' => ['type' => 'array', 'items' => ['type' => 'object']],
        ],
    ])]
    public function export(Request $request): JsonResponse
    {
        return response()->json($this->reporter->assemble($request->all()));
    }
}
```

A healthy attribute supplies something the code cannot express — a runtime shape,
a human description, an intentional override. An attribute that merely re-states
what a typed signature already says is a smell: prefer the type.

## The linter signposts the gap

You don't have to guess where an attribute is needed. `openapi:lint` generates
the spec, finds the thin spots, and — for the mechanical ones — names both the
gap and the attribute that closes it in its fix hint:

```text
$ php artisan openapi:lint --level=max

app/Http/Controllers/ReportController.php (1)
 │
 ╰─ ℹ️ operation.return-type-missing
        ReportController::export() has no return type or response attribute, so no
        response schema can be inferred
        at app/Http/Controllers/ReportController.php:18 (GET /reports/export)

        Suggested Fix: Add a return type to the action, or annotate it with
        #[Response] / #[ResponseResource].
```

A few rules whose fix hint points straight at an attribute:

| Rule | What it caught | What it suggests |
|---|---|---|
| `operation.return-type-missing` | An action with no usable return type, so no response schema. | Add a return type, or `#[Response]` / `#[ResponseResource]`. |
| `operation.security-missing` | An authed route whose scheme couldn't be derived. | Add an `auth:`/`scope:` requirement, or mark it `#[PublicEndpoint]` if it's intentionally public. |
| `response.no-error` | An operation with no `4xx`/`5xx` declared. | Add an error response (`@throws`, or `#[Response]`). |

So the workflow is: write conventional, typed code; run the linter; and let it
tell you the few places where the shape only the runtime knows needs an attribute
to make it explicit. See [Linting](linting.md) for the full rule catalog and
severity levels.

## Where to look next

- The full escape-hatch map — *goal → attribute* — lives at the end of
  [Auto-derivation](auto-derivation.md#what-if-convention-isnt-enough).
- The complete attribute reference is [Attributes](attributes.md); runnable
  snippets are in [Recipes](recipes.md).
- The two deliberate, permanent boundaries (never running your code; no bespoke
  documentation UI) are in the README's *Caveats* section.
