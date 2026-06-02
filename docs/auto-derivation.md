# Auto-derivation

Every operation is built from the controller's signature, PHPDoc, and route
middleware. The table below maps each part of the OpenAPI operation to its
source.

| Aspect | Source |
|---|---|
| Tag | Last meaningful segment of the controller's namespace. Skips generic segments (`Controllers`, `Http`, `App`, `Internal`, `External`, `Global`, `V0`, …) and the controller class itself. |
| Summary | First paragraph of the method's PHPDoc, or `#[Summary]` / `#[Operation(summary: …)]`. |
| Description | Remaining paragraphs of the method's PHPDoc (markdown permitted), or `#[Description]` / `#[Operation(description: …)]`. |
| `operationId` | Route name, or `{method}_{sanitized_path}`. |
| Path parameters | Action signature. Type hints, `Route::whereUuid()` / `whereNumber()` / `where(...)` constraints, and route-model-binding heuristics drive type and format. |
| Request body | Spatie Data class on the action (or on a configured payload-indirection object); `FormRequest` is supported natively. Schema is built from PHP types and validation rules. |
| Security | `auth:*` / `scope:*` middleware → a per-operation `security` requirement against the derived scheme(s) (Passport's OAuth2 flows, or `openapi.security_schemes`). When the route is authed but no scheme is derivable, `security` is omitted (not `[]`, which means *public*) and `operation.security-missing` flags it. |
| Error responses | `@throws ExceptionClass` → status codes; `auth`/`scope`/`throttle` middleware → 401 / 403 / 429. |
| Validation constraints | `Data::rules()` and Spatie validation attributes → `maxLength`, `minLength`, `pattern`, `enum`, `format`, `minimum`/`maximum`, `minItems`/`maxItems`. |

Use an authoring attribute when convention can't produce what you need. See
the [escape-hatch table](#what-if-convention-isnt-enough) below.

## A worked endpoint

This route definition:

```php
Route::middleware(['auth:api', 'scope:flights:read'])
    ->get('/flights/{flight}', [FlightController::class, 'show'])
    ->name('flights.show');
```

```php
namespace App\Http\Controllers\Api\V0\Flights;

#[Tag('Flights')]
final class FlightController
{
    /**
     * Show a single flight.
     *
     * Returns the full flight envelope including aircraft and crew.
     *
     * @throws ModelNotFoundException
     */
    public function show(Flight $flight): FlightData
    {
        return FlightData::from($flight);
    }
}
```

produces an operation with:

- `tags: [Flights]` from `#[Tag]` (or, without it, the `Flights` namespace segment).
- `summary: Show a single flight.` from the first docblock paragraph.
- `description: Returns the full flight envelope including aircraft and crew.` from the remaining paragraphs.
- `operationId: flights.show` from the route name.
- A `flight` path parameter with type and format inferred from the `Flight` model binding.
- A 200 response with `$ref: '#/components/schemas/FlightData'`.
- A 404 response from `@throws ModelNotFoundException`.
- A 401 response from `auth:api`.
- A 403 response from `scope:flights:read`.
- A security requirement for the `flights:read` scope.

`#[Tag]` is optional if the namespace-derived tag is acceptable; no other
attributes are required here.

## What if convention isn't enough?

Authoring attributes live in `Radiergummi\OpenApi\Attributes`. Pick the
one matching the override you need:

| Goal | Attribute |
|---|---|
| Override summary, description, `operationId`, or tags | [`#[Operation]`](attributes.md#operation-level-attributes) |
| Document an ad-hoc query parameter | [`#[QueryParam]`](recipes.md#document-an-ad-hoc-query-parameter) |
| Add an error response not in `@throws` | [`#[Response]`](recipes.md#add-an-error-response-that-isnt-in-throws) |
| Enrich a request- or response-body field | [`#[RequestField]` / `#[ResponseField]`](recipes.md#enrich-a-request-body-field) |
| Force a response resource the generator can't infer | [`#[ResponseResource]`](recipes.md#force-the-response-resource) |
| Hide an endpoint from production docs | [`#[Hide]`](recipes.md#hide-an-endpoint-from-production-docs) |
| Document a polymorphic response | [`#[Discriminator]`](recipes.md#document-a-polymorphic-response-with-a-discriminator) |
| Document an inbound webhook | [`#[Webhook]`](recipes.md#document-an-inbound-webhook) |
| Suppress a lint finding | [`#[IgnoreLint]`](linting.md#suppress-a-finding) |

Full reference: [Attributes](attributes.md). Runnable snippets: [Recipes](recipes.md).

## Conventions for new endpoints

1. Write a one-paragraph PHPDoc summary above the action.
2. Type-hint the request body as a `Data` class or `FormRequest`; declare
   `rules()` for constraints.
3. Type-hint the route-bound model parameter. Chain `->whereUuid(...)` or
   `->whereNumber(...)` on the route for regex constraints.
4. Apply `auth:api` and `scope:foo` middleware as usual.
5. Return a typed resource, or annotate with `#[ResponseResource]`.
6. Add `@throws` for every exception that maps to an HTTP error.
