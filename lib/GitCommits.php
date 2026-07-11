<?php

/**
 * Collects git commits made during ingested session windows.
 * All authors count (agent commits are work product). Keyed by hash,
 * so re-ingesting never duplicates. Missing/moved repos are skipped.
 */
class GitCommits
{
    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /** @return array{commits:int, dirs:int} */
    public function ingest(): array
    {
        // One git log per directory covering all its session windows (padded
        // 30 min each side) is far cheaper than a log per session.
        $dirs = $this->db->q(
            'SELECT dir, MIN(started_at) AS min_t, MAX(ended_at) AS max_t
             FROM sessions GROUP BY dir'
        );
        $stats = ['commits' => 0, 'dirs' => 0];
        foreach ($dirs as $d) {
            if (!is_dir($d['dir'] . '/.git') && !$this->isGitDir($d['dir'])) {
                continue;
            }
            $stats['dirs']++;
            $stats['commits'] += $this->ingestDir($d['dir'], (int) $d['min_t'] - 1800, (int) $d['max_t'] + 1800);
        }
        return $stats;
    }

    private function isGitDir(string $dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        $out = $this->run($dir, ['git', 'rev-parse', '--is-inside-work-tree']);
        return trim($out ?? '') === 'true';
    }

    private function ingestDir(string $dir, int $since, int $until): int
    {
        $sep = "\x1f";
        $out = $this->run($dir, [
            'git', 'log', '--all', '--no-merges',
            '--since=' . date('c', $since), '--until=' . date('c', $until),
            '--pretty=format:' . implode($sep, ['%H', '%an', '%ct', '%s']),
            '--shortstat',
        ]);
        if ($out === null || trim($out) === '') {
            return 0;
        }
        $count = 0;
        $current = null;
        foreach (explode("\n", $out) as $line) {
            if (str_contains($line, $sep)) {
                if ($current !== null) {
                    $count += $this->save($dir, $current);
                }
                [$hash, $author, $ts, $subject] = explode($sep, $line, 4);
                $current = [
                    'hash' => $hash, 'author' => $author, 'ts' => (int) $ts,
                    'subject' => $subject, 'files' => null, 'add' => null, 'del' => null,
                ];
            } elseif ($current !== null && preg_match('/(\d+) files? changed/', $line, $m)) {
                $current['files'] = (int) $m[1];
                $current['add'] = preg_match('/(\d+) insertions?/', $line, $mi) ? (int) $mi[1] : 0;
                $current['del'] = preg_match('/(\d+) deletions?/', $line, $md) ? (int) $md[1] : 0;
            }
        }
        if ($current !== null) {
            $count += $this->save($dir, $current);
        }
        return $count;
    }

    private function save(string $dir, array $c): int
    {
        return $this->db->exec(
            'INSERT INTO commits (hash, dir, author, committed_at, message, files, additions, deletions)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)
             ON CONFLICT(hash) DO UPDATE SET
                files = excluded.files, additions = excluded.additions, deletions = excluded.deletions',
            [$c['hash'], $dir, $c['author'], $c['ts'], $c['subject'], $c['files'], $c['add'], $c['del']]
        );
    }

    private function run(string $dir, array $cmd): ?string
    {
        $proc = proc_open(
            $cmd,
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $dir
        );
        if (!is_resource($proc)) {
            return null;
        }
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return proc_close($proc) === 0 ? $out : null;
    }
}
