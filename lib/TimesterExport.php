<?php

/**
 * Builds the timester payload: its DatabaseSchema with one TimeEntry per work block
 * (true start and end, UTC ISO strings like timester's own toISOString()), each
 * carrying `tz`, the zone where the work happened. Every block of a project-day gets
 * that day's digest as its description.
 *
 * sync() is the whole history plus a `source` marker: timester's "Sync from
 * ai-worklog" import replaces every ai-worklog entry with it, so renames, client
 * changes and ignored projects follow. Never send a range there — days outside it
 * would be deleted.
 */
class TimesterExport
{
    public const SOURCE = 'ai-worklog';

    public function __construct(
        private Api $api,
    ) {
    }

    /** Everything tracked, through today (dates where you were). */
    public function sync(): array
    {
        $zones = $this->api->zones();
        $today = $zones->dateOf(time());
        $first = $this->api->firstDay() ?? $today;
        return ['source' => self::SOURCE, 'through' => $today]
            + $this->build($zones->dayStart($first), $zones->dayEnd($today));
    }

    public function build(int $from, int $to): array
    {
        $rows = $this->api->days($from, $to); // ignored projects already excluded

        $clients = [];
        $clientIds = []; // name => id
        $projects = [];
        $entries = [];

        foreach ($rows as $row) {
            $clientId = null;
            if ($row['client'] !== null && $row['client'] !== '') {
                if (!isset($clientIds[$row['client']])) {
                    $clientIds[$row['client']] = count($clientIds) + 1;
                    $clients[] = ['id' => $clientIds[$row['client']], 'name' => $row['client']];
                }
                $clientId = $clientIds[$row['client']];
            }
            if (!isset($projects[$row['project_id']])) {
                $projects[$row['project_id']] = [
                    'id' => $row['project_id'],
                    'name' => $row['project'],
                    'clientId' => $clientId,
                ];
            }

            // Blocks are the project's merged intervals for that day: they never overlap
            // each other, and their durations add up to the day's seconds.
            $description = $this->description($row);
            foreach ($row['blocks'] as [$start, $end]) {
                $entries[] = [
                    'startTime' => self::iso($start),
                    'endTime' => self::iso($end),
                    'description' => $description,
                    'projectId' => $row['project_id'],
                    'tags' => [1],
                    'tz' => $this->api->zones()->nameAt($start),
                ];
            }
        }
        usort($entries, fn($a, $b) => strcmp($a['startTime'], $b['startTime']) ?: $a['projectId'] <=> $b['projectId']);
        foreach ($entries as $i => &$e) {
            $e = ['id' => $i + 1] + $e;
        }
        unset($e);

        return [
            'clients' => $clients,
            'projects' => array_values($projects),
            'tags' => [['id' => 1, 'name' => 'ai-worklog']],
            'timeEntries' => $entries,
            'version' => 1,
            'exportDate' => self::iso(time()),
        ];
    }

    private function description(array $row): string
    {
        if ($row['digest'] !== null && trim($row['digest']) !== '') {
            return trim($row['digest']);
        }
        $titles = [];
        foreach ($row['sessions'] as $s) {
            $t = trim((string) ($s['title'] ?? $s['first_prompt'] ?? ''));
            if ($t !== '') {
                $titles[] = mb_substr($t, 0, 100);
            }
        }
        return implode('; ', array_slice(array_unique($titles), 0, 4));
    }

    private static function iso(int $epoch): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z', $epoch);
    }
}
