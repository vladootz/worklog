<?php
// Front controller. Serves /api/* as JSON; everything else is static from this docroot.
// Runs under Herd/nginx (docroot mapped here by LocalValetDriver) and the built-in server:
//   php -S localhost:8111 -t public public/index.php
// The web layer only ever reads/writes ai-worklog.db — never sources, never Ollama.

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (!str_starts_with($uri, '/api/')) {
    if (PHP_SAPI === 'cli-server' && $uri !== '/' && is_file(__DIR__ . $uri)) {
        return false; // built-in server handles the static file itself
    }
    // Under nginx existing static files never reach PHP (LocalValetDriver serves them).
    if ($uri === '/' || $uri === '/index.html') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/index.html');
        exit;
    }
    // Settings is a standalone page, not a dialog on the dashboard.
    if ($uri === '/settings' || $uri === '/settings.html') {
        header('Content-Type: text/html; charset=utf-8');
        readfile(__DIR__ . '/settings.html');
        exit;
    }
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'not found';
    exit;
}

$root = dirname(__DIR__);
require $root . '/lib/Config.php';
require $root . '/lib/Db.php';
require $root . '/lib/Api.php';
require $root . '/lib/TimesterExport.php';

$config = new Config();
$db = new Db($config->dbPath);
$api = new Api($db, $config);

function jsonOut($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * Browser-CSRF guard for every state-changing request.
 *
 * This app has no auth and, under Herd, answers on a real hostname, so any page the
 * user happens to be visiting can aim a request at it. Content-Type is no defence:
 * a cross-origin POST with a text/plain body is a *simple* request, so it skips
 * preflight entirely, and `php://input` + json_decode never look at the header the
 * attacker declined to send. The response stays unreadable to them, but by then the
 * mapping has been rewritten or the ingest has been kicked off.
 *
 * Two header checks, no session and no token to plumb through a UI that has no login:
 *   1. Sec-Fetch-Site, when sent, must be `same-origin`. Every current browser sends
 *      it and script cannot forge it.
 *   2. Origin, when sent, must match the host we were reached on.
 * Neither header present means the caller is not a browser — curl, bin/worklog — and
 * is allowed through. This guards against cross-origin browsers, not against someone
 * who already has a shell on the machine; it is not a substitute for authentication.
 */
function requireSameOrigin(): void
{
    $site = $_SERVER['HTTP_SEC_FETCH_SITE'] ?? null;
    if ($site !== null && $site !== 'same-origin') {
        jsonOut(['error' => 'cross-origin request refused'], 403);
        exit;
    }
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = parse_url($origin, PHP_URL_HOST);
        $host = preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''));
        if (!is_string($originHost) || $host === '' || strcasecmp($originHost, $host) !== 0) {
            jsonOut(['error' => 'cross-origin request refused'], 403);
            exit;
        }
    }
}

/**
 * Decoded JSON request body, or 415. Requiring the header is a second, independent
 * barrier to the simple-request path above: setting it forces a CORS preflight that
 * a cross-origin caller cannot satisfy.
 */
function jsonBody(): array
{
    $ct = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
    if (!str_starts_with($ct, 'application/json')) {
        jsonOut(['error' => 'expected Content-Type: application/json'], 415);
        exit;
    }
    $decoded = json_decode((string) file_get_contents('php://input'), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * A real calendar date in Y-m-d, not merely a digit-shaped string. `2026-13-99`
 * matches /^\d{4}-\d{2}-\d{2}$/ but is not a date, and storing it puts a row in
 * `digests` that no day can ever match.
 */
function validDate(string $d): bool
{
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) {
        return false;
    }
    return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

/**
 * Does this project actually have recorded work on this day? Marking a day stale
 * that has none creates a `digests` row `Digests::run()` can never satisfy: it
 * stays stale forever and is re-queried on every pass.
 */
function dayHasWork(Api $api, int $projectId, string $date): bool
{
    $from = strtotime($date . ' 00:00:00');
    if ($from === false) {
        return false;
    }
    foreach ($api->days($from, strtotime('+1 day', $from)) as $row) {
        if ((int) $row['project_id'] === $projectId) {
            return true;
        }
    }
    return false;
}

function rangeParams(): array
{
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-13 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $fromTs = strtotime($from . ' 00:00:00');
    if ($fromTs === false) {
        $fromTs = strtotime(date('Y-m-d', strtotime('-13 days')) . ' 00:00:00');
    }
    $toMid = strtotime($to . ' 00:00:00');
    if ($toMid === false) {
        $toMid = strtotime(date('Y-m-d') . ' 00:00:00');
    }
    $toTs = strtotime('+1 day', $toMid); // DST-safe next midnight
    $projects = array_filter(array_map('intval', explode(',', $_GET['projects'] ?? '')));
    return [$fromTs, $toTs, array_values($projects)];
}

// Every state-changing request passes the CSRF guard before the router sees it, so a
// route added later inherits it instead of having to opt in.
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    requireSameOrigin();
}

try {
    switch (true) {
        case $uri === '/api/summary':
            [$from, $to, $pids] = rangeParams();
            jsonOut($api->summary($from, $to, $pids));
            break;

        case $uri === '/api/days':
            [$from, $to, $pids] = rangeParams();
            jsonOut($api->days($from, $to, $pids));
            break;

        case $uri === '/api/timeseries':
            [$from, $to, $pids] = rangeParams();
            $bucket = in_array($_GET['bucket'] ?? 'day', ['day', 'week', 'month'], true)
                ? ($_GET['bucket'] ?? 'day') : 'day';
            jsonOut($api->timeseries($from, $to, $bucket, $pids));
            break;

        case $uri === '/api/projects':
            jsonOut($api->projects());
            break;

        case $uri === '/api/digest/stale' && $_SERVER['REQUEST_METHOD'] === 'POST':
            $body = jsonBody();
            $pid = (int) ($body['project_id'] ?? 0);
            $date = (string) ($body['date'] ?? '');
            if ($pid <= 0 || !validDate($date)) {
                jsonOut(['error' => 'project_id and a valid YYYY-MM-DD date required'], 400);
                break;
            }
            // Without this the FK violation surfaces as a 500 carrying raw SQLSTATE text.
            if ($db->val('SELECT id FROM projects WHERE id = ?', [$pid]) === null) {
                jsonOut(['error' => 'unknown project_id'], 400);
                break;
            }
            if (!dayHasWork($api, $pid, $date)) {
                jsonOut(['error' => 'no recorded work for that project on that date'], 404);
                break;
            }
            $db->exec(
                'INSERT INTO digests (project_id, date, stale) VALUES (?, ?, 1)
                 ON CONFLICT(project_id, date) DO UPDATE SET stale = 1',
                [$pid, $date]
            );
            jsonOut(['ok' => true]);
            break;

        // Generate a digest for one day on demand. Ollama is still never called from
        // a web request: this marks the day stale and spawns the same detached CLI
        // pass /api/ingest uses. Digests::run() already revisits stale days that fall
        // outside the digest window, so no new scheduling is needed.
        case $uri === '/api/digest/generate' && $_SERVER['REQUEST_METHOD'] === 'POST':
            $body = jsonBody();
            $pid = (int) ($body['project_id'] ?? 0);
            $date = (string) ($body['date'] ?? '');
            if ($pid <= 0 || !validDate($date)) {
                jsonOut(['error' => 'project_id and a valid YYYY-MM-DD date required'], 400);
                break;
            }
            if ($db->val('SELECT id FROM projects WHERE id = ?', [$pid]) === null) {
                jsonOut(['error' => 'unknown project_id'], 400);
                break;
            }
            if (!dayHasWork($api, $pid, $date)) {
                jsonOut(['error' => 'no recorded work for that project on that date'], 404);
                break;
            }
            $db->exec(
                'INSERT INTO digests (project_id, date, stale) VALUES (?, ?, 1)
                 ON CONFLICT(project_id, date) DO UPDATE SET stale = 1',
                [$pid, $date]
            );
            // Probe the digest lock so an obviously-running pass is reported rather than
            // re-spawned. `queued` is advisory: two calls fired inside the spawn window
            // can both report true. That is safe — ingest.php takes the same lock with
            // LOCK_NB and the loser exits with "digests: already running — skipped", so
            // only one Ollama pass can ever run. The CLI is the authority, not this probe.
            $dLock = fopen($root . '/.ingest-digests.lock', 'c');
            if ($dLock === false || !flock($dLock, LOCK_EX | LOCK_NB)) {
                if ($dLock !== false) {
                    fclose($dLock);
                }
                jsonOut(['ok' => true, 'queued' => false, 'running' => true]);
                break;
            }
            flock($dLock, LOCK_UN);
            fclose($dLock);
            // Built only from server-side constants — no request value reaches the shell.
            exec('nohup ' . escapeshellarg($config->phpCli()) . ' ' . escapeshellarg($root . '/ingest.php')
                . ' --digests-only > ' . escapeshellarg($root . '/.ingest-digests.log') . ' 2>&1 &');
            jsonOut(['ok' => true, 'queued' => true]);
            break;

        case $uri === '/api/mappings' && $_SERVER['REQUEST_METHOD'] === 'GET':
            jsonOut($db->q(
                'SELECT dm.dir, dm.auto, p.id AS project_id, p.name AS project, p.client, p.ignored
                 FROM dir_mappings dm JOIN projects p ON p.id = dm.project_id
                 ORDER BY p.ignored, p.name COLLATE NOCASE, dm.dir'
            ));
            break;

        case $uri === '/api/mappings' && $_SERVER['REQUEST_METHOD'] === 'POST':
            $body = jsonBody();
            $dir = (string) ($body['dir'] ?? '');
            $name = trim((string) ($body['project'] ?? ''));
            if ($dir === '' || $name === '') {
                jsonOut(['error' => 'dir and project required'], 400);
                break;
            }
            $pid = $db->val('SELECT id FROM projects WHERE name = ? COLLATE NOCASE', [$name]);
            if ($pid === null) {
                $db->exec('INSERT INTO projects (name, created_at) VALUES (?, ?)', [$name, time()]);
                $pid = (int) $db->pdo->lastInsertId();
            }
            $client = trim((string) ($body['client'] ?? ''));
            $db->exec(
                'UPDATE projects SET client = ?, ignored = ? WHERE id = ?',
                [$client !== '' ? $client : null, (int) (bool) ($body['ignored'] ?? false), $pid]
            );
            $db->exec(
                'INSERT INTO dir_mappings (dir, project_id, auto) VALUES (?, ?, 0)
                 ON CONFLICT(dir) DO UPDATE SET project_id = excluded.project_id, auto = 0',
                [$dir, $pid]
            );
            // Drop projects that no longer own any directory.
            $db->exec('DELETE FROM digests WHERE project_id IN (SELECT id FROM projects WHERE id NOT IN (SELECT project_id FROM dir_mappings))');
            $db->exec('DELETE FROM projects WHERE id NOT IN (SELECT project_id FROM dir_mappings)');
            jsonOut(['ok' => true, 'project_id' => $pid]);
            break;

        case $uri === '/api/export/timester':
            [$from, $to, $pids] = rangeParams();
            $rounding = in_array((int) ($_GET['rounding'] ?? 0), [0, 15, 30], true) ? (int) ($_GET['rounding'] ?? 0) : 0;
            $payload = (new TimesterExport($api))->build($from, $to, $rounding, $pids);
            $fnFrom = preg_replace('/[^0-9-]/', '', (string) ($_GET['from'] ?? '')) ?: 'export';
            $fnTo = preg_replace('/[^0-9-]/', '', (string) ($_GET['to'] ?? ''));
            header('Content-Disposition: attachment; filename="worklog-' . $fnFrom . '_' . $fnTo . '.json"');
            jsonOut($payload);
            break;

        case $uri === '/api/status':
            jsonOut([
                'last_ingest_at' => (int) ($db->val("SELECT value FROM settings WHERE key = 'last_ingest_at'") ?? 0),
                'last_digest_at' => (int) ($db->val("SELECT value FROM settings WHERE key = 'last_digest_at'") ?? 0),
                'now' => time(),
            ]);
            break;

        // Re-read session data on demand (the "Read data" button / stale-on-load trigger).
        // Spawns the real CLI ingest — never runs it in-process — so ingest.php's cli-only
        // guard and the "web layer never touches sources/Ollama directly" rule both hold.
        // The command uses only server-side constants, so there is no injection surface.
        case $uri === '/api/ingest' && $_SERVER['REQUEST_METHOD'] === 'POST':
            $php = $config->phpCli();
            $ingest = $root . '/ingest.php';
            $lock = fopen($root . '/.ingest.lock', 'c');
            if ($lock === false) {
                jsonOut(['error' => 'cannot open ingest lock'], 500);
                break;
            }
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                fclose($lock);
                jsonOut(['ok' => true, 'running' => true]); // an ingest is already in progress
                break;
            }
            // Phase 1: fast source ingest, synchronous — time stats refresh right away.
            $out = [];
            $code = 1;
            exec(escapeshellarg($php) . ' ' . escapeshellarg($ingest) . ' --skip-digests 2>&1', $out, $code);
            flock($lock, LOCK_UN);
            fclose($lock);
            // Phase 2: Ollama digests detached in the background; surface on a later refresh.
            exec('nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($ingest)
                . ' --digests-only > ' . escapeshellarg($root . '/.ingest-digests.log') . ' 2>&1 &');
            jsonOut(['ok' => $code === 0, 'exit' => $code, 'message' => trim((string) end($out))]);
            break;

        default:
            jsonOut(['error' => 'not found'], 404);
    }
} catch (Throwable $e) {
    jsonOut(['error' => $e->getMessage()], 500);
}
