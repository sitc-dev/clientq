#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'HELP'
Usage: bash scripts/deploy-updates.sh --start-date YYYY-MM-DD --end-date YYYY-MM-DD --host USER@HOST --path /remote/app [--apply]

Select existing, nonignored Git worktree files by their current modification
times for an inclusive calendar-date range in America/New_York. The default is an
rsync dry run; --apply uploads the selected files over SSH.
The existing --date YYYY-MM-DD option is supported for a single day instead
of --start-date/--end-date.

Excludes tests/, README.md, update-prompt.txt, .env files, runtime uploads/logs,
browser/test artifacts, vendor/, scripts/, database dumps and backup files.
Preserves remote files not in the selection; never uses --delete or runs SQL.

Back up production and apply required tenant migrations before --apply.
Files changed again after the range will not match the earlier selection.

Actual upload attempts append entries to logs/clientq-YYYY-MM-DD.log locally
and <remote app>/logs/clientq-YYYY-MM-DD.log remotely, using the current UTC
logging date (not the selected upload dates) and the application log format.
Each entry includes JSON details with a run ID,
date range, outcome, rsync exit code, and uploaded/updated files. Unchanged
files are not listed as uploaded. Dry runs and empty selections write no logs.
Remote logging is checked before upload. If the server cannot be reached, a
remote failure entry cannot be guaranteed; the local failure is still recorded.
Logging failures return a nonzero status even when the upload itself succeeds.

Examples:
  bash scripts/deploy-updates.sh --start-date 2026-11-18 --end-date 2026-11-21 --host deploy@example.com --path /var/www/clientq
  bash scripts/deploy-updates.sh --start-date 2026-11-18 --end-date 2026-11-21 --host deploy@example.com --path /var/www/clientq --apply

Requires Bash, Git, Python 3.9+ with timezone data, rsync, and SSH locally,
and rsync, a POSIX shell, mkdir, cat, and SSH access on the remote server.
SSH aliases are supported. Both the deployment and application users need
write access to the shared daily log files, including newly created files.
HELP
}

fail() {
    printf 'Error: %s\n' "$*" >&2
    exit 1
}

deploy_date=
start_date=
end_date=
remote_host=
remote_path=
apply=false

while (( $# > 0 )); do
    case "$1" in
        --date|--start-date|--end-date|--host|--path)
            (( $# >= 2 )) && [[ -n "$2" && "$2" != --* ]] || fail "$1 requires a value."
            case "$1" in
                --date) deploy_date=$2 ;;
                --start-date) start_date=$2 ;;
                --end-date) end_date=$2 ;;
                --host) remote_host=$2 ;;
                --path) remote_path=$2 ;;
            esac
            shift 2
            ;;
        --apply) apply=true; shift ;;
        --help|-h) usage; exit 0 ;;
        *) fail "Unknown argument: $1. Use --help for usage." ;;
    esac
done

if [[ -n "$deploy_date" ]]; then
    [[ -z "$start_date" && -z "$end_date" ]] || fail "Use --date or --start-date/--end-date, not both."
    start_date=$deploy_date
    end_date=$deploy_date
fi
[[ -n "$start_date" && -n "$end_date" && -n "$remote_host" && -n "$remote_path" ]] \
    || fail "--start-date, --end-date, --host, and --path are required. Use --help for usage."
[[ "$remote_host" =~ ^[a-zA-Z0-9_][a-zA-Z0-9_.@-]*$ ]] \
    || fail "--host must be an SSH hostname or USER@HOST; use an SSH alias for custom ports."
while [[ "$remote_path" == */ ]]; do remote_path=${remote_path%/}; done
[[ "$remote_path" == /* && "$remote_path" != / && "$remote_path" != *$'\n'* && "$remote_path" != *$'\r'* ]] \
    || fail "--path must be an absolute remote application directory, not /."
[[ "/$remote_path/" != *"/../"* && "/$remote_path/" != *"/./"* ]] \
    || fail "--path must not contain . or .. components."

for dependency in git python3 rsync ssh; do
    command -v "$dependency" >/dev/null 2>&1 || fail "Required command not found: $dependency"
done

script_directory=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
repository=$(git -C "$script_directory" rev-parse --show-toplevel)
candidates=$(mktemp)
manifest=
metadata=
transfer_output=
record=
uploaded=
trap 'rm -f -- "$candidates" "$manifest" "$metadata" "$transfer_output" "$record" "$uploaded"' EXIT
manifest=$(mktemp)
metadata=$(mktemp)
transfer_output=$(mktemp)
record=$(mktemp)
uploaded=$(mktemp)
git -C "$repository" ls-files --cached --others --exclude-standard -z > "$candidates"

python3 - "$start_date" "$end_date" "$repository" "$candidates" "$manifest" "$metadata" "$remote_host" "$remote_path" <<'PY'
import datetime
import json
import os
from pathlib import Path
import re
import stat
import sys
import uuid
from zoneinfo import ZoneInfo

first_text, last_text, root_text, candidates_file, manifest_file, metadata_file, host, destination = sys.argv[1:]
days = []
for label, value in [("--start-date", first_text), ("--end-date", last_text)]:
    if not re.fullmatch(r"[0-9]{4}-[0-9]{2}-[0-9]{2}", value):
        sys.exit(f"Error: {label} must be a valid YYYY-MM-DD date.")
    try:
        days.append(datetime.date.fromisoformat(value))
    except ValueError:
        sys.exit(f"Error: {label} must be a valid YYYY-MM-DD date.")
first, last = days
if last < first:
    sys.exit("Error: --end-date must be on or after --start-date.")
try:
    following = last + datetime.timedelta(days=1)
except OverflowError:
    sys.exit("Error: --end-date is too late to calculate the inclusive range.")
zone = ZoneInfo("America/New_York")
start = datetime.datetime.combine(first, datetime.time.min, zone).timestamp()
end = datetime.datetime.combine(following, datetime.time.min, zone).timestamp()
root = Path(root_text).resolve()
excluded_directories = {
    "tests", "uploads", "logs", "vendor", "scripts", ".playwright-mcp", ".phpunit.cache"
}
selected = []
for raw_name in sorted(set(Path(candidates_file).read_bytes().split(b"\0"))):
    if not raw_name:
        continue
    name = os.fsdecode(raw_name)
    relative = Path(name)
    if relative.is_absolute() or ".." in relative.parts:
        sys.exit(f"Error: unsafe worktree path: {name!r}")
    if relative.parts[0] in excluded_directories:
        continue
    if relative.name in {"README.md", "update-prompt.txt", ".env"} or relative.name.startswith(".env."):
        continue
    if relative.name.endswith((".bak", ".dump", ".sql.gz", ".sql.zip")):
        continue
    if relative.suffix == ".sql" and relative.parts[0] != "database":
        continue
    source = root / relative
    try:
        metadata = source.lstat()
    except FileNotFoundError:
        continue
    if not start <= metadata.st_mtime < end:
        continue
    if stat.S_ISLNK(metadata.st_mode) or source.resolve() != source:
        sys.exit(f"Error: refusing to deploy a symlinked path: {name!r}")
    if stat.S_ISREG(metadata.st_mode):
        selected.append(raw_name)
Path(manifest_file).write_bytes(b"".join(name + b"\0" for name in selected))
Path(metadata_file).write_text(json.dumps({
    "run_id": str(uuid.uuid4()), "start_date": first_text, "end_date": last_text,
    "selection_timezone": "America/New_York",
    "host": host, "destination": destination, "selected_count": len(selected),
}), encoding="utf-8")
print(f"Selected {len(selected)} file(s) modified from {first_text} through {last_text}, inclusive (America/New_York).")
PY

if [[ ! -s "$manifest" ]]; then
    printf 'Nothing to deploy; no remote connection made.\n'
    exit 0
fi

while IFS= read -r -d '' file; do
    printf '  %q\n' "$file"
done < "$manifest"

printf '\nDestination: %s:%s/\n' "$remote_host" "${remote_path%/}"
printf 'SQL files are uploaded only; no databases, tenants, or records are copied or migrated.\n'
printf 'Apply required remote schema migrations separately before deploying dependent PHP.\n'
options=(
    --archive --no-owner --no-group --compress --checksum --relative
    --protect-args --from0 "--files-from=$manifest" --itemize-changes
)
if [[ "$apply" == false ]]; then
    printf 'DRY RUN: no remote files will be changed. Add --apply after reviewing the selection.\n\n'
    options+=(--dry-run)
    rsync "${options[@]}" -- "$repository/" "$remote_host:${remote_path%/}/"
    printf '\nDry run complete; nothing was uploaded and no deployment logs were written.\n'
    exit 0
fi

local_log=
remote_log=

make_record() {
    log_day=$(python3 - "$metadata" "$manifest" "$transfer_output" "$record" "$uploaded" "$1" "${2:-}" "${3:-}" <<'PY'
import datetime
import json
import os
from pathlib import Path
import re
import sys

metadata_file, manifest_file, output_file, record_file, uploaded_file, status, code, error = sys.argv[1:]
entry = json.loads(Path(metadata_file).read_text(encoding="utf-8"))
files = set(Path(manifest_file).read_bytes().split(b"\0")) - {b""}
changed = set()
if status == "SUCCESS":
    for line in Path(output_file).read_bytes().splitlines():
        if not line.startswith(b"CLIENTQ_TRANSFER:"):
            continue
        item, separator, remainder = line[len(b"CLIENTQ_TRANSFER:"):].partition(b":")
        _, name_separator, name = remainder.partition(b":")
        if len(item) != 11 or item[1:2] != b"f" or not separator or not name_separator:
            continue
        name = re.sub(rb"\\#([0-7]{3})", lambda match: bytes([int(match[1], 8)]), name)
        if name not in files and name.startswith(b"./"):
            name = name[2:]
        if name not in files:
            sys.exit("Error: rsync reported an uploaded file outside the selection.")
        changed.add(name)
names = sorted(changed)
Path(uploaded_file).write_bytes(b"".join(name + b"\0" for name in names))
now = datetime.datetime.now(datetime.timezone.utc)
entry.update({
    "timestamp": now.isoformat(),
    "status": status, "rsync_exit_code": int(code) if code else None,
    "uploaded_files": [os.fsdecode(name) for name in names],
})
if error:
    entry["error"] = error
level = "ERROR" if status == "FAILED" or error else "INFO"
line = (
    f"[{now:%Y-%m-%d %H:%M:%S}] [{level}] [scripts/deploy-updates.sh] "
    "ClientQ deployment: " + json.dumps(entry, ensure_ascii=True) + "\n"
)
Path(record_file).write_text(line, encoding="utf-8")
print(now.date().isoformat())
PY
    )
    local_log="$repository/logs/clientq-$log_day.log"
    remote_log="$remote_path/logs/clientq-$log_day.log"
}

append_local_log() {
    (umask 007; mkdir -p -- "$repository/logs" && cat "$record" >> "$local_log")
}

append_remote_log() {
    local remote_log_command
    remote_log_command=$(python3 - "$remote_path" "$log_day" <<'PY'
import shlex
import sys
command = 'umask 007; mkdir -p "$1/logs" && cat >> "$1/logs/clientq-$2.log"'
print("sh -c " + shlex.quote(command) + " sh "
      + shlex.quote(sys.argv[1]) + " " + shlex.quote(sys.argv[2]))
PY
    ) || return "$?"
    ssh -T -o ConnectTimeout=15 -- "$remote_host" "$remote_log_command" < "$record"
}

make_record STARTED
append_local_log || fail "Cannot write local deployment log: $local_log. Nothing was uploaded."
if append_remote_log; then
    :
else
    log_exit=$?
    make_record FAILED "" "Remote logging could not be initialized (SSH exit $log_exit); upload was not attempted."
    append_remote_log || printf 'Warning: the remote failure log could not be written.\n' >&2
    append_local_log || printf 'Error: the local failure log could not be written.\n' >&2
    printf 'Error: cannot initialize remote deployment log: %s. Nothing was uploaded.\n' "$remote_log" >&2
    exit "$log_exit"
fi

printf 'Uploading selected files.\n\n'
options+=(--out-format=CLIENTQ_TRANSFER:%i:%b:%n)
rsync_exit=0
rsync "${options[@]}" -- "$repository/" "$remote_host:${remote_path%/}/" > "$transfer_output" || rsync_exit=$?
if (( rsync_exit == 0 )); then
    make_record SUCCESS "$rsync_exit"
else
    cat "$transfer_output"
    make_record FAILED "$rsync_exit" "rsync failed; some files may have been transferred before the failure."
fi

logging_failed=false
if append_remote_log; then
    :
else
    log_exit=$?
    logging_failed=true
    make_record "$([[ "$rsync_exit" == 0 ]] && printf SUCCESS || printf FAILED)" "$rsync_exit" \
        "Remote completion log could not be written (SSH exit $log_exit)."
    printf 'Error: the upload outcome could not be logged remotely: %s\n' "$remote_log" >&2
fi
if ! append_local_log; then
    logging_failed=true
    printf 'Error: the upload outcome could not be logged locally: %s\n' "$local_log" >&2
fi

if (( rsync_exit != 0 )); then
    printf '\nUpload failed (rsync exit %s); check the deployment logs.\n' "$rsync_exit" >&2
    exit "$rsync_exit"
fi
printf '\nUpload complete.\nUploaded files:\n'
if [[ -s "$uploaded" ]]; then
    while IFS= read -r -d '' file; do
        printf '  %q\n' "$file"
    done < "$uploaded"
else
    printf '  (none; selected files were already up to date)\n'
fi
if [[ "$logging_failed" == true ]]; then
    printf 'Upload succeeded, but deployment logging failed.\n' >&2
    exit 1
fi
printf 'Logs: %s and %s:%s\n' "$local_log" "$remote_host" "$remote_log"
printf 'PHP cache reloads or Docker rebuilds, if needed, are separate steps.\n'
