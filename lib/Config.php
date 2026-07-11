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
    public string $ollamaModel = 'qwen2.5-coder:7b';
    public int $digestDays = 14;

    public function __construct()
    {
        $this->home = rtrim((string) (getenv('HOME') ?: '/Users/' . get_current_user()), '/');
        $this->claudeProjectsDir = $this->home . '/.claude/projects';
        $this->opencodeDb = $this->home . '/.local/share/opencode/opencode.db';
        $this->dbPath = dirname(__DIR__) . '/ai-worklog.db';
        $this->machine = php_uname('n');
        self::initTimezone();
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
