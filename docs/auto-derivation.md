# Auto-derivation

This is the package's selling point: if you follow conventions, every endpoint
is fully documented with **zero attributes**.

| Aspect | Source |
|---|---|
| **Tag** | Last meaningful segment of the controller's namespace. Skips generic segments (`Controllers`, `Http`, `App`, `Internal`, `External`, `Global`, `V0`, …) and the controller class itself. |
| **Summary** | First paragraph of the controller method's PHPDoc. |
| **Description** | Remaining paragraphs (markdown permitted). |
| **operationId** | Route name (if named) or `{method}_{sanitized_path}` otherwise. |
| **Path parameters** | Reflected from the action signature — type hints, `#[Where*]` regex constraints, and route-model-binding heuristics drive type and format. |
| **Request body** | Spatie Data class found on the action or its injected payload-indirection object — schema is built from PHP types + validation rules. Legacy `FormRequest` is also supported. |
| **Security** | `auth:api` and `scope:*` middleware drive OAuth2 schemes on the operation. |
| **Error responses** | `@throws ExceptionClass` PHPDoc on the action maps to status codes; `auth`/`scope`/`throttle` middleware contribute 401/403/429. |
| **Validation constraints** | `Data::rules()` and Spatie validation attributes are compiled and merged into property schemas: `maxLength`, `minLength`, `pattern`, `enum`, `format`, `minimum`/`maximum`, `minItems`/`maxItems`. |

> [!TIP]
> **If your controllers are shaped conventionally, you're done.** The rest of
> the documentation covers overrides and edge cases. Skim it once so you know
> where to look, then come back when you need it.

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

produces an operation that includes:

- `tags: [Flights]` — from `#[Tag]` (or, without it, the `Flights` namespace segment)
- `summary: Show a single flight.` — first paragraph of the docblock
- `description: Returns the full flight envelope including aircraft and crew.` — remaining paragraphs
- `operationId: flights.show` — from the route name
- a `flight` path parameter — type and format inferred from the `Flight` model binding
- a 200 response with `$ref: '#/components/schemas/FlightData'`
- a 404 response — from the `@throws ModelNotFoundException`
- a 401 response — from `auth:api`
- a 403 response — from `scope:flights:read`
- a security requirement for the `flights:read` scope

None of this required an authoring attribute beyond `#[Tag]`, and even that is
optional if you accept the namespace-derived tag.

## What if convention isn't enough?

Reach for an authoring attribute. They live in `Radiergummi\OpenApi\Core\Attributes`
and are the escape hatch — one per category of override.

| If you need to … | Reach for |
|---|---|
| Override the summary, description, operationId, or tags | [`#[Operation]`](attributes.md#operation-level-attributes) |
| Document an ad-hoc query parameter | [`#[QueryParam]`](recipes.md#document-an-ad-hoc-query-parameter) |
| Add error responses that aren't in `@throws` | [`#[Response]`](recipes.md#add-an-error-response-that-isnt-in-throws) |
| Enrich a request- or response-body field | [`#[RequestField]` / `#[ResponseField]`](recipes.md#enrich-a-request-body-field) |
| Force a response resource the generator can't infer | [`#[ResponseResource]`](recipes.md#force-the-response-resource) |
| Hide an endpoint from production docs | [`#[Hide]`](recipes.md#hide-an-endpoint-from-production-docs) |
| Document a polymorphic response | [`#[Discriminator]`](recipes.md#document-a-polymorphic-response-with-a-discriminator) |
| Document an inbound webhook | [`#[Webhook]`](recipes.md#document-an-inbound-webhook) |
| Suppress a lint finding | [`#[IgnoreLint]`](linting.md#suppress-a-finding) |

See the **[Attribute catalog](attributes.md)** for the full reference and
**[Recipes](recipes.md)** for runnable snippets.

## Conventions for new endpoints

When adding a new endpoint, the checklist is short:

1. Write a one-paragraph PHPDoc summary above the controller action.
2. Type-hint the request body as a `Data` class (or `FormRequest`) — declare
   `rules()` for constraints.
3. Type-hint the route-bound model parameter and use `#[Where*]` for regex
   constraints.
4. Use `auth:api` + `scope:foo` middleware as usual.
5. Return a typed resource — or add `#[ResponseResource]`.
6. Add `@throws` for every exception the action can raise that maps to an HTTP
   error.

Everything else is opt-in.
