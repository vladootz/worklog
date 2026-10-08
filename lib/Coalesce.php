<?php

/**
 * Turns sorted event timestamps into continuous work blocks:
 * events closer than the gap threshold belong to the same block.
 * The threshold is stored on every block so historical numbers
 * never change if the constant is tuned later.
 */
class Coalesce
{
    /**
     * @param int[] $sortedEvents epoch seconds, ascending
     * @return array<array{start:int, end:int, count:int}>
     */
    public static function blocks(array $sortedEvents, int $gapMinutes, int $minBlockSeconds): array
    {
        if ($sortedEvents === []) {
            return [];
        }
        $gap = $gapMinutes * 60;
        $blocks = [];
        $start = $prev = $sortedEvents[0];
        $count = 1;
        for ($i = 1, $n = count($sortedEvents); $i < $n; $i++) {
            $ts = $sortedEvents[$i];
            if ($ts - $prev > $gap) {
                $blocks[] = self::close($start, $prev, $count, $minBlockSeconds);
                $start = $ts;
                $count = 0;
            }
            $prev = $ts;
            $count++;
        }
        $blocks[] = self::close($start, $prev, $count, $minBlockSeconds);
        return $blocks;
    }

    /**
     * Same rule, shaped by keyboard/mouse presence. Gaps up to the threshold join as
     * before ('core'); longer gaps up to $bridgeMinutes join as 'fill' only where
     * samples cover the whole gap; away time is then cut from both. Wherever presence
     * is unknown this returns exactly what blocks() returns.
     * @param int[] $sortedEvents epoch seconds, ascending, distinct
     * @return array<array{start:int, end:int, count:int, kind:string}>
     */
    public static function presenceBlocks(
        array $sortedEvents,
        int $gapMinutes,
        int $minBlockSeconds,
        Presence $presence,
        int $bridgeMinutes,
    ): array {
        if ($sortedEvents === []) {
            return [];
        }
        $gap = $gapMinutes * 60;
        $bridge = $bridgeMinutes * 60;
        $blocks = [];
        $run = [$sortedEvents[0]];
        $links = [];
        for ($i = 1, $n = count($sortedEvents); $i < $n; $i++) {
            [$prev, $ts] = [$sortedEvents[$i - 1], $sortedEvents[$i]];
            if ($ts - $prev <= $gap) {
                $links[] = [$prev, $ts, 'core'];
            } elseif ($ts - $prev <= $bridge && $presence->covered($prev, $ts)) {
                $links[] = [$prev, $ts, 'fill'];
            } else {
                array_push($blocks, ...self::closeRun($run, $links, $minBlockSeconds, $presence));
                [$run, $links] = [[], []];
            }
            $run[] = $ts;
        }
        array_push($blocks, ...self::closeRun($run, $links, $minBlockSeconds, $presence));
        return $blocks;
    }

    /** One chain of linked events → its core/fill intervals minus away time. */
    private static function closeRun(array $run, array $links, int $minBlockSeconds, Presence $presence): array
    {
        $start = $run[0];
        $end = $run[count($run) - 1];
        // A run shorter than the minimum has only core links (a fill link is longer than the gap).
        $spans = $end - $start < $minBlockSeconds ? [[$start, $start + $minBlockSeconds, 'core']] : [];
        foreach ($spans === [] ? $links : [] as [$s, $e, $kind]) {
            $last = count($spans) - 1;
            if ($last >= 0 && $spans[$last][2] === $kind && $spans[$last][1] === $s) {
                $spans[$last][1] = $e;
            } else {
                $spans[] = [$s, $e, $kind];
            }
        }
        $out = [];
        foreach ($spans as [$s, $e, $kind]) {
            foreach (self::subtract($s, $e, $presence->away($s, $e)) as [$a, $b]) {
                $count = 0;
                if ($kind === 'core') {
                    foreach ($run as $ts) {
                        $count += (int) ($ts >= $a && $ts <= $b);
                    }
                }
                $out[] = ['start' => $a, 'end' => $b, 'count' => $count, 'kind' => $kind];
            }
        }
        return $out;
    }

    /**
     * [$start, $end] minus sorted, non-overlapping $cuts; empty pieces dropped.
     * @return array<array{0:int,1:int}>
     */
    public static function subtract(int $start, int $end, array $cuts): array
    {
        $out = [];
        $cur = $start;
        foreach ($cuts as [$s, $e]) {
            if ($s > $cur) {
                $out[] = [$cur, min($s, $end)];
            }
            $cur = max($cur, $e);
            if ($cur >= $end) {
                return $out;
            }
        }
        $out[] = [$cur, $end];
        return $out;
    }

    private static function close(int $start, int $end, int $count, int $minBlockSeconds): array
    {
        if ($end - $start < $minBlockSeconds) {
            $end = $start + $minBlockSeconds;
        }
        return ['start' => $start, 'end' => $end, 'count' => $count];
    }
}
