#!/usr/bin/env bash
# Package ai-worklog for sharing: every git-tracked file, none of your data.
# Your ai-worklog.db (sessions, clients, digests) is untracked and never included.
# Output: dist/ai-worklog-YYYY-MM-DD.zip, unzips into an ai-worklog/ folder.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT"

for cmd in git zip tar; do
    command -v "$cmd" >/dev/null || { echo "error: $cmd not found" >&2; exit 1; }
done

# Point out work that won't make it into the archive.
untracked=$(git status --porcelain | grep '^??' || true)
if [ -n "$untracked" ]; then
    echo "note: untracked files are NOT included:"
    echo "$untracked" | sed 's/^?? /  /'
fi

TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
STAGE="$TMP/ai-worklog"
mkdir -p "$STAGE" "$ROOT/dist"

# Copy the working-tree version of every tracked file (uncommitted edits included).
git ls-files -z | tar --null -T - -cf - | tar -xf - -C "$STAGE"

# Belt and braces: refuse to ship anything that looks like personal data.
leaks=$(find "$STAGE" -name '*.db*' -o -name '.env*' -o -name '*.sqlite*' -o -name 'presence.log*')
if [ -n "$leaks" ]; then
    echo "error: refusing to archive — data files found:" >&2
    echo "$leaks" >&2
    exit 1
fi

OUT="$ROOT/dist/ai-worklog-$(date +%Y-%m-%d).zip"
rm -f "$OUT"
(cd "$TMP" && zip -qr "$OUT" ai-worklog)

echo "created $(basename "$OUT") ($(du -h "$OUT" | cut -f1 | tr -d ' ')) in dist/"
echo "contains only git-tracked files — your ai-worklog.db stays home"
