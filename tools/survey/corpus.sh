#!/usr/bin/env bash
# corpus.sh [--only <name>] — run the pinned corpus and aggregate metrics.
#
# For each app in corpus.json: clone (setup.sh) -> reset stale composer state -> bootstrap
# -> run.sh -> metrics.php. Merges per-app metrics into $WS/results.json (by app name, so
# --only backfills a single app without clobbering the rest) and emits $WS/manifest.json.
#
# Holds an exclusive lock on $WS for the duration: a second run against the same workspace
# fails fast rather than corrupting the shared aggregate.
#
# Requires WS (workspace) and LIB (library checkout). Use a PHP 8.4 binary.
set -uo pipefail
HARNESS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WS="${WS:?set WS to your external survey workspace dir}"
LIB="${LIB:-$(cd "$HARNESS/../.." && pwd)}"
export WS LIB

only=""
[[ "${1:-}" == "--only" ]] && only="${2:-}"

corpus="$HARNESS/corpus.json"

# --- Run lock (#231) ---------------------------------------------------------------------
# Two corpus runs sharing one $WS race on results.json/manifest.json and flip each app's
# vendor link mid-generation. mkdir is atomic, so it doubles as the lock; a stale lock whose
# owner has died is reclaimed, a live one aborts the run.
mkdir -p "$WS"
lockdir="$WS/.survey.lock"
if ! mkdir "$lockdir" 2>/dev/null; then
  holder="$(cat "$lockdir/pid" 2>/dev/null || true)"
  if [[ -n "$holder" ]] && kill -0 "$holder" 2>/dev/null; then
    echo "error: another corpus run (pid $holder) holds $lockdir — aborting to avoid corrupting \$WS" >&2
    exit 1
  fi
  echo "warn: reclaiming stale lock (owner pid ${holder:-unknown} is gone)" >&2
  rm -rf "$lockdir"
  mkdir "$lockdir" || { echo "error: cannot acquire $lockdir" >&2; exit 1; }
fi
echo "$$" > "$lockdir/pid"
fresh_entries="$WS/.run-entries.json"
trap 'rm -rf "$lockdir"; rm -f "$fresh_entries" "$WS/results.json.tmp"' EXIT
# -----------------------------------------------------------------------------------------

# Read names into an array and iterate with `for`: a `while … <<< "$names"` loop leaks the
# corpus list onto the loop body's stdin, where a bootstrap subprocess (artisan upgrades,
# etc.) can drain it and silently end the loop early (#221). The array is immune. (Filled with
# a `while read` rather than `mapfile`, which the macOS system bash 3.2 lacks.)
names=()
while IFS= read -r line; do
  names+=("$line")
done < <(php -r '$c=json_decode(file_get_contents($argv[1]),true); foreach($c["apps"] as $a){echo $a["name"],"\n";}' "$corpus")

run_entries="["
first=true
ran=0

for name in ${names[@]+"${names[@]}"}; do
  [[ -z "$name" ]] && continue
  [[ -n "$only" && "$name" != "$only" ]] && continue

  # Read this app's pin + bootstrap from the corpus.
  # php field is for manifest/provenance; the runner uses the PATH php (caller puts php@8.4 on PATH).
  # shellcheck disable=SC2034
  read -r repo ref sha prefix php boot published < <(php -r '
    $c=json_decode(file_get_contents($argv[1]),true);
    foreach($c["apps"] as $a){ if($a["name"]===$argv[2]){
      echo $a["repo"]," ",$a["ref"]," ",$a["sha"]," ",$a["apiPrefix"]," ",$a["php"]," ",$a["bootstrap"]," ",($a["publishedSpec"]??"-"),"\n"; }}
  ' "$corpus" "$name")

  appdir="$WS/apps/$name"
  repodir="$appdir/repo"

  echo "=== $name @ $ref ($sha) ==="

  if [[ ! -d "$repodir/.git" ]]; then
    WS="$WS" "$HARNESS/setup.sh" "$name" "$repo" "$ref"
  fi
  # Pin exactly (setup.sh shallow-clones a branch; enforce the SHA when present).
  git -C "$repodir" rev-parse HEAD | grep -q "^$sha" || echo "  warn: HEAD != pinned SHA ($sha)"

  # Reset composer state a previous run may have left dirty (#233): a lock still pinning an
  # old library sha (which makes the relink a silent no-op), a corrupted composer.json, or
  # stale compiled caches referencing the package. This runs BEFORE the bootstrap makes its
  # own per-app composer.json edits (platform.php, constraint bumps), so those are preserved.
  # (Every command here is no-op-safe if the clone above failed, so no .git guard is needed.)
  git -C "$repodir" checkout -- composer.json composer.lock 2>/dev/null || true
  rm -rf "$repodir/vendor/radiergummi" \
         "$repodir/bootstrap/cache/packages.php" \
         "$repodir/bootstrap/cache/services.php"

  echo "booted" > "$appdir/boot_outcome"
  # A non-zero bootstrap exit that never reached survey_blocked still means the app
  # didn't come up — record it as blocked-compat so the outcome isn't left "booted".
  # stdin is fed from /dev/null so a bootstrap subprocess can't consume the caller's stdin.
  ( cd "$repodir" && WS="$WS" LIB="$LIB" BOOT_OUTCOME_FILE="$appdir/boot_outcome" bash "$HARNESS/$boot" ) < /dev/null \
    || { echo "  bootstrap exited non-zero — recording blocked-compat"; echo "blocked-compat" > "$appdir/boot_outcome"; }

  WS="$WS" "$HARNESS/run.sh" "$name" < /dev/null || true

  pubArgs=()
  [[ "$published" != "-" ]] && pubArgs=("--published=$repodir/$published")
  metrics=$(php "$HARNESS/metrics.php" "$appdir" --prefix="$prefix" ${pubArgs[@]+"${pubArgs[@]}"})

  entry=$(php -r '
    $m=json_decode((string)$argv[2],true);
    echo json_encode(["name"=>$argv[1],"metrics"=>$m]);
  ' "$name" "$metrics")

  if [[ -n "$entry" ]]; then
    $first || run_entries+=","
    run_entries+="$entry"
    first=false
    ran=$((ran + 1))
  fi
done
run_entries+="]"

# Merge this run's entries into the existing aggregate by app name (#229) and validate the
# result parses before it replaces the live file (#231). aggregate.php always emits valid
# JSON; the read-back guards against a truncated write (disk full, killed mid-write).
printf '%s' "$run_entries" > "$fresh_entries"
php "$HARNESS/aggregate.php" "$corpus" "$WS/results.json" "$fresh_entries" > "$WS/results.json.tmp"
if ! php -r 'exit(is_array(json_decode((string)file_get_contents($argv[1]),true)) ? 0 : 1);' "$WS/results.json.tmp" 2>/dev/null; then
  echo "error: merged results.json failed to parse — leaving the previous results.json intact" >&2
  exit 1
fi
mv "$WS/results.json.tmp" "$WS/results.json"

# Coverage guard (#221): on a full run, warn loudly if fewer apps were measured than the
# corpus pins — the symptom of a silently dropped app. The names array (one entry per pinned
# app) is the corpus count, so no extra corpus read is needed.
if [[ -z "$only" && "$ran" -ne "${#names[@]}" ]]; then
  echo "warn: measured $ran of ${#names[@]} corpus apps this run — some were skipped or dropped" >&2
fi

# Manifest covers the FULL corpus pin registry (not just --only): records each app's pinned
# SHA, its actual on-disk SHA (null if not cloned), and the library commit actually installed
# into each app's vendor (read from the symlink target's git HEAD), so every results file is
# self-verifying against the intended libraryCommit (#233).
php -r '
  $c=json_decode(file_get_contents($argv[1]),true);
  $ws=$argv[2]; $lib=$argv[3];
  $libCommit=trim(shell_exec("git -C ".escapeshellarg($lib)." rev-parse HEAD")?:"");
  $apps=[];
  foreach($c["apps"] as $a){
    $repo=$ws."/apps/".$a["name"]."/repo";
    $head=@trim(shell_exec("git -C ".escapeshellarg($repo)." rev-parse HEAD 2>/dev/null")?:"");
    $vendorLib=$repo."/vendor/radiergummi/laravel-openapi";
    $installed=@trim(shell_exec("git -C ".escapeshellarg($vendorLib)." rev-parse HEAD 2>/dev/null")?:"") ?: null;
    if($installed!==null && $installed!==$libCommit){
      fwrite(STDERR, "warn: ".$a["name"]." has library ".$installed." installed, expected ".$libCommit." (stale link?)\n");
    }
    $apps[]=["name"=>$a["name"],"pinnedSha"=>$a["sha"],"actualSha"=>$head?:null,"installedLibrarySha"=>$installed,"php"=>$a["php"]];
  }
  echo json_encode(["generatedAt"=>date("c"),"libraryCommit"=>$libCommit,"apps"=>$apps], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
' "$corpus" "$WS" "$LIB" > "$WS/manifest.json"

echo "wrote $WS/results.json and $WS/manifest.json"
