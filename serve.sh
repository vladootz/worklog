#!/usr/bin/env bash
# Start the ai-worklog dashboard at http://localhost:8111.
# Server only — for ingest + serve + re-ingest every 5 min use bin/worklog instead.
# Herd users don't need this: the site serves at https://worklog.test (see README).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT="${PORT:-8111}"
USAGE="usage: [PORT=N] ./serve.sh"

print_help() {
    cat <<EOF
$USAGE

Serves the dashboard at http://localhost:8111 until you press Ctrl-C. It does
not ingest, except once when ai-worklog.db does not exist yet (without digests).
If a server already answers on the port, it says so and exits.

  PORT=N       serve on port N instead of 8111
  -h, --help   print this help, then exit

To ingest again every 5 minutes while the dashboard is open, use bin/worklog.
EOF
}

for arg in "$@"; do
    case "$arg" in -h | --help) print_help; exit 0 ;; esac
done
if [ $# -gt 0 ]; then
    echo "unknown option: $1" >&2
    echo "$USAGE" >&2
    exit 2
fi

if ! command -v php >/dev/null; then
    echo "error: php not found — install PHP 8.2+ (e.g. brew install php)" >&2
    exit 1
fi

# First run: build the database so the dashboard isn't empty.
# Digests are skipped here to keep it fast; bin/worklog or `php ingest.php` generates them.
if [ ! -f "$ROOT/ai-worklog.db" ]; then
    echo "No ai-worklog.db yet — running first ingest (without digests)..."
    php "$ROOT/ingest.php" --skip-digests
fi

if curl -s -o /dev/null --max-time 1 "http://localhost:$PORT/api/projects"; then
    echo "Already serving at http://localhost:$PORT"
    exit 0
fi

echo "Serving http://localhost:$PORT — Ctrl-C to stop"
exec php -S "localhost:$PORT" -t "$ROOT/public" "$ROOT/public/index.php"
