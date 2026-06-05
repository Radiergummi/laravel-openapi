# Changelog

All notable changes to this project are documented here.

## [Unreleased]

### Added

- `#[RequestField]` is now also valid (and repeatable) on a controller action, documenting a request body field-by-field — the escape hatch for actions that validate outside a `FormRequest`/Spatie Data class (e.g. in an Action/service, where the body param is a bare `Illuminate\Http\Request`). Each `#[RequestField('name', …)]` becomes one request-body property; `required: true` fields populate the schema's `required` list; it composes with `#[RequestBody]` for the envelope/media type. On a method the new leading `$name` parameter is required (it is still derived from the target for the existing property / `PARAM_*`-constant placements, which are unchanged). A new Core `RequestFieldRequestSchemaResolver` runs ahead of the FormRequest resolver, so an explicit declaration wins over a type-hinted FormRequest. Closes #110; surfaced by the Vito full-spec attribute proof, where every write op validates in an Action class and previously had no attribute-expressible request body. (#110)
- Sanctum's `abilities:` and `ability:` route middleware now populate a route's per-operation security scopes. `abilities:` is all-of: `abilities:read,write` lists both `read` and `write` on a single requirement. `ability:` is any-of: `ability:read,write` emits one OR-alternative requirement per ability (`[{sanctum:['read']},{sanctum:['write']}]`), matching Sanctum's "any one ability" check; when combined with `abilities:`, each alternative also carries the all-of scopes. Scopes deduplicate. (#33, #90)
- `openapi.security_middleware_map` config key: maps a custom guard-middleware name to a scheme declared in `security_schemes`, so routes using project-specific guards (beyond `auth:sanctum` / `auth:api`) still emit a per-operation `security` requirement instead of looking public. A mapped entry takes precedence over the auto-derived `auth:*` / `scope:*` scheme resolution for that route (route scopes still attach to the mapped scheme); routes with no matching entry are unaffected. (#34)

- `openapi.operation_id_strategy` config key selecting how each operation's `operationId` is derived: `'route-name'` (default — the existing behaviour: sanitised route name, or `{method}_{path}` for unnamed routes; byte-stable with prior output) or `'method-path'` (always `{method}_{path}`, ignoring route names — for client toolchains where route names aren't meaningful method names). Every strategy's output is sanitised to satisfy the `operation.id-invalid-chars` rule; unknown values fall back to `'route-name'`. (#43)
- Lint rule `operation.return-type-missing` (level 3): nudges controller actions that have neither a usable return type (absent / `mixed` / `void`-like) nor a response-declaring attribute (`#[Response]` / `#[ResponseResource]`) — the most common reason a generated operation emits no response schema. Documentation-quality only; off at the default `--level 1`. (#44)

- Response schemas are now derived from Eloquent model metadata when a controller returns a model
  (or a `Collection<Model>`) directly. The schema is built by reflection from `$casts`, the model's
  `@property`/`@property-read` docblock, typed `$appends` accessors, and `$hidden`/`$visible`;
  `@property-read` model relations become `$ref`s to nested component schemas. Document computed
  fields with `@property-read` (or a typed legacy accessor) to include them. (#18)
- `openapi:lint --fix` and `--check`: auto-fix for the mechanical, unambiguous lint findings. `--fix` rewrites the offending PHP source in place (then lists the modified files so you can run your formatter, e.g. `vendor/bin/pint --dirty`); `--check` is a CI-safe dry run that writes nothing and exits `1` when any fix is pending (like `vendor/bin/pint --test`). Both compose with `--only` / `--skip` / `--level` / `--path` / `--spec`. Phase 1 covers the Tier A removals — `tag.duplicate`, `queryparam.duplicate`, `response.duplicate-status`, `link.duplicate-name`, and `field.no-effect` — by deleting the redundant or no-op attribute via `nikic/php-parser` (now an explicit dependency); a rule opts in by implementing `Lint\Fix\FixableRule`. Findings without an owning fixable rule are reported, never rewritten. See [Linting → Auto-fixing findings](docs/linting.md#auto-fixing-findings).
- `openapi.overrides` config key: a spec-only escape hatch to set operation-level fields (`operationId`, `summary`, `description`, `tags`, `deprecated`, and any `x-*` extension) per route name or URI glob, without touching controller code. Applied as a late pipeline stage (always loaded, independent of any plugin). Overrides beat plugin contributions and convention-derived values; a code-based `transformDocument()` callback still wins. URI globs match by specificity (literal-character count, ties broken by declaration order); an exact route-name key wins over any glob. See [Configuration → Operation overrides](docs/config.md#operation-overrides).
- Lint rules `overrides.unknown-field` (flags an override field outside the allowlist) and `overrides.unused` (flags an override key matching no route name or URI). Both are level 3 and severity-overridable.
- New `SkipSelfRoutes` route filter that excludes the library's own spec/playground routes (matched on the `openapi.` Laravel-route-name prefix) from the generated document. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-bundled-docs-controller-self-findings.md`) and BookStack survey (`docs/internal/dogfooding/2026-05-30-bookstack-self-route-pollutes-api-namespace-and-spec.md`).
- Architecture tests (`tests/Arch/ConventionsTest.php`) pinning three conventions previously enforced only by review: every source and test file declares `strict_types`; no plugin imports a sibling plugin (Core included — the convention plugins reach shared logic through `Support\`, not Core); and the public `Contracts\` surface references no `@internal` class. The pre-existing `tests/Arch/CoreBoundaryTest.php` expectations, which named the long-gone `Radiergummi\OpenApi\Core` namespace and passed vacuously, were repointed to the real `Plugins\Core` / `Support` namespaces.
- Behaviour-coverage gaps from the authoring-attribute audit (#86): `tests/Feature/ResponseExampleTest.php` asserts a well-formed `#[ResponseExample]` surfaces under the matching response's `content.*.examples`; `OperationTagsTest` gains a standalone `#[Tag]` case asserting the tag reaches `operation.tags`; and `VisibilityResolverTest` gains the `Expose(except:)` pair symmetric with the existing `Hide(except:)` cases; and `tests/Feature/SpecAttributeTest.php` asserts end-to-end that a `#[Spec('v1')]` route is pinned into the `v1` document and kept out of `v2` (overriding `v2`'s matching `prefix`) and `default`. The audit confirmed every other authoring attribute already has a behaviour test (`BaseExample`/`FieldAttribute` are abstract bases).
- `ValidationRulesToSchema` now maps four validation rules it previously dropped at its silent-ignore arm (#83): `multiple_of:N` → `multipleOf` (integer args stay int, decimal args become float — needed a new `multipleOf` slot on `FieldDescriptor`, wired through to the `OA\Schema` output), `active_url` → `format: uri` (consistent with `url`), `mac_address` → a colon/hyphen six-octet `pattern`, and `hex_color` → an optional-`#` six-digit hex `pattern`. `lowercase`/`uppercase` (only a lossy ASCII pattern that wrongly accepts multibyte uppercase) and `current_password` (a server-side auth check with no schema shape) stay intentionally unmapped. See [Request bodies → Validation rules → schema constraints](docs/request-bodies.md#validation-rules--schema-constraints).
- Sanctum security-scheme auto-derivation (#32): when any discovered route carries the `auth:sanctum` middleware, the generator now registers a `sanctum` http/bearer security scheme and emits the per-operation `security` requirement for those routes — previously a Sanctum-protected API produced zero schemes and empty `security`, silently mislabelling protected routes. Detection keys off the `auth:sanctum` middleware token rather than `class_exists(Sanctum::class)`, because Sanctum ships by default in fresh Laravel apps and is not always used for API auth; the token is the only signal that it is. The scheme joins the auto-derived defaults (after Passport's pair when both are present), config still wins on name collision, and the `#[Security]` / `#[PublicEndpoint]` override hatch is unchanged. Sanctum token *abilities* (`abilities:` / `ability:` middleware → scopes) remain unmapped — tracked as #90. Surfaced across six OSS-survey apps (Pelican, Koel, Vito, AdvisingApp, Speedtest Tracker, AureusERP).

### Changed

- Resolver fault isolation is now centralized in a single `Support\Registry\ResolverFaultBoundary`, applied uniformly at every resolver seam — primary-response, request-schema, query-parameter, and ref-schema (`OperationBuilder` and `RequestBodyExtractor`), including third-party plugin resolvers. Previously each resolver self-isolated inconsistently (some `catch (Throwable)`, some only `catch (ReflectionException)`, others not at all), so whether one malformed route degraded gracefully or aborted the whole `openapi:generate` run depended on which resolver happened to handle it. The boundary catches `Exception` only: a malformed route logs a warning (with route + resolver context) and is skipped while the rest of the document still generates, but `Error`/`TypeError` — programming bugs in resolver code — now propagate as stack traces instead of being swallowed into a silently missing schema. The five primary-response resolvers' redundant route-level `catch` blocks were removed; `SchemaFromFormRequest` and `SchemaFromDataClass` keep their fine-grained degrade-with-fallback catches (which still emit a degraded finding / partial schema) over `Throwable`, since they invoke arbitrary host-app code (`rules()` / `getValidationRules()`) that routinely raises `Error`/`TypeError` at spec time. No behaviour change for well-formed routes. (#100)
- Relocated the shared `ValidationRulesToSchema` (Laravel validation-rule → JSON-Schema mapper) and `FakerExampleSynthesiser` from `Plugins\Core\Support\` to `Support\Extraction\`. Both are plugin-agnostic and used by Core and the SpatieData plugin alike; living under `Support\` lets the convention plugins reach them without importing the Core plugin, which the new plugin-isolation arch test now forbids. Behaviour is unchanged.
- Lint rules `tag.duplicate` and `queryparam.duplicate` now detect duplicates by reading the `#[Tag]` / `#[QueryParam]` attributes on the controller method via reflection, rather than scanning the generated operation. The generator deduplicates both upstream (tags via `array_unique`, query parameters keyed by name), so a repeated attribute never reached the old spec-level check and the rules could not fire from real code — which also meant their `--fix` removers could never run. Severities were corrected to match impact: `tag.duplicate` 0 → 3 (a redundant tag changes nothing in the valid output — pure source hygiene, alongside `field.no-effect`), `queryparam.duplicate` 0 → 1 (the last-wins merge silently drops the earlier declaration's details). Consequence: `tag.duplicate` now only runs at `--level=3`/`max`; `queryparam.duplicate` still runs at the default level. The guarantee that duplicate parameter `(name, in)` never reach the document is unchanged, still enforced by the level-0 `parameter.duplicate-name`. Surfaced by Phase 1 dogfooding (#78).
- Widened `zircote/swagger-php` support to `^5.8 || ^6.1.2`, so apps pinning swagger-php 5.x
  (e.g. those that self-generate OpenAPI from `#[OA\*]` attributes) can install the package.
  A dedicated CI job runs the suite against swagger-php 5.8; the byte-exact example snapshots
  stay pinned to 6.x key ordering (5.x emits semantically-identical YAML in a different order),
  so the `snapshot` group is excluded from that job only.
- Widened the `symfony/type-info` constraint from `^8.0.9` to `^7.3 || ^8.0`. The component is used only for type→schema mapping and the APIs we rely on are stable across the 7.3 and 8.x lines; pinning `^8` needlessly forced a Symfony 8 component into otherwise Symfony-7 Laravel 12 installs. Verified against both the 7.3 floor and the 8.x line (full suite + PHPStan L8). Surfaced by OSS-survey dogfooding (`docs/internal/dogfooding/2026-05-31-pelican-spatie-data-pre-4.23-blocks-install.md`).
- Default `config('openapi.filters')` now includes `SkipSelfRoutes` as the first entry. A stock install no longer documents the library's own `/api/openapi.yaml` and `/api/docs` endpoints in the generated spec. Remove the entry from your published config to opt back in.
- CI matrix pins `orchestra/testbench` per Laravel cell (`^10.0` for `laravel: 12.*`, `^11.0` for `laravel: 13.*`) and asserts the resolved `laravel/framework` major matches the matrix variable after `composer update`. Previously the `12.*` cell silently floated to Laravel 13 via testbench 11, letting a Laravel-13-only API ship and break the package on the entire L12 range. `tests/TestCase::defineEnvironment()` also forces `filesystems.disks.local.serve` to false so the testbench-10 default (which registers two extra `storage/{path}` routes that aren't in the testbench-11 baseline) doesn't drift snapshots between cells. Surfaced by OSS-survey dogfooding (`docs/internal/dogfooding/2026-05-30-survey-ci-laravel12-cell-illusory.md`).
- PHPDoc parsing now uses `phpstan/phpdoc-parser` with `symfony/type-info` for type
  resolution, replacing `phpdocumentor/reflection-docblock`. This removes the direct
  dependency on the `phpdocumentor` type stack (`reflection-docblock` /
  `type-resolver`), so apps depending on the older major of those libraries can now
  install the package. New `Support\PhpDoc\DocBlockParser` and `Support\Types\TypeNodeResolver`
  services provide a reusable foundation for parsing further PHPDoc tags.

### Fixed
- A `#[Response(schema: [...])]` literal with a nested `properties` (object) or `items` (array) now validates on swagger-php 5.x (#116). `buildResponseFromAttribute()` passed the raw array straight to `new OA\Schema(...)`, leaving the nested arrays unconverted; swagger-php 5.x's validator rejects that (`@OA\Schema()->properties is an object literal`), failing `openapi:generate` — while 6.x silently tolerated it. The literal is now recursively converted into an `OA\Property` / `OA\Items` object graph, which validates on both the `^5.8` and `^6.1.2` lines. Surfaced by the Vito full-spec attribute proof. (#116)
- `openapi:generate` no longer hard-fails when a field attribute declares `type: 'array'` (#115). An items-less array schema is invalid and swagger-php rejected it, aborting the whole run — so a single `#[ResourceField('tags', type: 'array')]` took down generation. Array schemas now always carry an `items` schema. The field attributes (`#[ResourceField]`, `#[RequestField]`, `#[ResponseField]`, `#[QueryParam]`) gain an `items:` parameter naming the element type (`items: 'string'` → `items: {type: string}`); when omitted, an array emits a permissive `items: {}`. Surfaced by the Vito full-spec attribute proof. (#115)
- `openapi:generate` no longer aborts when a host app's `FormRequest::rules()` or Spatie Data `getValidationRules()` raises an `Error`/`TypeError` (rather than an `Exception`) at spec time — e.g. a `rules()` body that passes a route-binding placeholder into a typed Rule constructor (`TypeError`), calls a method on a null `auth()->user()` (`Error`), or reads an uninitialised typed request property. The #100 fault-isolation change narrowed these two userland-invocation seams (`SchemaFromFormRequest`, `SchemaFromDataClass`) from `catch (Throwable)` to `catch (Exception)`, so such throws escaped the per-schema degrade-with-fallback and crashed the whole run. Both seams catch `Throwable` again — they invoke arbitrary host-app code, unlike the library-internal resolvers the `Exception`-only `ResolverFaultBoundary` guards — degrading to a placeholder schema plus a `request-body.schema-degraded` finding. Regression surfaced re-running the OSS-survey dogfooding (Invoice Ninja, Bagisto, Pelican, Koel).
- `openapi:lint --path` / `--diff` no longer leak generation-time findings for out-of-scope routes (#50). The post-generation filter kept any finding with no `routeUri`, treating it as route-agnostic — but several extractor findings are *schema*-derived (`rule.unknown`, `rule.invalid-enum-value`, `request-body.schema-degraded`), emitted from registry-cached builders that have no single route because a FormRequest / Data class is `$ref`'d by many routes. These now carry their `source_class` and are scoped by *reachability*: a schema finding survives the filter exactly when an in-scope route references that schema (so a schema shared between an in-scope and an out-of-scope route stays visible — the filter never hides an in-scope finding). `errors.resolver-failed`, which is genuinely route-scoped via its `ErrorDescriptor`, now stamps `routeUri` directly. Surfaced from dogfooding the linter.
- A class-scoped `#[IgnoreLint]` now silences the schema-derived findings `rule.unknown`, `rule.invalid-enum-value`, and `request-body.schema-degraded` (#94). These recorded the originating class under an ad-hoc `source_class` context key that `SuppressionDirective::classMatches()` never read; they now use the canonical `Finding::CONTEXT_SOURCE_CLASS` key like every other source-class-bearing finding, so class-scoped suppression reaches them.
- Named routes with characters outside the codegen-safe identifier set no longer leak an invalid `operationId` that the bundled linter then rejects (#42). `PathsStage::buildOperationId()` previously returned the route name verbatim, so a namespaced name like `api:client.show` (the `:`) or a name carrying a bound segment (the `{}`) tripped the package's own `operation.id-invalid-chars` rule on a fresh run. The named-route branch now sanitises to the rule's pattern (`^[A-Za-z][A-Za-z0-9._-]*$`): disallowed characters become `_` and any leading non-letter is stripped, while the `.`/`-`/`_` the pattern permits are preserved so dotted names like `api.users.show` stay intact. Surfaced while dogfooding against Pelican.
- Docs: corrected the "Enrich a response field" recipe and `#[ResponseField]`'s docblock, which advertised placing `#[ResponseField]` on an API Resource `FIELD_*` class constant — a path no code reads. API Resource keys are declared with the class-level `#[ResourceField]` (ApiResources plugin); `#[ResponseField]`'s real surface is Spatie Data class properties. Found by the #86 attribute audit.
- Auth-gated routes for which no security scheme can be derived (e.g. `auth:sanctum` with Sanctum not yet supported, or any auth route when the scheme catalogue is empty) now **omit** the operation's `security` field instead of emitting `security: []`. In OpenAPI an explicit `[]` is the *affirmatively public* signal, so the generator was documenting a route it knows is authenticated as explicitly public — and `operation.security-missing` stayed silent because it could not tell that state apart from an intentional `#[PublicEndpoint]`. `SecurityExtractor::forRoute()` now returns `null` for the authed-but-no-scheme case so the field is left unset, letting the operation inherit any document-level requirement and letting the lint rule fire. Genuinely-public routes and `#[PublicEndpoint]` still emit `security: []` unchanged. Closes [#76](https://github.com/radiergummi/laravel-openapi/issues/76); surfaced across six Sanctum apps in the OSS-survey dogfooding.
- `#[Security]` is now repeatable: stacking multiple instances on a method or controller class produces one OR-alternative per attribute in the operation's `security` list. The class-level docblock advertised this, but the attribute lacked `Attribute::IS_REPEATABLE` and `OperationBuilder` only read the first instance, so the documented pattern threw at attribute resolution. Method-level attributes still win over class-level ones — if the method declares any `#[Security]`, the class-level instances are ignored.
- `DataResponseResolver` now handles union return types: `FlightData|OtherData` emits a `oneOf` of `$ref`s; a mix of Data and non-Data members (e.g. `FlightData|RedirectResponse`) collapses to a bare `$ref` for the Data member. Unions with no Data members defer to the next resolver. Previously every union return type fell through to a bare 200.
- `openapi:generate`/`openapi:lint` no longer crash when a controller, FormRequest, or Data class carries a malformed `@psalm-import-type`/`@phpstan-import-type` alias (or has an unreadable source file). Building the type context resolves every such alias on the class — annotations the resolver never consumes — and a broken one threw an exception that aborted the whole run. `TypeNodeResolver` now degrades to a context-free resolution instead.
- Single-action (`__invoke`) controllers are now introspected via their `__invoke` method rather than the class. Their `@return`/`@throws` docblocks, return type (paginator/collection item inference), parameters, and method-level attributes now describe the operation; previously these were read from the class (or ignored), so a single-action controller's docblock, response schema, and transitive-throws were silently dropped. Class-level attributes remain a fallback when `__invoke` carries none.
- `@return` paginator/collection item types are now resolved when the return type is nullable — `@return ?Paginator<Item>` and `@return Paginator<Item>|null` previously yielded no item schema.
- `SchemaFromDataClass` now passes a `TypeContext` (derived from the property's declaring class) when resolving Spatie Data property types, so a property typed `self`/`static`/`parent` (e.g. a recursively-nested Data class) resolves correctly. `symfony/type-info` 7.3+ throws `InvalidArgumentException` for those relative identifiers without a context; the previous code relied on 8.0's more lenient behaviour, which blocked the `^7.3` constraint widening above. Required for `symfony/type-info ^7.3 || ^8.0` support; verified on both lines.
- `openapi:lint --path=…` (and `--diff`) now also scopes findings emitted by generation stages — previously only the tree-walk phase respected the filter, so findings emitted during generation (e.g. `request.empty` from `RequestBodyExtractor`, `request-body.schema-degraded` from `SchemaFromFormRequest`) leaked through against routes outside the glob. The runner now drops post-collection findings whose `location.routeUri` does not match the filtered descriptor set; non-route-scoped findings (pre-build, spec-level) are preserved. Surfaced by discovered finding (`docs/internal/dogfooding/2026-05-30-discovered-lint-path-filter-skips-extractor-findings.md`).
- `FakerExampleSynthesiser` now reseeds Faker per `synthesise()` call from `(openapi.examples.faker_seed XOR crc32(fieldName))` so generated examples are stable regardless of preceding consumers of PHP's global `mt_rand` state. Faker's `seed()` delegates to `mt_srand()`, so the previous "seed once in the constructor" approach drifted when any framework boot code or earlier test consumed `mt_rand` between construction and the first `synthesise()` call — manifesting as `tests/Feature/ExamplesTest` failing intermittently when the full suite ran (~1 in 3) on a SpatieData flavor date field. Surfaced by discovered finding (`docs/internal/dogfooding/2026-05-30-discovered-examples-test-faker-date-flake.md`).
- Documented the nested-Data inlined-composition limitation in `docs/linting.md`'s suppression matrix: when a Data class composes a nested Data class as a typed property and the nested type is **inlined** (no `$ref`), findings on the inlined branch are stamped with the parent's source class. Property-scope `#[IgnoreLint]` on the nested class's field does not match in that case; use class-scope on either class instead. The `$ref` path (the default in practice for most Spatie Data codebases) is unaffected. Surfaced by discovered finding (`docs/internal/dogfooding/2026-05-30-discovered-nested-field-sourceclass-stamping.md`).
- `SpecTimeRequest::wire()` resolves a FormRequest's constructor dependencies through the container instead of `new $class()` (zero args). FormRequests with typed constructor args (a valid Laravel pattern — the framework injects them at request time) previously degraded with an `ArgumentCountError`, dropping their `rules()` from the spec and emitting a `request-body.schema-degraded` finding. We can't call `$container->make($formRequestClass)` directly because `FormRequestServiceProvider` registers an `afterResolving` callback that runs `validateResolved()` and throws at spec time (no HTTP input); the new `SpecTimeRequest::resolveConstructorDeps()` helper resolves each constructor arg via the container and then `new`s the class with the splatted args, bypassing the callback while still satisfying DI. Surfaced by OSS-survey dogfooding against Bagisto (`docs/internal/dogfooding/2026-05-30-bagisto-formrequest-constructor-di-degraded.md`).
- `SchemaFromFormRequest` / `ValidationRulesToSchema::process()` no longer crashes when a FormRequest field's ruleset is a **bare Rule object** (`'name' => new ValidCompanyQuantity()`) rather than a pipe-string or array. Laravel permits a single Rule instance as a field's value; the unguarded `normalizeRules(string|array)` call raised `TypeError: Argument #1 ($rules) must be of type array|string, … given`, aborting generation for the whole app. A bare object is now wrapped into a single-element list so it flows through the same path as an in-array Rule object (an unintrospectable rule yields a bare field plus the existing `rule.unknown` finding). The dotted-key branch keeps its own guard, so closures/objects on dotted keys remain skipped as before. Surfaced by OSS-survey dogfooding against Invoice Ninja (`docs/internal/dogfooding/2026-05-30-invoiceninja-formrequest-bare-rule-object-crash.md`).
- `IdentifierCase::Dot` regex widened to allow kebab-case within dot-separated segments. The Laravel-idiomatic operation ID style (`api.v0.projects.list-active`, `auth.resolve-account`) now passes `operation.id-naming-inconsistent` under the default config; strict-lowercase identifiers continue to match. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-operation-id-style-rejects-laravel-idiom.md`).
- `path.segment-naming-inconsistent` accepts a short file-extension tail (`.yaml`, `.atom`, `.pdf`, etc.; 1–8 lowercase alphanumerics) on path segments under any configured case, unblocking the `/api/openapi.yaml`, RSS/Atom feed, and downloadable-report idioms. The segment head must still conform to the configured case. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-path-segment-style-rejects-file-extensions.md`).
- `path.parameter-undefined` accepts either `{name}` or `{name?}` as a valid placeholder for any path parameter, decoupling Laravel's URI-template syntax from OAS's always-`required: true` rule. Surfaced by P4 dogfooding (`docs/internal/dogfooding/2026-05-29-p4-path-parameter-undefined-misses-optional-laravel-syntax.md`).
- `resource.fields-undeclared` no longer fires when the resolved resource class is `Illuminate\Http\Resources\Json\JsonResource` or any abstract subclass (e.g. anonymous `Model::toResource()` returns). The `resource.response-ambiguous` rule already covers the missing-item-type case. Surfaced by P4 dogfooding (`docs/internal/dogfooding/2026-05-29-p4-resource-fields-undeclared-anonymous-toresource.md`).

### Added
- `ResourceClassLocator` (`Radiergummi\OpenApi\Contracts\Routing\ResourceTargetLocator`) now reads Laravel's `#[Collects]` attribute and the legacy `$collects` property on `ResourceCollection` subclasses, resolving the item resource class for collections returned without an explicit `#[ResponseResource]`. Surfaced by P4 dogfooding (`docs/internal/dogfooding/2026-05-29-p4-resource-collection-collects-attribute-unread.md`).

### Changed
- `parameter.name-naming-inconsistent` now reads two separate config keys: `openapi.lint.style.path_parameter_case` (default `camel` — matches Laravel's route-binding idiom where path parameters take their names from controller method variables) and `openapi.lint.style.query_parameter_case` (default `snake` — matches JSON:API convention). The previous `openapi.lint.style.parameter_name_case` key has been removed; pre-1.0, no migration shim. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-parameter-name-style-default-mismatches-laravel.md`).
- `ComponentSchemaRegistry::deriveKey()` disambiguates basename collisions via PascalCase concatenation (e.g. `UsersContactInfoRequest`) instead of dot-joining (`Users.ContactInfoRequest`). Generated component keys now conform to the default `component.name-naming-inconsistent` PascalCase rule out of the box; consumers that pinned to the old key form must update. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-component-name-contains-dot-separator.md`).

### Added
- `Contracts\Routing\ResourceTargetLocator` — public contract for resolving the resource class and cardinality an action returns. Bound to `Plugins\ApiResources\ResourceClassLocator` (the existing `JsonResource`-aware implementation) in `OpenApiServiceProvider`. Plugin authors building response resolvers for alternative resource conventions (JSON:API, HAL, Fractal, …) should inject this contract instead of reimplementing `#[Collects]` / `$collects` / `#[ResponseResource]` detection. Surfaced by P3 dogfooding (`docs/internal/dogfooding/2026-05-29-p3-resource-class-locator-not-exposed-as-contract.md`).
- `Testing\ActionDescriptorFactory::make(...)` — static factory that builds an `ActionDescriptor` from a controller class-string + method name with sensible defaults and named-argument overrides. Replaces the ~10-line `makeDescriptor` helper plugin tests previously had to declare per file. Ships under the package's regular autoload, so downstream consumers get it in their test suites. Surfaced by P3 dogfooding (`docs/internal/dogfooding/2026-05-29-p3-action-descriptor-factory-missing-from-testing-package.md`).
- `Testing\SchemaContextScope::with(callable): mixed` — scope guard that pins swagger-php's global `Generator::$context` to OAS 3.1.0 for the duration of a callable. Required when plugin tests construct `OA\Schema` instances outside the generator pipeline; without it, `Schema::jsonSerialize()` silently rewrites 3.1-only keywords (`const` → `enum`, …) under swagger-php's default 3.0.0 context. Surfaced by P3 dogfooding (`docs/internal/dogfooding/2026-05-29-p3-component-schema-registry-method-shape-mismatch.md`).
- `ComponentSchemaRegistry::isInProgress(class-string): bool` is now public (read-only). Plugin code that recurses into the registry from a `buildOnce` factory can detect the re-entrance and choose a `$ref`-shaped placeholder rather than triggering a nested rebuild. Surfaced by the same P3 dogfooding L file as above.

### Changed
- The `ResourceTarget` DTO moved from `Plugins\ApiResources\ResourceTarget` to `Routing\ResourceTarget`. It is a routing-domain type (no ApiResources-specific dependencies); the new namespace sits next to `ActionDescriptor`. The class itself is unchanged. Surfaced by P3 dogfooding alongside the `ResourceTargetLocator` contract extraction.
- Baseline pipeline orchestration is now owned by `Support\Generator\BaselineRegistration` instead of `Core\CorePlugin` (renamed from `Core\Registration`). The five infrastructure stages (`RootStage`, `PathsStage`, `ErrorResponseInferenceStage`, `ComponentsStage`, `SecurityStage`) and the library-wide lint rules register before any plugin runs. `ErrorResponseInferenceStage` moved from `Core\Stages\` to `Support\Generator\Stages\` and `ErrorsResolverFailed` moved from `Core\Lint\` to `Lint\Rules\` so the rule whose finding is emitted by the baseline stage lives next to its peers, not in a plugin namespace. A user who disables the Core Plugin (e.g. to swap in a custom inference stack) no longer silently loses the error-response inference machinery or the rule registrations that suppress `meta.unknown-rule` against `errors.resolver-failed` annotations. Surfaced by P3 dogfooding while authoring a JSON:API plugin that only contributes error contributors.

### Added
- `ErrorDescriptor` carries an optional `ActionDescriptor $action` property identifying the route that produced the error response. An `ErrorResponseResolver` can now scope its envelope per-route — typically by returning null on routes where the configured `error_envelope` should win and a non-null body on routes the plugin owns. Previously a plugin had to register an unconditional resolver and accept that non-JSON:API routes would inherit its body shape. The three baseline contributors (`ThrowsErrorContributor`, `MiddlewareErrorContributor`, `ValidationErrorContributor`) pass the route's `ActionDescriptor` through; the property is nullable so future contributors without a route context (e.g. webhook-only or component-default errors) remain expressible. Surfaced by P3 dogfooding (`docs/internal/dogfooding/2026-05-29-p3-error-descriptor-no-route-context.md`).

### Fixed
- Console commands now declare their name and description via the version-portable `protected $signature` / `protected $description` properties instead of the `#[Signature]` / `#[Description]` attributes. `Illuminate\Console\Attributes\*` only exists on Laravel 13+; on Laravel 12 the attributes were silently ignored, every `openapi:*` command resolved to an empty name, and `package:discover` aborted with "The command defined in … cannot have an empty name" — i.e. the package was uninstallable on the whole of its advertised `^12.0` range. The library's own test matrix masked this because its dev toolchain (`orchestra/testbench`) resolves Laravel 13 even for the `12.*` cell; the `OpenApiServiceProviderTest` command-registration test now asserts all five command names so the gap regresses loudly. Surfaced by OSS-survey dogfooding against BookStack (`docs/internal/dogfooding/2026-05-30-bookstack-console-attributes-laravel13-only.md`).
- `RouteIntrospector` no longer aborts the entire run when a route points at a controller method that does not exist on the class (stale route definitions, methods handled via `__call`, etc.). It already degraded gracefully for a missing controller *class*; it now applies the same `hasMethod()` guard for a missing *method*, emitting an `ActionDescriptor` with a null method rather than letting a `ReflectionException` escape. Surfaced by OSS-survey dogfooding against BookStack (`docs/internal/dogfooding/2026-05-30-bookstack-route-introspector-missing-method-crash.md`).
- Closure (and other non-string) route middleware no longer crashes generation. Routes may carry inline closure middleware via controller middleware, which `gatherMiddleware()` surfaces without casting to string. `SecurityExtractor::expandGroups()` (`Cannot access offset of type Closure in isset or empty`) and the `MiddlewareErrorContributor` string-typed detectors (`Argument #1 ($entry) must be of type string, Closure given`) both assumed `list<string>`; they now skip non-string entries, which can neither name a middleware group nor map to a security scheme. Surfaced by OSS-survey dogfooding against BookStack (`docs/internal/dogfooding/2026-05-30-bookstack-security-extractor-closure-middleware-crash.md`).
- `SchemaFromFormRequest` no longer crashes when `rules()` reads runtime request state. The FormRequest is instantiated with a permissive route + user context: `$this->route('foo')` resolves to a stub for any binding name, `$this->user()` resolves to the same stub, and chained property/method access on the stub terminates without throwing. The rules array's structure (keys, types, required-ness, file detection) is preserved; only the values inside `Rule::in([...])` etc. are opaque placeholders — which is correct, since those values are not part of the OpenAPI schema. The existing `request-body.schema-degraded` finding now fires only for the residual cases (rules() branching on a type check, calls into a container service that is not bound at spec-time). The fix-hint points consumers at `#[IgnoreLint('request-body.schema-degraded', reason: '…')]` and `docs/request-bodies.md` documents the limitation. Surfaced by P4 dogfooding (`docs/internal/dogfooding/2026-05-29-p4-formrequest-rules-runtime-state-crashes-introspection.md`).
- Class-level `#[IgnoreLint]` on a request payload class now suppresses findings on the properties of the component schema that class produces. Previously the directive was collected but never matched — the `ClassScope` branch of `SuppressionDirective::suppresses()` only compared source file paths, while findings emitted from the spec tree carry a JSON-pointer location (`#/components/schemas/<key>/properties/<field>`) and no source file. The walker now stamps `CONTEXT_SOURCE_CLASS` and `CONTEXT_SOURCE_MEMBER` on every finding emitted under a `ComponentSchemaNode` whose owning class is known, and the directive matches structurally on that class context in addition to the existing file-path match. The class-to-component-key map is exposed on `ComponentSchemaRegistry::componentClassMap()`. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-class-level-ignorelint-not-honored-for-component-schemas.md`).
- `Illuminate\Foundation\Http\FormRequest` is now registered as a payload class by `CorePlugin`, so a class-level `#[IgnoreLint]` on a FormRequest is collected when the FormRequest appears as a controller-method parameter. Previously the directive was silently dropped because `SuppressionCollector::fromDataParameter` only descended into types matching a registered payload class, and `FormRequest` was not in the list. `Illuminate\Http\Resources\Json\JsonResource` is now likewise registered by `ApiResourcesPlugin`. A new `SuppressionCollector::collectFromComponentSchemas()` method walks `ComponentSchemaRegistry::componentClassMap()` after generation, picking up class-level directives on classes (typically return-typed `JsonResource`s) the descriptor walk never reached. Surfaced by P4 dogfooding (`docs/internal/dogfooding/2026-05-29-p4-formrequest-rules-runtime-state-crashes-introspection.md`).
- `@throws` on a trait-composed controller method is now resolved against the *trait's* file context (its namespace and `use` imports), not the using class's. PHP's `ReflectionMethod::getDeclaringClass()` reports the using class for a method composed via `use TraitName`, which would make phpDocumentor's `ContextFactory` read the using class's imports and miss any `use` statements that only live in the trait's file — so a bare `@throws SomeAppException` in a trait silently resolved to the wrong FQCN and the per-class `#[ExceptionResponse]` attribute path was unusable for trait-bound controller methods. `ThrowsExtractor::contextFor()` now walks `getTraits()` depth-first to find the trait that lexically declares the method and substitutes its `ReflectionClass` before invoking `ContextFactory::createFromReflector`. Direct methods, inherited methods, and `ReflectionFunction` reflectors are unaffected. Surfaced by P2 dogfooding (`docs/internal/dogfooding/2026-05-29-p2-throws-extractor-trait-context.md`).
- Path parameters now always emit `required: true`, as required by OpenAPI 3.x §4.8.12.1 ("If the parameter location is `path`, this property is REQUIRED and its value MUST be `true`"). Previously, Laravel's `{param?}` optional-segment syntax produced `required: false`, which yields a spec-invalid document and is rejected by downstream tooling (Stoplight, Spectral, Scalar, openapi-generator). The optional-in-URL signal is preserved as a description suffix ("Optional in URL — the segment may be omitted when calling this route.") so it isn't silently lost. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-optional-path-param-emits-invalid-spec.md`).
- Stock Laravel `Illuminate\Validation\Rules\{Email, Exists, Unique, NotIn}` rule objects are now mapped to JSON Schema constraints in `ValidationRulesToSchema::applyObjectRule()`. `Email` becomes `type: string, format: email` (with an "MX record will be validated." description note when `validateMxRecord()` is set); `Exists` and `Unique` contribute database-constraint descriptions referencing the table/column; `NotIn` contributes a description listing the disallowed values. Previously these factory rules emitted `rule.unknown` findings and their constraints were silently dropped. `ImageFile` is unchanged — it extends `File` and is already handled by the existing `File` branch. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-stock-laravel-rule-classes-unhandled.md`).
- Laravel wildcard rule keys (`*` and `foo.*`) no longer emit a literal property named `*` or silently drop the constraints. A bare `*` rule key — Laravel's idiom for "validate every value in the request body" — now produces a JSON Schema `additionalProperties` entry with the wildcard's constraints applied, instead of a misshapen `properties: { "*": …}` schema. A `foo.*` rule key without a separately-declared `foo` parent now synthesises the parent as `type: array` with the wildcard rule's constraints landing on `items` (previously the wildcard's `itemsFields` entry was silently dropped because no matching property had been created). Deeper-nested paths like `foo.bar.*` and `foo.*.bar` remain unmodelled for now and continue to fall under the existing "some nested fields were skipped" schema description. Surfaced by P1 dogfooding (`docs/internal/dogfooding/2026-05-29-p1-wildcard-rule-key-emits-asterisk-property.md`).

### Added
- Bundled PHPStan extension at `extension.neon` that catches attribute misuses at edit time, before the spec is generated. Auto-registered via `phpstan/extension-installer`; otherwise included manually via `vendor/radiergummi/laravel-openapi/extension.neon`. Fourteen rules ship: `openapi.link.bothOperationTargets`, `openapi.link.missingOperationTarget`, `openapi.example.bothValueAndFile`, `openapi.example.missingValueOrFile`, `openapi.expose.onlyAndExcept`, `openapi.hide.onlyAndExcept`, `openapi.visibility.hideExposeConflict` (unconditional pairs only — env-scoped cases stay with the runtime `visibility.hide-expose-conflict`), `openapi.security.publicAndSecuredConflict`, `openapi.response.duplicateStatus`, `openapi.responseHeader.duplicate`, `openapi.response.refAndSchema` (both `ref` and `schema` set on a `#[Response]` — `schema` wins, `ref` is silently dropped), `openapi.field.rangeOrdering` (literal `min* > max*` on a `FieldAttribute` subclass), `openapi.queryParam.requiredWithDefault` (`required: true` together with a `default:` — the default makes the parameter implicitly optional), and `openapi.exceptionResponse.nonThrowable` (`#[ExceptionResponse]` on a class that doesn't implement `Throwable` — the attribute is silently ignored). Identifiers use camelCase because PHPStan's identifier grammar forbids dashes; the runtime counterparts (where they exist) keep kebab-case. See [`docs/linting.md`](docs/linting.md#static-checks-phpstan).
- Lint rule `response.ref-unresolvable` (level 0) — flags `#[Response(ref: SomeClass::class)]` where no registered `RefSchemaResolver` can resolve the class (e.g. a Spatie `Data` class while the SpatieData plugin is disabled, or a class no convention recognises). The generator silently drops the body in this case — the response is emitted with no content and no broken `$ref` for `ref.broken` to catch — so the failure was previously invisible until you inspected the output. Implemented as a `PreBuildRule` mirroring `spec.unknown-reference`. To support it, `RefSchemaResolver` gains a side-effect-free `canResolve(string $class): bool` companion to `resolveRef()` so the rule can test resolvability without building a component schema.
- `SpecStage` plugin extension point. Plugins can now contribute document-level
  transformations via `OpenApiRegistry::addStage()`, alongside the existing
  resolver and rule registration surfaces. The pipeline runs core stages
  (root, paths, components, security), then any plugin-registered stages, then
  the terminal transformer stage.
- New `openapi:diff:config` artisan command reports drift between the published `config/openapi.php` and the package default — flags added keys, removed keys, and changed default values.
- Auto-synthesised examples for fields without an authored example, using a targeted Faker map keyed by format (`email`, `uuid`, `uri`, etc.) and field-name suffix (`*_email`, `*_url`, etc.). Strict lowest-priority fallback — authored sources always win. Disabled per spec via `config('openapi.examples.synthesise') = false`. Deterministic via `config('openapi.examples.faker_seed')`. `fakerphp/faker` is an optional `require-dev` dependency that degrades to "no example" when absent.
- Field-attribute descriptions now recognise three inline directives — `@example <value>`, `@no-example`, `@enum a, b, c` — letting authors declare examples and enum domains without a separate attribute. `@enum` tokens are coerced by lexical shape (so `@enum 200, 404, 500` yields ints, not strings). An explicit `example:` / `enum:` argument on the attribute always wins over the directive — including when the explicit value is `null`, which suppresses the directive's value.
- Faker example synthesis now also runs for Spatie `Data` class properties, matching the behaviour previously limited to `FormRequest` fields so a `Data` class and its equivalent `FormRequest` produce consistent example slots.
- New `SelfDocumentingRule` interface lets custom Laravel validation rule objects declare their own schema constraints (`description`, `type`, `format`, `pattern`, `enum`, `minLength`/`maxLength`/`minimum`/`maximum`) instead of being dropped to a `rule.unknown` lint finding. A second lint rule ID, `rule.invalid-enum-value`, fires when such a rule returns a non-scalar enum entry; the offending value is dropped and the rest of the documentation still applies.
- `#[Tag]` attribute now accepts a `BackedEnum` case in addition to a string, so consumers can centralise tag taxonomies as an enum.
- Lint rule `response.success-empty-body` (level 2) — flags 2xx responses (other than 204/205/304) that declare no body schema. Catches the silent footgun where a controller with no return type produces a `200` with empty content, breaking client codegen. HEAD operations are skipped.
- Lint rule `request-body.schema-degraded` (level 1) — emitted by `SchemaFromFormRequest` when instantiating a FormRequest or calling its `rules()` method throws. Previously the failure was only visible as a single log warning; the placeholder schema landed in the spec silently. The finding surfaces in `openapi:lint` (and in CI) with the exception message and the FormRequest's file/line.
- `config('openapi.error_envelope')` config key with four presets (`none`, `laravel`, `rfc7807`, `json-api`) selecting the body shape of standard error responses.
- `ErrorDescriptor` and `ErrorResponse` value objects in `Radiergummi\OpenApi\Core\Errors\`.
- Optional `exception` key on `middleware_responses` entries, carrying the canonical thrown exception per middleware so resolvers can branch on exception class.
- `#[Summary]` and `#[Description]` attributes. **On controllers / operations**
  they're standalone alternatives to `#[Operation(summary: …, description: …)]`
  for the common case of overriding just one of those fields. Precedence:
  method `#[Summary]` → method `#[Operation(summary)]` → method docblock →
  class `#[Summary]` → class `#[Operation(summary)]`. Anything written on the
  method — including the docblock — beats class-level attributes. Class-level
  placement is for `__invoke` (single-action) controllers, where it outranks
  the class docblock. **On a Spatie `Data` class or Eloquent `JsonResource`
  class** the same attributes set the component schema's `title` /
  `description`.
- Two new example flavors. `examples/api-resources/` isolates the Laravel
  `JsonResource` convention (output-side only; no FormRequest or Data class).
  `examples/fractal/` exercises `league/fractal` with `#[FractalResponse]` and
  class-level `#[TransformerField]` declarations on the transformer. Both are
  registered in `Examples\Shared\Flavors`, asserted by `ExamplesTest`
  (snapshot + OpenAPI 3.1 validity + clean lint), and runnable via
  `composer examples:api-resources` / `composer examples:fractal`.
- `tests/Unit/Lint/LintRouteFilterTest.php` covers the `--diff` flag in
  isolation by stubbing the git-shelling protected methods. Asserts the
  no-diff baseline, explicit-ref usage, default-ref resolution, and the
  config-touched fallback that bypasses per-descriptor filtering.
- `tests/Unit/Core/Generator/OperationBuilderTest.php` exercises
  `OperationBuilder::build` directly: baseline 200 response, `#[Response(201)]`
  primary override, multi-2xx `#[Response]` merging, `#[Header]` parameter
  emission, and `#[ExternalDocs]` plumbing.
- `EventsTest` now covers the `SkipReason::GlobalFilter` branch of
  `RouteSkipped` (alongside the existing `Visibility` and `SpecMembership`
  cases). Registers an inline `RouteFilter` via `openapi.filters` and asserts
  the event fires with the right reason and summary.
- Authoring-attribute and command coverage gaps (#56): `tests/Feature/AttributeCoverageTest.php`
  pins the two attributes that lacked document-level feature coverage — `#[ResponseField]`
  (description/`readOnly` surfaced on a response component schema) and operation-level
  `#[Deprecated]` (`deprecated: true` on the operation, distinct from the property-level case) —
  plus a multi-attribute combination on one route (`#[Summary]` + `#[Deprecated]` + `#[QueryParam]`
  + `#[Response]` + `#[PublicEndpoint]`). `GenerateCommandTest` gains a zero-route case asserting
  the command succeeds and writes a valid empty-`paths` document. (The other attributes the audit
  listed were already covered — `#[Security]`/`#[PublicEndpoint]`/`#[RequestBody]`/`#[Response]` by
  `AuthoringAttributesTest`, `#[QueryParam]` by `QueryParamClassLevelTest`, `#[ResponseResource]` by
  `PaginatorResponseTest`, `#[Expose]` by `VisibilityDefaultHiddenTest` — and `GenerateCommand`
  already had bad-directory, bad-format, and multi-spec `--output` failure cases.)
- Quality-bar invariant coverage (#57): `tests/Feature/DeterministicGenerationTest.php` pins
  that regeneration is stable — two independent `generateSpec()` runs (with scoped pipeline state
  reset between them, as Octane does) produce identical documents, and every operation gets a
  unique `operationId`. The existing `ValidationRulesToSchemaTest` gains a dataset pinning the
  current silent-ignore contract for rules with no constraint mapping yet (`multiple_of`,
  `active_url`, `mac_address`, `hex_color`, `lowercase`, `uppercase`, `current_password`); closing
  any of these is tracked in #83. (The audit's other invariants were already covered:
  validation→constraint mapping by `ValidationRulesToSchemaTest`, unknown-rule-object findings by
  `RuleUnknownTest`, union `oneOf` by `UnionReturnTypeTest`/`DataDiscriminatorTest`, and optional
  path parameters by `UriParametersExtractorTest` — which correctly keeps `required: true` per
  OpenAPI 3.x §4.8.12.1.)
- Edge-case coverage from the 2026-05-31 audit (#55): `tests/Unit/Lint/RuleRegistryTest.php`
  pins the registry's current no-deduplication behaviour when two rules share an id (both are
  kept, `forLevel` returns both, a severity override keyed by the id collapses both); a new
  `OpenApiGeneratorTest` case asserts the YAML and JSON serialisers produce structurally
  equivalent documents from one generation; and `VisibilityResolverTest` gains the fall-through
  ordering cases where a present `#[Hide]` whose env scope misses defers to `#[Expose]`, then to
  the configured default. (The audit's cyclic-schema and `RouteFilter`/`SpecStage` contract cases
  were already covered by `SchemaFromDataClassTest`, `OpenApiGeneratorTest`, and
  `PluginStageRegistrationTest`.)
- Observability events. The generator and linter dispatch four Laravel events for use as
  read-only notification hooks (mutation still belongs to `OpenApiExtensions` transformers):
  `SpecGenerationStarted`, `SpecGenerationCompleted` (carries the assembled document and
  duration), `RouteSkipped` (carries the route, spec, `SkipReason`, and inclusion summary),
  and `LintFindingEmitted` (fires from any `FindingsCollector::emit()` — covers both
  extractor-emitted findings during generation and rule-emitted findings during lint runs).
  See [`docs/extensions.md`](docs/extensions.md#events).
- Multi-spec support: `config('openapi.specs')` lets one app emit multiple OpenAPI documents,
  partitioned by URL `prefix`, `middleware`, or controller `namespace`. New `#[Spec]` attribute
  pins a route to specific specs explicitly. New `openapi:why` command explains per-route, per-
  spec inclusion; `openapi:generate --explain` prints the same decisions for every (route × spec).
  Lint now runs per spec (`--spec=` narrows; pre-build rules always run). Three new pre-build
  rules: `spec.unknown-reference`, `spec.route-orphaned`, `spec.config-orphaned`. See
  [`docs/multi-spec.md`](docs/multi-spec.md).
- `Radiergummi\OpenApi\Core\Lint\LintRunner` — reusable service that orchestrates one
  lint run from a structured `LintOptions` value object and returns a `LintResult` value
  object (findings + threshold level + exit code). Extracted out of `LintCommand` so the
  lint pipeline is unit-testable without driving the artisan command and reusable from
  programmatic entry points (custom CLI wrappers, HTTP endpoints).
- `Radiergummi\OpenApi\Core\Lint\LintRouteFilter` — separates the --path / --diff
  descriptor-filtering logic (including default-branch detection via
  `git symbolic-ref refs/remotes/origin/HEAD`) into a composable service.
- Console tests for `openapi:generate` (`tests/Unit/Console/GenerateCommandTest.php`)
  covering the configured output path, explicit path-argument override, `--format=json`,
  stdout sink (`path: '-'`), and missing-output-directory failure. The previous coverage
  exercised the command only indirectly via `Kernel::call` in `ExamplesTest`.
- Unit tests for `LintRunner` (`tests/Unit/Lint/LintRunnerTest.php`) covering happy
  path, --no-suppress, config-driven level fallback, --only allowlist filtering,
  config-driven --skip merging, and `--level=max` resolution.
- New plugin extension point: `ErrorResponseContributor`. Plugins can now
  contribute inferred error responses (e.g. a validation-driven 422 from
  their payload type) via `OpenApiRegistry::addErrorResponseContributor()`.
  Core ships three contributors covering `@throws` annotations, route
  middleware, and FormRequest validation; the
  `Core\Stages\ErrorResponseInferenceStage` runs the chain after
  `PathsStage` and dedupes by status (first contributor wins; explicit
  `#[Response]` attributes always override inferred responses).
- Per-operation `ActionDescriptor` lookup on `GenerationContext`
  (`bindAction()` / `actionFor()`) so stages can find the action that
  produced an `OA\Operation`. Populated by `PathsStage`.
- `ErrorResponseInferenceStage` now also decorates operations attached to
  `$doc->webhooks`, matching the pre-refactor behaviour of
  `OperationBuilder` which produced standard responses for both paths and
  webhooks. Webhook routes with `@throws` annotations or middleware regain
  their inferred error responses.
- Stub Rule class `ErrorsResolverFailed` for the `errors.resolver-failed`
  finding emitted by `ErrorResponseInferenceStage` when a resolver throws.
  Registering the ID lets `#[IgnoreLint('errors.resolver-failed')]` pass
  `meta.unknown-rule`, allows severity overrides, and surfaces the rule in
  the lint catalog. The finding now also carries a `fixHint`.

### Changed
- Error-response inference moved out of `Support\Generator\OperationBuilder`
  into `Core\Stages\ErrorResponseInferenceStage`. Closes the
  `Support → Core` layering violation; `OperationBuilder` no longer imports
  from `Core\`. FormRequest-using routes now automatically gain a `422`
  response even without an explicit `@throws ValidationException` (via the
  new `ValidationErrorContributor`); bundled example snapshots updated.
- `Core\Extraction\StandardResponsesExtractor` removed. Its logic is split
  across `Core\Inference\ThrowsErrorContributor`,
  `Core\Inference\MiddlewareErrorContributor`,
  `Core\Inference\ValidationErrorContributor`, and the new
  `ErrorResponseInferenceStage`. Pre-1.0; no migration shim. Third-party
  code depending directly on the class must migrate to the contributor
  chain or to subclassing the stage.
- Restructured namespaces to clarify the public extension surface vs. internal infrastructure: extension-point interfaces moved to `Contracts\` (`Plugin`, `RequestSchemaResolver`, `RefSchemaResolver`, `QueryParameterResolver`, `PrimaryResponseResolver`, `ErrorResponseResolver`, `SpecStage`, `RouteFilter`, `SelfDocumentingRule`); internal infrastructure moved to `Support\` (`OpenApiRegistry`, generator pipeline + stages, spec resolution, inclusion evaluator, visibility resolver, extraction primitives, `ConfigDiffer`); the lint subsystem moved out of `Core\Lint\` to top-level `Lint\`. `Core\` now exclusively holds the **Core Plugin's** concrete strategies: error envelopes (`Core\Errors\`), extractors (`Core\Extractors\`), the default query-parameter resolver (`Core\Resolvers\`), the paginator schema factory (`Core\Pagination\`), the Faker example synthesiser (`Core\Examples\`), route introspection (`Core\Routing\`), and `CorePlugin`. No behaviour change; pre-1.0 namespace cleanup.
- The `type:` argument on field and header attributes (`#[QueryParam]`, `#[RequestField]`, `#[ResponseField]`, `#[Header]`, `#[ResponseHeader]`, plus the plugin attributes `#[ResourceField]`, `#[TransformerField]`, `#[AllowedFilter]`) is now typed as the OpenAPI primitive-type literal union (`'array'`, `'boolean'`, `'integer'`, `'null'`, `'number'`, `'object'`, `'string'`) via the `OpenApiPrimitiveType` PHPStan alias. `#[ResourceField]` / `#[TransformerField]` additionally accept a `class-string` for nested `$ref`s. PHPStan now flags a misspelled scalar type (e.g. `type: 'int'`) at the call site. No runtime behaviour change.
- Tightened PHPDoc types across authoring attributes (`#[Summary]`, `#[Description]`, `#[Operation]`, `#[Tag]`, `#[Webhook]`, `#[Response]`, `#[ExceptionResponse]`, `#[Header]`, `#[ResponseHeader]`, `#[Link]`, `#[Security]`, `#[Hide]`, `#[Expose]`, `#[Spec]`, `#[IgnoreLint]`, `#[Deprecated]`, `#[ExternalDocs]`, `#[Discriminator]`, `#[RequestBody]`, `#[BaseExample]` / `#[Example]` / `#[ResponseExample]`, the `FieldAttribute` family, and the plugin attributes `#[ResourceField]`, `#[TransformerField]`, `#[AllowedFilter]`). Names, descriptions, identifiers, formats, schemes, scope strings, tag entries, environment filter entries, and similar slots are now typed `non-empty-string`; length/item constraints (`minLength`, `maxLength`, `minItems`, `maxItems`) are typed `int<0, max>`. PHPStan now flags empty strings and negative lengths passed to these attributes at the call site. No runtime behaviour change.
- Renamed `ErrorResponseFactory` → `ErrorResponseResolver`. Method renamed to `resolveErrorResponse(ErrorDescriptor): ?ErrorResponse`. `OpenApiRegistry::addErrorResponseFactory()` → `addErrorResponseResolver()`.
- `StandardResponsesExtractor` calls the resolver chain per status code (instead of once per operation) so each status can carry a distinct body shape.
- For body-bearing envelopes (`laravel`, `rfc7807`, `json-api` and any custom resolver that returns content/headers/links), error responses are inlined per operation instead of being shared via `components.responses.<Name>`. This avoids first-write-wins collisions when two operations at the same status need different bodies (e.g. `ValidationException` vs `UnprocessableEntityHttpException` at 422, where the two would otherwise silently share whichever schema registered first). Shared component schemas referenced *inside* the inlined response (e.g. `#/components/schemas/Error`) are still reused; only the response wrapper is inlined. The `none` envelope (the default) still emits a single `components.responses.<Name>` per known status.
- `StandardResponsesExtractor::resolveBody()` now defends against a misbehaving `ErrorResponseResolver`: if one throws, a `errors.resolver-failed` finding is emitted and the chain continues so a single bad resolver no longer aborts an entire generation run.
- `StandardResponsesExtractor` now accepts throwable *interfaces* (e.g. `\Throwable`) as the exception class on a `@throws` line. Previously only `class_exists()` was checked, which silently dropped interfaces from `ErrorDescriptor::exceptionClass` and blocked later concrete `@throws` on the same status from filling the slot.
- `StandardResponsesExtractor::buildResponse()` no longer overrides the curated default description when a resolver returns an empty-string `description`. OpenAPI 3.1 requires `response.description` to be non-empty; only non-empty resolver descriptions override the default.
- `ComponentSchemaRegistry::registerNamed()` now reserves the key in the basename-collision index so a later user-class registration with the same basename (e.g. an app `App\Errors\Error` Data class while the Laravel envelope holds `Error`) is disambiguated via the normal namespace-prefixing rule instead of silently overwriting the named schema.
- `openapi:generate` now rejects unrecognised `--format` values with a
  non-zero exit and an explicit error message. The previous behaviour silently
  fell back to YAML for anything other than `json`. Covered by
  `GenerateCommandTest::it rejects unsupported --format values with a clear error`.
- `ExamplesTest`'s validity check now boots each flavor, regenerates the
  document in-memory via `OpenApiGenerationOrchestrator`, and runs
  swagger-php's `Analysis::validate()` directly. The previous version only
  parsed the committed YAML and asserted on the top-level keys, which was
  redundant with the snapshot-equality test.
- Auto-wired pipeline classes now carry the `#[Scoped]` container attribute
  (`Illuminate\Container\Attributes\Scoped`) and self-register on first resolve;
  the matching one-arg `$this->app->scoped(X::class)` calls in
  `OpenApiServiceProvider` are gone. Closure-form bindings remain only where the
  binding needs config values, factory methods, registry-derived arrays, or
  decorated wrappers reflection cannot supply.
- `SkipNovaRoutes`, `SkipTelescopeRoutes`, `SkipIgnitionRoutes`,
  `SkipPassportRoutes`, `PayloadParameterScanner`, and `SpecRegistry` read
  their config values through `#[Config]` parameter attributes
  (`Illuminate\Container\Attributes\Config`) instead of `config()` calls in a
  service-provider closure. The `Skip*Routes::fromConfig()` static factories
  are gone — the container resolves these classes directly.
- The seven naming-convention lint rules (`OperationIdNamingInconsistent`,
  `FieldNameNamingInconsistent`, `PathSegmentNamingInconsistent`,
  `ParameterNameNamingInconsistent`, `TagNameNamingInconsistent`,
  `HeaderNameNamingInconsistent`, `ComponentNameNamingInconsistent`) each read
  their `openapi.lint.style.*` config key via `#[Config]` on the constructor
  parameter; `AbstractNamingRule` accepts both an `IdentifierCase` enum
  (test-friendly) and the raw config string. `VisibilityResolver` follows the
  same pattern for `openapi.visibility.default`.
- `SuppressionCollector` now takes `OpenApiRegistry` directly and reads
  `payloadClasses()` itself; the indirection list comes from `#[Config]`.
- The `EventDispatchingFindingsCollector → LoggingFindingsCollector` decorator
  chain is now assembled by the container via `#[Scoped]` on both classes
  plus `#[Give(LoggingFindingsCollector::class)]` on the decorator's `$inner`
  param (which breaks the otherwise circular interface resolution). A
  one-line interface alias in the service provider maps `FindingsCollector`
  to the decorator so Testbench environments — which skip the framework's
  `resolveEnvironmentUsing()` bootstrap step that `#[Bind]` needs — also work.
- `IdentifierCase` gained a `fromConfig()` static factory mirroring
  `VisibilityMode::fromConfig()`; `AbstractNamingRule` uses it instead of
  inlining the enum coercion, and the cached `$pattern` property is gone —
  `$case->pattern()` is a pure `match`, so the caching was buying nothing.
- `VisibilityAttributeNoOp` now takes a `VisibilityResolver` and reads
  `defaultMode()` from it instead of re-running `config('openapi.visibility.default')`
  through `VisibilityMode::fromConfig()` on every route check.
- `SchemaFromFormRequest` now takes a `Psr\Log\LoggerInterface` constructor
  argument instead of reaching for `Illuminate\Support\Facades\Log`, matching
  the sibling pipeline classes (`PaginatorResponseResolver`,
  `SchemaFromDataClass`). The warning message changed from
  "[OpenAPI] Schema introspection failed for FormRequest …" to
  "SchemaFromFormRequest failed for …" for consistency with the sibling
  classes.
- `GenerateCommand`, `ClearCommand`, and `WhyCommand` now use the
  `#[Signature]` / `#[Description]` attribute pair (already used by
  `LintCommand`) instead of the `$name` / `$description` properties plus
  `configure()`. Argument and option names continue to be exposed as
  `ARGUMENT_*` / `OPTION_*` constants on each command class.
- `examples/generate.php` now passes the output path via `--output` (the
  documented option name). The previous `path` positional was rejected by
  Symfony's argument parser after the `path` arg was renamed to `spec` in
  the multi-spec refactor.
- `docs/config.md` lists `output_path` under the top-level keys table.
- `docs/attributes.md` lists `#[Spec]` in the operation-level catalog with a
  pointer to `docs/multi-spec.md`.
- `config/openapi.php` no longer references the internal "Plan A2" identifier
  next to the `lint.baseline` placeholder.
- `#[Header]` constructor shape now mirrors `#[ResponseHeader]` (minus `status`). New
  optional arguments: `format` (passed through to the schema) and `deprecated` (passed
  through to the parameter). Argument order is now `name, description, type, format,
  example, required, deprecated` — the previous order was `name, description, required,
  type, example`. Existing call sites use named arguments and are unaffected.
- Route exclusion now lives entirely in `InclusionEvaluator`. `RouteIntrospector` no longer
  takes a filter list and unconditionally yields every Laravel route; vendor-route skippers
  (Telescope/Nova/Ignition/Passport) and any `config('openapi.filters')` entries are applied
  at the evaluator stage. Consequence: every exclusion — including vendor routes — now
  produces a `RouteSkipped` event, a `trace` entry visible to `openapi:why`, and a
  `SkipReason::GlobalFilter` on the decision. The lint pipeline pre-filters descriptors via
  the new `InclusionEvaluator::passesGlobalFilters()` to keep vendor routes out of pre-build
  rules and the tree walk.
- **`spatie/laravel-data` is now a soft dependency.** Moved from `require` to
  `require-dev`; the `SpatieDataPlugin::register()` body is guarded by
  `class_exists(\Spatie\LaravelData\Data::class)` and silently no-ops when the package
  is absent. `OpenApiServiceProvider::registerSpatieDataPlugin()` is guarded similarly,
  while the Core FormRequest bindings (`PayloadParameterScanner`,
  `SchemaFromFormRequest`, `FormRequestRequestSchemaResolver`, `RequestBodyExtractor`,
  `StandardResponsesExtractor`, `ExampleFileLoader`) moved out of that method into a new
  `registerRequestSchemas()` so they survive without Spatie installed. Consumers using
  Spatie Data: `composer require spatie/laravel-data` and everything works as before.
- `LintCommand` shrank from ~700 LOC to ~150 LOC. The body of `handle()` now parses CLI
  options into a `LintOptions`, hands off to `LintRunner`, and renders the resulting
  `LintResult` through the chosen `Formatter`. No behaviour change.
- `--diff` no longer hardcodes `develop` as the upstream branch. The default ref is now
  derived from git itself: `git symbolic-ref refs/remotes/origin/HEAD` first, then the
  first existing local branch among `main`, `master`, `trunk`, then `HEAD~1`. The
  `--diff infra-touched` detection also drops the host-project-specific paths
  (`app/Support/OpenApi/`, `app/Providers/OpenApiServiceProvider.php`) — only the
  published OpenAPI config (`config/openapi.php`) triggers the full-route-set fallback.
- `config/openapi.php` defaults tightened: `info.version` is now
  `env('API_VERSION', '1.0.0')` instead of the hardcoded `'0.1.0'` (consumers who
  publish the config no longer ship `0.1.0` by accident), and `lint.baseline` is now
  `null` instead of `base_path('openapi-baseline.json')` (the existing comment already
  documented this as the disable sentinel; the default is now aligned).
- Removed `reset()` methods on the scoped pipeline classes — they were redundant under
  the `scoped` container lifecycle (each scope yields a fresh instance) and the
  docblock on `OpenApiServiceProvider` already acknowledged this. Affected classes:
  `OpenApiGenerator`, `OperationBuilder`, `ComponentSchemaRegistry`, `ExampleFileLoader`,
  `ThrowsExtractor`, `RouteIntrospector`. The lint-rule `Resettable` interface is
  unaffected — `SpecTreeWalker` still calls `Rule::reset()` before each walk. Callers
  that need a fresh pipeline mid-scope should call `$app->forgetScopedInstances()`
  (one existing test was migrated to demonstrate the pattern).
- Internal refactor of `OpenApiGenerator`: document assembly now runs through a
  `SpecPipeline` composed of typed stages (`RootStage`, `PathsStage`,
  `ComponentsStage`, `SecurityStage`, `TransformersStage`). No behaviour
  change. `OpenApiExtensions` (static-callable transformer surface) is
  unchanged.

### Documentation
- Document exception → response resolution precedence in `docs/config.md` (new
  *Exception-response precedence* section) and cross-link from `docs/recipes.md`.
  Clarify `middleware_responses` keyed by Laravel middleware alias and that it
  only fills statuses no `@throws`-derived response already supplied. Fix the
  inverted "short name first, then FQCN" comment in `config/openapi.php` —
  actual lookup is FQCN first, short name second. Clarify
  `lint.enabled_rules: null` semantics in the config comment.
- `meta.no-suppression-reason` finding now shows the actual rule ID being
  silenced (`#[IgnoreLint('rule.id', reason: '…')]`) instead of a generic
  placeholder.
- **Documentation restructure.** The single 1,335-line `docs/usage.md` is split
  into per-concept pages under `docs/`: `getting-started.md`,
  `auto-derivation.md`, `request-bodies.md`, `attributes.md`, `recipes.md`,
  `plugins.md`, `linting.md`, `extensions.md`, `plugin-authoring.md`,
  `config.md`, `troubleshooting.md`, `architecture.md`, plus a `docs/README.md`
  index. The README is refreshed with the auto-derivation pitch, a comparison
  table vs. l5-swagger/vyuldashev/Scramble, and links to the new pages.
  `docs/usage.md` is removed; existing deep links to anchors in that file
  need to be updated to point at the new pages.
- `#[ResponseHeader]` attribute is now documented in `docs/attributes.md`
  (previously missing from the catalog).
- Internal `docs/test-cleanup.md` working tracker moved to
  `docs/internal/test-cleanup.md` so user-facing `docs/` only contains
  user-facing pages.

### Added
- `#[Expose]` attribute (`src/Core/Attributes/Expose.php`) — opt routes into the
  generated document when the new hidden-default mode is active. Mirrors
  `#[Hide]` with the same mutually-exclusive `only` / `except` environment
  scoping. `#[Hide]` always wins on conflict.
- `visibility.default` config flag (`config/openapi.php`) — accepts `'public'`
  (the current behaviour) or `'hidden'` (every route excluded unless `#[Expose]`
  applies).
- `visibility.hide-expose-conflict` lint rule (level 1) — reports routes whose
  `#[Hide]` and `#[Expose]` env scopes overlap in the current environment.
- `visibility.attribute-no-op` lint rule (level 2) — reports unconditional
  visibility attributes that have no effect under the active default mode.

- `Radiergummi\OpenApi\Plugins\Fractal\Serializer` enum (cases `DataArray`,
  `ArraySerializer`, `JsonApi`) plus a `serializer:` parameter on
  `#[FractalResponse]` — names the Fractal serializer the endpoint runs at
  runtime so the generated envelope matches it. `FractalEnvelopeFactory` now
  dispatches per serializer: ArraySerializer single is a bare `$ref` (no
  envelope), collection is a top-level array; JsonApi produces resource
  objects `{data: {type, id, attributes: $ref}}` under
  `application/vnd.api+json` with hyphenated `meta.pagination` keys when
  paginated. The default `Serializer::DataArray` is unchanged. Custom
  serializers outside this set still use `#[Response]` to override. (OAPI-052)

- `fractal.transformer-class-missing` lint rule (level 1, registered by
  `FractalPlugin`) — flags `#[FractalResponse]` attributes that name a
  transformer class that does not exist (typos like `BookTrnasformer::class`).
  Surfaces during `openapi:lint` what would otherwise only appear in the
  generation log when `FractalResponseResolver` silently drops the operation's
  response. (OAPI-059)
- `SchemaDescriptor::toSchema(string $defaultType = 'string')` — canonical
  helper that builds a standalone `OA\Schema` from a descriptor and applies the
  OAS 3.1 `type: [..., 'null']` widening (which `toOpenApi()` deliberately
  omits). Used by `CoreQueryParameterResolver` and
  `QueryBuilderParameterResolver`, replacing the duplicated 4-line snippet they
  carried. (OAPI-049)
- Feature test (`DefaultPluginsConfigTest`) asserts the shipped default
  `config/openapi.php` `plugins` array generates a clean document — a typo in
  either commented-out FQCN or an accidental uncomment would now fail in CI.
  (OAPI-057)
- `openapi.security_schemes` config map — registers OpenAPI security schemes by name. Each entry
  is passed through to swagger-php's `OA\SecurityScheme`; the map key becomes `securityScheme`.
  Entries are merged with the Passport-derived `oauth2` / `oauth2ClientCredentials` pair (emitted
  only when Passport is installed and its named routes are registered); config entries win on
  key collision. `#[Security]` now accepts an optional `scheme:` parameter naming which
  configured scheme the requirement targets — existing `#[Security(['scope'])]` usages keep
  working against the project default scheme. (OAPI-042)
- `#[ResponseHeader]` authoring attribute — repeatable on methods/functions, declares a header
  on the response whose `status:` it targets (defaults to 200). Carries `name`, `description`,
  `type`, `format`, `example`, `required`, `deprecated`. Replaces the request-`#[Header]`
  workaround for documenting headers like `Location` on 201 responses. (OAPI-041)
- `DataResponseResolver` (SpatieData plugin) — auto-derives the primary `200 OK` response from a
  Spatie `Data` return type, a `DataCollection<int, Item>` (item class read from the `@return`
  PHPDoc generic), or a `PaginatedDataCollection` / `CursorPaginatedDataCollection` (renders the
  matching paginator envelope). Mirrors the ApiResources plugin's `ResourceResponseResolver`.
  Explicit `#[Response]` attributes still take precedence. (OAPI-040)
- `CoreQueryParameterResolver` — reflects `#[QueryParam]` attributes off controller methods (and
  classes, for shared parameters declared once) and emits OpenAPI query-parameter entries with
  the attribute's full `FieldAttribute` surface (type, format, enum, default, nullable, bounds).
  Closes the documentation-vs-implementation gap where `#[QueryParam]` existed but was never
  read. (OAPI-039)
- `#[Deprecated]` authoring attribute (in `Radiergummi\OpenApi\Attributes`) — symmetric to
  the PHPDoc `@deprecated` tag and the PHP 8.4 native `#[\Deprecated]` attribute. Targets
  methods, functions, properties, promoted constructor parameters, and class constants. Sets
  `deprecated: true` on the generated operation (method-level) or schema property
  (property / parameter level). (OAPI-043)
- `SkipPassportRoutes` route filter registered by default — Laravel Passport's CRUD endpoints
  (route names under the `passport.*` prefix) are filtered out of generated specs alongside
  Nova / Telescope / Ignition. The filter tolerates Passport being absent. (OAPI-044)
- `examples/` suite: five runnable flavors (vanilla, form-requests, spatie-data, query-builder, combined)
  that all expose the same flights+bookings API and ship a generated `openapi.yaml` snapshot.
  Verified in CI against fresh generation, OpenAPI 3.1 validity, and `openapi:lint`.
- Laravel paginator return types (`LengthAwarePaginator`, `Paginator`, `CursorPaginator`) are
  now documented automatically. The paginated item type is resolved from a `#[ResponseResource]`
  attribute or a `@return Paginator<Item>` PHPDoc generic.
- Eloquent API Resources (`JsonResource` / `ResourceCollection`) are now
  documented automatically via the default-enabled `ApiResourcesPlugin`. Each
  resource declares its output keys with repeatable class-level
  `#[ResourceField]` attributes; single responses emit the `{data}` envelope and
  collections the `{data, links, meta}` envelope. Three lint rules
  (`resource.fields-undeclared`, `resource.field-type-missing`,
  `resource.response-ambiguous`) report incomplete declarations.
- `spatie/laravel-query-builder` filter/sort/include query parameters are now
  documented via the optional `QueryBuilderPlugin` (shipped disabled — uncomment
  it in `config/openapi.php` after installing the package). Endpoints declare
  parameters with `#[AllowedFilter]`, `#[AllowedSort]`, and `#[AllowedInclude]`.
  Two lint rules (`query-builder.params-undeclared`,
  `query-builder.filter-type-missing`) report incomplete declarations.
- `league/fractal` transformer responses are now documented via the optional
  `FractalPlugin` (shipped disabled — uncomment it in `config/openapi.php` after
  installing the package). Transformers declare output keys with
  `#[TransformerField]` and includes with `#[TransformerInclude]`; endpoints bind
  to a transformer with `#[FractalResponse]`, which accepts `collection: true`
  for a flat collection and `paginated: true` for a paginated one (envelope
  includes `meta.pagination` matching Fractal's `IlluminatePaginatorAdapter`).
  Four lint rules (`fractal.response-unbound`, `fractal.fields-undeclared`,
  `fractal.include-transformer-missing`, `fractal.duplicate-key`) report
  incomplete or invalid declarations.
- `openapi.security_default_scheme` config option — names the scheme that
  `#[Security(['scope'])]` (without `scheme:`) and middleware-derived
  `forRoute()` security target by default. Accepts a string (single scheme) or
  a list of strings (multiple OR-alternatives). When unset, resolution falls
  back to Passport's `oauth2` + `oauth2ClientCredentials` pair if installed,
  otherwise the first scheme declared in `openapi.security_schemes`, otherwise
  an empty requirement — preserving the previous behaviour for projects that
  do not opt in. Mixed-scheme projects (Passport + custom bearer) can now set
  this once instead of passing `scheme:` on every `#[Security]`. (OAPI-045)
- `Radiergummi\OpenApi\Core\Lint\ReflectionAttributeCache` — per-walk cache
  attached to `LintContext` that wraps `getAttributes()` bucketing and
  `ReflectionClass` construction. Sibling lint rules that introspect the same
  target class (resource, transformer) or the same operation method now share
  the cache. Resource, Fractal, and QueryBuilder lint rules migrated to use it
  (and to read controller / method attributes through the new
  `ActionDescriptor::actionAttributes()` helpers instead of allocating fresh
  `ReflectionClass` / `getAttributes()` walks per rule). (OAPI-054)

### Changed (breaking)
- `#[Hide]` constructor argument renamed: `environments` → `only`. Also gains
  `except` as an exclusive alternative. Migration: rewrite
  `#[Hide(environments: [...])]` to `#[Hide(only: [...])]`.

### Changed
- `SpecTreeBuilder` now resolves `allOf`-composed schema properties when
  building `FieldNode` trees. A schema written as
  `allOf: [{$ref: Base}, {properties: {…}}]` exposes both the
  `$ref`-inherited properties and the local ones in
  `ComponentSchemaNode->fields`, with a cycle guard against recursive `allOf`
  chains; the `required` list is unioned across branches. `oneOf` / `anyOf`
  are deliberately not composed. False positives in
  `schema.required-without-property` and false negatives in
  `schema.enum-type-mismatch` / every other `FieldRule` are now closed for
  `allOf`-composed schemas. (OAPI-038)
- `SecurityExtractor` and `ReturnTypeExtractor` now memoise per-run state on
  the instance: Passport availability (`class_exists` + 3× `Router::has()`),
  the router's middleware-groups snapshot, the parsed
  `openapi.security_schemes` catalogue, and per-reflector
  `genericArgument()` results. Both are bound as scoped singletons so the
  caches reset between requests under Octane. The biggest win is the
  `DocBlockFactory::create()` parse, which is now done once per method
  across all primary-response resolvers that consult the same `@return`
  generic. (OAPI-051)
- `ActionDescriptor` now exposes `controllerAttributes()` /
  `actionAttributes()` helpers that read each reflector's attribute list once
  per descriptor and bucket by attribute FQCN. `OperationBuilder` switched
  every `getAttributes(SomeAttribute::class)` call onto these helpers, so a
  build over `n` routes does `O(2·n)` attribute walks instead of `O(17·n)`.
  No behaviour change; the bucket cache is scoped to the descriptor's lifetime,
  so it carries no Octane-state risk. (OAPI-050)
- `#[ResponseHeader]` now also targets `TARGET_CLASS` and is read off both the
  controller and the action reflector by `OperationBuilder` — shared response
  headers (`X-Request-Id`, `X-RateLimit-Remaining`) can be declared once on the
  controller instead of repeated on every method. Method-level declarations win
  on `(status, name)` collision; declaration order is otherwise preserved. The
  shape now mirrors `#[Header]`. (OAPI-046)
- `SkipPassportRoutes` now exposes a parameterless `fromConfig()` factory and
  is registered through it in `OpenApiServiceProvider`, matching the shape of
  the sibling filters (`SkipNovaRoutes` / `SkipTelescopeRoutes` /
  `SkipIgnitionRoutes`). Behaviour is unchanged — Passport's route-name prefix
  is not user-configurable, so the constructor still takes no parameters; a
  class-level docblock spells that out so the deviation no longer reads as an
  oversight. (OAPI-047)
- `fractal.response-unbound` lint rule moved from level 1 to level 2 (opt-in),
  matching its `query-builder.params-undeclared` sibling. The rule's
  `description()` now spells out the blind spot (`fractal()` helper /
  `Spatie\Fractalistic\Fractal` facade are not detected) so the caveat surfaces
  in `openapi:lint --list-rules` output. Default-level lint runs no longer
  produce a silent zero-findings result that could be mistaken for endorsement
  in Fractal-heavy codebases. (OAPI-060)
- `PaginatorKind::fromClass()` now recognises Spatie's `PaginatedDataCollection`
  and `CursorPaginatedDataCollection` (FQCN-matched to keep Core free of plugin
  imports). `PaginatorResponseResolver` now claims those return types via the
  shared `RefSchemaResolver` chain (with `DataRefSchemaResolver` already in it),
  so `DataResponseResolver` shrank to single `Data` + non-paginating
  `DataCollection<…, Item>`. Paginator-envelope construction now lives in one
  place. (OAPI-048)
- `SchemaFromResource` now takes `Closure(): list<RefSchemaResolver>` to mirror
  the sibling `SchemaFromTransformer`; both sides of the cross-plugin
  construction graph are lazy, closing the latent OOM tripwire for a future
  third `SchemaFromX` + `XRefSchemaResolver` pair. (OAPI-055)
- `FractalResponseResolver`, `ResourceResponseResolver`, and
  `DataResponseResolver` now catch only `ReflectionException` — the documented
  tolerable failure mode. Real bugs (`TypeError`, schema-build logic errors,
  `Error` subclasses) now propagate so they surface in dev rather than
  disappearing into a warning log. (OAPI-058)
- Plugin-suite integration test (`PluginSuiteIntegrationTest`) tightened with
  negative assertions (paginator route is not Fractal-wrapped; resource and
  Fractal routes carry no QueryBuilder params), full Fractal envelope-shape
  coverage (single / collection / paginated), `#[AllowedInclude]` coverage on
  the paginator route, and an included transformer asserted to land as its own
  component schema. (OAPI-056)
- `composer.json` `require-dev` constraint for `league/fractal` loosened from
  `^0.20.2` to `~0.20.2` — explicit about allowing 0.20.x patch updates without
  claiming 0.21 forward compatibility. (OAPI-061)
- `config/openapi.php` Fractal-plugin comment now names both triggers
  (`league/fractal` and `spatie/laravel-fractal`) so users coming in via the
  Spatie wrapper have a signal that they already meet the requirement. (OAPI-062)
- PHPStan now runs at level 8 with `treatPhpDocTypesAsCertain` disabled and is a blocking CI check.
- Document generation now skips routes whose controller class cannot be resolved at introspection time, instead of aborting the entire run with a `ReflectionException`.
- Upgraded core dependencies to current major versions: `zircote/swagger-php` 6, `symfony/type-info` 8, and `phpdocumentor/reflection-docblock` 6.
- `league/fractal`, `spatie/laravel-fractal`, and `spatie/laravel-query-builder`
  are now listed under `suggest` — install the relevant package and uncomment
  the matching plugin in `config/openapi.php` to enable it.

### Fixed
- The `schema.constraints-missing` lint rule now handles OpenAPI 3.1 nullable type arrays (`type: [string, null]`). Previously such schemas caused a `TypeError` and were silently left unchecked.
- The lint spec-tree builder no longer fails when a media type's schema is a plain `$ref` string rather than an inline schema object; such schemas are now handled gracefully.
- Generated documents keep the OpenAPI 3.1 nullable form (`type: ['…', 'null']`). swagger-php 6 defaults its serialisation context to OpenAPI 3.0, which down-converts nullable unions to the removed `nullable` keyword; generation now pins a 3.1 context.
- `#[AllowedFilter(nullable: true)]` now widens the generated `filter[…]` schema to `type: ['…', 'null']`. Previously the `nullable` flag was accepted on the attribute but silently dropped from the wire schema.

## [0.1.0] - 2026-05-18

### Added
- Initial public release: OpenAPI 3.1 generation and documentation linting for Laravel.
- Bundled Spatie Data plugin and FormRequest request-schema support.
- `openapi:generate`, `openapi:lint`, `openapi:clear` artisan commands.
- Config-driven spec endpoint and Scalar playground routes.
