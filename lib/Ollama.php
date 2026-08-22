<?php

/** Minimal Ollama client (curl, no streaming). CLI-only by design. */
class Ollama
{
    public function __construct(
        private string $baseUrl,
        private string $model,
    ) {
    }

    public function available(): bool
    {
        $ch = curl_init($this->baseUrl . '/api/tags');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
        ]);
        $ok = curl_exec($ch) !== false && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        return $ok;
    }

    public function generate(string $prompt): ?string
    {
        $ch = curl_init($this->baseUrl . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                // Reasoning models (qwen3 and friends) otherwise spend most of their
                // budget on a chain of thought that is then discarded. Measured on
                // qwen3:14b: 82s / 261 eval tokens with thinking, 3.2s / 27 without —
                // and the 120s ceiling this replaced was silently killing most digests.
                // Ignored by models that do not think.
                'think' => false,
                'options' => ['temperature' => 0.3],
            ]),
        ]);
        $raw = curl_exec($ch);
        if ($raw === false) {
            return null;
        }
        $text = json_decode($raw, true)['response'] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
