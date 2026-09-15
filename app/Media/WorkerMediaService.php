<?php

declare(strict_types=1);

namespace GermanPath\Media;

use GermanPath\Config\Config;
use GermanPath\Support\Logger;
use JsonException;

final class WorkerMediaService
{
    /** @var callable(string, array<string, mixed>, array<string, string>): array{status: int, body: string}|null */
    private $transport;

    /**
     * The optional transport is deliberately injectable so Worker failures and
     * response validation can be tested without network access.
     *
     * @param callable(string, array<string, mixed>, array<string, string>): array{status: int, body: string}|null $transport
     */
    public function __construct(
        private readonly Config $config,
        private readonly Logger $logger,
        ?callable $transport = null
    ) {
        $this->transport = $transport ?? [$this, 'httpTransport'];
    }

    /** @param array<string, mixed> $media */
    public function sign(array $media, int $userId): array
    {
        $workerUrl = (string) $this->config->get('worker_url', '');
        $secret = (string) $this->config->get('worker_secret', '');
        if ($workerUrl === '' || $secret === '') {
            throw new MediaException('Protected media is not configured yet.');
        }

        $videoKey = $this->mediaKey($media, 'video');
        $subtitleKey = $this->optionalMediaKey($media, 'subtitle');
        $payload = [
            'media_id' => (string) ($media['id'] ?? ''),
            'storage' => (string) ($media['storage'] ?? ''),
            'video' => $videoKey,
            'subtitle' => $subtitleKey,
            'user_id' => $userId,
            'expires_in' => (int) $this->config->get('worker_token_ttl', 300),
        ];
        if ($payload['media_id'] === '' || $payload['storage'] === '') {
            throw new MediaException('Media metadata is incomplete.', 500);
        }

        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = ($this->transport)(
            $workerUrl . (string) $this->config->get('worker_sign_path', '/sign'),
            $payload,
            [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'X-Worker-Secret' => $secret,
            ]
        );
        if ($response === null || $response['status'] < 200 || $response['status'] >= 300) {
            $this->logger->warning('Worker media signing failed', [
                'media_id' => $payload['media_id'],
                'status' => $response['status'] ?? 0,
            ]);
            throw new MediaException('The media service is temporarily unavailable.');
        }

        try {
            $decoded = json_decode($response['body'], true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MediaException('The media service returned an invalid response.', 502);
        }
        if (!is_array($decoded)
            || !is_string($decoded['video_url'] ?? null)
            || trim($decoded['video_url']) === ''
            || !is_string($decoded['expires_at'] ?? null)
        ) {
            throw new MediaException('The media service returned incomplete media URLs.', 502);
        }
        if ($this->config->isProduction()
            && !str_starts_with($decoded['video_url'], 'https://')
        ) {
            throw new MediaException('The media service returned an insecure video URL.', 502);
        }

        return [
            'video_url' => $decoded['video_url'],
            'subtitle_url' => isset($decoded['subtitle_url']) && is_string($decoded['subtitle_url'])
                ? $decoded['subtitle_url']
                : null,
            'expires_at' => $decoded['expires_at'],
        ];
    }

    /** @param array<string, mixed> $media */
    private function mediaKey(array $media, string $field): string
    {
        $key = $media[$field] ?? null;
        if (!is_string($key) || trim($key) === '' || str_contains($key, '..') || str_starts_with($key, '/')) {
            throw new MediaException('Media metadata contains an invalid storage key.', 500);
        }
        return $key;
    }

    /** @param array<string, mixed> $media */
    private function optionalMediaKey(array $media, string $field): ?string
    {
        if (($media[$field] ?? '') === '') {
            return null;
        }
        return $this->mediaKey($media, $field);
    }

    /** @param array<string, mixed> $payload @param array<string, string> $headers */
    private function httpTransport(string $url, array $payload, array $headers): ?array
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $headerLines = [];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $json,
                'timeout' => (int) $this->config->get('worker_timeout_seconds', 8),
                'ignore_errors' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $matches) === 1) {
                $status = (int) $matches[1];
                break;
            }
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : ''];
    }
}