export const meta = {
  name: 'fullspec-analysis',
  description: 'Read-only per-domain gap analysis + annotation planning for driving any laravel-openapi consumer app to a complete spec. Args: {repoPath, apiPrefix?}. No edits, no issues filed.',
  whenToUse: 'First (discovery) phase of the full-spec attribute proof on a new app. Pass the app repo path; emits per-domain annotation plans + a deduped gap inventory + execution order.',
  phases: [
    { title: 'Discover', detail: 'route:list → controller domains under the API prefix' },
    { title: 'Analyze', detail: 'one agent per domain → annotation plan + gaps' },
    { title: 'Synthesize', detail: 'dedup/rank gaps, consolidate plan' },
  ],
}

// ---- args (parameterised; defaults make it runnable with just {repoPath}) ----
const REPO = args?.repoPath
if (!REPO) throw new Error('fullspec-analysis requires args.repoPath (the consumer app repo root)')
const PREFIX = args?.apiPrefix ?? '/api'
const VENDOR_LIB = args?.libVendorPath ?? `${REPO}/vendor/radiergummi/laravel-openapi/src`
const PHP = args?.phpBin ?? 'php' // override with an 8.4 binary path if the host default is incompatible

const DISCOVER_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    domains: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      controller: { type: 'string', description: 'short controller class name' },
      controllerFqcn: { type: 'string' },
      resourceGuess: { type: ['string', 'null'], description: 'paired *Resource class if discoverable' },
      opCount: { type: 'integer' },
    }, required: ['controller', 'opCount'] } },
  },
  required: ['domains'],
}

phase('Discover')
const discovery = await agent(
  `Discover the API controller domains of a Laravel app for an OpenAPI annotation pass. READ-ONLY.

Repo: ${REPO}
API prefix: ${PREFIX}
PHP: ${PHP} (if 'php' fails on a version constraint, try /opt/homebrew/opt/php@8.4/bin/php)

Do:
1. Run \`<php> artisan route:list --json\` inside ${REPO} (2>/dev/null to drop deprecation noise).
2. Keep only routes whose uri starts with "${PREFIX.replace(/^\//, '')}" (or "${PREFIX}").
3. Group by controller class. For each controller: short name, FQCN, op count, and a best-guess paired Resource class (open the controller, find the *Resource it returns; null if none).
Return ONLY the schema. Skip closures and framework routes.`,
  { label: 'discover', phase: 'Discover', schema: DISCOVER_SCHEMA }
)
const DOMAINS = (discovery.domains ?? []).filter(d => d.opCount > 0)
log(`Discovered ${DOMAINS.length} controller domains under ${PREFIX}`)

const ANALYSIS_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    domain: { type: 'string' },
    resourceClass: { type: ['string', 'null'] },
    toArrayReadable: { type: 'boolean' },
    ops: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      method: { type: 'string' }, uri: { type: 'string' }, action: { type: 'string' },
      kind: { type: 'string', enum: ['list', 'show', 'create', 'update', 'delete', 'action', 'other'] },
    }, required: ['method', 'uri', 'action', 'kind'] } },
    responseFields: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      name: { type: 'string' }, type: { type: 'string' }, nullable: { type: 'boolean' }, note: { type: 'string' },
    }, required: ['name', 'type'] } },
    attributesToAdd: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      target: { type: 'string' }, attribute: { type: 'string' },
      purpose: { type: 'string', enum: ['response', 'request', 'query-param', 'path-param', 'error', 'security', 'docs', 'other'] },
    }, required: ['target', 'attribute', 'purpose'] } },
    gaps: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      summary: { type: 'string' },
      kind: { type: 'string', enum: ['missing-attribute', 'awkward-attribute', 'library-bug', 'open-question'] },
      evidence: { type: 'string' },
    }, required: ['summary', 'kind', 'evidence'] } },
    notes: { type: 'string' },
  },
  required: ['domain', 'ops', 'attributesToAdd', 'gaps'],
}

function analysisPrompt(d) {
  return `You are planning OpenAPI authoring-attribute annotations for ONE controller domain. READ-ONLY: do NOT edit/create/delete any file, and do NOT run git or gh.

Repo: ${REPO}
Attribute definitions (the package under test): ${VENDOR_LIB}
Domain: ${d.controller} (FQCN ${d.controllerFqcn ?? '?'}; paired Resource: ${d.resourceGuess ?? 'none'}; ~${d.opCount} ops)

A "complete" op has: request body schema where it accepts input; a SUBSTANTIVE 2xx response (the response/its {data:$ref} resolves to >=1 real property — empty envelope does NOT count); declared path+query params; a security requirement (Sanctum auto-derives; override only with #[Security]/#[PublicEndpoint]); and is openapi:lint-clean (summary/description/tag/error responses). The package reads signatures + #[attributes]; it does NOT read Resource toArray() bodies or Action-class validation — those shapes must come from attributes.

Steps (read-only):
1. Find the controller; list each action's HTTP method, URI, method name, kind.
2. If a Resource is paired, read it: extract toArray() keys + infer each field's type; record whether toArray() is a single return-[literal] (toArrayReadable).
3. For create/update, find the validation source (often an Action/service class, not a FormRequest) and extract request fields + rules.
4. Identify query params (filter/paginate/sort) and path params not covered by route-model binding.
5. Identify error responses (abort/exceptions/@throws) to document.
6. Read EXACT attribute constructor signatures you will use from ${VENDOR_LIB}: Plugins/ApiResources/Attributes/ResourceField.php and Attributes/{RequestBody,RequestField,QueryParam,PathParam,Response,ExceptionResponse,ResponseField,Summary,Description,Tag}.php.
7. Produce attributesToAdd: exact attribute calls + the file/class/method each goes on, to make this domain complete + lint-clean. Prefer convention where it already works; only add attributes for what signatures can't express.
8. Record gaps: anything needed that NO attribute can express, or an awkward/insufficient attribute, or a likely library bug, or an open question — with file:line evidence.

Return ONLY the structured object, with real field names/types (not placeholders).`
}

phase('Analyze')
const analyses = (await parallel(
  DOMAINS.map(d => () => agent(analysisPrompt(d), { label: `analyze:${d.controller}`, phase: 'Analyze', schema: ANALYSIS_SCHEMA }))
)).filter(Boolean)

const stats = {
  domains: analyses.length,
  totalOpsPlanned: analyses.reduce((a, x) => a + (x.ops?.length || 0), 0),
  resourcesReadableToArray: analyses.filter(x => x.resourceClass && x.toArrayReadable).length,
  resourcesWithResource: analyses.filter(x => x.resourceClass).length,
  totalResponseFields: analyses.reduce((a, x) => a + (x.responseFields?.length || 0), 0),
  totalAttributesToAdd: analyses.reduce((a, x) => a + (x.attributesToAdd?.length || 0), 0),
  gapsByKind: analyses.flatMap(x => x.gaps || []).reduce((m, g) => { m[g.kind] = (m[g.kind] || 0) + 1; return m }, {}),
  totalGaps: analyses.reduce((a, x) => a + (x.gaps?.length || 0), 0),
}
log(`Analyzed ${stats.domains} domains · ${stats.totalOpsPlanned} ops · ${stats.totalAttributesToAdd} attributes planned · ${stats.totalGaps} raw gaps`)

const SYNTH_SCHEMA = {
  type: 'object', additionalProperties: false,
  properties: {
    uniqueGaps: { type: 'array', items: { type: 'object', additionalProperties: false, properties: {
      title: { type: 'string' },
      kind: { type: 'string', enum: ['missing-attribute', 'awkward-attribute', 'library-bug', 'open-question'] },
      affectedDomains: { type: 'array', items: { type: 'string' } },
      severity: { type: 'string', enum: ['blocks-completeness', 'workaround-needed', 'nice-to-have'] },
      detail: { type: 'string' },
    }, required: ['title', 'kind', 'affectedDomains', 'severity', 'detail'] } },
    executionOrder: { type: 'array', items: { type: 'string' } },
    sharedResourceNote: { type: 'string' },
    planMarkdown: { type: 'string' },
  },
  required: ['uniqueGaps', 'executionOrder', 'planMarkdown'],
}

phase('Synthesize')
const synth = await agent(
  `Consolidate ${analyses.length} per-domain annotation analyses into one plan + a deduped, ranked gap inventory.

Analyses (JSON):
${JSON.stringify(analyses)}

Do:
1. Dedup gaps across domains (many share the same gap, e.g., "Action-class validation not auto-read → every write op needs hand-authored #[RequestField]s"). Collapse into uniqueGaps with affectedDomains, a proposed issue title, kind, and severity (blocks-completeness = no attribute expresses it; workaround-needed = awkward but escape-hatch exists; nice-to-have).
2. Note shared Resources (reused across controllers) so the apply phase annotates each once.
3. Recommend executionOrder — lead with ONE exemplar domain exercising the most attribute types (response + request + query-param + error), then largest-first.
4. Write planMarkdown: consolidated plan grouped by Resource then controller, listing concrete attributes to add — ready to paste into a runbook.

Return ONLY the structured object.`,
  { label: 'synthesize', phase: 'Synthesize', schema: SYNTH_SCHEMA }
)

return {
  app: REPO, apiPrefix: PREFIX, stats,
  uniqueGaps: synth.uniqueGaps,
  executionOrder: synth.executionOrder,
  sharedResourceNote: synth.sharedResourceNote,
  planMarkdown: synth.planMarkdown,
  perDomain: analyses.map(a => ({ domain: a.domain, resource: a.resourceClass, ops: a.ops?.length || 0, attrs: a.attributesToAdd?.length || 0, gaps: a.gaps?.length || 0, toArrayReadable: a.toArrayReadable })),
  // Full per-domain analyses, keyed by domain — lets a consumer (lift.js) hand each apply agent
  // ONLY its own domain's pre-computed attribute list instead of re-deriving from the whole plan.
  domainAnalyses: Object.fromEntries(analyses.map(a => [a.domain, a])),
}
