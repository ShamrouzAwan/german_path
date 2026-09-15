<?php

declare(strict_types=1);

namespace GermanPath\Support;

use GermanPath\Config\Config;

final class Logger
{
    public function __construct(private readonly Config $config)
    {
    }

    /** @param array<string, scalar|null> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    /** @param array<string, scalar|null> $context */
    private function write(string $level, string $message, array $context): void
    {
        $path = $this->config->path('log_path');
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        $safeContext = $this->redact($context);
        $suffix = $safeContext === [] ? '' : ' ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES);
        @file_put_contents(
            $path,
            sprintf("[%s] %s %s%s\n", gmdate('c'), $level, $message, $suffix),
            FILE_APPEND | LOCK_EX
        );
    }

    /** @param array<string, scalar|null> $context @return array<string, scalar|null> */
    private function redact(array $context): array
    {
        foreach (array_keys($context) as $key) {
            if (preg_match('/pass|secret|token|credential|signed.?url/i', $key)) {
                $context[$key] = '[REDACTED]';
            }
        }

        return $context;
    }
}
