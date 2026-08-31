# ai-worklog — project context

Local-only time & deliverables dashboard. It reads the session data Claude Code and opencode
already write to disk, reconstructs work blocks from event timestamps, and turns them into
per-project / per-client hours plus AI-written deliverable bullets.

## Stack and the one rule that shapes everything

**PHP 8.2+ · SQLite · vanilla JS/CSS/SVG. Zero dependencies, by design.**
No Composer, no npm, no bundler, no CDN, no framework. If a task seems to need a package,
it does not — say so and stop rather than adding one. The charts, the date handling and the
DOM layer are all hand-rolled on purpose.

## Layout

| Path | Role |
| --- | --- |
| `ingest.php` | CLI entry point. Reads sources, writes `ai-worklog.db`, runs digests. **CLI only** — it refuses to run under a web SAPI. |
| `lib/Config.php` | Every tunable: port, gap threshold, Ollama URL/model, `digestDays`, source paths. There is deliberately no config file format. |
| `lib/Db.php` | Thin PDO wrapper. `q()` rows, `one()` row, `val()` scalar, `exec()` write — all take bound params. |
| `lib/Api.php` | All read logic: `summary()`, `days()`, `timeseries()`, `projects()`, `digestsRange()`, plus the interval-union maths. |
| `lib/ClaudeSource.php`, `lib/OpencodeSource.php`, `lib/GitCommits.php` | Ingest sources. |
| `lib/Digests.php` | Ollama digest generation, cached by `inputs_hash`. |
| `lib/Coalesce.php` | Event timestamps → work blocks. |
| `lib/TimesterExport.php` | Billing export shaping. |
| `public/index.php` | Front controller. `/api/*` JSON; `/settings` and `/` serve their HTML; everything else static. |
| `public/index.html`, `app.js`, `charts.js`, `style.css` | The dashboard UI. |
| `public/settings.html`, `settings.js` | The `/settings` page: tabbed (one tab today — project mappings), built so a new setting is a new panel. Self-contained on purpose (no bundler); re-declares `esc`/`announce`/`getJSON`. |
| `ai-worklog.db` | SQLite. Gitignored. Your permanent archive — Claude Code deletes transcripts after `cleanupPeriodDays`. |
| `_plans/`, `docs/plans/` | Blueprints. Read the newest before starting related work. |

## Running it

```sh
./serve.sh                                          # ingest + serve on :8111
bin/worklog                                         # ingest → serve → open → re-ingest every 5 min
php ingest.php                                      # incremental ingest + digests (last 30 days)
php ingest.php --skip-digests                       # time stats only, ~8s
php ingest.php --digests-only                       # Ollama pass only
php ingest.php --digest-from 2026-01-01 --digest-to 2026-06-30   # backfill a range
php -S localhost:8111 -t public public/index.php    # serve only
```

Under Laravel Herd the repo serves at **https://worklog.test** with no `serve.sh`.
`LocalValetDriver.php` pins the docroot to `public/`, which is what keeps `ai-worklog.db`,
`lib/` and `ingest.php` off the web.

## Load-bearing invariants — do not break these

1. **Ollama is only ever called from `ingest.php`.** Never from a web request. The web layer
   triggers digests by marking a day stale and spawning the detached CLI pass
   (`POST /api/ingest`, `POST /api/digest/generate`). A call site under `public/` is a bug.
2. **The web layer never ingests in-process.** It shells out via `Config::phpCli()` so
   `ingest.php`'s CLI-only guard still holds. Override the binary with `WORKLOG_PHP`.
3. **Every state-changing request passes `requireSameOrigin()`**, invoked once before the
   router in `public/index.php` so new routes inherit it. This app has no auth; the guard
   stops cross-origin browsers, it is **not** authentication. Do not expose beyond localhost
   without adding real auth.
4. **Every DB write is a bound prepared statement.** Every `exec()` argument is a server-side
   constant through `escapeshellarg` — no request value may reach the shell.
5. **Blocks are the ground truth.** Per-project totals and the wall-clock total are interval
   unions computed at query time, so parallel agent sessions count fully per project and are
   never double-counted globally. Leverage = project time ÷ wall clock.
6. **The gap threshold is stored on each block**, so historical numbers never silently change
   when the default moves.
7. **Days bucket in the system timezone.** `Config::initTimezone()` deliberately overrides
   php.ini — Homebrew ships UTC and late-night sessions must not land on the wrong billing day.
8. **Ingestion is idempotent.** Re-runs never double-count. Digests are cached by `inputs_hash`
   and only regenerate when inputs change or the row is marked stale.

## UI conventions

- **The UI is monochrome. Colour is reserved for data.** There is no chromatic accent token;
  selected states use `--selected-bg` / `--selected-line` plus font weight. The only saturated
  colours on the page are `PALETTE` and `OTHER_COLOR` in `charts.js`.
- Contrast is a hard requirement: text tokens ≥ 4.5:1, `--line-control` and chart fills ≥ 3:1,
  against `--bg`, `--panel` **and** `--raised`. Ratios are recorded in `public/style.css`.
- `--line` is a decorative hairline only. **Every interactive border uses `--line-control`.**
- Selection and state must never be conveyed by colour alone — pair it with weight, text or
  strike-through.
- Everything interactive must be keyboard-operable and carry an accessible name. Announce async
  work through `announce()`, which writes to the page-level `#sr-status` region (one per page:
  dashboard and `/settings` each own one). The settings page also has a list-scoped region
  (`#mapping-count`) plus a `.save-state` per row — scoped regions are fine; a second
  *page-level* one is not.
- All interpolated API data goes through `esc()` (`app.js`) or `escHtml()` (`charts.js`).
  No inline `on*` attributes, no `eval`.
- Query params are untrusted: validate, drop what fails, never echo back.

## Gotchas

- **`assignSlots()` in `charts.js` must stay bounded.** It probes for a free palette slot; an
  unbounded loop there freezes the browser tab the moment the series count reaches
  `PALETTE.length`. This has already happened once.
- **Displayed totals must reconcile.** Summing raw seconds for a group while each row rounds
  independently produces headers that disagree with the rows beneath them. Use
  `displayedMinutes()` / `fmtMins()`.
- **A date regex is not a date check.** `/^\d{4}-\d{2}-\d{2}$/` accepts `2026-13-99`. Use
  `validDate()` (`checkdate`) on anything that reaches the DB.
- **Never override a `<dialog>`'s display when closed.** An author rule like
  `#foo-dialog { display: flex }` beats the UA's `dialog:not([open]) { display: none }` and the
  closed dialog renders inline at the page bottom (the settings dialog did exactly that).
  `dialog:not([open]) { display: none }` is pinned in `style.css` — keep it.
- **`first_prompt` is truncated to 160 chars in `Api::sessionInfo()`.** It is only the UI's
  fallback for a missing title, and the UI shows ≤110 chars of that; shipping whole prompts made
  a 30-day `/api/days` response ~85 KB heavier. Don't "fix" the truncation without a reason.
- **Session `title` is not a deliverable.** Claude Code seeds it from the first prompt, so it
  often *is* prompt text. `looksLikePrompt()` in `app.js` filters it; commits rank above it.
- `Config::digestDays` must cover the dashboard's default range (currently both 30), or visible
  days can never have a digest.
- Ollama being down is not an error — digests are skipped and everything else works.
- **Keep `'think' => false` in `lib/Ollama.php`.** `qwen3:14b` is a reasoning model; with thinking on it
  spent 82s / 261 eval tokens on a prompt that takes 3.2s / 27 tokens with it off, which pushed most digests
  past the curl timeout. Measured, not guessed.
- **Never swallow a failed generation.** `Digests::run()` counts and logs failures; a run that reports only
  `generated` and `cached` will look healthy while most of the work silently timed out.
- **Do not mark a day stale that has no recorded work.** `dayHasWork()` guards both digest routes — such a
  row can never be satisfied and stays stale forever.
- **Background digest results must land in place.** `watchDigests()` polls `/api/digests` and calls
  `patchDigests()` → `paintDeliveredCell()`, which rewrites only the Delivered cell and the open
  detail's `.detail-delivered` block. Calling `load()` there repaints the table and throws away the
  user's expanded rows, "show more" page and cross-filter mid-generation.
- **A repaint of the same range must preserve the user's page state.** `renderDays()` compares
  `currentQueryKey()`; on a match it keeps `shownDays` and the cross-filter and re-opens the detail
  rows by project+date (`openRowKeys()` / `restoreOpenRows()`), never by index. Only a real range or
  project-filter change resets them.
- **Stale is the start of a generation, not the end.** `/api/digest/generate` sets `stale = 1` before
  the model runs, so a row only leaves `pendingDigests` once it has a digest *and* `stale` is clear —
  and `markPending()` re-applies the disabled "Generating…" button after any repaint in between.
  `watchDigests()` stops on the pending rows, not on `last_digest_at`: a pass already in flight when
  the user clicks ends seconds later and would otherwise cut the watch short.
- `archive.sh` refuses to build if anything database-like is staged. Keep it that way.

## Before saying a change is done

Run the gate: `php -l` every touched PHP file, `node --check` every touched JS file, then load
`https://worklog.test` at **1440 and 390** — console clean, no PHP notice in the page, no
horizontal page scroll, keyboard path intact. Update this file in the same task when a change
affects anything documented here.
