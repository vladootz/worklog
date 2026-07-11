<?php

/**
 * Ingests Claude Code session transcripts from ~/.claude/projects.
 * A session = <uuid>.jsonl plus optional <uuid>/subagents/*.jsonl sidecars
 * (subagent activity counts toward the parent session).
 * Idempotent: a changed session is deleted and re-inserted wholesale.
 */
class ClaudeSource
{
    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /** @return array{sessions:int, skipped:int} */
    public function ingest(): array
    {
        $stats = ['sessions' => 0, 'skipped' => 0];
        foreach (glob($this->config->claudeProjectsDir . '/*', GLOB_ONLYDIR) ?: [] as $projectDir) {
            foreach (glob($projectDir . '/*.jsonl') ?: [] as $mainFile) {
                $uuid = basename($mainFile, '.jsonl');
                $files = array_merge(
                    [$mainFile],
                    glob($projectDir . '/' . $uuid . '/subagents/*.jsonl') ?: []
                );
                if (!$this->filesChanged($files) && $this->sessionExists($uuid)) {
                    $stats['skipped']++;
                    continue;
                }
                $this->ingestSession($uuid, $files, $projectDir);
                $this->rememberFiles($files);
                $stats['sessions']++;
            }
        }
        return $stats;
    }

    private function sessionExists(string $uuid): bool
    {
        return $this->db->val(
            'SELECT COUNT(*) FROM sessions WHERE source = ? AND source_session_id = ? AND machine = ?',
            ['claude', $uuid, $this->config->machine]
        ) > 0;
    }

    private function filesChanged(array $files): bool
    {
        foreach ($files as $file) {
            $stat = @stat($file);
            if ($stat === false) {
                continue;
            }
            $row = $this->db->one('SELECT mtime, size FROM ingest_state WHERE path = ?', [$file]);
            if ($row === null || (int) $row['mtime'] !== $stat['mtime'] || (int) $row['size'] !== $stat['size']) {
                return true;
            }
        }
        return false;
    }

    private function rememberFiles(array $files): void
    {
        foreach ($files as $file) {
            $stat = @stat($file);
            if ($stat === false) {
                continue;
            }
            $this->db->exec(
                'INSERT INTO ingest_state (path, mtime, size) VALUES (?, ?, ?)
                 ON CONFLICT(path) DO UPDATE SET mtime = excluded.mtime, size = excluded.size',
                [$file, $stat['mtime'], $stat['size']]
            );
        }
    }

    private function ingestSession(string $uuid, array $files, string $projectDir): void
    {
        $events = [];
        $cwd = null;
        $title = null;
        $firstPrompt = null;

        foreach ($files as $file) {
            $handle = @fopen($file, 'r');
            if ($handle === false) {
                continue;
            }
            while (($line = fgets($handle)) !== false) {
                $j = json_decode($line, true);
                if (!is_array($j)) {
                    continue;
                }
                if (isset($j['timestamp']) && is_string($j['timestamp'])) {
                    $ts = strtotime($j['timestamp']);
                    if ($ts !== false) {
                        $events[] = $ts;
                    }
                }
                if ($cwd === null && isset($j['cwd']) && is_string($j['cwd'])) {
                    $cwd = $j['cwd'];
                }
                if (($j['type'] ?? '') === 'ai-title' && !empty($j['aiTitle'])) {
                    $title = $j['aiTitle'];
                }
                if ($firstPrompt === null && ($j['type'] ?? '') === 'user' && $file === $files[0]) {
                    $content = $j['message']['content'] ?? null;
                    if (is_array($content)) {
                        foreach ($content as $part) {
                            if (($part['type'] ?? '') === 'text' && is_string($part['text'] ?? null)) {
                                $content = $part['text'];
                                break;
                            }
                        }
                    }
                    if (is_string($content) && $content !== '' && $content[0] !== '<') {
                        $firstPrompt = mb_substr(trim($content), 0, 500);
                    }
                }
            }
            fclose($handle);
        }

        if ($events === []) {
            return; // nothing datable — skip empty transcripts
        }
        sort($events);

        // cwd is authoritative; fall back to decoding the dir slug (dashes → slashes).
        $dir = $cwd ?? str_replace('-', '/', basename($projectDir));

        $this->db->exec(
            'DELETE FROM sessions WHERE source = ? AND source_session_id = ? AND machine = ?',
            ['claude', $uuid, $this->config->machine]
        );
        $this->db->exec(
            'INSERT INTO sessions (source, source_session_id, machine, dir, title, first_prompt, started_at, ended_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'claude',
                $uuid,
                $this->config->machine,
                $dir,
                $title ?? ($firstPrompt !== null ? mb_substr($firstPrompt, 0, 80) : null),
                $firstPrompt,
                $events[0],
                $events[count($events) - 1],
            ]
        );
        $sessionId = (int) $this->db->pdo->lastInsertId();
        $this->db->projectIdForDir($dir);
        $this->insertBlocks($sessionId, $dir, $events);
    }

    private function insertBlocks(int $sessionId, string $dir, array $sortedEvents): void
    {
        if (!class_exists('Coalesce')) {
            return; // wired in at the coalescing step
        }
        foreach (Coalesce::blocks($sortedEvents, $this->config->gapMinutes, $this->config->minBlockSeconds) as $b) {
            $this->db->exec(
                'INSERT INTO blocks (session_id, dir, start_at, end_at, event_count, gap_threshold_min)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [$sessionId, $dir, $b['start'], $b['end'], $b['count'], $this->config->gapMinutes]
            );
        }
    }
}
