<?php

/**
 * Generates per-project-per-day deliverable digests from stored facts
 * (session titles/prompts, commits, diff stats) via local Ollama.
 * Cached by inputs_hash: a day is only re-summarized when its inputs
 * changed or it was explicitly marked stale. Runs at ingest, never web.
 */
class Digests
{
    public function __construct(
        private Db $db,
        private Config $config,
        private Api $api,
        private Ollama $ollama,
    ) {
    }

    /** @return array{generated:int, cached:int, failed:int, skipped_ollama_down?:bool} */
    public function run(int $days, ?string $rangeFrom = null, ?string $rangeTo = null): array
    {
        $stats = ['generated' => 0, 'cached' => 0, 'failed' => 0];
        if (!$this->ollama->available()) {
            $stats['skipped_ollama_down'] = true;
            return $stats;
        }

        $from = strtotime(($rangeFrom ?? date('Y-m-d', strtotime("-{$days} days"))) . ' 00:00:00');
        $to = strtotime('+1 day', strtotime(($rangeTo ?? date('Y-m-d')) . ' 00:00:00'));
        $rows = $this->api->days($from, $to);

        // Also revisit explicitly stale days outside the window.
        foreach ($this->db->q('SELECT project_id, date FROM digests WHERE stale = 1') as $s) {
            $dayFrom = strtotime($s['date'] . ' 00:00:00');
            if ($dayFrom >= $from && $dayFrom < $to) {
                continue; // already covered
            }
            foreach ($this->api->days($dayFrom, strtotime('+1 day', $dayFrom)) as $r) {
                if ($r['project_id'] === (int) $s['project_id']) {
                    $rows[] = $r;
                }
            }
        }

        foreach ($rows as $row) {
            $existing = $this->db->one(
                'SELECT inputs_hash, stale FROM digests WHERE project_id = ? AND date = ?',
                [$row['project_id'], $row['date']]
            );
            // A sub-5-minute day isn't worth a model call on its own, but an explicit
            // request overrides that: stale = 1 means someone asked for this exact day.
            if ($row['seconds'] < 300 && !(int) ($existing['stale'] ?? 0)) {
                continue;
            }
            $inputs = $this->inputsFor($row);
            $hash = sha1(json_encode($inputs));
            if ($existing !== null && $existing['inputs_hash'] === $hash && !(int) $existing['stale']) {
                $stats['cached']++;
                continue;
            }
            $text = $this->ollama->generate($this->prompt($row, $inputs));
            if ($text === null) {
                // Counted, not swallowed. These used to vanish silently, so a run could
                // report "25 generated, 47 cached" while 60 more had timed out.
                $stats['failed']++;
                echo "  digest FAILED (model timeout or empty): {$row['date']} {$row['project']}\n";
                continue; // retried next ingest — the hash still will not match
            }
            $this->db->exec(
                'INSERT INTO digests (project_id, date, text, model, inputs_hash, stale, generated_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?)
                 ON CONFLICT(project_id, date) DO UPDATE SET
                    text = excluded.text, model = excluded.model, inputs_hash = excluded.inputs_hash,
                    stale = 0, generated_at = excluded.generated_at',
                [$row['project_id'], $row['date'], $text, $this->config->ollamaModel, $hash, time()]
            );
            $stats['generated']++;
            echo "  digest: {$row['date']} {$row['project']}\n";
        }
        return $stats;
    }

    private function inputsFor(array $row): array
    {
        $sessions = array_map(
            fn($s) => [
                'title' => mb_substr((string) ($s['title'] ?? ''), 0, 200),
                'prompt' => mb_substr((string) ($s['first_prompt'] ?? ''), 0, 200),
                'diff' => ($s['files_changed'] ?? null) !== null
                    ? "{$s['files_changed']} files +{$s['additions']}/-{$s['deletions']}" : null,
            ],
            array_slice($row['sessions'], 0, 12)
        );
        $commits = array_map(
            fn($c) => mb_substr($c['message'], 0, 150) . " (+{$c['additions']}/-{$c['deletions']})",
            array_slice($row['commits'], 0, 20)
        );
        return ['sessions' => $sessions, 'commits' => $commits];
    }

    private function prompt(array $row, array $inputs): string
    {
        $lines = ["Project: {$row['project']}", "Date: {$row['date']}", ''];
        $lines[] = 'Work sessions (agent-assisted coding):';
        foreach ($inputs['sessions'] as $s) {
            $desc = $s['title'] ?: $s['prompt'];
            $lines[] = '- ' . $desc . ($s['diff'] ? " [{$s['diff']}]" : '');
        }
        if ($inputs['commits'] !== []) {
            $lines[] = '';
            $lines[] = 'Git commits:';
            foreach ($inputs['commits'] as $c) {
                $lines[] = '- ' . $c;
            }
        }
        return "You write client-facing worklog entries for a freelance developer.\n"
            . "Summarize this day of work on one project into 2-4 short deliverable bullets.\n"
            . "Rules: past tense, concrete outcomes, no fluff, no headers, no intro or outro, "
            . "one line per bullet starting with \"- \". Merge related items. Never invent work "
            . "not supported by the facts below.\n\n"
            . implode("\n", $lines);
    }
}
