<?php

/**
 * Read-side queries for the dashboard. Blocks are stored per session (ground
 * truth); per-project and wall-clock totals are computed here via interval
 * union, so overlap is never destroyed at ingest and never double-counted
 * in dedup views (PLAN.md decisions 2-3).
 */
class Api
{
    private ?Zones $zones = null;

    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /** Every moment → calendar day conversion goes through this (CLAUDE.md invariant 7). */
    public function zones(): Zones
    {
        return $this->zones ??= new Zones($this->db);
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
     * 'fill' blocks (gap time credited from presence alone) lose every second in which
     * another project — ignored ones included — had a 'core' block: your attention was
     * there, so the gap is not billed twice. Done before the project filter, so a
     * filtered view still cuts against projects it does not show.
     * @return array<array{project_id:int,project:string,client:?string,auto:int,session_id:int,start:int,end:int}>
     */
    private function blocksInRange(int $from, int $to, array $projectIds = []): array
    {
        $map = $this->dirProjectMap();
        $rows = $this->db->q(
            'SELECT session_id, dir, start_at, end_at, kind FROM blocks WHERE end_at > ? AND start_at < ?',
            [$from, $to]
        );
        $cores = [];
        foreach ($rows as $r) {
            if ($r['kind'] === 'core') {
                $cores[] = [$map[$r['dir']]['id'] ?? 'dir:' . $r['dir'], (int) $r['start_at'], (int) $r['end_at']];
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $p = $map[$r['dir']] ?? null;
            if ($p === null || $p['ignored']) {
                continue;
            }
            if ($projectIds !== [] && !in_array($p['id'], $projectIds, true)) {
                continue;
            }
            [$start, $end] = [(int) $r['start_at'], (int) $r['end_at']];
            $pieces = [[$start, $end]];
            if ($r['kind'] === 'fill') {
                $cuts = [];
                foreach ($cores as [$owner, $s, $e]) {
                    if ($owner !== $p['id'] && $e > $start && $s < $end) {
                        $cuts[] = [$s, $e];
                    }
                }
                $pieces = Coalesce::subtract($start, $end, self::mergedIntervals($cuts));
            }
            foreach ($pieces as [$s, $e]) {
                if ($e <= $from || $s >= $to) {
                    continue;
                }
                $out[] = [
                    'project_id' => $p['id'],
                    'project' => $p['name'],
                    'client' => $p['client'],
                    'auto' => $p['auto'],
                    'session_id' => (int) $r['session_id'],
                    'start' => max($s, $from),
                    'end' => min($e, $to),
                ];
            }
        }
        return $out;
    }

    /** First date with tracked time (where you were), or null when nothing is tracked. */
    public function firstDay(): ?string
    {
        $first = $this->db->val('SELECT MIN(start_at) FROM blocks');
        return $first === null ? null : $this->zones()->dateOf((int) $first);
    }

    /**
     * Projects worked on in fixed windows ending today (where you are now), for the
     * landing page. Independent of the dashboard's range and project filter.
     * A month is 30 days, matching the "Last 30 days" preset.
     */
    public function windows(): array
    {
        $today = $this->zones()->dateOf(time());
        $out = [];
        foreach (['week' => 7, 'month' => 30, '3m' => 91, '6m' => 182, '1y' => 365, '2y' => 730] as $key => $days) {
            $from = Zones::addDays($today, 1 - $days);
            $s = $this->summary($this->zones()->dayStart($from), $this->zones()->dayEnd($today));
            $out[] = [
                'key' => $key,
                'from' => $from,
                'to' => $today,
                'projects' => array_map(
                    fn($p) => ['name' => $p['name'], 'client' => $p['client'], 'seconds' => $p['seconds']],
                    $s['projects']
                ),
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
            foreach ($this->zones()->splitByDay($b['start'], $b['end']) as [$date, $s, $e]) {
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
        // Bounded to the requested days — the table holds the whole archive.
        foreach ($this->db->q(
            'SELECT project_id, date, text, stale FROM digests WHERE date >= ? AND date <= ?',
            [$this->zones()->dateOf($from), $this->zones()->dateOf($to - 1)]
        ) as $d) {
            $digests[$d['project_id'] . '|' . $d['date']] = $d;
        }
        // One query for the whole range instead of one per project-day cell;
        // the day filter is applied per cell from the grouped result.
        $commitsByDirDay = [];
        $allDirs = [];
        foreach ($sessionInfo as $s) {
            $allDirs[$s['dir']] = true;
        }
        if ($allDirs !== []) {
            $ph = implode(',', array_fill(0, count($allDirs), '?'));
            foreach ($this->db->q(
                "SELECT dir, hash, message, author, committed_at, files, additions, deletions
                 FROM commits WHERE dir IN ($ph) AND committed_at >= ? AND committed_at < ?
                 ORDER BY committed_at",
                [...array_keys($allDirs), $from, $to]
            ) as $c) {
                $dir = $c['dir'];
                unset($c['dir']); // keep the row shape identical to the old per-cell query
                $commitsByDirDay[$dir][$this->zones()->dateOf((int) $c['committed_at'])][] = $c;
            }
        }
        $rows = [];
        foreach ($cells as $key => $c) {
            $sessions = array_values(array_intersect_key($sessionInfo, $c['session_ids']));
            $commits = [];
            $dirs = [];
            foreach ($sessions as $s) {
                $dirs[$s['dir']] = true;
            }
            foreach (array_keys($dirs) as $dir) {
                foreach ($commitsByDirDay[$dir][$c['date']] ?? [] as $c2) {
                    $commits[] = $c2;
                }
            }
            usort($commits, fn($a, $b) => $a['committed_at'] <=> $b['committed_at']);
            $blocks = self::mergedIntervals($c['intervals']);
            $rows[] = [
                'date' => $c['date'],
                'project_id' => $c['project_id'],
                'project' => $c['project'],
                'client' => $c['client'],
                'auto' => $c['auto'],
                'seconds' => self::unionSeconds($c['intervals']),
                'blocks' => $blocks,
                'tz' => $this->zones()->nameAt($blocks[0][0]), // for showing block times as that day's clock
                'sessions' => $sessions,
                'commits' => $commits,
                'digest' => $digests[$key]['text'] ?? null,
                'digest_stale' => (int) ($digests[$key]['stale'] ?? 0),
            ];
        }
        usort($rows, fn($a, $b) => [$b['date'], $b['seconds']] <=> [$a['date'], $a['seconds']]);
        return $rows;
    }

    /** Digest rows for a day range, for the UI's cheap background poll. */
    public function digestsRange(int $from, int $to): array
    {
        return $this->db->q(
            'SELECT project_id, date, text, stale FROM digests WHERE date >= ? AND date <= ?',
            [$this->zones()->dateOf($from), $this->zones()->dateOf($to - 1)]
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
            // first_prompt is only the UI's fallback for a missing title, and the UI
            // shows at most 110 chars of it — shipping whole prompts in every day row
            // put ~85 KB of dead weight in a 30-day /api/days response.
            if ($s['first_prompt'] !== null) {
                $s['first_prompt'] = self::truncate((string) $s['first_prompt'], 160);
            }
            $out[(int) $s['id']] = $s;
        }
        return $out;
    }

    private static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max);
    }

    public function timeseries(int $from, int $to, string $bucket, array $projectIds = []): array
    {
        $blocks = $this->blocksInRange($from, $to, $projectIds);
        $perDay = []; // "$pid|$date" => intervals
        $names = [];
        foreach ($blocks as $b) {
            $names[$b['project_id']] = $b['project'];
            foreach ($this->zones()->splitByDay($b['start'], $b['end']) as [$date, $s, $e]) {
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
        // Step through dates, not seconds: days are 19–28 h long around DST and travel.
        $bucketKeys = [];
        $last = $this->zones()->dateOf($to - 1);
        for ($d = $this->zones()->dateOf($from); $d <= $last; $d = Zones::addDays($d, 1)) {
            $bucketKeys[self::bucketKey($d, $bucket)] = true;
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
