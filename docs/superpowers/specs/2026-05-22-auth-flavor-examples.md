# Auth-Flavor Examples — Design

**Date:** 2026-05-22
**Status:** Draft (to be expanded before implementation)
**Purpose:** Showcase how `radiergummi/laravel-openapi` documents the two auth conventions
that consumer Laravel apps actually run in production — Passport (OAuth 2.0) and Sanctum
(token / SPA cookie) — as new entries in the `examples/` suite.

The existing five flavors (`vanilla`, `form-requests`, `spatie-data`, `query-builder`,
`combined`) cover request/response shape conventions; none of them exercises an auth
stack. The examples README still carries a "Coming next: authentication flavors" note
([`examples/README.md`](../../../examples/README.md)) that this spec converts into
concrete work.

---

## Goals

- Two runnable flavors, each living under `examples/<flavor>/`, that boot a real Laravel
  container via Testbench, generate `openapi.yaml`, and pass `openapi:lint` in CI.
- Demonstrate the **end-to-end derivation** of every auth-related output the generator
  produces: `components.securitySchemes` entries, per-operation `security` requirements
  derived from middleware, and `#[Security(scheme: ...)]` overrides.
- Make it obvious at a glance which lines in user code drive which OAS output.
- Cover both auth flows on the same flights/bookings API surface that the existing
  flavors already use, so a viewer can diff against `examples/vanilla/` to see the
  auth-specific additions in isolation.

## Non-goals

- Wiring a working OAuth dance (token issuance, login UI, social providers). The
  example only documents the *spec surface* — auth runtime behaviour is out of scope.
- A combined `passport + sanctum` flavor. Mixed-scheme support already lives in the
  `combined` flavor's `bearer + oauth2` setup and shouldn't be duplicated.
- Custom guards, custom token drivers, custom scope storage. These are application
  concerns that the generator does not interpret.
- A Web UI or interactive playground beyond the existing Scalar route.

## Flavors

### `examples/passport/`

**Convention demonstrated.** Laravel Passport's installed routes are filtered out by
`SkipPassportRoutes` (already enabled in the default config). `oauth2` and
`oauth2ClientCredentials` security schemes are emitted automatically when Passport is
present. `auth:api` middleware → `401`; `scope:flights:read` middleware →
`security: [{oauth2: ['flights:read']}]` requirement.

**Code on display.**
- A `FlightController` and `BookingController` with `auth:api` + `scope:*` middleware on
  the routes. No `#[Security]` attributes — the requirements flow entirely from
  middleware, since that's the Passport-flavored project's natural source of truth.
- A `Passport::tokensCan([...])` registration in a service provider so the lint rule
  `security.scope-undeclared` can verify scope coverage.

**Spec output asserted.**
- `components.securitySchemes` contains `oauth2` (authorizationCode + refresh) and
  `oauth2ClientCredentials` (clientCredentials), both pointing at the Passport-configured
  token / authorize URLs.
- `GET /flights` carries `security: [{oauth2: ['flights:read']}]`.
- `POST /flights` carries `security: [{oauth2: ['flights:write']}]` and a `401` plus
  `403` response under `responses`.
- Passport's own `/oauth/*` routes are absent from `paths`.

### `examples/sanctum/`

**Convention demonstrated.** Sanctum doesn't auto-publish OAS-relevant identifiers, so
the example registers a `bearer` security scheme via `openapi.security_schemes` and
sets `openapi.security_default_scheme = 'bearer'`. `auth:sanctum` middleware → `401`;
`abilities:flights:read` middleware → `security: [{bearer: ['flights:read']}]`.

**Code on display.**
- The same controllers, with `auth:sanctum` + `abilities:*` middleware. Again no
  `#[Security]` on call sites — middleware is the source of truth.
- A `config/openapi.php` overlay declaring the `bearer` scheme and the default.

**Spec output asserted.**
- `components.securitySchemes.bearer` has `type: http, scheme: bearer, bearerFormat:
  JWT` (or `Bearer`, TBD during implementation — the spec is permissive, just pick one
  and stick to it).
- `security_default_scheme = 'bearer'` flows through, so middleware-derived security
  references the bearer scheme without per-attribute `scheme:` arguments.
- `paths./flights.get.security` matches `[{bearer: ['flights:read']}]`.

## Domain surface

Identical to the existing five flavors. The flights/bookings API is the constant; the
auth stack is the variable. Re-using the shared domain keeps the diff against
`examples/vanilla/` small enough to read in one viewing.

## Open questions to resolve before building

- **bearerFormat.** Sanctum's plain-text tokens aren't JWTs; `bearerFormat: Sanctum`
  is unconventional but accurate. Defer until implementation.
- **Scope vs. ability vocabulary.** Sanctum calls them "abilities", Passport "scopes".
  The generated spec speaks scopes either way (OAS doesn't have an "ability" concept).
  Document this in the flavor's README rather than in code.
- **Should the Passport flavor exercise the `oauth2ClientCredentials` flow too?** A
  second endpoint guarded by `client_credentials:flights:server` middleware would
  cover it but adds surface area. Decide during implementation.
- **CI integration.** The capstone `PluginSuiteIntegrationTest` shape (one suite,
  multi-flavor assertions) vs. per-flavor feature tests. Probably per-flavor for these
  two — they're more demo than smoke.

## Build steps (when picked up)

1. Scaffold `examples/passport/` from `examples/vanilla/` and add Passport scopes +
   middleware. Generate `openapi.yaml`. Add a feature test asserting the security shape.
2. Scaffold `examples/sanctum/` likewise. Register the bearer scheme via config.
   Generate. Add a feature test.
3. Add `composer examples:passport` and `composer examples:sanctum` scripts. Hook into
   the `composer examples` aggregate.
4. Update `examples/README.md`: remove the "Coming next" line, add the two flavor
   entries to the comparison table.
5. Update the top-level README plugin matrix to point at the auth flavors when
   explaining `Security` and `security_schemes`.
