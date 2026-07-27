# State machine, labels & comment protocol

Every role depends on this. The phase of any issue/PR is **the `agent:*` label on it** — never
an in-memory value. A fresh session reconstructs the whole pipeline from these labels plus the
last role comment.

## Phases

```
Full:      planning ─▶ plan-review ─▶ coding ⇄ review ─▶ [docs] ─▶ [survey] ─▶ merge ─▶ done
           planner     reviewer       coder   reviewer  docs-writer surveyor    lead
                                         ▲______│ (≤3 rounds)  └▶ reviewer docs-check

Fast-path:                              coding ──▶ review ──────────▶ merge ─▶ done
(area:cli / area:lint /                 coder      reviewer (×1)      lead
 ≤20-line mechanical)

Docs-path:  [planning] ─▶ docs ──▶ review ──▶ merge ─▶ done
(documentation issue)   docs-writer  reviewer    lead
```

- **`[survey]` is conditional:** run only for generation-affecting areas (`area:core`,
  `area:responses`, `area:params`, `area:requests`, `area:plugins`, `area:security`,
  `area:multi-spec`). Skipped for lint/cli/docs. The lead picks the tier when admitting; a
  fast-pathed item that turns out larger/generation-affecting is promoted to Full.
- **`[docs]` is conditional:** after code review, the lead inserts a docs sub-phase only when the
  change has **material** documentation impact (new public surface, authoring attribute, config
  key, lint rule, or a conceptual change). The `docs-writer` updates the pages on the same branch,
  then a `reviewer` docs-checks. Trivial doc impact is handled inline by the coder + the reviewer's
  docs-gap duty — no sub-phase. A `documentation`-labeled issue uses the Docs-path, owned by the
  `docs-writer`.

## Label map (one active phase label at a time)

| Phase        | Issue/PR label      | Owner role | PR state |
|--------------|---------------------|------------|----------|
| planning     | `agent:planning`    | planner    | draft    |
| plan-review  | `agent:plan-review` | reviewer   | draft    |
| coding       | `agent:coding`      | coder      | draft    |
| review       | `agent:in-review`   | reviewer    | ready    |
| docs         | `agent:docs`        | docs-writer | ready    |
| survey       | `agent:survey`      | surveyor    | ready    |
| escalated    | `agent:needs-human` | (human)     | ready    |

When a role finishes its phase it (a) posts a comment, (b) `SendMessage`s the lead, then the
**lead** moves the label and reassigns the task. Roles do not relabel across phases themselves —
the lead owns transitions so there is a single writer. The lead transitions with
`bin/set-phase <num> <phase> "<comment>"`, which removes any other `agent:*` label and posts the
annotation atomically — keeping the single-active-label invariant intact. Once the PR exists,
transition the **PR** number: `set-phase` detects a PR and also clears the stale label from the
issue(s) it closes, so the phase locus migrates issue → PR with no double-count on `resume-scan`.

Issues carrying any of `blocked`, `blocked:upstream`, `deferred`, `epic`, `human-task`, or
`agent:needs-human` are **never** picked up.

`spec` is **not** on that list. Here it means *"OpenAPI-specification work"* — the generation
behaviour of the library — not *"planning-only issue"*. Nearly the whole backlog carries it, so
excluding it would leave the team nothing to do. `bin/eligible-issues` is the source of truth.

## Comment protocol (durable annotations)

Post a comment on the issue (planning) or PR (everything after) at **every** transition and for
**every** finding. Format so humans skim it and `resume-scan` can parse it:

```
🤖 **[<role>]** <one-line status>

<details: findings list, regression table, commit sha, decision, etc.>
```

Examples:
- `🤖 **[planner]** plan posted — see PR body. Ready for plan-review.`
- `🤖 **[reviewer]** plan-review: tightened scope to Tier-1 body-scan; approved.`
- `🤖 **[coder]** implemented; CI green locally (test/pint/phpstan). Pushed a1b2c3d. Ready for review.`
- `🤖 **[reviewer]** review round 2 — 1 finding: nullable branch untested (src/Lint/...:88).`
- `🤖 **[coder]** addressed round 2; added test; pushed d4e5f6a.`
- `🤖 **[surveyor]** Layer A pass — 0 regressions across 11 apps.`
- `🤖 **[surveyor]** Layer A FAIL — Vito: paths −3, schemas −1. Back to coder (attempt 1/3).`
- `🤖 **[lead]** non-controversial + all gates green → squash-merged, closed #N, worktree removed.`
- `🤖 **[lead]** controversial (touches Contracts) → needs-human. PR ready, not merged.`

## Resume contract

On startup the lead runs `bin/resume-scan`, which returns, per `agent:*` label, the issues/PRs in
that phase. The lead rebuilds tasks and re-spawns roles, then each role reads the **last comment
matching its role tag** on its assigned issue/PR to recover context. Because coders push WIP
frequently and worktrees persist on disk, no work is lost across sessions.
