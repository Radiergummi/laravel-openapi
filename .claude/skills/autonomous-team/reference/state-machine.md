# State machine, labels & comment protocol

Every role depends on this. The phase of any issue/PR is **the `agent:*` label on it** — never
an in-memory value. A fresh session reconstructs the whole pipeline from these labels plus the
last role comment.

## Phases

```
Full:      planning ─▶ plan-review ─▶ coding ⇄ review ─▶ [survey] ─▶ merge ─▶ done
           planner     reviewer       coder   reviewer    surveyor    lead
                                         ▲______│ (≤3 rounds)

Fast-path:                              coding ──▶ review ──────────▶ merge ─▶ done
(docs / area:cli / area:lint /          coder      reviewer (×1)      lead
 ≤20-line mechanical)
```

- **`[survey]` is conditional:** run only for generation-affecting areas (`area:core`,
  `area:responses`, `area:params`, `area:requests`, `area:plugins`, `area:security`,
  `area:multi-spec`). Skipped for lint/cli/docs. The lead picks the tier when admitting; a
  fast-pathed item that turns out larger/generation-affecting is promoted to Full.

## Label map (one active phase label at a time)

| Phase        | Issue/PR label      | Owner role | PR state |
|--------------|---------------------|------------|----------|
| planning     | `agent:planning`    | planner    | draft    |
| plan-review  | `agent:plan-review` | reviewer   | draft    |
| coding       | `agent:coding`      | coder      | draft    |
| review       | `agent:in-review`   | reviewer   | ready    |
| survey       | `agent:survey`      | surveyor   | ready    |
| escalated    | `agent:needs-human` | (human)    | ready    |

When a role finishes its phase it (a) posts a comment, (b) `SendMessage`s the lead, then the
**lead** moves the label and reassigns the task. Roles do not relabel across phases themselves —
the lead owns transitions so there is a single writer.

Issues carrying any of `blocked`, `deferred`, `spec`, `epic`, or `agent:needs-human` are
**never** picked up.

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
