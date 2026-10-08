<?php

/**
 * Ingests opencode sessions from its SQLite database, opened strictly read-only
 * (falls back to reading a temp copy if the live DB can't be opened while locked).
 * Child sessions (parent_id set) are opencode subagents: their events and cost
 * fold into the top-level parent session, mirroring how Claude subagents are handled.
 * Incremental via a time_updated watermark; changed sessions are delete+reinserted.
 */
class OpencodeSource
{
    private ?SQLite3 $src = null;

    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /** @return array{sessions:int, skipped:int} */
    public function ingest(): array
    {
        if (!is_file($this->config->opencodeDb)) {
            return ['sessions' => 0, 'skipped' => 0];
        }
        $this->src = $this->openReadOnly();
        $watermark = (int) ($this->db->val("SELECT value FROM settings WHERE key = 'opencode_watermark'") ?? 0);

        // Top-level sessions whose own or children's updates passed the watermark.
        $rows = $this->srcQuery(
            'SELECT s.*, COALESCE((SELECT MAX(c.time_updated) FROM session c WHERE c.parent_id = s.id), 0) AS child_updated
             FROM session s WHERE s.parent_id IS NULL'
        );
        $stats = ['sessions' => 0, 'skipped' => 0];
        $newWatermark = $watermark;
        foreach ($rows as $row) {
            $effective = max((int) $row['time_updated'], (int) $row['child_updated']);
            if ($effective <= $watermark && $this->exists($row['id'])) {
                $stats['skipped']++;
                continue;
            }
            $this->ingestSession($row);
            $stats['sessions']++;
            $newWatermark = max($newWatermark, $effective);
        }
        $this->db->exec(
            "INSERT INTO settings (key, value) VALUES ('opencode_watermark', ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value",
            [(string) $newWatermark]
        );
        $this->src->close();
        return $stats;
    }

    private function openReadOnly(): SQLite3
    {
        try {
            $src = new SQLite3($this->config->opencodeDb, SQLITE3_OPEN_READONLY);
            $src->enableExceptions(true); // warning mode deprecated since 8.3; lazy WAL failures must throw so the snapshot fallback engages
            $src->busyTimeout(3000);
            $src->querySingle('SELECT COUNT(*) FROM session'); // probe: WAL readonly can fail lazily
            return $src;
        } catch (\Throwable $e) {
            // Live DB locked/unreadable: read a snapshot copy instead (still never writes source).
            $tmp = sys_get_temp_dir() . '/ai-worklog-opencode-copy.db';
            copy($this->config->opencodeDb, $tmp);
            foreach (['-wal', '-shm'] as $suffix) {
                if (is_file($this->config->opencodeDb . $suffix)) {
                    copy($this->config->opencodeDb . $suffix, $tmp . $suffix);
                }
            }
            return new SQLite3($tmp, SQLITE3_OPEN_READONLY);
        }
    }

    private function srcQuery(string $sql, array $params = []): array
    {
        $st = $this->src->prepare($sql);
        foreach ($params as $i => $p) {
            $st->bindValue($i + 1, $p);
        }
        $res = $st->execute();
        $rows = [];
        while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            $rows[] = $row;
        }
        return $rows;
    }

    private function exists(string $sourceId): bool
    {
        return $this->db->val(
            'SELECT COUNT(*) FROM sessions WHERE source = ? AND source_session_id = ? AND machine = ?',
            ['opencode', $sourceId, $this->config->machine]
        ) > 0;
    }

    private function ingestSession(array $s): void
    {
        $ids = [$s['id']];
        $cost = (float) $s['cost'];
        $tokensIn = (int) $s['tokens_input'];
        $tokensOut = (int) $s['tokens_output'];
        foreach ($this->srcQuery('SELECT id, cost, tokens_input, tokens_output FROM session WHERE parent_id = ?', [$s['id']]) as $child) {
            $ids[] = $child['id'];
            $cost += (float) $child['cost'];
            $tokensIn += (int) $child['tokens_input'];
            $tokensOut += (int) $child['tokens_output'];
        }

        $events = [];
        $prompts = [];
        foreach ($ids as $sid) {
            foreach ($this->srcQuery(
                "SELECT time_created, time_updated, json_extract(data, '$.role') AS role FROM message WHERE session_id = ?",
                [$sid]
            ) as $m) {
                $events[] = intdiv((int) $m['time_created'], 1000);
                $events[] = intdiv((int) $m['time_updated'], 1000);
                // Child sessions are subagents: their user messages come from the agent.
                if ($sid === $s['id'] && $m['role'] === 'user') {
                    $prompts[] = intdiv((int) $m['time_created'], 1000);
                }
            }
        }
        if ($events === []) {
            $events[] = intdiv((int) $s['time_created'], 1000);
            $events[] = intdiv((int) $s['time_updated'], 1000);
        }
        sort($events);

        $firstPrompt = $this->srcQuery(
            "SELECT p.data FROM message m JOIN part p ON p.message_id = m.id
             WHERE m.session_id = ? AND json_extract(m.data, '$.role') = 'user'
               AND json_extract(p.data, '$.type') = 'text'
             ORDER BY m.time_created, p.id LIMIT 1",
            [$s['id']]
        );
        $promptText = null;
        if ($firstPrompt !== []) {
            $text = json_decode($firstPrompt[0]['data'], true)['text'] ?? null;
            if (is_string($text) && $text !== '') {
                $promptText = mb_substr(trim($text), 0, 500);
            }
        }

        $this->db->exec(
            'DELETE FROM sessions WHERE source = ? AND source_session_id = ? AND machine = ?',
            ['opencode', $s['id'], $this->config->machine]
        );
        $this->db->exec(
            'INSERT INTO sessions (source, source_session_id, machine, dir, title, first_prompt, started_at, ended_at,
                                   cost, tokens_in, tokens_out, files_changed, additions, deletions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                'opencode',
                $s['id'],
                $this->config->machine,
                $s['directory'],
                $s['title'] ?: $promptText,
                $promptText,
                $events[0],
                $events[count($events) - 1],
                $cost,
                $tokensIn,
                $tokensOut,
                $s['summary_files'],
                $s['summary_additions'],
                $s['summary_deletions'],
            ]
        );
        $sessionId = (int) $this->db->pdo->lastInsertId();
        $this->db->projectIdForDir($s['directory']);
        (new Timeline($this->db, $this->config))->store($sessionId, $s['directory'], $events, $prompts);
    }
}
