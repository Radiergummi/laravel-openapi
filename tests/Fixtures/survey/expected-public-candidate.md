<!--
PUBLICATION GATE: this is a generated CANDIDATE for maintainer review, not the published
report. App names are rendered as-is from the corpus. Before publishing, obtain maintainer
permission for each named app or anonymize it to its category. Tracked in issue #159. No
head-to-head third-party numbers.
-->

# Field report candidate: laravel-openapi against real-world APIs

Black-box run against 5 open-source Laravel applications — 50 total routes across 67 API operations.

## Corpus

| Application | API ops | Response schemas | Request bodies | Component schemas | Response shape |
|---|--:|--:|--:|--:|---|
| Alpha | 30 | 27 | 12 | 18 | full-schema |
| Beta | 20 | 3 | 6 | 5 | envelope, empty body |
| Gamma | 12 | 0 | 1 | 0 | no response body |
| Delta | 0 | 0 | 0 | 0 | blocked (compat) |
| Orphan | 5 | 4 | 1 | 2 | full-schema |

## Robustness

- Apps that generated and linted cleanly: **1 / 5**
- Operations with a substantive 2xx response schema: **34 / 67**
- Operations documenting any 2xx outcome: **56 / 67**
- Operations with a request body: **20**

## Response spectrum

Each app is classified from its measured `responseSchemas / apiOperations` ratio:

| Application | Classification | responseSchemas | documentedResponses | apiOperations |
|---|---|--:|--:|--:|
| Alpha | full-schema | 27 | 30 | 30 |
| Beta | envelope, empty body | 3 | 19 | 20 |
| Gamma | no response body | 0 | 2 | 12 |
| Delta | blocked (compat) | 0 | 0 | 0 |
| Orphan | full-schema | 4 | 5 | 5 |

## Provenance

- Library commit: `abc1234def5678abc1234def5678abc1234def56`
- Generated at: `2026-06-18T09:30:00+00:00`

| App | Pinned SHA | Actual SHA | Installed library | PHP |
|---|---|---|---|---|
| Alpha | `1111111111111111111111111111111111111111` | `1111111111111111111111111111111111111111` | `abc1234def5678abc1234def5678abc1234def56` | 8.4 |
| Beta | `2222222222222222222222222222222222222222` | `2222222222222222222222222222222222222222` | `abc1234def5678abc1234def5678abc1234def56` | 8.4 |
| Gamma | `3333333333333333333333333333333333333333` | `3333333333333333333333333333333333333333` | `abc1234def5678abc1234def5678abc1234def56` | 8.4 |
| Delta | `4444444444444444444444444444444444444444` | `—` | `—` | 8.4 |
| Phantom | `5555555555555555555555555555555555555555` | `5555555555555555555555555555555555555555` | `abc1234def5678abc1234def5678abc1234def56` | 8.4 |
