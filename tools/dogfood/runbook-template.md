# Runbook — {{APP}}

- **Repo:** {{REPO}}
- **Ref:** {{REF}}
- **Pinned SHA:** {{SHA}}
- **PHP / Laravel:** _fill in from composer.json / `php artisan --version`_
- **Published spec:** _path under this app dir, or "no machine-readable spec"_

## Setup log (time-boxed)

_Commands actually run, in order. Note anything that deviated from the app's
documented install._

- [ ] `composer install`
- [ ] `.env` prepared; `php artisan key:generate`
- [ ] DB pointed at sqlite / in-memory
- [ ] composer path repo for this library added; `composer require radiergummi/laravel-openapi:@dev`
- [ ] `php artisan vendor:publish --tag=openapi-config`
- [ ] `php artisan list openapi` (CLI surface confirmed)

**Boot outcome:** booted | blocked-setup | blocked-compat

## Generate / lint outcomes

- `openapi:generate` exit: _N_
- `openapi:lint` exit: _N_
- routes introspected: _N_
- crashes / isolations: _summary + reproducer pointers_

## Coverage

- Cov%: _from the compare.php report_
- biggest gaps: _one or two lines_

## Findings filed

_One line per finding: a GitHub issue filed against this library at the instant
of discovery._

- L · <title> — #<issue>

## Blockers / notes

_Anything the next run needs to know._
