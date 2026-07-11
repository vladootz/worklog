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

    private static function close(int $start, int $end, int $count, int $minBlockSeconds): array
    {
        if ($end - $start < $minBlockSeconds) {
            $end = $start + $minBlockSeconds;
        }
        return ['start' => $start, 'end' => $end, 'count' => $count];
    }
}
