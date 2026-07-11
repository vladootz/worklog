<?php

/**
 * Read-side queries for the dashboard. Blocks are stored per session (ground
 * truth); per-project and wall-clock totals are computed here via interval
 * union, so overlap is never destroyed at ingest and never double-counted
 * in dedup views (PLAN.md decisions 2-3).
 */
class Api
{
    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /** @return array<string, array{id:int,name:string,client:?string,ignored:int,auto:int}> dir => project */
    public function dirProjectMap(): array
    {
        $map = [];
        $rows = $this->db->q(
            'SELECT dm.dir, dm.auto, p.id, p.name, p.client, p.ignored
             FROM dir_mappings dm JOIN projects p ON p.id = dm.project_id'
        );
        foreach ($rows as $r) {
            $map[$r['dir']] = [
                'id' => (int) $r['id'],
                'name' => $r['name'],
                'client' => $r['client'],
                'ignored' => (int) $r['ignored'],
                'auto' => (int) $r['auto'],
            ];
        }
        return $map;
    }

    /** Merge overlapping [start,end] intervals; returns total seconds. */
    public static function unionSeconds(array $intervals): int
    {
        if ($intervals === []) {
            return 0;
        }
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
        $total = 0;
        [$curStart, $curEnd] = $intervals[0];
        foreach ($intervals as [$s, $e]) {
            if ($s > $curEnd) {
                $total += $curEnd - $curStart;
                [$curStart, $curEnd] = [$s, $e];
            } elseif ($e > $curEnd) {
                $curEnd = $e;
            }
        }
        return $total + ($curEnd - $curStart);
    }

    /**
     * Blocks in range, clipped, resolved to projects, ignored projects dropped.
     * @return array<array{project_id:int,project:string,client:?string,auto:int,session_id:int,start:int,end:int}>
     */
    private function blocksInRange(int $from, int $to, array $projectIds = []): array
    {
        $map = $this->dirProjectMap();
        $rows = $this->db->q(
            'SELECT session_id, dir, start_at, end_at FROM blocks WHERE end_at > ? AND start_at < ?',
            [$from, $to]
        );
        $out = [];
        foreach ($rows as $r) {
            $p = $map[$r['dir']] ?? null;
            if ($p === null || $p['ignored']) {
                continue;
            }
            if ($projectIds !== [] && !in_array($p['id'], $projectIds, true)) {
                continue;
            }
            $out[] = [
                'project_id' => $p['id'],
                'project' => $p['name'],
                'client' => $p['client'],
                'auto' => $p['auto'],
                'session_id' => (int) $r['session_id'],
                'start' => max((int) $r['start_at'], $from),
                'end' => min((int) $r['end_at'], $to),
            ];
        }
        return $out;
    }

    public function summary(int $from, int $to, array $projectIds = []): array
    {
        $blocks = $this->blocksInRange($from, $to, $projectIds);
        $byProject = [];
        $all = [];
        foreach ($blocks as $b) {
            $byProject[$b['project_id']]['name'] = $b['project'];
            $byProject[$b['project_id']]['client'] = $b['client'];
            $byProject[$b['project_id']]['auto'] = $b['auto'];
            $byProject[$b['project_id']]['intervals'][] = [$b['start'], $b['end']];
            $all[] = [$b['start'], $b['end']];
        }
        $projects = [];
        $sumProjects = 0;
        foreach ($byProject as $pid => $p) {
            $secs = self::unionSeconds($p['intervals']);
            $sumProjects += $secs;
            $projects[] = [
                'id' => $pid,
                'name' => $p['name'],
                'client' => $p['client'],
                'auto' => $p['auto'],
                'seconds' => $secs,
            ];
        }
        usort($projects, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
        $wallClock = self::unionSeconds($all);
        return [
            'projects' => $projects,
            'total_project_seconds' => $sumProjects,
            'wall_clock_seconds' => $wallClock,
            'leverage' => $wallClock > 0 ? round($sumProjects / $wallClock, 2) : 0,
        ];
    }

    /**
     * Per project per local day: union seconds, sessions, digest.
     * Blocks are split at local midnight so a session crossing midnight
     * bills each day its true share.
     */
    public function days(int $from, int $to, array $projectIds = []): array
    {
        $blocks = $this->blocksInRange($from, $to, $projectIds);
        $cells = []; // "$pid|$date" => data
        foreach ($blocks as $b) {
            foreach (self::splitByDay($b['start'], $b['end']) as [$date, $s, $e]) {
                $key = $b['project_id'] . '|' . $date;
                $cells[$key]['project_id'] = $b['project_id'];
                $cells[$key]['project'] = $b['project'];
                $cells[$key]['client'] = $b['client'];
                $cells[$key]['auto'] = $b['auto'];
                $cells[$key]['date'] = $date;
                $cells[$key]['intervals'][] = [$s, $e];
                $cells[$key]['session_ids'][$b['session_id']] = true;
            }
        }
        $allSessionIds = [];
        foreach ($cells as $c) {
            $allSessionIds = array_merge($allSessionIds, array_keys($c['session_ids']));
        }
        $sessionInfo = $this->sessionInfo($allSessionIds);
        $digests = [];
        foreach ($this->db->q('SELECT project_id, date, text, stale FROM digests') as $d) {
            $digests[$d['project_id'] . '|' . $d['date']] = $d;
        }
        $rows = [];
        foreach ($cells as $key => $c) {
            $sessions = array_values(array_intersect_key($sessionInfo, $c['session_ids']));
            $commits = $this->commitsFor($c, $sessions);
            $rows[] = [
                'date' => $c['date'],
                'project_id' => $c['project_id'],
                'project' => $c['project'],
                'client' => $c['client'],
                'auto' => $c['auto'],
                'seconds' => self::unionSeconds($c['intervals']),
                'blocks' => self::mergedIntervals($c['intervals']),
                'sessions' => $sessions,
                'commits' => $commits,
                'digest' => $digests[$key]['text'] ?? null,
                'digest_stale' => (int) ($digests[$key]['stale'] ?? 0),
            ];
        }
        usort($rows, fn($a, $b) => [$b['date'], $b['seconds']] <=> [$a['date'], $a['seconds']]);
        return $rows;
    }

    private function commitsFor(array $cell, array $sessions): array
    {
        $dirs = array_values(array_unique(array_column($sessions, 'dir')));
        if ($dirs === []) {
            return [];
        }
        $dayStart = strtotime($cell['date'] . ' 00:00:00');
        $dayEnd = strtotime('+1 day', $dayStart); // DST-safe
        $ph = implode(',', array_fill(0, count($dirs), '?'));
        return $this->db->q(
            "SELECT hash, message, author, committed_at, files, additions, deletions
             FROM commits WHERE dir IN ($ph) AND committed_at >= ? AND committed_at < ?
             ORDER BY committed_at",
            [...$dirs, $dayStart, $dayEnd]
        );
    }

    /** @return array<int, array> session_id => info */
    private function sessionInfo(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $out = [];
        foreach ($this->db->q(
            "SELECT id, source, dir, title, first_prompt, started_at, ended_at, cost,
                    files_changed, additions, deletions
             FROM sessions WHERE id IN ($ph) ORDER BY started_at",
            $ids
        ) as $s) {
            $out[(int) $s['id']] = $s;
        }
        return $out;
    }

    public function timeseries(int $from, int $to, string $bucket, array $projectIds = []): array
    {
        $blocks = $this->blocksInRange($from, $to, $projectIds);
        $perDay = []; // "$pid|$date" => intervals
        $names = [];
        foreach ($blocks as $b) {
            $names[$b['project_id']] = $b['project'];
            foreach (self::splitByDay($b['start'], $b['end']) as [$date, $s, $e]) {
                $perDay[$b['project_id'] . '|' . $date]['intervals'][] = [$s, $e];
            }
        }
        $series = []; // pid => bucketKey => seconds (sum of daily unions)
        foreach ($perDay as $key => $data) {
            [$pid, $date] = explode('|', $key);
            $bk = self::bucketKey($date, $bucket);
            $series[$pid][$bk] = ($series[$pid][$bk] ?? 0) + self::unionSeconds($data['intervals']);
        }
        // A time axis must be continuous: emit every bucket in range, even empty ones.
        $bucketKeys = [];
        for ($ts = $from; $ts < $to; $ts += 86400) {
            $bucketKeys[self::bucketKey(date('Y-m-d', $ts), $bucket)] = true;
        }
        $bucketKeys = array_keys($bucketKeys);
        sort($bucketKeys);
        $out = [];
        foreach ($series as $pid => $values) {
            $out[] = ['id' => (int) $pid, 'name' => $names[$pid], 'values' => $values];
        }
        usort($out, fn($a, $b) => array_sum($b['values']) <=> array_sum($a['values']));
        return ['buckets' => $bucketKeys, 'series' => $out];
    }

    private static function bucketKey(string $date, string $bucket): string
    {
        $ts = strtotime($date);
        return match ($bucket) {
            'week' => date('o-\WW', $ts), // ISO week, Monday start
            'month' => date('Y-m', $ts),
            default => $date,
        };
    }

    /** @return array<array{0:string,1:int,2:int}> [localDate, start, end] pieces */
    public static function splitByDay(int $start, int $end): array
    {
        $out = [];
        $cur = $start;
        while ($cur < $end) {
            $dayEnd = strtotime('+1 day', strtotime(date('Y-m-d', $cur) . ' 00:00:00')); // DST-safe
            $pieceEnd = min($end, $dayEnd);
            $out[] = [date('Y-m-d', $cur), $cur, $pieceEnd];
            $cur = $pieceEnd;
        }
        if ($out === [] && $start === $end) {
            $out[] = [date('Y-m-d', $start), $start, $end];
        }
        return $out;
    }

    private static function mergedIntervals(array $intervals): array
    {
        usort($intervals, fn($a, $b) => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($intervals as [$s, $e]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $s <= $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }
        return $merged;
    }

    /** All projects with dirs, for filters and the mapping UI. */
    public function projects(): array
    {
        return $this->db->q(
            'SELECT p.id, p.name, p.client, p.ignored,
                    MIN(dm.auto) AS auto,
                    GROUP_CONCAT(dm.dir, CHAR(10)) AS dirs
             FROM projects p LEFT JOIN dir_mappings dm ON dm.project_id = p.id
             GROUP BY p.id ORDER BY p.name COLLATE NOCASE'
        );
    }
}
