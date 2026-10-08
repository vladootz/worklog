<?php

/**
 * Rebuilds a timester backup so that everything tagged ai-worklog is exactly a fresh
 * export: worklog is the source of truth for those entries, their projects' names and
 * clients. A merge import cannot do this — timester matches entries on project + start
 * time and projects on name, so a renamed project or a regrouped day lands next to the
 * old entries instead of replacing them. Import the result with timester's replace.
 *
 * Entries without the ai-worklog tag are copied unchanged. Clients are never removed.
 */
class TimesterReplace
{
    public const TAG = 'ai-worklog';

    /**
     * @param array $backup timester DatabaseSchema (clients, projects, tags, timeEntries, …)
     * @param array $export TimesterExport::build() output
     * @return array{db: array, report: array}
     */
    public static function apply(array $backup, array $export): array
    {
        $db = $backup;
        $report = ['removed' => 0, 'removed_seconds' => 0, 'added' => 0, 'added_seconds' => 0,
            'projects_created' => [], 'clients_created' => [], 'client_changes' => [], 'projects_removed' => []];

        $tag = null;
        foreach ($db['tags'] as $t) {
            if ($t['name'] === self::TAG) {
                $tag = $t['id'];
            }
        }
        if ($tag === null) {
            $tag = self::nextId($db['tags']);
            $db['tags'][] = ['id' => $tag, 'name' => self::TAG];
        }

        // Drop every ai-worklog entry; remember which projects held them.
        $heldWorklog = [];
        $kept = [];
        foreach ($db['timeEntries'] as $e) {
            if (in_array($tag, $e['tags'] ?? [], true)) {
                $heldWorklog[$e['projectId']] = true;
                $report['removed']++;
                $report['removed_seconds'] += self::seconds($e);
            } else {
                $kept[] = $e;
            }
        }
        $db['timeEntries'] = $kept;

        $exportClients = array_column($export['clients'], 'name', 'id');
        $projectIdFor = []; // export project id → timester project id
        $inExport = [];
        foreach ($export['projects'] as $p) {
            $clientId = $p['clientId'] !== null ? self::clientId($db, $exportClients[$p['clientId']], $report) : null;
            $i = self::find($db['projects'], $p['name']);
            if ($i === null) {
                $id = self::nextId($db['projects']);
                $db['projects'][] = ['id' => $id, 'name' => $p['name'], 'clientId' => $clientId];
                $report['projects_created'][] = $p['name'] . ($clientId !== null ? ' (' . $exportClients[$p['clientId']] . ')' : '');
            } else {
                $id = $db['projects'][$i]['id'];
                $old = $db['projects'][$i]['clientId'] ?? null;
                // No client in worklog means "not set there", not "remove it here".
                if ($clientId !== null && $old !== $clientId) {
                    $db['projects'][$i]['clientId'] = $clientId;
                    $report['client_changes'][] = $db['projects'][$i]['name'] . ': '
                        . (self::clientName($db, $old) ?? '(none)') . ' → ' . $exportClients[$p['clientId']];
                }
            }
            $projectIdFor[$p['id']] = $id;
            $inExport[$id] = true;
        }

        $nextEntry = self::nextId($backup['timeEntries']);
        foreach ($export['timeEntries'] as $e) {
            $db['timeEntries'][] = [
                'startTime' => $e['startTime'],
                'endTime' => $e['endTime'],
                'description' => $e['description'],
                'projectId' => $projectIdFor[$e['projectId']],
                'tags' => [$tag],
                'id' => $nextEntry++,
            ];
            $report['added']++;
            $report['added_seconds'] += self::seconds($e);
        }

        // A project that only existed for ai-worklog time and is gone from worklog
        // (renamed, ignored, merged) would linger empty. Projects that never held
        // ai-worklog entries are the user's own and always stay.
        $used = array_flip(array_column($db['timeEntries'], 'projectId'));
        $db['projects'] = array_values(array_filter($db['projects'], function ($p) use ($heldWorklog, $inExport, $used, &$report) {
            if (isset($heldWorklog[$p['id']]) && !isset($inExport[$p['id']]) && !isset($used[$p['id']])) {
                $report['projects_removed'][] = $p['name'];
                return false;
            }
            return true;
        }));

        return ['db' => $db, 'report' => $report];
    }

    /** Index of a project by name: exact first, then case-insensitive. */
    private static function find(array $projects, string $name): ?int
    {
        foreach ($projects as $i => $p) {
            if ($p['name'] === $name) {
                return $i;
            }
        }
        foreach ($projects as $i => $p) {
            if (mb_strtolower($p['name']) === mb_strtolower($name)) {
                return $i;
            }
        }
        return null;
    }

    private static function clientId(array &$db, string $name, array &$report): int
    {
        foreach ([fn($c) => $c['name'] === $name, fn($c) => mb_strtolower($c['name']) === mb_strtolower($name)] as $match) {
            foreach ($db['clients'] as $c) {
                if ($match($c)) {
                    return $c['id'];
                }
            }
        }
        $id = self::nextId($db['clients']);
        $db['clients'][] = ['id' => $id, 'name' => $name];
        $report['clients_created'][] = $name;
        return $id;
    }

    private static function clientName(array $db, $id): ?string
    {
        foreach ($db['clients'] as $c) {
            if ($c['id'] === $id) {
                return $c['name'];
            }
        }
        return null;
    }

    private static function nextId(array $rows): int
    {
        return $rows === [] ? 1 : max(array_column($rows, 'id')) + 1;
    }

    private static function seconds(array $e): int
    {
        return strtotime($e['endTime']) - strtotime($e['startTime']);
    }
}
