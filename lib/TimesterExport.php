<?php

/**
 * Builds a timester DatabaseSchema JSON payload: one TimeEntry per project
 * per day (PLAN.md decision 10). Times are UTC ISO strings exactly like
 * timester's own toISOString() output. Import via timester's MERGE import —
 * its mergeData() dedupes clients/projects/tags by name but NOT timeEntries,
 * so a range must only be merged once.
 */
class TimesterExport
{
    public function __construct(
        private Api $api,
    ) {
    }

    public function build(int $from, int $to, int $roundingMinutes, array $projectIds = []): array
    {
        $rows = $this->api->days($from, $to, $projectIds); // ignored projects already excluded

        $clients = [];
        $clientIds = []; // name => id
        $projects = [];
        $entries = [];
        $entryId = 1;

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

            $seconds = $row['seconds'];
            if ($roundingMinutes > 0) {
                $seconds = (int) (ceil($seconds / ($roundingMinutes * 60)) * $roundingMinutes * 60);
            }
            $start = $row['blocks'][0][0];
            $entries[] = [
                'id' => $entryId++,
                'startTime' => self::iso($start),
                'endTime' => self::iso($start + $seconds),
                'description' => $this->description($row),
                'projectId' => $row['project_id'],
                'tags' => [1],
            ];
        }

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
