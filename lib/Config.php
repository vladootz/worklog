<?php

class Config
{
    public string $home;
    public string $claudeProjectsDir;
    public string $opencodeDb;
    public string $dbPath;
    public string $machine;
    public int $gapMinutes = 15;
    public int $minBlockSeconds = 60;
    public int $port = 8111;
    public string $ollamaUrl = 'http://localhost:11434';
    public string $ollamaModel = 'qwen3:14b';
    // Must cover the dashboard's default range (last 30 days), or days 15-30 are
    // visible with no digest and the UI has nothing to show in "Delivered".
    // Widening is cheap at rest: Digests::run() hashes each project-day's inputs and
    // only calls Ollama on a miss, so this costs sha1s per pass, not model calls.
    public int $digestDays = 30;

    public function __construct()
    {
        $this->home = rtrim((string) (getenv('HOME') ?: '/Users/' . get_current_user()), '/');
        $this->claudeProjectsDir = $this->home . '/.claude/projects';
        $this->opencodeDb = $this->home . '/.local/share/opencode/opencode.db';
        $this->dbPath = dirname(__DIR__) . '/ai-worklog.db';
        $this->machine = php_uname('n');
        self::initTimezone();
    }

    /**
     * Absolute path to a CLI php binary the web layer can shell out to for ingest.
     * Under Herd/nginx the request runs on PHP-FPM whose PATH usually lacks `php`
     * (macOS ships none), so `exec('php ...')` would fail — resolve it explicitly.
     * Order: $WORKLOG_PHP → `php` on PATH → Herd's bundled CLI → Homebrew.
     */
    public function phpCli(): string
    {
        $env = getenv('WORKLOG_PHP');
        if ($env && is_executable($env)) {
            return $env;
        }
        $onPath = trim((string) @shell_exec('command -v php 2>/dev/null'));
        if ($onPath !== '' && is_executable($onPath)) {
            return $onPath;
        }
        $candidates = [
            $this->home . '/Library/Application Support/Herd/bin/php',
            '/opt/homebrew/bin/php',
            '/opt/homebrew/opt/php@8.2/bin/php',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];
        foreach ($candidates as $c) {
            if (is_executable($c)) {
                return $c;
            }
        }
        return 'php'; // last resort — let exec fail loudly if truly unavailable
    }

    public static function initTimezone(): void
    {
        // The system timezone must win over php.ini (Homebrew ships UTC),
        // otherwise late-night sessions land on the wrong billing day.
        $link = @readlink('/etc/localtime');
        if ($link !== false && preg_match('#zoneinfo/(.+)$#', $link, $m)) {
            date_default_timezone_set($m[1]);
            return;
        }
        if (!ini_get('date.timezone')) {
            date_default_timezone_set('UTC');
        }
    }
}
