#!/usr/bin/env bash
# Start the ai-worklog dashboard at http://localhost:8111 and open it in the browser.
# Server only — for ingest + serve + re-ingest every 5 min use bin/worklog instead.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT="${PORT:-8111}"

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

open_browser() {
    if command -v open >/dev/null; then open "http://localhost:$PORT"
    elif command -v xdg-open >/dev/null; then xdg-open "http://localhost:$PORT"
    fi
}

if curl -s -o /dev/null --max-time 1 "http://localhost:$PORT/api/projects"; then
    echo "Already serving at http://localhost:$PORT"
    open_browser
    exit 0
fi

echo "Serving http://localhost:$PORT — Ctrl-C to stop"
( sleep 0.7 && open_browser ) &
exec php -S "localhost:$PORT" -t "$ROOT/public" "$ROOT/serve.php"
