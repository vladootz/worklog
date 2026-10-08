<?php

/**
 * Keyboard/mouse presence from bin/presence samples ("<epoch> <idle_seconds>", one a
 * minute). A sample (t, idle) means there was no input in [t - idle, t]. Where samples
 * are missing — sampler not installed, Mac asleep — presence is unknown and callers
 * fall back to the plain gap rule.
 */
class Presence
{
    /** @var int[] sample times, ascending */
    private array $times;
    /** @var array<array{0:int,1:int}> away intervals, ascending, non-overlapping */
    private array $away = [];

    /** @param array<array{ts:int|string, idle:int|string}> $rows ordered by ts */
    private function __construct(array $rows, int $idleSeconds, private int $maxGap)
    {
        $this->times = array_map(fn($r) => (int) $r['ts'], $rows);
        $stretches = array_map(fn($r) => [(int) $r['ts'] - (int) $r['idle'], (int) $r['ts']], $rows);
        usort($stretches, fn($x, $y) => $x[0] <=> $y[0]);
        $merged = [];
        foreach ($stretches as [$s, $e]) {
            $last = count($merged) - 1;
            if ($last >= 0 && $s < $merged[$last][1]) {
                $merged[$last][1] = max($merged[$last][1], $e);
            } else {
                $merged[] = [$s, $e];
            }
        }
        // The first $idleSeconds of every stretch still count: reading is work.
        foreach ($merged as [$s, $e]) {
            if ($e - $s > $idleSeconds) {
                $this->away[] = [$s + $idleSeconds, $e];
            }
        }
    }

    /** Samples that can say anything about [$from, $to]. */
    public static function load(Db $db, Config $config, int $from, int $to): self
    {
        $maxGap = $config->presenceMaxSampleGapSeconds;
        return new self(
            $db->q(
                'SELECT ts, idle FROM presence_samples WHERE ts >= ? AND ts <= ? ORDER BY ts',
                [$from - $maxGap, $to + $maxGap]
            ),
            $config->presenceIdleMinutes * 60,
            $maxGap
        );
    }

    public function hasSamples(): bool
    {
        return $this->times !== [];
    }

    /** True when samples run unbroken — no two more than maxGap apart — across [$a, $b]. */
    public function covered(int $a, int $b): bool
    {
        $cur = null;
        for ($i = $this->firstAtOrAfter($a - $this->maxGap), $n = count($this->times); $i < $n; $i++) {
            $ts = $this->times[$i];
            if ($ts - ($cur ?? $a) > $this->maxGap) {
                return false;
            }
            $cur = $ts;
            if ($cur >= $b) {
                return true;
            }
        }
        return $cur !== null && $b - $cur <= $this->maxGap;
    }

    /** @return array<array{0:int,1:int}> away intervals clipped to [$a, $b] */
    public function away(int $a, int $b): array
    {
        $out = [];
        foreach ($this->away as [$s, $e]) {
            if ($e <= $a) {
                continue;
            }
            if ($s >= $b) {
                break;
            }
            $out[] = [max($s, $a), min($e, $b)];
        }
        return $out;
    }

    private function firstAtOrAfter(int $t): int
    {
        [$lo, $hi] = [0, count($this->times)];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->times[$mid] < $t) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo;
    }

    /**
     * Copy complete new lines of presence.log into presence_samples, from the byte
     * offset the last run stopped at; a zone column feeds Zones::record(). A shorter file than that offset was replaced
     * or truncated, so it is read from the start (re-inserts are ignored).
     * @return array{new:int, total:int, newest:?int}
     */
    public static function ingestLog(Db $db, Config $config): array
    {
        $size = @filesize($config->presenceLog);
        $handle = $size === false ? false : @fopen($config->presenceLog, 'r');
        if ($handle !== false) {
            $offset = (int) ($db->val("SELECT value FROM settings WHERE key = 'presence_offset'") ?? 0);
            if ($size < $offset) {
                $offset = 0;
            }
            fseek($handle, $offset);
            $insert = $db->pdo->prepare('INSERT OR IGNORE INTO presence_samples (ts, idle) VALUES (?, ?)');
            $new = 0;
            $lastZone = null;
            while (($line = fgets($handle)) !== false) {
                if (!str_ends_with($line, "\n")) {
                    break; // the sampler is mid-write; this line is read next run
                }
                $offset += strlen($line);
                // "<epoch> <idle>" or, since the sampler logs the zone, "<epoch> <idle> <Area/City>".
                if (preg_match('/^(\d+) (\d+)(?: (\S+))?$/', rtrim($line), $m)) {
                    $insert->execute([(int) $m[1], (int) $m[2]]);
                    $new += $insert->rowCount();
                    if (isset($m[3]) && $m[3] !== $lastZone) {
                        Zones::record($db, (int) $m[1], $m[3]);
                        $lastZone = $m[3];
                    }
                }
            }
            fclose($handle);
            $db->exec(
                "INSERT INTO settings (key, value) VALUES ('presence_offset', ?)
                 ON CONFLICT(key) DO UPDATE SET value = excluded.value",
                [(string) $offset]
            );
        }
        $stats = $db->one('SELECT COUNT(*) AS total, MAX(ts) AS newest FROM presence_samples');
        return [
            'new' => $new ?? 0,
            'total' => (int) $stats['total'],
            'newest' => $stats['newest'] === null ? null : (int) $stats['newest'],
        ];
    }
}
