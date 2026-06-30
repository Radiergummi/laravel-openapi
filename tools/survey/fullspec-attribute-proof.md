# Full-spec attribute proof — well-typed vs. dynamic (#461)

Evidence for the release premise *"well-typed apps → almost automatic; less-typed apps → more
attributes."* Where `well-typed-bar.md` (#460) measures **out-of-the-box coverage**, this measures
the **authoring-attribute lift**: how far convention gets unaided, and how much hand-authoring it
then takes to reach a near-complete spec — on two architecturally opposite apps, against the same
unchanged library.

> **Maintainer's call on publication.** Like `well-typed-bar.md`, this is a measurement + a record,
> not published copy. App-naming and narrative in the public `docs/field-report.md` stay gated by
> **#159**. No percentage here is auto-published to README/docs.

Method: `docs/internal/fullspec-attribute-proof-playbook.md` (local), driven by
`.claude/skills/survey/lift.js`. The library is the system under test — **never modified**;
annotations are uncommitted app-side scratch. Both runs ended with `libraryClean: true`.

## Apps (segmentation from `well-typed-bar.md`)

| Role | App | Architecture | Provenance |
|------|-----|--------------|------------|
| Well-typed (thesis 1) | **Lychee** | Spatie `Data` returns, FormRequest bodies | **Fresh** lift, current `main` |
| Dynamic / imperative (thesis 2) | **Vito** | Imperative Resource `toArray()`, validation outside FormRequests | **Cited** lift, 2026-06-06, library `44919fb3` (`$WS/apps/Vito/lift.json`) |

## The contrast

| | **Lychee** (well-typed) | **Vito** (imperative / dynamic) |
|---|---:|---:|
| API operations (`/api`) | 205 | 146 |
| **Baseline completeness** (0 attributes, 0 app edits) | **97.6%** | **43.2%** |
| Baseline response schemas | 100 | 77 |
| Baseline request bodies | 92 | 0 |
| Baseline lint findings | 65 (all `response.no-error`) | 132 |
| Harvested attributes (deterministic, from published spec) | 0 | 342 |
| Authored attributes (agent judgment) | ~48 files | 50 files |
| **Final completeness** | **98.5%** | **86.8%** |
| **Completeness lift** | **+0.9 pts** | **+43.6 pts** |
| Final response schemas | 128 (+28) | 144 (+67) |

Same library, opposite outcomes. **Lychee was already 97.6% complete on first contact** — convention
(Spatie `Data`, FormRequest rules, route-model binding, Sanctum) carried it with **no app code
touched**. **Vito started at 43.2%** because its responses are built imperatively and its writes
validate outside FormRequests — shapes the generator cannot read by design — so reaching completeness
took real authoring effort, every gap **named by the linter** (132 baseline findings:
`resource.fields-undeclared`, `resource.response-ambiguous`, `response.no-error`, …).

This is the premise, demonstrated: well-typed → near-automatic; imperative/dynamic → bounded,
signposted attribute work.

## Lychee — the well-typed exemplar

- **97.6% complete with zero attributes.** The library read the Spatie `Data` return shapes, 92
  FormRequest request bodies, and Sanctum security unaided.
- The authored pass (≈48 files) was bounded and mechanical: `#[ExceptionResponse]` on ~10 app
  exception classes, `#[Response]` overrides on imperative/array-returning methods, 7
  `#[RequestBody]`/`#[RequestField]`. Completeness moved only **+0.9 pts** because almost nothing was
  missing; the +28 response schemas are mostly inlined object/`Data` shapes the attributes spelled
  out, not large new coverage.
- `harvested = 0` — the published spec carried no Summary/Description to transcribe.

### Caveat — partial run (thesis-1 headline unaffected)

The Lychee lift hit infrastructure failures: **9 plan-phase analysis agents died** mid-response
(`connection closed`) and **6 apply slots stalled**. The affected controllers (`Webhook`, `Search`,
`Map`, `UserGroups*`, `ShopManagement`, `Config`, `SecurityAdvisories`, `UserManagement`) were
**never annotated**, so the **`afterAgent` 98.5% is a floor**. This does not weaken the conclusion:
the thesis-1 headline is the **97.6% baseline**, a clean deterministic measurement taken before any
agent work. Lint barely moved (65 → 62) for the same reason — most `response.no-error` findings live
on the un-annotated controllers; that is annotation volume, not a library limit.

## Vito — the dynamic anchor (cited)

From the recorded 2026-06-06 lift (`$WS/apps/Vito/lift-report.md`):

- Baseline **43.2%** → afterAgent **86.8%** (+43.6 pts); response schemas 77 → 144; request bodies
  0 → 40; lint 132 → 45.
- Cost: **342 harvested** + **50 authored** files; `annotation.patch` reproduces `afterAgent`.
- Dominant gap: `response()->noContent()` inferred as `200`, not `204`. Others: `@deprecated` has no
  attribute; `ResourceField nullable/conditional` on a class-string collapses to a bare `$ref`;
  discriminated-union request bodies; `text/plain` has no `MediaType` case.

## Gap inventory (candidate — NOT filed)

Per the playbook, gaps are a candidate inventory for maintainer triage, **not auto-filed**. The
"library bug" labels are the apply agent's _unverified_ interpretation — confirm before filing, and
note some are by-design (e.g. "DELETE-body undocumentable" is HTTP semantics).

**Lychee — 18 gaps.** Bug-candidates worth a look (verify; some may already be tracked/fixed):

1. **Duplicate path+query param** when a validated field name matches a URI `{segment}`
   (`CheckoutController::finalize/cancel`, `OrderController::get`) — `prepareForValidation()` merges
   route params into `rules()`.
2. **Spurious `422` on empty-`rules()` FormRequests** for GET/DELETE (`BasketController`,
   `TimelineController::dates`, `FlowController`).
3. **Nested `#[RequestField('items.*.id')]` dot-notation** emits a flat literal key, not a nested
   array schema (`OrderController::markAsDelivered`).
4. **`Data` classes not auto-registered** as `Collection<int,T>` items or with non-standard
   constructors (`DuplicateFinder`, `StatisticsController`, `PhotoAlbumResource`).

Limitations/config (not bugs): no per-method security-requirement attribute for custom middleware
(`support:se`); no session/cookie scheme for `login_required`; runtime-conditional request schemas
(`ProfileController::update`); duplicate `OPTIONS` ops from `Route::match(['GET','OPTIONS'])`.

**Vito — 25 gaps** (cited report): chiefly `noContent()`→`204`, `@deprecated`, `ResourceField`
nullability composition.

> Recommendation: triage the four Lychee bug-candidates; some may already be tracked. None blocked a
> near-complete spec — each had an attribute or override workaround.

## Verdict

The authoring attributes are a **sufficient escape hatch** at both ends of the spectrum:

- A **well-typed** app needs **near-zero** of them (Lychee: 97.6% at baseline).
- An **imperative/dynamic** app closes the gap with **bounded, linter-signposted** effort, no
  guessing and no library changes (Vito: 43.2% → 86.8%).

Both lifts left the library unmodified. Reproduce: `Workflow({ scriptPath:
".claude/skills/survey/lift.js", args: { app: "Lychee", ws: "<$WS>", lib: "<repo>" } })`.
