---
name: upstream-review
description: Review a new upstream release of a high-coupling dependency (Laravel, swagger-php, Spatie Data, etc.) for impact on laravel-openapi, and file actionable findings as GitHub issues. Use when a tracked dependency makes a release, or to sweep all tracked deps for newer versions.
---

# Upstream release review

Reviews a new upstream release for impact on `radiergummi/laravel-openapi`. The package is
tightly coupled to specific upstream behaviours (route introspection, which JSON-Schema
keywords swagger-php models, Spatie Data class shapes, the phpdoc-parser/type-info stack), so
a release can break us loudly (a renamed method we `#[Override]`) or silently (swagger-php
dropping a modeled keyword). This skill makes that review streamlined and sharp.

`manifest.md` is the brain: per-package coupling summaries, mechanical tripwires, and the
exact upstream symbols to diff. Read it before reviewing any package. Constraints live in
`composer.json` (read them live); the manifest never duplicates them.

## Strategy: three legs, cheapest-first

Run the legs in cost order. Legs 1–2 do most of the work — `#[Override]` everywhere +
PHPStan level 8 + the test suite + survey Layer A already turn most API-shape breaks into
hard errors or measurable regressions. Leg 3 is the narrow fallback, not the default.

1. **Changelog / release notes.** Read the published CHANGELOG / GitHub release across the
   reviewed→latest gap. Pull out anything touching the package's manifest **Coupling** line.
2. **Mechanical run (gated).** In a throwaway worktree, bump only this constraint and run the
   suite (procedure below). The tripwire that catches breaks for this package is named in the
   manifest. **Gate:** run leg 2 only when leg 1 flags a potential break, the bump crosses a
   major, or the user asked for the full pass — not for benign patch bumps.
3. **Targeted diff reading (narrow).** Only when leg 1 flags semantic risk or leg 2 is
   ambiguous: diff the manifest's **Diff targets** for this package between old and new vendor
   trees. Reserved for changes that compile clean and pass tests but alter generated output.

## Modes

**Discovery (default).** Sweep all tracked packages:
1. Run `bin/outdated` from the repo root → JSON lines of tracked packages with a newer
   release than installed (`{name, current, latest}`).
2. For each, read its manifest **Watermark**. Skip packages whose `latest` is ≤ the watermark
   (already reviewed). Review the rest.
3. Run the legs cheapest-first for each package to review.

**Manual.** The user supplies a `pkg@version` or a changelog URL. Skip discovery; jump
straight to the legs for that one package.

## Leg 2 procedure (gated mechanical run)

Isolate so `composer.json` / `composer.lock` stay pristine:

1. Create a throwaway worktree (reuse the autonomous-team pattern):
   `.claude/skills/autonomous-team/bin/worktree-add upstream-review-<pkg>` (or `git worktree
   add` directly). Work inside it.
2. In the worktree, bump only the one constraint to the new version and
   `composer update <pkg> --with-dependencies`.
3. Run, capturing pass/fail for each:
   - `composer lint` (PHPStan level 8 — catches `#[Override]` / signature breaks)
   - `composer test` (Pest suite, incl. ExamplesTest + snapshots)
   - survey Layer A — `tools/survey/corpus.sh` per the **survey** skill's prerequisites
     (`WS`, `LIB`, PHP 8.4 on PATH). A regression here is a real-world signal.
4. Record which checks failed and the relevant output. Remove the worktree when done
   (`git worktree remove`). Never bump the constraint on `main` here — that's a separate PR.

## Classifying findings

Each finding is one of:
- **breaks-us** — a current capability regresses or errors. File an issue.
- **new-opportunity** — the release unblocks something we deferred (e.g. swagger-php now
  models a keyword that was blocking #140). File an issue, link the unblocked one.
- **benign** — no coupling-point impact. Note it in the report; no issue.

## Filing issues

For each actionable finding, file a GitHub issue wired into the Issues-based workflow:
- Title: `<pkg> <version>: <impact>`.
- Body: what changed, which coupling point (quote the manifest), which leg surfaced it, and a
  recommendation. Link any unblocked issue.
- Labels: from the package's manifest **Tier/area**, plus `bug` (breaks-us) or `spec`
  (new-opportunity / needs design).
- Do **not** open PRs — that is the `autonomous-team` skill's job.

## Advancing the watermark

After reviewing a package, update its **Watermark** in `manifest.md` to
`Last reviewed: <latest> (<today>)`, regardless of classification — a benign release is still
reviewed. Commit the manifest change. This is what keeps discovery from re-reviewing the same
release.

## Output: the report

Emit a structured report, one block per reviewed release:
- Package, reviewed→latest gap.
- Per finding: what changed, coupling point touched, classification, recommendation.
- Links to any issues filed.
- Which legs ran and why leg 2/3 were or weren't triggered (auditable).
