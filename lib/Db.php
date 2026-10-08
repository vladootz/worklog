<?php

class Db
{
    public PDO $pdo;

    public function __construct(string $path)
    {
        $this->pdo = new PDO('sqlite:' . $path);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
        // Web-triggered ingest can overlap the background digest run; make writers
        // wait for the lock (up to 5s) instead of failing with "database is locked".
        $this->pdo->exec('PRAGMA busy_timeout = 5000');
        $this->migrate();
    }

    private function migrate(): void
    {
        $this->pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS projects (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    client TEXT,
    ignored INTEGER NOT NULL DEFAULT 0,
    created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS dir_mappings (
    dir TEXT PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    auto INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE IF NOT EXISTS sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL,
    source_session_id TEXT NOT NULL,
    machine TEXT NOT NULL,
    dir TEXT NOT NULL,
    title TEXT,
    first_prompt TEXT,
    started_at INTEGER,
    ended_at INTEGER,
    cost REAL,
    tokens_in INTEGER,
    tokens_out INTEGER,
    files_changed INTEGER,
    additions INTEGER,
    deletions INTEGER,
    UNIQUE(source, source_session_id, machine)
);
CREATE TABLE IF NOT EXISTS blocks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    session_id INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    dir TEXT NOT NULL,
    start_at INTEGER NOT NULL,
    end_at INTEGER NOT NULL,
    event_count INTEGER NOT NULL,
    gap_threshold_min INTEGER NOT NULL,
    kind TEXT NOT NULL DEFAULT 'core',
    idle_threshold_min INTEGER,
    bridge_max_min INTEGER
);
CREATE INDEX IF NOT EXISTS idx_blocks_time ON blocks(start_at);
CREATE INDEX IF NOT EXISTS idx_blocks_session ON blocks(session_id);
CREATE TABLE IF NOT EXISTS events (
    session_id INTEGER NOT NULL REFERENCES sessions(id) ON DELETE CASCADE,
    ts INTEGER NOT NULL,
    is_prompt INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (session_id, ts, is_prompt)
) WITHOUT ROWID;
CREATE TABLE IF NOT EXISTS presence_samples (
    ts INTEGER PRIMARY KEY,
    idle INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS commits (
    hash TEXT PRIMARY KEY,
    dir TEXT NOT NULL,
    author TEXT,
    committed_at INTEGER NOT NULL,
    message TEXT,
    files INTEGER,
    additions INTEGER,
    deletions INTEGER
);
CREATE INDEX IF NOT EXISTS idx_commits_time ON commits(committed_at);
CREATE TABLE IF NOT EXISTS digests (
    project_id INTEGER NOT NULL REFERENCES projects(id),
    date TEXT NOT NULL,
    text TEXT,
    model TEXT,
    inputs_hash TEXT,
    stale INTEGER NOT NULL DEFAULT 0,
    generated_at INTEGER,
    PRIMARY KEY (project_id, date)
);
CREATE TABLE IF NOT EXISTS ingest_state (
    path TEXT PRIMARY KEY,
    mtime INTEGER NOT NULL,
    size INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT
);
SQL);
        // CREATE TABLE IF NOT EXISTS never alters an existing table; add columns
        // introduced after a DB was first created.
        $blockCols = array_column($this->q('PRAGMA table_info(blocks)'), 'name');
        foreach ([
            'kind' => "TEXT NOT NULL DEFAULT 'core'",
            'idle_threshold_min' => 'INTEGER',
            'bridge_max_min' => 'INTEGER',
        ] as $col => $type) {
            if (!in_array($col, $blockCols, true)) {
                $this->pdo->exec("ALTER TABLE blocks ADD COLUMN $col $type");
            }
        }
    }

    public function q(string $sql, array $params = []): array
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    public function exec(string $sql, array $params = []): int
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public function one(string $sql, array $params = []): ?array
    {
        $rows = $this->q($sql, $params);
        return $rows[0] ?? null;
    }

    public function val(string $sql, array $params = [])
    {
        $row = $this->one($sql, $params);
        return $row === null ? null : array_values($row)[0];
    }

    /** Project id for a dir: existing mapping, else auto-create project named after basename. */
    public function projectIdForDir(string $dir): int
    {
        $id = $this->val('SELECT project_id FROM dir_mappings WHERE dir = ?', [$dir]);
        if ($id !== null) {
            return (int) $id;
        }
        $name = basename($dir) !== '' ? basename($dir) : $dir;
        $pid = $this->val('SELECT id FROM projects WHERE name = ?', [$name]);
        if ($pid === null) {
            $this->exec('INSERT INTO projects (name, created_at) VALUES (?, ?)', [$name, time()]);
            $pid = $this->pdo->lastInsertId();
        }
        $this->exec('INSERT INTO dir_mappings (dir, project_id, auto) VALUES (?, ?, 1)', [$dir, $pid]);
        return (int) $pid;
    }
}
