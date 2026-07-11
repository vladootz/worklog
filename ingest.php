<?php
// CLI entry point: incremental ingest of Claude Code + opencode session data,
// git commits, and Ollama digests into ai-worklog.db. Never run from the web.

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require __DIR__ . '/lib/Config.php';
require __DIR__ . '/lib/Db.php';
require __DIR__ . '/lib/Coalesce.php';
require __DIR__ . '/lib/ClaudeSource.php';
require __DIR__ . '/lib/OpencodeSource.php';
require __DIR__ . '/lib/GitCommits.php';
require __DIR__ . '/lib/Api.php';
require __DIR__ . '/lib/Ollama.php';
require __DIR__ . '/lib/Digests.php';

$config = new Config();
$db = new Db($config->dbPath);

echo "ai-worklog ingest — db: {$config->dbPath}, tz: " . date_default_timezone_get() . "\n";

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

$opts = getopt('', ['skip-digests', 'digest-days:', 'digest-from:', 'digest-to:']);
if (!isset($opts['skip-digests'])) {
    $digests = new Digests($db, $config, new Api($db, $config), new Ollama($config->ollamaUrl, $config->ollamaModel));
    $dStats = $digests->run(
        (int) ($opts['digest-days'] ?? $config->digestDays),
        $opts['digest-from'] ?? null,
        $opts['digest-to'] ?? null
    );
    echo isset($dStats['skipped_ollama_down'])
        ? "digests: skipped (Ollama not reachable at {$config->ollamaUrl})\n"
        : "digests: {$dStats['generated']} generated, {$dStats['cached']} cached\n";
}
