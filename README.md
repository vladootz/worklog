# ai-worklog

**Your AI coding assistants already know when you worked and what you shipped — this turns that into a time & deliverables dashboard.**

[Claude Code](https://claude.com/claude-code) and [opencode](https://opencode.ai) write detailed session data to disk as you work. ai-worklog ingests it — every message and tool-call timestamp, session titles, git commits made during sessions — and gives you an honest, explainable picture of where your hours went, per project and per client. No timers to start, no forms to fill in after the fact.

Zero dependencies by design: **PHP 8.2 + SQLite + vanilla JS/CSS/SVG.** No Composer, no npm, no CDN. Everything runs and stays on your machine.

![ai-worklog dashboard with demo data](docs/screenshot.png)

## Features

- **Automatic time tracking** — work blocks reconstructed from session event timestamps; nothing to remember to switch on
- **Honest overlap math** — parallel agent sessions count fully per project but are never double-counted in your wall-clock total; the ratio between the two is your **leverage factor**
- **Deliverables, not vibes** — git commits and diff stats captured per session window, summarized into 2–4 bullets per project per day by a local Ollama model
- **Projects & clients** — directories auto-become projects; merge, rename, assign clients, or ignore them from the ⚙ settings panel
- **Hand-rolled SVG charts** — stacked per-project bars by day/week/month, date presets, project filters
- **Billing export** — one JSON entry per project per day, with optional 15/30-min rounding
- **Deterministic and explainable** — every rule (gap threshold, dedup, rounding) is simple enough to defend on an invoice

## Requirements

- **PHP 8.2+** with `pdo_sqlite` (both ship with `brew install php`)
- **macOS or Linux** — anywhere Claude Code / opencode write their data
- At least one data source:
  - Claude Code sessions in `~/.claude/projects`
  - opencode DB at `~/.local/share/opencode/opencode.db` (opened strictly read-only)
- *(Optional)* [Ollama](https://ollama.com) with `qwen2.5-coder:7b` for daily digest bullets — everything else works without it

## Quick start

```sh
git clone https://github.com/vladootz/ai-worklog.git && cd ai-worklog
./serve.sh
```

That's it — first run ingests your session history into `ai-worklog.db`, then serves the dashboard at [http://localhost:8111](http://localhost:8111).

For day-to-day use, the full launcher also re-ingests every 5 minutes while the dashboard is open:

```sh
bin/worklog          # ingest → serve → open browser → re-ingest every 5 min
```

Or run the pieces manually:

```sh
php ingest.php                              # incremental ingest + digests (last 14 days)
php -S localhost:8111 -t public public/index.php   # serve the dashboard
```

### Laravel Herd

If the repo sits in a [Herd](https://herd.laravel.com)-parked directory (e.g. `~/Sites/worklog`), the dashboard serves automatically at `https://worklog.test` — no `serve.sh` needed. `LocalValetDriver.php` pins the docroot to `public/`, so `ai-worklog.db`, `lib/`, and `ingest.php` are never web-accessible. Isolate the site to any PHP ≥ 8.2 (`herd isolate 8.5`). Herd only serves; ingest still runs from the CLI (`php ingest.php`, or `bin/worklog` for the 5-minute loop).

Useful ingest flags:

| Flag | Effect |
| --- | --- |
| `--skip-digests` | skip Ollama digest generation |
| `--digest-days N` | digest window (default 14) |
| `--digest-from YYYY-MM-DD --digest-to YYYY-MM-DD` | backfill digests for a range (months of history on a 7B model takes a while) |

## How time is computed

- Every message/tool event timestamp from Claude Code JSONL (subagents included) and the opencode DB becomes an event.
- Events less than **15 minutes apart** merge into one work block. The threshold is stored on each block, so historical numbers never silently change; single-event blocks get a 60-second minimum. Agent runtime counts as work — the agent grinding while you're away is still your project moving.
- Blocks are stored per session — the ground truth. Per-project totals and the deduplicated wall-clock total are interval unions computed at query time, so parallel agent sessions are honest per project and never double-counted globally. **Leverage** = project time ÷ wall clock.
- Days are bucketed in the system timezone (php.ini is deliberately overridden — Homebrew ships UTC, and late-night sessions shouldn't land on the wrong billing day).

## Deliverables

Facts first: git commits made during session windows, session titles, and diff stats are stored at ingest. A local Ollama model (`qwen2.5-coder:7b` by default) then writes 2–4 deliverable bullets per project per day, cached by input hash. "Regenerate" in the day detail marks a digest stale for the next ingest.

Digest input is only prompts, titles, commit messages, and diff stats — never full transcripts. Ollama is only ever called from `ingest.php`, never from a web request, and if it isn't running, digests are simply skipped.

## Projects & mappings

Every directory auto-becomes a project named after its basename (shown with an "unmapped" badge). In ⚙ settings: rename to merge several dirs into one project, assign a client, or ignore throwaway dirs. Ignored projects disappear from all views and exports.

## Export for billing

The Export button produces one JSON time entry per project per day for the current range — description from the digest bullets, optional rounding up to 15/30 min. The format matches the merge-import schema of timester (the author's billing tool), but it's plain JSON that's easy to adapt to your own. **Merge each range only once** — re-imported time entries are not deduped on the timester side.

## Configuration

The defaults live in [`lib/Config.php`](lib/Config.php): port (8111), gap threshold (15 min), Ollama URL/model, digest window, and source paths. Edit them there — there is deliberately no config file format to learn.

## Privacy & your data

Everything is local: your sessions, projects, clients, and digests live in `ai-worklog.db` in the repo root, which is gitignored. No telemetry, no network calls except to your own localhost Ollama.

To share the app itself without any of that:

```sh
./archive.sh         # → dist/ai-worklog-YYYY-MM-DD.zip
```

The archive contains only git-tracked files and refuses to build if anything database-like slips in.

## Retention

Claude Code deletes transcripts after `cleanupPeriodDays` (worth raising in `~/.claude/settings.json` — this repo's author uses 365). From the moment something is ingested, `ai-worklog.db` is your permanent archive; ingestion is idempotent, so re-runs never double-count.

## Design notes

The full set of decisions and their rationale — time model, overlap treatment, schema, why zero packages — is in [PLAN.md](PLAN.md).

## License

[MIT](LICENSE)
