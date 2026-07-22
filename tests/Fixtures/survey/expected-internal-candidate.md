# Internal synthesis candidate — laravel-openapi survey

Maintainer-only working substrate for the field report. Coverage figures below are each
app's comparison against **its own published spec**, not any third-party benchmark.

## Coverage vs each app's own published spec

| Application | Published ops | Our ops | In both | Coverage % |
|---|--:|--:|--:|--:|
| Alpha | 32 | 30 | 28 | 87.5 |

## Recurring lint findings (corpus rollup)

| Rule | Total findings |
|---|--:|
| `response.no-error` | 4 |
| `operation.summary-missing` | 2 |
| `response.success-empty-body` | 2 |
| `operation.return-type-missing` | 1 |

## Attribute-surface gap inventory (from Layer B1)

| Gap | Apps affected |
|---|---|
| No attribute for polymorphic union responses | Alpha, Gamma |
| Cursor pagination meta block not derivable | Alpha |
| Streamed CSV download response not describable | Gamma |

## Annotation lift (per app, from Layer B1)

| Application | Basis | Baseline % | After harvest % | After agent % | Request bodies (base → final) | Harvested attrs | Authored attrs |
|---|---|--:|--:|--:|--:|--:|--:|
| Alpha | classified | 90 | 92 | 100 | 12 → 21 | 14 | 3 |
| Gamma | — | 8.3 | 8.3 | 75 | — → — | 0 | 1 |

Harvested attributes are transcribed from each app's own published spec; authored
attributes are added by the annotation pass. The two are tracked separately.
The percentage is response-axis-only, read under the stated basis; request bodies are
reported beside it because documenting them is a large part of what annotation does.

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
