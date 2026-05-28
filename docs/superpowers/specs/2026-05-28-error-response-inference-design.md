# Error-response inference stage

Move error-response inference out of `OperationBuilder` and into a pipeline
stage with a contributor chain. Closes a layering violation (Support →
Core) and turns "infer error responses from `@throws` and middleware" into a
proper extension point.

## Background

`Support\Generator\OperationBuilder` calls
`Core\Extraction\StandardResponsesExtractor::extract($action)` to derive a
list of error `OA\Response`s, which it merges into each operation as it
builds them. The extractor walks `@throws` annotations and route middleware,
maps them to HTTP statuses via `config('openapi.exception_responses')` /
`middleware_responses` / `#[ExceptionResponse]`, then calls the
`ErrorResponseResolver` chain for envelope bodies and emits
`throws.unmapped` lint findings for unmapped exceptions.

Two problems:

1. **Layering violation.** `Support\Generator\OperationBuilder` imports
   `Core\Extraction\StandardResponsesExtractor`. Support is meant to be
   plugin-agnostic infrastructure; it should not know about Core's
   Laravel-convention strategies. The whole point of `Core\` as a separate
   namespace is that "disable Core, the rest still works."
2. **No extension point for additional inference sources.** Only Core can
   contribute to inferred error responses. A future plugin that wants to
   infer `422` from `FormRequest`/`Data` presence, or `409` from a
   `ConflictsWith` attribute on the action, has nowhere to plug in.

The current "extractor called by OperationBuilder" shape exists because the
responses had to be merged into the operation at build time. They don't —
the assembled document is mutable until the terminal stage runs.

## Goals

- `Support\Generator\OperationBuilder` has zero imports from `Core\`.
- Inferred error responses are produced by a `SpecStage` that runs after
  `PathsStage` and before `TransformersStage`, registered by
  `Core\Registration` via the existing `OpenApiRegistry::addStage()` surface.
- Inference sources are pluggable via a contributor chain registered on
  `OpenApiRegistry`. Core ships three contributors covering the Laravel-
  native conventions it already owns elsewhere (`@throws` annotations,
  route middleware, FormRequest validation) and registers them itself.
  Plugins introducing their own validation-bearing payload types
  (e.g. SpatieData) plug in via the same surface.
- Explicit `#[Response(status: …)]` attributes always win over inferred
  responses. (Today this is incidental, by ordering; it becomes explicit.)
- Zero behaviour change for the bundled flavors. All snapshot tests pass
  before and after with no fixture edits.

## Non-goals

- Replacing the `ErrorResponseResolver` chain. Body shape (envelope) is
  unchanged.
- Splitting `OperationBuilder` further. Only the standard-responses call
  moves out; the rest of the operation construction stays put.
- A general "post-PathsStage decorator" framework. This is one stage doing
  one job; if a second similar stage is wanted later, the abstraction can be
  pulled out then.
- Migrating `OperationBuilder` itself to `Core\` or `Contracts\`. The class
  belongs in Support; the dependency is what's wrong, not the location.

## Design

### Stage

```php
namespace Radiergummi\OpenApi\Core\Stages;

use OpenApi\Annotations as OA;
use Radiergummi\OpenApi\Contracts\Generator\SpecStage;
use Radiergummi\OpenApi\Generator\GenerationContext;

#[Scoped]
final readonly class ErrorResponseInferenceStage implements SpecStage
{
    /**
     * @param list<ErrorResponseContributor>  $contributors
     * @param list<ErrorResponseResolver>     $envelopeResolvers
     */
    public function __construct(
        private array $contributors,
        private array $envelopeResolvers,
        private ComponentSchemaRegistry $registry,
        private FindingsCollector $findings,
    ) {}

    public function apply(OA\OpenApi $doc, GenerationContext $context): void
    { /* see Algorithm */ }
}
```

Lives in `Core\Stages\`. Mirrors how plugins would expose stages from
`Plugins\<Foo>\Stages\`.

### Contributor contract

```php
namespace Radiergummi\OpenApi\Contracts\Registry;

use Radiergummi\OpenApi\Errors\ErrorDescriptor;
use Radiergummi\OpenApi\Routing\ActionDescriptor;

interface ErrorResponseContributor
{
    /**
     * Inspect the action and return any error descriptors implied by it.
     *
     * @return list<ErrorDescriptor>
     */
    public function contribute(ActionDescriptor $descriptor): array;
}
```

Returns `ErrorDescriptor`s, not `OA\Response`s — the stage owns body
resolution and `OA\Response` construction. Contributors only declare *which
status codes are implied and why*.

Contributors receive only `ActionDescriptor` to discourage cross-coupling;
config maps and other state are injected per-contributor at construction.

### Core contributors

Three `final readonly` `#[Scoped]` classes in `Core\Inference\`:

| Class | Reads | Source of mappings |
|---|---|---|
| `ThrowsErrorContributor` | `$descriptor->throws` | `#[ExceptionResponse]` on exception class, then `config('openapi.exception_responses')` |
| `MiddlewareErrorContributor` | `$descriptor->route->gatherMiddleware()` | `config('openapi.middleware_responses')` (auth/scope/throttle keys) |
| `ValidationErrorContributor` | action parameters | `config('openapi.exception_responses')` keyed by `ValidationException::class` |

`ValidationErrorContributor` walks the action's parameters looking for a
subclass of `Illuminate\Foundation\Http\FormRequest`. Presence implies a
possible `422` (Laravel auto-validates injected FormRequests; the
controller body never runs if validation fails). The description and
status come from `config('openapi.exception_responses')` so the value
stays consistent with what `ThrowsErrorContributor` would emit when an
explicit `@throws ValidationException` is declared. Dedupe-by-status in
the stage means an action with both signals still produces exactly one
`422` response.

This is squarely Core's job: FormRequest is a Laravel-native convention
that the rest of Core already understands
(`FormRequestRequestSchemaResolver`, `SchemaFromFormRequest`). Inferring
the corresponding error response sits at the same layer.

The lint finding `throws.unmapped` moves into `ThrowsErrorContributor` —
it's emitted on the contributor's own pass, not by the stage. The
contributor pulls `FindingsCollector` via constructor injection.

The `DetectsAuthMiddleware` trait used by `MiddlewareErrorContributor`
stays in `Support\Extraction\` — it's generic Laravel middleware-detection
and unrelated to the response-inference concern.

**Plugin contributors follow the same pattern.** Anything that introduces
a validation-bearing payload type should ship its own contributor:

- **SpatieData plugin** should ship a `DataValidationErrorContributor`
  detecting `Spatie\LaravelData\Data` subclasses on the action signature.
  Follow-up to this work; the chain is the prerequisite.
- Future plugins introducing their own payload conventions plug in via
  the same `OpenApiRegistry::addErrorResponseContributor()` surface.

The detection logic is small ("walk params, `is_subclass_of`") and stays
local to each contributor; we deliberately don't introduce a "does this
resolver imply a status?" sub-interface on `RequestSchemaResolver`. The
two concerns (schema resolution, error-status inference) are independent
and grouping them would couple unrelated lifecycles.

### Registry surface

Two new methods on `OpenApiRegistry`, matching the existing resolver
pattern exactly:

```php
/** @param class-string<ErrorResponseContributor> $class */
public function addErrorResponseContributor(string $class): void;

/** @return list<class-string<ErrorResponseContributor>> */
public function errorResponseContributors(): array;
```

`Core\Registration::register()` adds all three Core contributors:

```php
$registry->addErrorResponseContributor(ThrowsErrorContributor::class);
$registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
$registry->addErrorResponseContributor(ValidationErrorContributor::class);
$registry->addStage(ErrorResponseInferenceStage::class);
```

Class-strings (not instances), consistent with every other registry method.
`SpecPipeline` (or the stage's binding) resolves them through
`$container->make()` at run time.

### `GenerationContext` extension

`GenerationContext` becomes the lookup for "which `ActionDescriptor`
produced this `OA\Operation`":

```php
final class GenerationContext // drops `readonly`
{
    /** @var SplObjectStorage<OA\Operation, ActionDescriptor> */
    private SplObjectStorage $actions;

    public function __construct(
        public readonly SpecDefinition $spec,
        public readonly string $environment,
    ) {
        $this->actions = new SplObjectStorage();
    }

    public function bindAction(OA\Operation $operation, ActionDescriptor $descriptor): void
    {
        $this->actions[$operation] = $descriptor;
    }

    public function actionFor(OA\Operation $operation): ?ActionDescriptor
    {
        return $this->actions[$operation] ?? null;
    }
}
```

`readonly` drops from the class declaration; the two existing fields stay
`readonly` individually. The internal `SplObjectStorage` is mutable, which
is intentional — stages need to write to it during the pipeline.

`PathsStage::attachOperation()` binds each `OA\Operation` it produces
before assigning it to the path item:

```php
$context->bindAction($operationSchema, $action);
$pathItem->{strtolower($upper)} = $operationSchema;
```

(Bind once per HTTP method, so a route with multiple verbs registers each
of its `OA\Operation`s.)

This lookup is also useful beyond this stage — `LintContext::actionFor()`
already does the same job at lint time. Bringing the symmetry into the
generator is a small payoff on top of the layering fix.

### Algorithm (`ErrorResponseInferenceStage::apply`)

```
foreach pathItem in $doc->paths:
    foreach httpMethod (get/post/put/patch/delete/options/trace):
        $operation = $pathItem->{httpMethod}
        if $operation === UNDEFINED or null: continue

        $action = $context->actionFor($operation)
        if $action === null: continue   # closure routes etc.

        # 1. Collect ErrorDescriptors from every contributor
        $descriptors = []
        foreach $contributor in $this->contributors:
            $descriptors = array_merge($descriptors, $contributor->contribute($action))

        # 2. Dedupe by status; first contributor wins. See Precedence section.
        $byStatus = []
        foreach $d in $descriptors:                  # iteration is in contributor order
            $byStatus[$d->status] ??= $d              # null-coalesce: first wins

        # 3. Skip statuses already declared explicitly on the operation
        foreach $existing in $operation->responses ?? []:
            unset($byStatus[(int) $existing->response])

        # 4. Build OA\Response per remaining status via envelope chain
        foreach $byStatus as $status => $d:
            $body = $this->resolveBody($d)
            $operation->responses[] = $this->buildResponse($d, $body, ...)
```

`resolveBody()` and `buildResponse()` lift verbatim from the current
`StandardResponsesExtractor`. The envelope chain (`ErrorResponseResolver`s)
and the `STATUS_COMPONENT_NAMES` map move with them.

### Precedence

Two precedence rules, in order of evaluation:

**1. Explicit `#[Response]` always wins over inferred.** Today this is
incidental — `OperationBuilder` calls the extractor before reading
explicit attributes, and swagger-php's last-write-wins ordering then
overwrites by status. Under the stage, the order flips: explicit
attributes are already on the operation when the stage runs, and
inferred responses append *after*. The stage therefore checks
`$operation->responses` for each status before appending and skips
statuses that are already declared. The observable behaviour is
identical to today's; the precedence becomes intentional rather than a
side-effect of call ordering.

**2. Between contributors, first-registered wins.** When two
contributors produce an `ErrorDescriptor` for the same status, the
descriptor from the contributor registered earlier on
`OpenApiRegistry` is kept; later contributors' descriptors for that
status are discarded. Concretely, in `apply()`:

```
$byStatus = []
foreach $contributor in $this->contributors:                # registration order
    foreach $d in $contributor->contribute($action):
        $byStatus[$d->status] ??= $d                        # null-coalesce: first wins
```

This means the order Core registers its three contributors in
`Core\Registration::register()` is **load-bearing**:

```php
$registry->addErrorResponseContributor(ThrowsErrorContributor::class);
$registry->addErrorResponseContributor(MiddlewareErrorContributor::class);
$registry->addErrorResponseContributor(ValidationErrorContributor::class);
```

Rationale for this specific order:

- **`ThrowsErrorContributor` first** — `@throws` annotations are the most
  *specific* signal a developer can provide. If a docblock says
  `@throws ValidationException` and the exception class carries a
  `#[ExceptionResponse]` with a custom description, that description
  should win over the generic one from
  `config('openapi.exception_responses')` that the validation
  contributor would emit.
- **`MiddlewareErrorContributor` second** — middleware-derived statuses
  (401/403/429) rarely overlap with throws-derived ones, but when they
  do (e.g. an action `@throws AuthenticationException` *and* sits
  behind auth middleware), the explicit `@throws` is the authoritative
  source.
- **`ValidationErrorContributor` last** — it's the most *implicit*
  signal (presence of a FormRequest parameter), so it gives way to
  either of the more explicit contributors.

Plugin-registered contributors append to this list and therefore yield
to all three Core contributors by default. A plugin that needs to
*override* a Core contributor's output for a specific status would need
to subclass the Core contributor and re-register (replacing rather than
appending). That's intentional — it forces a conscious choice rather
than letting load order silently flip behaviour.

### Pipeline placement

Plugin-registered stages already run after the core stages and before the
terminal `TransformersStage`. `ErrorResponseInferenceStage` registers via
`addStage()` from Core — same mechanism plugins use. No new pipeline
phases.

Sub-order between Core's stage and future plugin-registered stages is
registration order. Core registers first
(`CoreRegistration::register()` runs before any plugin), so its inference
stage runs first.

### Service-provider wiring

`OpenApiServiceProvider` binds:

- `ThrowsErrorContributor`, `MiddlewareErrorContributor`,
  `ValidationErrorContributor` as `#[Scoped]` (auto-detected).
- `ErrorResponseInferenceStage` as `#[Scoped]`, constructor pulls the
  contributor list from `OpenApiRegistry::errorResponseContributors()` and
  the envelope chain from `OpenApiRegistry::errorResponseResolvers()`.
- Removes the `StandardResponsesExtractor` binding.
- `OperationBuilder` binding loses the
  `standardResponsesExtractor:` argument.

### `OperationBuilder` after refactor

- Drops the `private StandardResponsesExtractor $standardResponsesExtractor`
  constructor argument.
- Drops the `$standardResponses = $this->standardResponsesExtractor->extract($action)`
  call in `build()`.
- The merged response array stops including standard responses; only
  explicit `#[Response]` attributes remain at this point in the pipeline.
- Zero `Core\` imports remain. Verified by a static check (see Testing).

## Testing

- **Contributor unit tests**: one focused test per Core contributor.
  Asserts that given an `ActionDescriptor` with specific `throws` /
  `middleware`, the contributor returns the expected `ErrorDescriptor`s and
  (for `ThrowsErrorContributor`) emits the expected `throws.unmapped`
  findings.
- **Stage unit test**: build a minimal `OA\OpenApi` with one operation, a
  bound `ActionDescriptor`, two fake contributors. Assert the operation
  ends up with the deduped, envelope-resolved responses; assert explicit
  `#[Response]` attributes survive.
- **Precedence tests** (two cases, in the stage unit test or its own
  file):
  - Both contributors emit `422` with **different** descriptions; assert
    the response keeps the first contributor's description. Verifies the
    contributor-precedence rule.
  - An operation has an explicit `#[Response(status: 422)]` and a
    contributor emits `422`; assert the explicit response is preserved
    untouched and the contributor's is dropped. Verifies the
    explicit-wins-over-inferred rule.
- **Pipeline ordering test**: confirm `ErrorResponseInferenceStage` runs
  after `PathsStage` (`actionFor()` returns the bound descriptor) and that
  the stage is registered in plugin position (not as a core stage).
- **Layering check**: an architectural test (extending the existing
  `tests/Arch/CoreBoundaryTest.php`) asserts that no class under
  `src/Support/` has any import from `src/Core/`. Catches re-introduction
  of the same violation.
- **Regression net**: the full snapshot test suite (`ExamplesTest`,
  `EnvelopePresetSnapshotTest`, `PluginSuiteIntegrationTest`,
  `MixedExceptionsAtSameStatusTest`) is the primary safety net — the
  observed YAML output must not change for any of the bundled flavours.

The existing `StandardResponsesExtractorRobustnessTest` cases (lift them
into the contributor and stage tests respectively; assertions stay the
same).

## Public API impact

- **End users**: none. `OpenApiGenerator::generate()`, the CLI commands,
  and the generated spec output are unchanged.
- **Plugin authors**: new optional extension point.
  - `Contracts\Registry\ErrorResponseContributor` interface
  - `OpenApiRegistry::addErrorResponseContributor(string)` and
    `errorResponseContributors(): array`
  - `GenerationContext::actionFor()` / `bindAction()`
- **`Core\Extraction\StandardResponsesExtractor` is removed.** Anyone who
  imported it directly (third-party code, not bundled plugins) has to
  migrate to either the stage (for inferred responses) or the contributor
  chain (to add new sources). Pre-1.0, no migration guide required; the
  CHANGELOG entry lists the affected symbols.

## Files

New:

- `src/Contracts/Registry/ErrorResponseContributor.php`
- `src/Core/Stages/ErrorResponseInferenceStage.php`
- `src/Core/Inference/ThrowsErrorContributor.php`
- `src/Core/Inference/MiddlewareErrorContributor.php`
- `src/Core/Inference/ValidationErrorContributor.php`
- `tests/Unit/Core/Inference/ThrowsErrorContributorTest.php`
- `tests/Unit/Core/Inference/MiddlewareErrorContributorTest.php`
- `tests/Unit/Core/Inference/ValidationErrorContributorTest.php`
- `tests/Unit/Core/Stages/ErrorResponseInferenceStageTest.php`

Modified:

- `src/Generator/GenerationContext.php` — drops `readonly` class modifier;
  adds `bindAction()` / `actionFor()`.
- `src/Support/Generator/Stages/PathsStage.php` — calls
  `$context->bindAction()` once per produced `OA\Operation`.
- `src/Support/Generator/OperationBuilder.php` — drops the
  `StandardResponsesExtractor` dependency; removes the call site and the
  ensuing merge.
- `src/Support/Registry/OpenApiRegistry.php` — adds
  `addErrorResponseContributor()` / `errorResponseContributors()`.
- `src/Core/Registration.php` — registers the two contributors and the
  stage.
- `src/OpenApiServiceProvider.php` — binds the new classes; removes the
  `StandardResponsesExtractor` binding and the `OperationBuilder`
  argument.
- `tests/Arch/CoreBoundaryTest.php` — adds an assertion that
  `src/Support/` contains no `use Radiergummi\OpenApi\Core\` lines.
- `CHANGELOG.md` — `[Unreleased]` entry: internal refactor, new
  `ErrorResponseContributor` extension point, removal of
  `StandardResponsesExtractor`.
- `docs/plugin-authoring.md` — document the new `addErrorResponseContributor()`
  surface alongside the existing registry methods.

Deleted:

- `src/Core/Extraction/StandardResponsesExtractor.php` — its logic splits
  across the two contributors and the stage; no class with that name
  survives.
- `tests/Unit/Core/Extractors/StandardResponsesExtractorRobustnessTest.php` —
  cases redistributed across the new contributor tests.

## Out of scope (revisit later)

- A general "post-`PathsStage` decorator" abstraction. This is one stage
  doing one job; if a second similar stage appears (e.g.
  `ResponseHeaderInferenceStage`), extract a common pattern then.
- Per-operation context plumbing for non-error use cases. The
  `actionFor()` lookup is generic and available for any stage, but no
  other stage is changed in this work.
- Moving `OperationBuilder` to `Core\`. After this refactor it has zero
  Core imports, so the layering question is settled where it stands.
- Validation-driven `422` inference for **third-party** payload types.
  Core ships `ValidationErrorContributor` for FormRequest (in scope, see
  above). Equivalent contributors for Spatie Data, Fractal, etc. are each
  plugin's own follow-up — the chain exists from this work onward.
