<?php

/**
 * Which calendar day a moment belongs to: its local date in the time zone you were in
 * at that moment. The `zones` table holds change points (since → tz), recorded as you
 * travel (bin/presence each minute, every ingest) or set with `ingest.php --zone`.
 * Once a moment's zone is recorded its day never moves, wherever the dashboard or an
 * export runs later. With nothing recorded, the system zone applies (the old behaviour).
 */
class Zones
{
    /** @var int[] change points, ascending */
    private array $since = [];
    /** @var DateTimeZone[] */
    private array $zones = [];
    private bool $recorded;

    public function __construct(Db $db)
    {
        foreach ($db->q('SELECT since, tz FROM zones ORDER BY since') as $r) {
            $this->since[] = (int) $r['since'];
            $this->zones[] = new DateTimeZone($r['tz']);
        }
        $this->recorded = $this->since !== [];
        if (!$this->recorded) {
            $this->since[] = PHP_INT_MIN;
            $this->zones[] = new DateTimeZone(date_default_timezone_get());
        }
    }

    /** A moment before the first change point takes the first recorded zone. */
    public function zoneAt(int $t): DateTimeZone
    {
        return $this->zones[max(0, $this->lastAtOrBefore($t))];
    }

    public function dateOf(int $t): string
    {
        return (new DateTimeImmutable('@' . $t))->setTimezone($this->zoneAt($t))->format('Y-m-d');
    }

    /**
     * First moment of a date. At a zone change a day can be shorter or longer than 24 h,
     * like a DST day: flying east cuts the last day short, flying west stretches it.
     */
    public function dayStart(string $date): int
    {
        $candidates = [];
        $names = [];
        foreach ($this->zones as $z) {
            $names[$z->getName()] = $z;
        }
        foreach ($names as $z) {
            $candidates[] = (new DateTimeImmutable($date . ' 00:00:00', $z))->getTimestamp();
        }
        // A day can also begin exactly at a change point.
        array_push($candidates, ...array_filter($this->since, fn($s) => $s !== PHP_INT_MIN));
        $best = null;
        foreach ($candidates as $c) {
            if (($best === null || $c < $best) && $this->dateOf($c) === $date) {
                $best = $c;
            }
        }
        // No moment has this date (a jump of more than a day): fall back to plain midnight.
        return $best ?? (new DateTimeImmutable($date . ' 00:00:00', $this->zoneAt(strtotime($date . ' 12:00:00 UTC'))))->getTimestamp();
    }

    /** First moment after a date: the start of the next one. */
    public function dayEnd(string $date): int
    {
        return $this->dayStart(self::addDays($date, 1));
    }

    /**
     * Split [$start, $end] at every local midnight and zone change.
     * @return array<array{0:string,1:int,2:int}> [localDate, start, end] pieces
     */
    public function splitByDay(int $start, int $end): array
    {
        $out = [];
        $cur = $start;
        while ($cur < $end) {
            $local = (new DateTimeImmutable('@' . $cur))->setTimezone($this->zoneAt($cur));
            $midnight = $local->setTime(0, 0)->modify('+1 day')->getTimestamp(); // DST-safe
            $i = $this->lastAtOrBefore($cur) + 1;
            $change = $this->since[$i] ?? PHP_INT_MAX;
            $pieceEnd = min($end, $midnight, $change);
            $out[] = [$local->format('Y-m-d'), $cur, $pieceEnd];
            $cur = $pieceEnd;
        }
        if ($out === [] && $start === $end) {
            $out[] = [$this->dateOf($start), $start, $end];
        }
        return $out;
    }

    public function nameAt(int $t): string
    {
        return $this->zoneAt($t)->getName();
    }

    /**
     * Note that the Mac was in $tz at $t. Only a change is stored. Flying west can put the
     * new zone's date behind the old one; the old zone then stays until the new zone's
     * clock reaches that date, so dates never run backwards.
     */
    public static function record(Db $db, int $t, string $tz): bool
    {
        if (!self::valid($tz)) {
            return false;
        }
        $zones = new self($db);
        if ($zones->recorded && $zones->nameAt($t) === $tz) {
            return false;
        }
        $new = new DateTimeZone($tz);
        $oldDate = $zones->dateOf($t);
        if ($zones->recorded && (new DateTimeImmutable('@' . $t))->setTimezone($new)->format('Y-m-d') < $oldDate) {
            $t = (new DateTimeImmutable($oldDate . ' 00:00:00', $new))->getTimestamp();
        }
        $db->exec('INSERT OR REPLACE INTO zones (since, tz) VALUES (?, ?)', [$t, $tz]);
        return true;
    }

    /** You were in $tz from local midnight of $date on (`ingest.php --zone`). */
    public static function setFrom(Db $db, string $date, string $tz): int
    {
        $since = (new DateTimeImmutable($date . ' 00:00:00', new DateTimeZone($tz)))->getTimestamp();
        $db->exec('INSERT OR REPLACE INTO zones (since, tz) VALUES (?, ?)', [$since, $tz]);
        return $since;
    }

    public static function valid(string $tz): bool
    {
        return in_array($tz, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true);
    }

    /** Calendar arithmetic on a Y-m-d date, no zone involved. */
    public static function addDays(string $date, int $days): string
    {
        return (new DateTimeImmutable($date . ' 12:00:00', new DateTimeZone('UTC')))->modify(sprintf('%+d day', $days))->format('Y-m-d');
    }

    /** Index of the last change point at or before $t, or -1. */
    private function lastAtOrBefore(int $t): int
    {
        [$lo, $hi] = [0, count($this->since)];
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->since[$mid] <= $t) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }
        return $lo - 1;
    }
}
