# ai-worklog — Plan

Automatic project time & deliverables dashboard fed by Claude Code and opencode session data.
Replaces manual entry into timester; timester remains the billing system of record via an export bridge.

## Decisions (agreed 2026-07-07)

1. **Purpose**: personal insight AND billable-quality numbers — every rule below must be deterministic and explainable to a client.
2. **Time model**: activity coalescing. All event timestamps for a project are merged into work blocks; gaps < **15 min** count as continuous work, larger gaps are breaks. Agent runtime (tool calls streaming while user is away) counts as work. The gap threshold is stored on every computed block so historical numbers never silently change.
3. **Overlapping projects**: store true per-project blocks (never destroy information at ingestion). Dashboard shows both per-project totals and a deduplicated wall-clock total per day, plus a "leverage factor" (agent-hours ÷ wall-clock hours). Billing treatment of overlap is decided at export time, per case.
4. **Relationship to timester**: new standalone tool. Export bridge produces timester-schema JSON consumed via timester's existing `mergeData()` (non-destructive merge by name). Timester's code is not modified.
5. **Deliverables**: facts stored at ingestion (git commits, session titles/first prompts, diff stats) + LLM digest per **project-day** via local Ollama (`qwen2.5-coder:7b`; try `qwen3:8b` if quality disappoints). Digests are generated lazily (on view / when a day completes), cached in SQLite, with a "regenerate" button. Digest input = user prompts + session titles + commit messages + diff stats — never full transcripts.
6. **Machines**: single Mac today; schema carries a `machine` column and ingestion is idempotent so a second machine later is additive.
7. **Runtime model**: on-demand. One command (`worklog`) runs incremental ingest, starts `php -S localhost:8111`, opens the browser; re-scans every few minutes while open. No daemon. Nightly launchd timer is an optional later add-on.
8. **Stack**: PHP 8.2 + SQLite (pdo_sqlite), zero packages (no Composer, no npm), vanilla CSS/JS, hand-rolled SVG charts. Ollama called with curl from the CLI ingest step only — never from a web request.
9. **Project identity**: auto-project per directory basename + `dir_mapping` override table editable in the dashboard: merge dirs into one canonical project, rename, attach client, or mark ignored. Unmapped dirs appear with an "unmapped" badge; export refuses unmapped/ignored projects.
10. **Timester export**: one `TimeEntry` per project per day — `startTime` = first block start, `endTime` = start + summed duration, `description` = digest bullets. Rounding is an export-time option (none / 15m / 30m, default none). Export is manual and date-range scoped: review dashboard → click export → merge-import into timester.
11. **Backfill & retention**: ingest all available history at first run (opencode back to 2026-04-03; Claude Code last ~30 days — older is already deleted). Raise `cleanupPeriodDays` to 365 in `~/.claude/settings.json`. The ai-worklog SQLite is the permanent archive from first ingest onward.
12. **Conventions**: local timezone, day = midnight–midnight, week starts Monday. Port 8111.

## Data sources (verified on this machine)

- **Claude Code**: `~/.claude/projects/<dir-slug>/<session-uuid>.jsonl` — one JSONL per session, per-message timestamps, `cwd`, first user prompt. Subagent sidechains at `<session-uuid>/subagents/*.jsonl` count toward the parent session. 239 files across 42 project dirs currently.
- **opencode**: `~/.local/share/opencode/opencode.db` (SQLite, WAL) — `session` table has directory, title, timestamps, cost, tokens, diff summary (files/additions/deletions); `message`/`part` tables provide per-message timestamps (verify exact time columns during M2). 370 sessions / 49 projects. Open read-only.
- **git**: for each session directory that is a repo, `git log` within the session time window (all authors — agent commits are work product), stored by hash. Missing/moved repos skipped silently.

## SQLite schema (ai-worklog.db)

- `projects` (id, name, client, ignored, created_at)
- `dir_mappings` (dir TEXT PK, project_id FK) — dirs without a row auto-map to a basename project
- `sessions` (id, source 'claude'|'opencode', source_session_id, dir, machine, title, first_prompt, started_at, ended_at, cost, tokens_in, tokens_out, files_changed, additions, deletions; UNIQUE(source, source_session_id, machine))
- `blocks` (id, session_id FK, project_dir, start_at, end_at, event_count, gap_threshold_min)
- `commits` (hash PK, dir, author, committed_at, message, files, additions, deletions)
- `digests` (project_id, date, text, model, inputs_hash, generated_at; PK(project_id, date))
- `ingest_state` (path PK, mtime, size) — incremental scan bookkeeping; re-ingest of a file deletes+rewrites its sessions/blocks (idempotent)
- `settings` (key, value)

## Layout

```
~/projects/ai-worklog/
  ingest.php          # CLI: scan sources → sessions/blocks/commits → lazy digests
  serve.php           # router for php -S (static + /api/*)
  lib/                # Db.php, ClaudeSource.php, OpencodeSource.php, Coalesce.php,
                      # GitCommits.php, Ollama.php, TimesterExport.php
  public/             # index.html, app.js, charts.js (SVG), style.css
  bin/worklog         # shell script: ingest → serve → open browser
  ai-worklog.db       # created on first run (gitignored)
```

## API (JSON, read-only except noted)

- `GET /api/summary?from&to&projects` — totals, per-project time, dedup wall-clock, leverage
- `GET /api/timeseries?from&to&projects&bucket=day|week|month` — stacked per-project durations
- `GET /api/days?from&to&projects` — project-day rows: blocks, sessions, commits, digest
- `GET /api/projects` / `POST /api/mappings` — mapping management
- `POST /api/digest/regenerate` — marks a project-day digest stale (regenerated by next ingest run; the web layer never calls Ollama)
- `GET /api/export/timester?from&to&rounding` — timester-schema JSON download

## UI (single page)

Filter bar (date range presets + custom, project multi-select) → summary cards (total time, dedup wall-clock, leverage, active projects) → stacked SVG bar chart with day/week/month grouping → project-day detail table (expandable: blocks timeline, session titles, commits, digest, regenerate button). Settings drawer for dir mappings. Export modal (range, rounding, download JSON).

## Milestones

- **M1 — Ingest core + walking skeleton**: schema, Claude JSONL scanner (incl. subagents), block coalescing, `bin/worklog`, dashboard shows a plain per-project-per-day table. *Verify: one known day's total matches your memory/timester entry.*
- **M2 — opencode + overlap math**: opencode ingest (read-only attach; confirm per-message timestamp columns), dedup wall-clock + leverage, SVG stacked chart with day/week/month buckets, filters.
- **M3 — Deliverables**: git commit collection, Ollama digest generation (lazy, cached, regenerate), detail view.
- **M4 — Mapping + export**: dir-mapping settings UI, timester export with rounding option, refuse unmapped/ignored.
- **M5 — Polish**: `cleanupPeriodDays: 365`, shell alias, README, empty-state/error handling.

## Verification approach

After M1 and M2, cross-check 2–3 recent days against timester's manual entries and your recollection; investigate any day off by >20%. After M4, run one real weekly export → merge into timester → confirm entries and no duplicates on repeat import.
