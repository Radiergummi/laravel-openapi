# The well-typed coverage bar (#460)

A reproducible, segmented metric for the release premise *"well-typed apps → almost automatic;
less-typed apps → more attributes."* The corpus headline (~28% substantive over 11 apps,
Fractal-off) is **dragged down by architecturally dynamic apps** and understates the case the
release wants to stand on. This page defines the segmentation, states the reproducible command,
reports the measured numbers (labelling fresh vs cited), and proposes a bar.

> **The committed bar is the maintainer's call.** Per `measure first, then commit`, this page is
> a measurement + a *proposal*. No percentage is published to README/docs by this work.

## 1. Why a plain app-aggregate is the wrong number

The headline `substantivePercent` (`responseSchemas / apiOperations`) is **pessimistic** in two
ways that have nothing to do with how well an app is typed:

- A **correctly-empty** action (a `void`/`204`/`noContent` endpoint) has no body to document, yet
  it counts against `substantivePercent`. Lychee is the extreme case: **40.0%** substantive but
  **97.6%** complete — the gap is ~120 genuinely no-content operations scored as misses.
- A **dynamic** action (`response()->json($runtimeArray)`, a Fractal transformer, a multi/conditional
  return) produces no static shape the generator can read **by design** — Tier-2 territory the
  premise explicitly does *not* claim to cover unaided.

So the honest question is conditional: *when an app types its returns, does the generator produce a
schema?* That requires segmenting both the **apps** (which are well-typed) and the **actions**
(which return a typed payload).

## 2. Segmentation criteria (independent of the generator's output)

Each corpus app carries a `typedness` tier in `corpus.json`, assigned from **architectural source
signals** — never from substantive coverage (that would be circular):

| Tier | Criteria | Apps |
|------|----------|------|
| `well-typed` | Responses are predominantly typed: API Resources (`JsonResource`) and/or Spatie `Data` returned from controllers, or model-metadata-legible Filament resources; **not** Fractal/dynamic-json dominant. | AdvisingApp, AureusERP, Lychee, Vito |
| `mixed` | Partial convention adoption — some Resources/Data, but a large dynamic-json or reflection-opaque remainder. | Bagisto, Koel, SpeedtestTracker |
| `dynamic` | Hand-built dynamic payloads dominate (`response()->json($array)`); little/no Resource/Data usage. | BookStack, Coolify |
| `dynamic-fractal` | League Fractal transformers (`->itemResponse()`/`->listResponse()`/`transformWith`) are the response idiom — resolved only with the (default-off) Fractal plugin. | InvoiceNinja, Pelican |

Signals used (all repo-derivable, generator-independent): density of `JsonResource`/Spatie `Data`
classes used as returns; presence of `league/fractal`; volume of `response()->json(` call sites;
and the per-route return-shape distribution in `classify.json` (below).

## 3. The reproducible metric

`tools/survey/typedness.php` joins two per-app artifacts and segments by **action-return shape**:

- `generated-spec.json` — the generated document (substantive test, shared with `metrics.php`).
- `classify.json` — per-route reflected return type + shape, produced by the **#413 classifier**.
  Each route's `shape` buckets into `typed` (Resource/Data construction, `resource::` helpers,
  static Resource factories, literals), `no-content` (`void`/`noContent`), or `dynamic` (everything
  else: `json()` bodies, Fractal chains, multi/conditional returns, closures, reflection failures).

It emits, per app and per tier:

- `substantivePercent` — `substantive / apiOperations` (the pessimistic headline; matches `metrics.php`).
- `honestPercent` — `substantive / (apiOperations − correctlyEmpty)`; whole-app coverage with the
  correctly-empty drag removed.
- `typedReturnCoverage` — of actions returning a **typed payload**, the share given a substantive
  schema. **This is the premise's headline number.**

```bash
# One app:
php tools/survey/typedness.php "$WS/apps/Vito" --prefix=/api

# Whole corpus, rolled up by typedness tier:
php tools/survey/typedness.php --corpus "$WS"
```

`classify.json` is the one input not yet produced by the committed harness — the classifier lands
with **#413** ("fold a lean version into `metrics.php`"). Until then, `typedness.php` consumes the
recorded `classify.json` artifacts; run without one it still prints `substantivePercent` and marks
the action-typedness fields `null` (it never guesses the shape — spec contentless-2xx cannot tell a
void action from a generator give-up, which is exactly why #413 exists).

## 4. Measured results

**Provenance.** Recorded corpus run **2026-06-26**, library commit `0c1d9e3f`, 11 apps, PHP 8.4,
**published default plugin set** (Fractal/QueryBuilder off — the honest "untouched install").
`substantive`/`apiOperations` are **freshly recomputed by `typedness.php`** from each app's recorded
`generated-spec.json`; they reproduce the recorded `results.json` exactly for the 9 apps it scored,
and additionally fill **InvoiceNinja (67/522)** and **Koel (56/170)**, which were `null` in
`results.json` (the metrics step fataled under the worktree autoloader, #468 — the specs themselves
generated cleanly, `generateExit: 0`). The action-typedness join consumes the recorded
**`classify.json`** (#413 classifier spike, same run); it is **cited recorded data**, not freshly
re-runnable here until #413 lands the classifier.

### By tier

| Tier | apiOps | substantive% | honest% | typed actions | typedReturnCoverage |
|------|-------:|-------------:|--------:|--------------:|--------------------:|
| **well-typed** | 470 | 60.9% | **73.9%** | 222 | **87.8%** |
| mixed | 219 | 30.1% | 32.5% | 54 | 79.6% |
| dynamic | 240 | 7.9% | 7.9% | 2 | — |
| dynamic-fractal (Fractal off) | 680 | 10.7% | 10.9% | 2 | — |
| **corpus (11)** | 1609 | 27.6% | 29.6% | 280 | **85.0%** |

### Well-typed apps, per app

| App | apiOps | substantive% | honest% | typedReturnCoverage | Note |
|-----|-------:|-------------:|--------:|--------------------:|------|
| Vito | 144 | 79.2% | 99.1% | 100% | 34 `JsonResource` classes; cleanest case |
| AdvisingApp | 31 | 77.4% | 77.4% | — | Filament/invokable controllers — classifier reads 0 typed actions, yet 77% substantive via **Tier-0 model metadata** |
| AureusERP | 90 | 73.3% | 73.3% | 100% | Resource-returning Filament + QueryBuilder API |
| Lychee | 205 | 40.0% | 54.3% | 64.9% | 119 Spatie `Data` classes; heavy correctly-empty + static-factory returns |

## 5. Caveats (read before quoting any number)

1. **Classifier blind spot understates typed coverage.** AdvisingApp's invokable Filament controllers
   defeat reflection, so the action-conditional 85% **excludes** apps whose typedness comes from
   model metadata rather than explicit `return new XResource(...)`. Its whole-app **77.4%** is the
   honest figure there. The corpus-wide `typedReturnCoverage` is therefore a **floor**, not a ceiling.
2. **`honest%` denominator is approximate for void-heavy apps.** It uses the classifier's reflected
   `no-content` count. Lychee's classifier finds 54 void actions but the spec emits ~120 contentless
   2xx; the residual ~66 are give-ups *or* classifier misses, so Lychee's true honest coverage is a
   **band, 54%–~96%**. Tightening this (separating correctly-empty from genuinely-missing precisely)
   is the **#413** three-way metric's job.
3. **Out-of-box vs stack-enabled (#443).** InvoiceNinja and Pelican are Fractal apps measured with
   Fractal **off** (the published default), so their transformer returns score as `dynamic`. Cited
   prior measurement: InvoiceNinja **Fractal-on ≈ 322/522 ≈ 62%** substantive. `dynamic-fractal`'s
   10.7% is "plugin disabled," **not** "library can't" — the well-typed bar deliberately excludes
   these to avoid conflating the two.
4. Single run, one library commit. Re-baseline when #413/#443 land.

## 6. Proposed bar (for maintainer sign-off)

Lead the premise with the **conditional, action-level** number — it is the least circular and states
the mechanism directly:

> **When a controller action returns a typed API Resource or Data object, the generator produces a
> response schema for it unaided ~85% of the time across real apps — and 100% in the cleanly-typed
> ones (AureusERP, Koel, Vito) — with zero authoring attributes.**

As a whole-**app** figure for a well-typed app, the supporting floor is **~60% substantive / ~74%
honest** unaided response coverage across the entire API surface (the residual being dynamic and
void operations). Recommended framing:

- **Headline (conditional):** *type your returns → ~85–100% of them get a schema, unaided.*
- **Supporting (whole-app):** *a well-typed app reaches ~74% honest response coverage out of the box.*
- **Do not** publish a single inflated app-aggregate %; it conflates dynamic and void operations and
  invites the "but my app got 12%" rebuttal that the segmentation exists to pre-empt.

The committed number that backs the README premise sentence is the maintainer's to set.
