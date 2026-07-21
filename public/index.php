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
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
            $pid = (int) ($body['project_id'] ?? 0);
            $date = (string) ($body['date'] ?? '');
            if ($pid <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                jsonOut(['error' => 'project_id and date required'], 400);
                break;
            }
            $db->exec(
                'INSERT INTO digests (project_id, date, stale) VALUES (?, ?, 1)
                 ON CONFLICT(project_id, date) DO UPDATE SET stale = 1',
                [$pid, $date]
            );
            jsonOut(['ok' => true]);
            break;

        case $uri === '/api/mappings' && $_SERVER['REQUEST_METHOD'] === 'GET':
            jsonOut($db->q(
                'SELECT dm.dir, dm.auto, p.id AS project_id, p.name AS project, p.client, p.ignored
                 FROM dir_mappings dm JOIN projects p ON p.id = dm.project_id
                 ORDER BY p.ignored, p.name COLLATE NOCASE, dm.dir'
            ));
            break;

        case $uri === '/api/mappings' && $_SERVER['REQUEST_METHOD'] === 'POST':
            $body = json_decode(file_get_contents('php://input'), true) ?? [];
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

        default:
            jsonOut(['error' => 'not found'], 404);
    }
} catch (Throwable $e) {
    jsonOut(['error' => $e->getMessage()], 500);
}
