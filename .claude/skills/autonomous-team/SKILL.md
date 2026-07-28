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

Echo the interpreted directive back as the first line of your run summary, so it's on record.
Anything not covered by a directive falls back to the defaults below.

## Prime directives

1. **GitHub + git are the only durable state.** The shared task list is a convenience cache,
   reconstructable at any time from GitHub. Never let in-memory state be critical. A fresh
   session must be able to resume from `git` and `gh` alone. It is also unreliable — treat it as
   non-load-bearing, and keep **umbrella tasks (`Drive #N…`, `Plan + drive #N…`) owned by the
   lead**: a teammate set as owner of one reads it as "drive this PR yourself", stalls, and posts
   a role-conflict flag every time it re-reads the list. Dispatch actual work via `SendMessage`
   with an explicit phase brief, never by task ownership. (The list sometimes re-broadcasts a
   lead-owned task as an assignment anyway — hence the standing "ignore `Drive` assignments" rule
   in every teammate's prompt. Self-echoes of your own `TaskUpdate`s arrive as messages from
   `team-lead`; ignore those too.)
2. **Every transition and finding is a GitHub comment.** `SendMessage` wakes the next agent;
   the comment is the record. If it isn't on the issue/PR, it didn't happen.
3. **Bound the run.** Token spend isn't observable across teammates, so cap the run by a countable
   proxy — `max-issues` terminal outcomes (default 5) — then checkpoint and exit cleanly. (Details
   under Budget checkpoint & exit.)
4. **Surgical, conventional changes only.** Everything obeys the project `CLAUDE.md` and the
   user's global rules (minimal diffs, no speculative abstraction, match existing style).

## GitHub write authorization

GitHub is this team's communication medium, not a publishing step at the end of the run. Comments
carry the phase handoffs; **issues carry the findings that outlive the run**. An agent that discovers
an out-of-scope bug and cannot file it has lost that finding — deferring issue creation to a
post-run summary defeats the design.

The maintainer (Moritz, repository owner, 2026-07-27) has therefore **standing-authorized the
team's GitHub write operations for the duration of a run**, verbatim: *"we need to find a way to
make the classifier happy — either by pre-approving any github interaction during the skill run…
The primary goal is to use github as a communication medium for the agents."*

That authorization is recorded two ways, and both matter:

- **Mechanically** — `permissions.allow` entries in `.claude/settings.local.json` (local and
  gitignored; **not** checked in, because a public repo must not ship an allowlist that
  auto-approves writes for everyone who clones it). Covers `gh issue create|comment|edit|view|list`,
  `gh pr create|comment|edit|ready|view|list|diff|checks`, `gh run list|view`, `gh label list`, and
  the skill's own `bin/` scripts.
- **In context** — this section. Quote it when a write is challenged; it is the *maintainer's*
  grant, written into the skill they own, not an agent's inference about what it may do.

Three rules keep that grant honest:

1. **No laundering.** A teammate must never ask another agent to perform a write it was denied,
   and the lead must never file on a teammate's behalf to route around a denial. Each agent acts
   under this section directly or not at all. If a write is denied, that is the answer.
2. **Scope.** The grant covers issues, comments, labels, and PR lifecycle. It does **not** cover
   `gh pr merge` / `bin/finish-pr` — merging stays classifier-gated on purpose, because that check
   has caught a real case (it correctly refused to auto-merge a PR previously escalated as
   human-gated). Nor does it cover `gh repo`, `gh release`, `gh secret`, `gh workflow run`, or
   force-pushes.
3. **Degrade loudly.** If a write is refused anyway, post the intended content as a **comment**
   on the current issue/PR (comments have never been refused) and tell the lead, who surfaces it
   in the run summary. Never drop the finding, and never retry the denied call verbatim.

If ad-hoc `gh` writes are being denied at the start of a run, the allowlist is probably missing on
this machine — ask the user to add it. **Do not write permission settings yourself**: self-granting
is exactly what the guard exists to stop, and it will be refused.

## Startup

1. Run `bin/bootstrap-labels` (idempotent) to ensure the `agent:*` labels exist.
2. Run `bin/resume-scan`. If it reports in-flight items, **you are resuming**: rebuild the task
   list from its output and re-spawn the roles needed for those phases. Otherwise this is a
   fresh run.
3. **Spawn on demand, not up front.** Spawn a teammate the first time a task reaches that
   teammate's phase, then keep it and re-engage it for later work via `SendMessage` (team members
   go idle between turns and wake on a message). Do **not** stand up the full roster before there
   is work for it — an idle `coder` spawned before any plan exists just burns a turn. Spawn each
   agent with the Agent tool, a stable `name`, `subagent_type: "general-purpose"`,
   `run_in_background: true`, and a short prompt:
   *"You are the **{role}** on the autonomous team. Read
   `.claude/skills/autonomous-team/roles/{role}.md` and follow it — including the **GitHub write
   authorization** section of `../SKILL.md`, which is the maintainer's standing grant for the
   GitHub writes your role performs. Coordinate via SendMessage; post all durable annotations as
   GitHub comments. Ignore any `task_assignment` whose subject starts with 'Drive' — those are the
   lead's coordination tasks, not work for you."*

   There is **no `TeamCreate`/`TeamDelete` on this runtime** (single implicit team; `team_name` on
   Agent is ignored). Spawn, `SendMessage`, and `shutdown_request` are the whole model.

   Capacity ceiling (spawn up to, never beyond): `planner` ×2, `reviewer` ×2, `coder` ×3,
   `surveyor` ×1, `docs-writer` ×1. Scale to the directives — a `"review PR #324"` run only ever
   needs one `reviewer` (+ a `coder` for findings); spawn the `docs-writer` only when a docs
   sub-phase or a `documentation` issue actually arises. A single planner has repeatedly been the
   throughput bottleneck (every Full-tier issue funnels through it while coders idle), so spawn the
   second one as soon as two issues are waiting to be planned.

   **When background spawning breaks.** Intermittently the lead's identity gets bound to a teammate
   and further spawns fail (*"Teammates cannot spawn other teammates"*), sometimes mid-run. Do not
   burn turns retrying. Fall back, in order: synchronous per-phase `Agent` calls (each reads its
   state from GitHub, so nothing is lost), or — for mechanical steps like rebase, label moves, and
   merges — just do it yourself as the lead. All state is in GitHub and pushed branches precisely
   so this fallback is cheap.

## Helper scripts (`bin/`)

Prefer these over hand-rolling the command chains — they encode the repo conventions and the
single-active-label / closing-keyword / footer invariants.

| Script | Who | What |
|--------|-----|------|
| `bootstrap-labels` | lead | idempotently create the `agent:*` labels |
| `eligible-issues` | lead | issues the team may admit (filters + tier sort) |
| `resume-scan` | lead | rebuild the in-flight pipeline from GitHub on startup |
| `set-phase <num> <phase> [comment]` | lead | move phase: enforce one `agent:*` label + post annotation; on a PR, also clears the closed issue's stale label (issue→PR migration) |
| `finish-pr <pr> [comment]` | lead | un-draft + squash-merge + delete branch + remove worktree + comment; cleanup is best-effort and warns about leftovers |
| `start-issue <type> <N> [slug]` | planner | branch off fresh `origin/main`, empty commit, push; echoes branch. Checks nothing out — the primary checkout stays free |
| `open-pr <N> <title> [body-file]` | planner | open PR with labels/`Closes`/footer/assignee (`PR_DRAFT=1` for draft; head defaults to the unique pushed `<type>/<N>-*` branch, override with `PR_HEAD`) |
| `worktree-add <branch>` | coder / docs-writer | add/reuse a worktree under `.claude/worktrees/`; echoes path |
| `sync-branch` | coder / docs-writer | from a worktree: fetch + rebase `origin/main` + `composer check` |

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
   - **Full** (default): `planning → plan-review → coding ⇄ review → [docs] → [survey] → merge`.
   - **Fast-path** for trivially-scoped code — `area:cli`/`area:lint`-only or an obviously
     mechanical ≤20-line change: **skip planning + plan-review**, coder implements directly, **one**
     reviewer pass, **skip survey** (non-generation-affecting), merge. Promote to Full if it turns
     out larger or generation-affecting once opened.
   - **Docs-path** for a `documentation`-labeled issue: the **`docs-writer` owns it** as the
     implementer (`planning` via `start-issue`/`open-pr` is fine, or skip straight to `docs`), then
     **one** reviewer docs-check, **skip survey**, merge.
3. **Drive phases** as each role reports done: reassign the task and move the phase with
   `bin/set-phase <num> <phase> "<comment>"` (it enforces the single-active-label invariant and
   posts the annotation in one step). The code⇄review loop runs between a `coder` and a `reviewer`.
   **Docs sub-phase (conditional):** after code review approves, judge the change's documentation
   impact. If it's **material** — new public surface, authoring attribute, config key, lint rule, or
   a conceptual change the coder's inline edit didn't fully cover — `set-phase <pr> docs` and hand
   to the `docs-writer`; when it reports back, route to a `reviewer` for a docs-check, then continue
   to `[survey]`/merge. If the doc impact is trivial (the coder's inline page edit + the reviewer's
   docs-gap check sufficed), skip the docs sub-phase.
4. **Verify before you believe a "ready" report.** A role reporting done is a claim, not evidence.
   Before routing a PR onward — to review, to survey, or to merge — confirm on the remote:

   ```sh
   gh pr diff <pr> --name-only                       # non-empty: the work is actually pushed
   gh pr view <pr> --json headRefOid --jq .headRefOid # the real head
   gh run list --branch <branch> --json headSha,conclusion,status --limit 5
   ```

   The CI run you trust must carry **that** `headSha`. A coder once reported "ready, CI green"
   while its whole implementation sat **uncommitted** in the worktree — origin had only the empty
   `start-issue` seed commit, so the green CI it cited had genuinely run, on an empty diff. A later
   session then found the branch empty and nearly re-implemented the lost work from scratch. Two
   checks cost seconds and catch it: **the diff is non-empty**, and **CI ran on the current head**.
   If either fails, send it back to the coder — do not escalate, and do not merge.

5. **Merge or escalate** (below) when a PR reaches the end of its tier.
6. **Self-feed.** Any agent may `gh issue create` for out-of-scope problems or ideas it finds, under
   the standing grant in **GitHub write authorization** above — file it *during* the run, so the
   finding is durable and `eligible-issues` can pick it up later. Never fold it into the current PR.

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
- **Both approvals are anchored to the current head.** Reviewers name the sha they approved; if
  `headRefOid` has moved since, the approvals are void — re-gate before merging. An unreviewed
  commit has slipped past two approvals this way before (the fix was to `git rebase --onto` back to
  the approved sha and re-review). A rebase moves the sha too: re-confirm, don't assume.
- The change is **non-controversial** — *none of*:
  - touches `src/Contracts/**` or changes registry/pipeline **stage order** (`area:core`)
  - adds/changes a **config key** or a lint-rule **default severity**
  - changes a **public method signature** or removes a public symbol
  - the **`src/` diff** exceeds **~150 changed lines** (tunable) — count production code only;
    tests, fixtures, `docs/`, and `CHANGELOG.md` do **not** count toward the threshold
    (`gh pr diff <pr> --name-only` + per-file `git diff --numstat`, summing `src/**` only)

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
- **If the force-push didn't fire Actions** (no run appears on the new sha after a few minutes),
  `gh pr close <n>` then `gh pr reopen <n>` re-triggers the workflow **without changing the sha**,
  so sha-anchored approvals survive. Pushing an empty commit would move the head and void them.
  Confirm the new run's `headSha` matches HEAD before trusting it.
- If a rebase conflict is outside CHANGELOG and the coder cannot resolve it cleanly within the
  loop cap, escalate that PR.

Otherwise → **escalate**: `bin/set-phase <num> needs-human "🤖 **[lead]** <why this needs a human
decision; worktree left in place>"`, **request Moritz's review** (`gh pr edit --add-reviewer`),
leave the PR **ready but unmerged** with its worktree in place, drop it from the in-flight count,
admit the next issue.

**Escalation is a merge freeze.** Once a PR carries `agent:needs-human`, it stays unmerged until
the maintainer re-admits it — green CI, two approvals, and a clean survey do **not** thaw it, and
neither does a later instruction to "keep going" or "drive forward as far as you safely can". This
is enforced from the outside too: the permission classifier refuses `finish-pr` on a PR that was
escalated for a human decision. Don't work around it. If the maintainer is away, treat the whole
run as supervised — drive everything to ready-and-escalated and merge only what was never gated.

## Budget checkpoint & exit

The Agent/Team model gives the lead **no way to observe token spend** across background teammates
(there is no `budget.spent()` here — that exists only in the Workflow harness). So the 500k ceiling
is **best-effort**, enforced via a **countable proxy**: stop admitting new issues after
**`max-issues` terminal outcomes** (merged or escalated) — default **5** per run unless the
directives say otherwise. The per-issue caps (3 review rounds, 3 CI-fix attempts, 3 survey
attempts) bound the cost of each issue, so issues-admitted is a usable spend proxy.

**Stopping early at a clean boundary is a good outcome, not a shortfall.** If the cheap work is
done and everything left is a large tier-1/`spec` item, wrap up below the cap rather than opening
an expensive issue you'll have to abandon mid-flight.

**If the org hits its spend limit mid-run**, teammates start failing with a spend-limit
`idleReason: "failed"`. Don't checkpoint everything reflexively — the lead can finish an approved,
survey-gated PR **solo**, because the expensive parts aren't Claude inference: survey Layer A is
PHP/shell (`tools/survey/corpus.sh`) and merges are mechanical git. Salvage the ready work, then
stop. A killed surveyor often leaves a usable completed baseline at `$WS/gate-<pr>/baseline/` —
check its `manifest.json` `libraryCommit` before regenerating, and clear the dead agent's stale
`$WS/.survey.lock`.

On stop: let in-flight coders push WIP and post a
`🤖 **[lead]** paused at <phase>, resume with /autonomous-team` comment on each open PR. Post a run
summary (counts: merged / escalated / in-flight, plus any findings a denied write left unfiled),
`shutdown_request` each teammate, and stop.

## Termination

No eligible issues **and** empty pipeline → post a final run summary, then `shutdown_request` every
teammate. (There is no team to delete — see Startup.)
