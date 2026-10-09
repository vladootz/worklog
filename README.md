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
- **Projects worked on** — next to the leverage figure, the projects and hours for the past week, month, 3 months, 6 months, year and 2 years; open a row for the full list with clients
- **Billing export** — your whole history as one entry per work block, with real start and end times and the time zone where you worked, ready for timester's one-step sync
- **Deterministic and explainable** — every rule (gap threshold, dedup, rounding) is simple enough to defend on an invoice

## Requirements

- **PHP 8.2+** with `pdo_sqlite` (both ship with `brew install php`)
- **macOS or Linux** — anywhere Claude Code / opencode write their data
- At least one data source:
  - Claude Code sessions in `~/.claude/projects`
  - opencode DB at `~/.local/share/opencode/opencode.db` (opened strictly read-only)
- *(Optional)* [Ollama](https://ollama.com) with `qwen3:14b` for daily digest bullets — everything else works without it

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
php ingest.php                              # incremental ingest + digests (last 30 days)
php -S localhost:8111 -t public public/index.php   # serve the dashboard
```

### Laravel Herd

If the repo sits in a [Herd](https://herd.laravel.com)-parked directory (e.g. `~/Sites/worklog`), the dashboard serves automatically at `https://worklog.test` — no `serve.sh` needed. `LocalValetDriver.php` pins the docroot to `public/`, so `ai-worklog.db`, `lib/`, and `ingest.php` are never web-accessible. Isolate the site to any PHP ≥ 8.2 (`herd isolate 8.5`).

Because Herd only serves (nothing runs the 5-minute re-ingest loop that `bin/worklog` provides), the dashboard keeps itself fresh from the browser instead of cron: it re-ingests on load when the last ingest is over 5 minutes old, and the **Read data** button in the header forces a re-read on demand. The web layer never ingests in-process — it shells out to the CLI (`Config::phpCli()` resolves a PHP ≥ 8.2 binary; override with `WORKLOG_PHP`), so `ingest.php`'s cli-only guard still holds. Time stats refresh synchronously (`--skip-digests`, ~8s); the Ollama digest bullets regenerate in a background pass and appear a moment later. Running `php ingest.php` or `bin/worklog` from the CLI still works exactly as before.

Useful ingest flags:

| Flag | Effect |
| --- | --- |
| `--skip-digests` | skip Ollama digest generation |
| `--digest-days N` | digest window (default 30 — matches the dashboard's default range) |
| | *a run prints `N FAILED` if the model timed out on any day; those retry on the next pass* |
| `--digest-from YYYY-MM-DD --digest-to YYYY-MM-DD` | backfill digests for a range (months of history on a 7B model takes a while) |
| `--recoalesce` | rebuild every block from the stored events with the current rule, then exit |

## How time is computed

- Every message/tool event timestamp from Claude Code JSONL (subagents included) and the opencode DB becomes an event. Ingest keeps each event in the `events` table, with a flag on the prompts you typed.
- Events less than **15 minutes apart** merge into one work block. The threshold is stored on each block, so historical numbers never silently change; single-event blocks get a 60-second minimum. Without presence data, agent runtime counts as work, also when you are away.
- With the presence sampler on (next section), your keyboard and mouse input shapes the blocks:
  - A gap of 15 to 60 minutes counts when your Mac recorded input during the whole gap. For example, you test the site in a browser between two prompts.
  - Time with no input for more than 5 minutes does not count, also when an agent still works. The first 5 minutes of each idle stretch still count, because reading is work.
  - Gap time does not count while another project had activity, because your attention was there.
  - Where there are no samples (before you installed the sampler, or while the Mac was asleep), the plain 15-minute rule applies.
- `php ingest.php --recoalesce` rebuilds all blocks from the stored events with the current rule. Historical numbers change only when you run it.
- Blocks are stored per session — the ground truth. Per-project totals and the deduplicated wall-clock total are interval unions computed at query time, so parallel agent sessions are honest per project and never double-counted globally. **Leverage** = project time ÷ wall clock.
- A moment belongs to the calendar day where you were at that moment. Ingest records your time zone as you travel, so a past day never moves when you export it from another country. Around a flight a day can be shorter or longer than 24 hours, like a daylight-saving day.

## Presence sampler (optional)

The sampler records how many seconds passed since your last keyboard or mouse input, once a minute. A launchd agent runs it, so it works when the dashboard is closed. Each line of `presence.log` holds the Unix time, the idle seconds and the time zone of the Mac. The file stays on your Mac.

```sh
bin/presence install     # start sampling every 60 s, also after login
bin/presence status      # show if the sampler runs, and the newest samples
bin/presence uninstall   # stop sampling (presence.log is kept)
```

The next ingest reads the new lines of `presence.log`. Sessions after the install use the presence rule. Older sessions keep the plain 15-minute rule.

## Travelling

The dashboard keeps a travel log: the time zone you were in, and from when. The presence sampler notes the zone of your Mac every minute, and every ingest notes it too. Your Mac must set its time zone automatically (System Settings → General → Date & Time).

```sh
php ingest.php --zones                              # show the travel log
php ingest.php --zone 2026-07-15=Europe/Bucharest   # you were in Bucharest from 15 July, 00:00 local time
```

You can give `--zone` more than once. Use it to fill in trips from before you installed the sampler, or to correct a wrong entry.

## Deliverables

Facts first: git commits made during session windows, session titles, and diff stats are stored at ingest. A local Ollama model (`qwen3:14b` by default) then writes 2–4 deliverable bullets per project per day, cached by input hash. "Regenerate" in the day detail marks a digest stale for the next ingest.

Digest input is only prompts, titles, commit messages, and diff stats — never full transcripts. Ollama is only ever called from `ingest.php`, never from a web request, and if it isn't running, digests are simply skipped.

The digest window defaults to 30 days so that every day the dashboard shows by default can have a digest.

## Projects & mappings

Every directory auto-becomes a project named after its basename (shown with an "unmapped" badge). In ⚙ settings: rename to merge several dirs into one project, assign a client, or ignore throwaway dirs. Ignored projects disappear from all views and exports.

## Export for billing

The Export button downloads your whole tracked history, whatever range the dashboard shows. Each work block becomes one time entry with its real start and end time, the time zone where you worked, and that day's digest as its description. The file uses the schema of timester (the author's billing tool) with a `source: "ai-worklog"` marker. It is plain JSON that is easy to adapt to your own tool.

To bring it into timester:

1. In ai-worklog, click **Export**, then **Download JSON**.
2. In timester, click **Import** and select the file. Timester picks **Sync from ai-worklog** by itself.
3. Read the preview, then click **Sync**.

The sync replaces every entry tagged `ai-worklog` and never touches the entries you made in timester. Renamed projects, client changes and ignored projects follow on their own. Never import this file with Merge: timester refuses it, because a merge would add a second copy of every entry.

### Fallback: rebuild a timester backup

Use `bin/timester-replace` only with a timester version that has no Sync option. It applies the same rules to a backup file:

1. In ⚙ settings, rename, merge, ignore or assign clients until the dashboard is right.
2. In timester, export a backup.
3. Run `bin/timester-replace` with that backup:

   ```sh
   bin/timester-replace ~/Downloads/timester-export-2026-10-10_09-00.json
   ```

4. Read the report. It lists the entries that change, the projects it creates or removes, and the client changes.
5. In timester, import the file it wrote with the option that replaces all data.

The command replaces every entry tagged `ai-worklog` with a fresh export up to yesterday, in the same format as the Export button. It copies all your other entries unchanged and checks this before it writes. A project in timester is a name under a client, so the command matches on both. It never changes the client of a project that holds your own entries, and it never deletes a client. If you edit an `ai-worklog` entry by hand in timester, the next rebuild overwrites that edit.

## Configuration

The defaults live in [`lib/Config.php`](lib/Config.php): port (8111), gap threshold (15 min), presence idle time (5 min) and longest presence gap (60 min), Ollama URL/model, digest window, and source paths. Edit them there — there is deliberately no config file format to learn.

## Request safety

The app has no accounts, but it answers on a real hostname under Herd, so every state-changing request
(`POST /api/*`) is checked for same-origin before the router runs: `Sec-Fetch-Site` must be `same-origin` when
the browser sends it, and `Origin`, when present, must match the host. A request with neither header is treated
as a non-browser caller (curl, `bin/worklog`) and allowed. This stops another site in your browser from
rewriting your project mappings or kicking off ingests; it is **not** authentication. Do not expose this app
beyond localhost without adding real auth.

## Privacy & your data

Everything is local: your sessions, projects, clients, and digests live in `ai-worklog.db` in the repo root, which is gitignored. No telemetry, no network calls except to your own localhost Ollama.

To share the app itself without any of that:

```sh
./archive.sh         # → dist/ai-worklog-YYYY-MM-DD.zip
```

The archive contains only git-tracked files and refuses to build if anything database-like slips in. `presence.log` is gitignored too, and the archive refuses it in the same way.

## Retention

Claude Code deletes transcripts after `cleanupPeriodDays` (worth raising in `~/.claude/settings.json` — this repo's author uses 365). From the moment something is ingested, `ai-worklog.db` is your permanent archive; ingestion is idempotent, so re-runs never double-count. The database also keeps the raw event times, so `--recoalesce` can rebuild blocks after Claude Code has deleted the transcripts.

## Design notes

The full set of decisions and their rationale — time model, overlap treatment, schema, why zero packages — is in [PLAN.md](PLAN.md).

## License

[MIT](LICENSE)
