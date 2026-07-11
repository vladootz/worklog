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
        curl_close($ch);
        return $ok;
    }

    public function generate(string $prompt): ?string
    {
        $ch = curl_init($this->baseUrl . '/api/generate');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode([
                'model' => $this->model,
                'prompt' => $prompt,
                'stream' => false,
                'options' => ['temperature' => 0.3],
            ]),
        ]);
        $raw = curl_exec($ch);
        curl_close($ch);
        if ($raw === false) {
            return null;
        }
        $text = json_decode($raw, true)['response'] ?? null;
        return is_string($text) && trim($text) !== '' ? trim($text) : null;
    }
}
