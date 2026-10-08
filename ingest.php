<?php
// CLI entry point: incremental ingest of Claude Code + opencode session data,
// git commits, and Ollama digests into ai-worklog.db. Never run from the web.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Coalesce.php';
require __DIR__ . '/lib/Timeline.php';
require __DIR__ . '/lib/ClaudeSource.php';
require __DIR__ . '/lib/OpencodeSource.php';
require __DIR__ . '/lib/GitCommits.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Ollama.php';
require __DIR__ . '/lib/Digests.php';

$config = new Config();
$db = new Db($config->dbPath);
// --digests-only skips source ingest and runs Ollama digests alone (the web layer's
// background pass); --skip-digests does the opposite. Neither → full run.
// --recoalesce only rebuilds every session's blocks from its stored events.
$opts = getopt('', ['skip-digests', 'digests-only', 'digest-days:', 'digest-from:', 'digest-to:', 'recoalesce']);

echo "ai-worklog ingest — db: {$config->dbPath}, tz: " . date_default_timezone_get() . "\n";

if (isset($opts['recoalesce'])) {
    // Sessions with no events (transcript deleted before events were stored) keep their blocks.
    $timeline = new Timeline($db, $config);
    $db->pdo->beginTransaction();
    $rows = $db->q('SELECT id, dir FROM sessions WHERE id IN (SELECT DISTINCT session_id FROM events)');
    foreach ($rows as $s) {
        $timeline->writeBlocks((int) $s['id'], $s['dir']);
    }
    $db->pdo->commit();
    echo 'recoalesce: rebuilt blocks for ' . count($rows) . " sessions from stored events\n";
    exit(0);
}

if (!isset($opts['digests-only'])) {
    // One-time backfill: sessions ingested before the events table existed have
    // blocks but no events. Forget what was seen so every session re-ingests once.
    $backfill = $db->val("SELECT value FROM settings WHERE key = 'events_backfilled'") === null;
    if ($backfill) {
        $db->exec('DELETE FROM ingest_state');
        $db->exec("DELETE FROM settings WHERE key = 'opencode_watermark'");
        echo "events: backfilling — every session re-ingests once\n";
    }

    $db->pdo->beginTransaction();
    $claudeStats = (new ClaudeSource($db, $config))->ingest();
    $db->pdo->commit();
    echo "claude: {$claudeStats['sessions']} sessions ingested, {$claudeStats['skipped']} unchanged\n";

    $db->pdo->beginTransaction();
    $ocStats = (new OpencodeSource($db, $config))->ingest();
    $db->pdo->commit();
    echo "opencode: {$ocStats['sessions']} sessions ingested, {$ocStats['skipped']} unchanged\n";

    $db->pdo->beginTransaction();
    $gitStats = (new GitCommits($db, $config))->ingest();
    $db->pdo->commit();
    echo "git: {$gitStats['commits']} commits upserted across {$gitStats['dirs']} repos\n";

    if ($backfill) {
        settingStamp($db, 'events_backfilled');
    }
    settingStamp($db, 'last_ingest_at');
}

if (!isset($opts['skip-digests'])) {
    // The web-triggered background pass runs with --digests-only; serialize those on a
    // non-blocking lock so repeated page loads can't pile up concurrent Ollama runs.
    // Plain CLI/full runs (no --digests-only) are unlocked and always generate.
    $digestLock = null;
    if (isset($opts['digests-only'])) {
        $digestLock = fopen(__DIR__ . '/.ingest-digests.lock', 'c');
        if ($digestLock === false || !flock($digestLock, LOCK_EX | LOCK_NB)) {
            echo "digests: already running — skipped\n";
            exit(0);
        }
    }
    $digests = new Digests($db, $config, new Api($db, $config), new Ollama($config->ollamaUrl, $config->ollamaModel));
    $dStats = $digests->run(
        (int) ($opts['digest-days'] ?? $config->digestDays),
        $opts['digest-from'] ?? null,
        $opts['digest-to'] ?? null
    );
    if (isset($dStats['skipped_ollama_down'])) {
        echo "digests: skipped (Ollama not reachable at {$config->ollamaUrl})\n";
    } else {
        echo "digests: {$dStats['generated']} generated, {$dStats['cached']} cached"
            . (($dStats['failed'] ?? 0) > 0 ? ", {$dStats['failed']} FAILED" : '') . "\n";
        settingStamp($db, 'last_digest_at');
    }
    if ($digestLock) {
        flock($digestLock, LOCK_UN);
        fclose($digestLock);
    }
}

/** Record a unix-timestamp marker in the settings table (used for freshness checks). */
function settingStamp(Db $db, string $key): void
{
    $db->exec(
        "INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value",
        [$key, (string) time()]
    );
}
