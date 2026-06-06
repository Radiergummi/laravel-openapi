<!--
PUBLICATION GATE: the app names below are used pre-publication only. Before this package
ships / this page goes public, obtain maintainer permission for each named app or anonymize
it to its category. Tracked in issue #159. Keep the "drifted spec" point GENERALIZED — never
pin a stale/inaccurate spec to a named project. No head-to-head competitor numbers.
-->

# Field report: laravel-openapi against real-world APIs

Feature lists are easy to write. The harder question — the one worth answering before you
adopt a generator — is *what happens when you point it at a large, messy, real codebase you
didn't write?*

So we did. We ran `openapi:generate` and `openapi:lint` against eleven popular open-source
Laravel applications, black-box: **no app code was modified, and no app-specific plugins were
written.** Whatever convention could derive on first contact is what you see here — including,
honestly, what it couldn't.

## The corpus

Eleven applications spanning wikis, invoicing, e-commerce, server panels, a music streamer, a
photo manager, an institutional CRM, and a 26-plugin ERP — together more than 3,400 routes:

| Application | What it is | Laravel | Routes |
|---|---|---|---|
| BookStack | Documentation wiki | 12 | 246 |
| Invoice Ninja | Invoicing platform | 12 | 530 |
| Bagisto | E-commerce | 12 | 406 |
| Pelican | Game-server panel | 13 | 256 |
| Lychee | Photo manager | 12 | 269 |
| Koel | Music streamer | 13 | 237 |
| Coolify | Server-management panel | 12 | 359 |
| Vito | Server-management panel | 12 | 457 |
| AdvisingApp | Multi-tenant CRM (Filament) | 12 | 539 |
| Speedtest Tracker | Speed-test dashboard | 13 | 41 |
| AureusERP | 26-plugin ERP (Webkul) | 13 | 149 |

## Robustness: it doesn't fall over

**All eleven generated and linted to completion, with zero crashes.** That includes AureusERP
— a 26-plugin ERP, the densest codebase in the set — which generated 160 operations on first
contact.

- Every app installed cleanly on the first `composer require`, on both Laravel 12 and 13.
- The suite ran against both supported `swagger-php` majors (5.x and 6.x) without issue.
- The handful of generation-aborting bugs we *did* find — all early, in the first apps we
  tried — were fixed before 1.0. The last several apps we added, totalling thousands of
  routes, surfaced none.

If your application is modern Laravel, the realistic expectation is: it installs, it runs, and
it produces a document.

## What convention covered — and what it didn't

This is the part most generators won't tell you plainly. The package reads *structure that
already exists in your code* — signatures, types, PHPDoc, attributes, model metadata — and it
does **not** parse method bodies to guess shapes. The corpus made the consequences concrete.

### Request bodies: strong, where the code declares them

Where an app uses `FormRequest` or typed `Data` classes, request-body fidelity was consistently
strong:

- **AureusERP** yielded **17 rich request-body schemas** straight from its FormRequests — one of
  them (a partner request) with **23 typed properties**, validation constraints and all.
- Apps that validate **inline** in the controller body (`$request->validate([...])`) or in
  separate Action classes gave up less — a known, bounded gap, not a failure. Body-level
  inference for those idioms is [on the roadmap](../README.md#roadmap).

### Responses: exactly as typed — a three-point spectrum

Response-body fidelity tracked one thing above all: **how concretely the controller types its
return.** Across all eleven apps it fell on a clean spectrum:

1. **Typed to the concrete class → full schema.** Lychee types its controller returns to
   concrete `*Resource`/`Data` classes, and convention produced **83 response schemas** with no
   annotations at all.
2. **Typed to a base class → envelope, empty payload.** An app that types returns to the *base*
   `JsonResource` (rather than the concrete subclass) gets the correct `{ data: … }` envelope,
   but the payload `$ref` is empty — the concrete shape exists only at runtime, so there's
   nothing to read.
3. **Untyped / imperative / transformer-based → no response body.** Apps returning untyped
   arrays, building responses through a custom helper, or using Fractal transformers got the
   operation and its status codes, but no response schema — there's no shape in the signature
   to derive from.

**The honest takeaway, and the one sentence to remember:** *type your returns to the concrete
class and you get response schemas for free; everything short of that gives you proportionally
less.* This isn't a limitation we're hiding — it's the deliberate boundary described in
[What it won't infer](../README.md#what-it-wont-infer), and the linter tells you exactly which
operations landed where.

## A note on accuracy

One pattern is worth calling out because it cuts against intuition. In more than one app, the
generated document matched the application's **live route table** more closely than a
hand-maintained spec that had quietly drifted away from the code — routes renamed, or
annotations describing endpoints that no longer existed. A spec derived from the code can't
drift from it. That's the quiet argument for generation over hand-authoring: not that it's less
work (though it is), but that it stays true.

## How to read this

A few caveats, stated plainly so the numbers mean what they say:

- **Black-box.** No application's code was changed and no app-specific plugins were written.
  These are first-contact results, not best-case ones — a few well-placed type hints or
  attributes would raise coverage in most of these apps.
- **Point-in-time.** Each result reflects a specific version of each app and of this package.
  Both move; your numbers will differ.
- **Your mileage depends on your code, not on luck.** The spectrum above is the whole story:
  coverage is a direct function of how much shape your code expresses in types and signatures.
  That's also what makes it predictable.

## What this means for your app

If your controllers are typed and you lean on `FormRequest`/`Data`, expect convention to carry
most of the document on the first run. If they aren't, expect the linter to show you precisely
where the gaps are — and add a type hint or an [authoring attribute](attributes.md) to close
each one. Either way, you start from a document that already tracks your real routes, and you
improve it deliberately from there.
