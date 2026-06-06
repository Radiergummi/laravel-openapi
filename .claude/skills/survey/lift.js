export const meta = {
  name: 'survey-lift',
  description: 'Annotation lift for one survey app: baseline -> harvest -> agent apply -> lift delta.',
  phases: [
    { title: 'Baseline' }, { title: 'Harvest' }, { title: 'Plan' },
    { title: 'Apply' }, { title: 'Measure' }, { title: 'Emit' },
  ],
}

const APP = args?.app
if (!APP) throw new Error('survey-lift requires args.app (e.g. {app:"Vito"})')
const WS = args?.ws
if (!WS) throw new Error('survey-lift requires args.ws (absolute dogfood workspace, e.g. "/Users/.../laravel-openapi-dogfood")')
const LIB = args?.lib
if (!LIB) throw new Error('survey-lift requires args.lib (absolute library/worktree checkout root)')
const REPO = args?.repoPath ?? `${WS}/apps/${APP}/repo`
const PREFIX = args?.apiPrefix ?? '/api'
const BIN = `${LIB}/.claude/skills/survey/bin`

// Workflow subagents run fresh shells that do NOT inherit exported env. Bake the
// environment into every command so each agent invocation is self-contained.
const ENV = `export PATH="/opt/homebrew/opt/php@8.4/bin:$PATH"; export WS="${WS}"; export LIB="${LIB}";`

const METRICS = {
  type: 'object', additionalProperties: true,
  properties: { apiOperations: { type: 'integer' }, responseSchemas: { type: 'integer' },
    requestBodies: { type: 'integer' }, completenessPercent: { type: 'number' } },
  required: ['apiOperations', 'completenessPercent'],
}
const measureSchema = { type: 'object', additionalProperties: false,
  properties: { metrics: METRICS }, required: ['metrics'] }

async function measure(label, phase) {
  return agent(
    `Measure app "${APP}". Run each command in a single bash invocation, prefixed with this env setup:
  ${ENV}
Commands:
  ${ENV} ${BIN}/survey-generate ${APP}
  ${ENV} php ${LIB}/tools/survey/metrics.php ${WS}/apps/${APP} --prefix=${PREFIX}
The second command prints a JSON metrics object. Return {metrics: <that JSON object verbatim>}.`,
    { label, phase, schema: measureSchema })
}

phase('Baseline')
await agent(`Reset app "${APP}" to its pinned baseline. Run (single bash invocation):
  ${ENV} ${BIN}/survey-reset ${APP}
Report the printed line.`,
  { label: 'reset', phase: 'Baseline' })
const baseline = await measure('baseline', 'Baseline')

phase('Harvest')
const harvestSchema = { type: 'object', additionalProperties: false,
  properties: { harvested: { type: 'integer' }, byFile: { type: 'object', additionalProperties: { type: 'integer' } } },
  required: ['harvested'] }
const harvest = await agent(
  `Deterministic doc-harvest for "${APP}". Run (single bash invocation):
  ${ENV} php ${BIN}/survey-harvest-docs ${APP}
It transcribes Summary/Description/Tag (+ deduped QueryParam) from the published spec onto controller methods and prints JSON {harvested, byFile}. Return that JSON.`,
  { label: 'harvest', phase: 'Harvest', schema: harvestSchema })
const afterHarvest = await measure('after-harvest', 'Harvest')

phase('Plan')
const plan = await workflow({ scriptPath: '.claude/skills/survey/fullspec-analysis.js' },
  { repoPath: REPO, apiPrefix: PREFIX, phpBin: '/opt/homebrew/opt/php@8.4/bin/php' })

phase('Apply')
const applySchema = { type: 'object', additionalProperties: false,
  properties: { domain: { type: 'string' }, applied: { type: 'array', items: { type: 'string' } },
    crash: { type: 'boolean' }, gaps: { type: 'array', items: { type: 'object', additionalProperties: true } } },
  required: ['domain', 'applied', 'crash'] }

const domains = plan.executionOrder ?? []
const applied = []
for (const domain of domains) {            // SERIAL — never parallel writers on one app tree
  const result = await agent(
    `Apply ONLY the non-harvested authoring attributes for domain "${domain}" of app "${APP}", per the plan below. Do NOT add Summary/Description/Tag/QueryParam (the deterministic harvest already placed those) — add response/request SHAPE, error, and security attributes only.

Environment — prefix EVERY shell command you run with this (fresh shells don't inherit env):
  ${ENV}

Plan (per-domain attributesToAdd + shared-resource notes):
${JSON.stringify(plan.planMarkdown ?? plan)}

Rules:
- Edit the app's code only (${WS}/apps/${APP}/repo). NEVER modify the library (${LIB}).
- Use \`${BIN}/survey-attr-sigs <Attr...>\` for exact constructor signatures.
- After editing, run \`${BIN}/survey-generate ${APP}\` — expect gen_exit=0, empty stderr. If your edit crashed it, fix your edit; if it's a library bug, record a gap and move on (do not patch the library).
- Run \`${BIN}/survey-completeness ${APP}\` to confirm this domain's ops drop out of INCOMPLETE.
Return {domain, applied:[files], crash, gaps:[{title,kind,evidence}]}.`,
    { label: `apply:${domain}`, phase: 'Apply', schema: applySchema })
  applied.push(result)
}

phase('Measure')
const afterAgent = await measure('after-agent', 'Measure')
const capture = await agent(
  `Finalize "${APP}". Run (single bash invocation, prefixed with the env):
  ${ENV} ${BIN}/survey-capture-patch ${APP}
to save annotation.patch; then verify the library is clean:
  ${ENV} git -C ${LIB} status --porcelain
(must print nothing). Return {patch, libraryClean:boolean, stat}.`,
  { label: 'capture', phase: 'Measure',
    schema: { type: 'object', additionalProperties: false,
      properties: { patch: { type: 'string' }, libraryClean: { type: 'boolean' }, stat: { type: 'string' } },
      required: ['patch', 'libraryClean'] } })

phase('Emit')
const summary = {
  app: APP, apiPrefix: PREFIX,
  baseline: baseline.metrics, afterHarvest: afterHarvest.metrics, afterAgent: afterAgent.metrics,
  attributesApplied: {
    harvested: { total: harvest.harvested, byFile: harvest.byFile ?? {} },
    authored: applied.flatMap(a => a.applied),
  },
  domains: applied,
  gapsEncountered: applied.flatMap(a => a.gaps ?? []),
  patch: capture.patch, libraryClean: capture.libraryClean,
}
await agent(
  `Write the lift artifacts for "${APP}" to ${WS}/apps/${APP}/. Prefix shell commands with:
  ${ENV}
Write the object below as ${WS}/apps/${APP}/lift.json, adding a manifest computed via:
  pinnedSha    = git -C ${WS}/apps/${APP}/repo rev-parse HEAD
  libraryCommit= git -C ${LIB} rev-parse HEAD
  apiPrefix    = ${PREFIX}
  generatedAt  = date -u +%FT%TZ
Also write a short human-readable ${WS}/apps/${APP}/lift-report.md (baseline→after-harvest→after-agent completenessPercent + harvested vs authored attribute counts + the gap list). Object:
${JSON.stringify(summary)}
Return the final lift.json path.`,
  { label: 'emit', phase: 'Emit' })

return summary
