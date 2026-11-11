<?php

namespace App\Services\Voice;

final class VoiceInfrastructure
{
    public function configured(): bool
    {
        $secret = config('voice.livekit_api_secret');
        $workerSecret = config('voice.worker_secret');

        return config('voice.enabled') === true && $this->livekitConfigured()
            && is_string($workerSecret) && strlen($workerSecret) >= 32 && strlen($workerSecret) <= 256
            && $workerSecret !== $secret && $workerSecret !== config('app.key')
            && preg_match('/\A[a-zA-Z0-9_-]{1,80}\z/D', (string) config('voice.worker_name'))
            && preg_match('/\A[a-zA-Z0-9_-]{1,40}\z/D', (string) config('voice.worker_key_id'));
    }

    public function livekitConfigured(): bool
    {
        $key = config('voice.livekit_api_key');
        $secret = config('voice.livekit_api_secret');

        return $this->url() !== null && is_string($key) && preg_match('/\A[A-Za-z0-9_-]{3,200}\z/D', $key)
            && is_string($secret) && strlen($secret) >= 32 && strlen($secret) <= 256;
    }

    public function requireLiveKit(): void
    {
        if (! $this->livekitConfigured()) {
            throw new VoiceFailure('not_configured', 503);
        }
    }

    public function requireConfigured(): void
    {
        if (! $this->configured()) {
            throw new VoiceFailure('not_configured', 503);
        }
    }

    public function url(): ?string
    {
        $url = config('voice.livekit_url');
        if (! is_string($url) || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || ! in_array($parts['path'] ?? '', ['', '/'], true)) {
            return null;
        }
        if (($parts['scheme'] ?? '') !== 'wss') {
            if (($parts['scheme'] ?? '') !== 'ws' || ! config('voice.allow_insecure_local')
                || ! app()->environment(['local', 'testing', 'e2e'])
                || ! in_array($parts['host'], ['localhost', '127.0.0.1', '[::1]'], true)) {
                return null;
            }
        }

        return rtrim($url, '/');
    }

    public function workerHealthUrl(): string
    {
        $url = config('voice.worker_health_url', 'http://127.0.0.1:8081');

        return rtrim(is_string($url) && $url !== '' ? $url : 'http://127.0.0.1:8081', '/');
    }
}
