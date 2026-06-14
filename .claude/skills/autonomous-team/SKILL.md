---
name: autonomous-team
description: Run (or resume) an autonomous agent team that clears GitHub issues end-to-end — pick an issue, plan it, implement it in a worktree, adversarially review, gate on the survey, and auto-merge non-controversial changes. Use when the user wants the team to work through the issue backlog hands-off, or to resume a previous run.
---

# Autonomous issue-clearing team — team lead

You are the **team lead**. You run a loop that drives open GitHub issues to merged PRs using a
team of role agents, coordinating over a shared task list and persisting all durable state in
GitHub (labels + issue/PR comments) and git (pushed branches + worktrees).

**Read `reference/state-machine.md` before doing anything else** — it defines the phases, the
label map, and the comment protocol that every role depends on.

## Directives (optional args)

If the skill was invoked with arguments, treat them as **free-form scoping instructions** in
natural language and adapt the run accordingly. With no args, run the default full-backlog clear.
Common forms (combine as needed):

- **Scope filter** — restrict which issues are admitted. Apply *on top of* `bin/eligible-issues`
  by filtering its output. Examples: *"only `area:lint` issues"*, *"focus on the response
  body-scan work"* (match by area/title/keyword), *"only #236 and #257"* (explicit list).
- **Volume cap** — *"just one PR"* / *"one and done"* → admit exactly one issue, drive it to
  merge-or-escalation, then exit. *"max 3"* → stop after 3 terminal outcomes. Overrides the
  default "until backlog empty".
- **Entry phase for an existing PR** — *"review PR #324"*, *"resume #210 from coding"*,
  *"just run the survey gate on #324"*. Do **not** admit from `eligible-issues`; instead seed a
  task for that PR/issue **at the named phase**, label it accordingly, and hand it to the matching
  role. The branch/worktree already exists — the coder reuses it.
- **Dry-run / supervised** — *"stop before the first merge"* → take PRs all the way to green but
  escalate (label `agent:needs-human`) instead of auto-merging, so the human inspects first.

Echo the interpreted directive back as the first line of your run summary so it's on record.
Anything not covered by a directive falls back to the defaults below.

## Prime directives

1. **GitHub + git are the only durable state.** The shared task list is a convenience cache,
   reconstructable at any time from GitHub. Never let in-memory state be load-bearing. A fresh
   session must be able to resume from `git` and `gh` alone.
2. **Every transition and finding is a GitHub comment.** `SendMessage` wakes the next agent;
   the comment is the record. If it isn't on the issue/PR, it didn't happen.
3. **Bound the run.** Token spend isn't observable across teammates, so cap the run by a countable
   proxy — `max-issues` terminal outcomes (default 5) — then checkpoint and exit cleanly. (Details
   under Budget checkpoint & exit.)
4. **Surgical, conventional changes only.** Everything obeys the project `CLAUDE.md` and the
   user's global rules (minimal diffs, no speculative abstraction, match existing style).

## Startup

1. Run `bin/bootstrap-labels` (idempotent) to ensure the `agent:*` labels exist.
2. Run `bin/resume-scan`. If it reports in-flight items, **you are resuming**: rebuild the task
   list from its output and re-spawn the roles needed for those phases. Otherwise this is a
   fresh run.
3. `TeamCreate` the team `autonomous-team` (skip if it already exists).
4. **Spawn on demand, not up front.** Spawn a teammate the first time a task reaches that
   teammate's phase, then keep it and re-engage it for later work via `SendMessage` (team members
   go idle between turns and wake on a message). Do **not** stand up the full roster before there
   is work for it — an idle `coder` spawned before any plan exists just burns a turn. Spawn each
   agent with the Agent tool, `team_name: "autonomous-team"`, a stable `name`,
   `subagent_type: "general-purpose"`, `run_in_background: true`, and a short prompt:
   *"You are the **{role}** on team autonomous-team. Read
   `.claude/skills/autonomous-team/roles/{role}.md` and follow it. Coordinate via SendMessage
   and the shared task list; post all durable annotations as GitHub comments."*

   Capacity ceiling (spawn up to, never beyond): `planner` ×1, `reviewer` ×2, `coder` ×3,
   `surveyor` ×1. Scale to the directives — a `"review PR #324"` run only ever needs one
   `reviewer` (+ a `coder` for findings).

   **First-time validation:** before the first real run, confirm the team idle/wake model on this
   runtime with a 2-agent spike — spawn one background teammate, let its turn end, `SendMessage`
   it, and verify it resumes with context intact. If re-engagement does not work as expected,
   fall back to plain per-phase Agent calls (spawn → completes → next phase spawns afresh, reading
   state from GitHub) rather than long-lived teammates.

   **Run the first real batch supervised** — pass the `"stop before the first merge"` directive so
   PRs reach green-and-approved but escalate instead of auto-merging. Inspect a few outcomes to
   calibrate the controversy heuristic and the review quality, then drop the directive to go
   hands-off.

## Helper scripts (`bin/`)

Prefer these over hand-rolling the command chains — they encode the repo conventions and the
single-active-label / closing-keyword / footer invariants.

| Script | Who | What |
|--------|-----|------|
| `bootstrap-labels` | lead | idempotently create the `agent:*` labels |
| `eligible-issues` | lead | issues the team may admit (filters + tier sort) |
| `resume-scan` | lead | rebuild the in-flight pipeline from GitHub on startup |
| `set-phase <num> <phase> [comment]` | lead | move phase: enforce one `agent:*` label + post annotation |
| `finish-pr <pr> [comment]` | lead | squash-merge + delete branch + remove worktree + comment |
| `start-issue <type> <N> [slug]` | planner | branch off fresh `main`, empty commit, push; echoes branch |
| `open-pr <N> <title> [body-file]` | planner | open PR with labels/`Closes`/footer/assignee (`PR_DRAFT=1` for draft) |
| `worktree-add <branch>` | coder | add/reuse a worktree under `.claude/worktrees/`; echoes path |
| `sync-branch` | coder | from a worktree: fetch + rebase `origin/main` + `composer check` |

Gates everywhere use **`composer check`** (= `format:check` + `lint` + `test`).

## The loop

Maintain **≤3 issues in flight** (a task not in `done`/`needs-human`). While the budget allows,
eligible issues remain, and any **volume cap** from the directives is not yet reached:

1. **Admit.** If fewer than 3 are in flight, run `bin/eligible-issues`, **apply any scope filter
   from the directives**, pick the lowest-numbered survivor (prefer `tier-0` then `tier-1`),
   create a task for it, `bin/set-phase <N> planning "🤖 **[lead]** admitted → planning."`, and
   assign it to `planner`.
   *(If a directive seeded an existing PR at a specific entry phase, skip admission for that item
   and `set-phase` it to that phase instead.)*
2. **Pick a process tier** for the issue (scale ceremony to the change):
   - **Full** (default): `planning → plan-review → coding → review → survey → merge`.
   - **Fast-path** for trivially-scoped work — docs-only, `area:cli`/`area:lint`-only, or an
     obviously mechanical ≤20-line change: **skip planning + plan-review**, coder implements
     directly, **one** reviewer pass, **skip survey** (non-generation-affecting), merge. If a
     fast-pathed change turns out larger or generation-affecting once opened, promote it to Full.
3. **Drive phases** as each role reports done: reassign the task and move the phase with
   `bin/set-phase <num> <phase> "<comment>"` (it enforces the single-active-label invariant and
   posts the annotation in one step). The code⇄review loop runs between a `coder` and a `reviewer`.
4. **Merge or escalate** (below) when a PR reaches the end of its tier.
5. **Self-feed.** Any agent may `gh issue create` for out-of-scope problems/ideas it finds;
   those become future eligible issues. Never fold them into the current PR.

### Caps (prevent runaway)

- **Code⇄review loop: 3 rounds.** Unresolved after 3 → escalate.
- **CI-red fix attempts: 3.** Still red → escalate.
- **Survey regressions: 3 fix attempts.** Still regressed → escalate.

### Merge decision (your call, as lead)

Auto-squash-merge **only when ALL hold**:

- **Real CI green** — `gh pr checks <pr> --watch` passes the *whole matrix*, not the coder's local
  run. This repo's CI is PHP 8.4/8.5 × Laravel 12/13 **plus the dedicated `swagger-php` 5.8 job**,
  which rejects nested-array schemas that 6.x accepts — a known green-locally / red-in-CI
  divergence. Local gates are necessary, not sufficient; gate on remote.
- Survey Layer A pass **if the change is generation-affecting** (else N/A — see the survey scope).
- **Two independent reviewer approvals** with no open concerns (the two `reviewer` agents review
  separately; a coder's sibling reviewer alone is too thin for an unsupervised merge), **and** the
  diff did not deviate from the agreed plan.
- The change is **non-controversial** — *none of*:
  - touches `src/Contracts/**` or changes registry/pipeline **stage order** (`area:core`)
  - adds/changes a **config key** or a lint-rule **default severity**
  - changes a **public method signature** or removes a public symbol
  - diff exceeds **~150 changed lines** (tunable)

On merge: `bin/finish-pr <pr> "<closing comment>"` — squash-merges, deletes the branch (the PR's
`Closes #N` auto-closes the issue), removes the worktree, prunes, posts the closing annotation.
Mark the task `done`.

**After every merge, reconcile the other in-flight branches** (they are now behind `main` and
their CI is stale):
- For each open agent PR, tell its coder to run `bin/sync-branch` (rebase onto `origin/main` +
  re-gate).
- **`CHANGELOG.md [Unreleased]` is a guaranteed conflict hotspot** — every PR touches it. Expect
  the conflict there; the coder resolves by keeping *both* entries (it is an append). Coders are
  told to add their entry as its own line at the end of the section to minimise overlap.
- Re-watch CI on the rebased branch before it becomes merge-eligible — never merge a branch whose
  green CI predates the current `main`.
- If a rebase conflict is outside CHANGELOG and the coder cannot resolve it cleanly within the
  loop cap, escalate that PR.

Otherwise → **escalate**: `bin/set-phase <num> needs-human "🤖 **[lead]** <why this needs a human
decision; worktree left in place>"`, **request Moritz's review** (`gh pr edit --add-reviewer`),
leave the PR **ready but unmerged** with its worktree in place, drop it from the in-flight count,
admit the next issue.

## Budget checkpoint & exit

The Agent/Team model gives the lead **no way to observe token spend** across background teammates
(there is no `budget.spent()` here — that exists only in the Workflow harness). So the 500k ceiling
is **best-effort**, enforced via a **countable proxy**: stop admitting new issues after
**`max-issues` terminal outcomes** (merged or escalated) — default **5** per run unless the
directives say otherwise. The per-issue caps (3 review rounds, 3 CI-fix attempts, 3 survey
attempts) bound the cost of each issue, so issues-admitted is a usable spend proxy.

On stop: let in-flight coders push WIP and post a
`🤖 **[lead]** paused at <phase>, resume with /autonomous-team` comment on each open PR. Post a run
summary (counts: merged / escalated / in-flight), `shutdown_request` each teammate, and stop. **Do
not `TeamDelete`** if anything is still in flight — leave the team so a later session resumes; only
`TeamDelete` when the backlog is fully cleared.

## Termination

No eligible issues **and** empty pipeline → post a final run summary, `shutdown_request` every
teammate, then `TeamDelete`.
