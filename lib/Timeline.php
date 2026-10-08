<?php

/**
 * Writes one session's timeline: its raw events and the blocks coalesced from them.
 * Events are kept so blocks can be rebuilt later with a better rule
 * (`ingest.php --recoalesce`) after the source transcripts are gone.
 */
class Timeline
{
    private ?PDOStatement $insertEvent = null;

    public function __construct(
        private Db $db,
        private Config $config,
    ) {
    }

    /**
     * @param int[] $events     epoch seconds, any order, duplicates allowed
     * @param int[] $promptTimes epoch seconds of human prompts (a subset of $events)
     */
    public function store(int $sessionId, string $dir, array $events, array $promptTimes): void
    {
        $this->insertEvent ??= $this->db->pdo->prepare(
            'INSERT OR IGNORE INTO events (session_id, ts, is_prompt) VALUES (?, ?, ?)'
        );
        $prompts = array_flip($promptTimes);
        foreach (array_unique($events) as $ts) {
            $this->insertEvent->execute([$sessionId, $ts, isset($prompts[$ts]) ? 1 : 0]);
        }
        $this->writeBlocks($sessionId, $dir);
    }

    /** (Re)build a session's blocks from its stored events with the current rule. */
    public function writeBlocks(int $sessionId, string $dir): void
    {
        $times = array_map('intval', array_column(
            $this->db->q('SELECT DISTINCT ts FROM events WHERE session_id = ? ORDER BY ts', [$sessionId]),
            'ts'
        ));
        $this->db->exec('DELETE FROM blocks WHERE session_id = ?', [$sessionId]);
        if ($times === []) {
            return;
        }
        $c = $this->config;
        // No samples for this session (history, sampler off) → the plain gap rule, untouched.
        $presence = Presence::load($this->db, $c, $times[0], $times[count($times) - 1]);
        if ($presence->hasSamples()) {
            $blocks = Coalesce::presenceBlocks($times, $c->gapMinutes, $c->minBlockSeconds, $presence, $c->presenceBridgeMinutes);
            [$idleMin, $bridgeMin] = [$c->presenceIdleMinutes, $c->presenceBridgeMinutes];
        } else {
            $blocks = Coalesce::blocks($times, $c->gapMinutes, $c->minBlockSeconds);
            [$idleMin, $bridgeMin] = [null, null];
        }
        foreach ($blocks as $b) {
            $this->db->exec(
                'INSERT INTO blocks (session_id, dir, start_at, end_at, event_count, gap_threshold_min,
                                     kind, idle_threshold_min, bridge_max_min)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$sessionId, $dir, $b['start'], $b['end'], $b['count'], $c->gapMinutes,
                 $b['kind'] ?? 'core', $idleMin, $bridgeMin]
            );
        }
    }
}
