#!/usr/bin/env bash
# corpus.sh [--only <name>] — run the pinned corpus and aggregate metrics.
#
# For each app in corpus.json: clone (setup.sh) -> bootstrap -> run.sh -> metrics.php.
# Aggregates per-app metrics into $WS/results.json and emits $WS/manifest.json.
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
names=$(php -r '$c=json_decode(file_get_contents($argv[1]),true); foreach($c["apps"] as $a){echo $a["name"],"\n";}' "$corpus")

results="["
first=true

while IFS= read -r name; do
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

  echo "booted" > "$appdir/boot_outcome"
  ( cd "$repodir" && WS="$WS" LIB="$LIB" BOOT_OUTCOME_FILE="$appdir/boot_outcome" bash "$HARNESS/$boot" ) \
    || echo "  bootstrap returned non-zero (recorded)"

  WS="$WS" "$HARNESS/run.sh" "$name" || true

  pubArgs=()
  [[ "$published" != "-" ]] && pubArgs=("--published=$repodir/$published")
  metrics=$(php "$HARNESS/metrics.php" "$appdir" --prefix="$prefix" ${pubArgs[@]+"${pubArgs[@]}"})

  entry=$(php -r '
    $m=json_decode((string)$argv[2],true);
    echo json_encode(["name"=>$argv[1],"metrics"=>$m]);
  ' "$name" "$metrics")

  if [[ -n "$entry" ]]; then
    $first || results+=","
    results+="$entry"
    first=false
  fi
done <<< "$names"

results+="]"
echo "$results" | php -r 'echo json_encode(json_decode((string)stream_get_contents(STDIN),true), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);' > "$WS/results.json"

# Manifest covers the FULL corpus pin registry (not just --only): records each app's
# pinned SHA + its actual on-disk SHA (null if not cloned) + the library commit.
php -r '
  $c=json_decode(file_get_contents($argv[1]),true);
  $ws=$argv[2]; $lib=$argv[3];
  $apps=[];
  foreach($c["apps"] as $a){
    $head=@trim(shell_exec("git -C ".escapeshellarg($ws."/apps/".$a["name"]."/repo")." rev-parse HEAD 2>/dev/null")?:"");
    $apps[]=["name"=>$a["name"],"pinnedSha"=>$a["sha"],"actualSha"=>$head?:null,"php"=>$a["php"]];
  }
  $libCommit=trim(shell_exec("git -C ".escapeshellarg($lib)." rev-parse HEAD")?:"");
  echo json_encode(["generatedAt"=>date("c"),"libraryCommit"=>$libCommit,"apps"=>$apps], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
' "$corpus" "$WS" "$LIB" > "$WS/manifest.json"

echo "wrote $WS/results.json and $WS/manifest.json"
