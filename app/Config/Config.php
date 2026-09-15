<?php

declare(strict_types=1);

namespace GermanPath\Config;

final class Config
{
    /** @var array<string, mixed> */
    private array $values;

    /** @param array<string, mixed> $values */
    public function __construct(array $values)
    {
        $this->values = $values;
    }

    public static function load(string $rootPath): self
    {
        $env = self::readDotEnv($rootPath . DIRECTORY_SEPARATOR . '.env');

        $values = [
            'app_env' => self::value('APP_ENV', $env, 'development'),
            'site_name' => self::value('SITE_NAME', $env, 'GermanPath'),
            'site_url' => rtrim(self::value('SITE_URL', $env, 'https://germanpath.site'), '/'),
            'db_path' => self::absolutePath($rootPath, self::value('DB_PATH', $env, 'storage/database.sqlite')),
            'log_path' => self::absolutePath($rootPath, self::value('LOG_PATH', $env, 'logs/application.log')),
            'error_log_path' => self::absolutePath($rootPath, self::value('ERROR_LOG_PATH', $env, 'logs/error.log')),
            'auto_migrate' => self::toBool(self::value('AUTO_MIGRATE', $env, 'true')),
            'admin_email' => self::value('ADMIN_EMAIL', $env, ''),
            'worker_url' => rtrim(self::value('WORKER_URL', $env, ''), '/'),
            'worker_secret' => self::value('WORKER_SECRET', $env, ''),
            'worker_sign_path' => '/' . ltrim(self::value('WORKER_SIGN_PATH', $env, '/sign'), '/'),
            'worker_timeout_seconds' => max(1, (int) self::value('WORKER_TIMEOUT_SECONDS', $env, '8')),
            'worker_token_ttl' => max(30, min(900, (int) self::value('WORKER_TOKEN_TTL', $env, '300'))),
            'mail_driver' => self::value('MAIL_DRIVER', $env, 'log'),
            'mail_from' => self::value('MAIL_FROM', $env, 'no-reply@germanpath.site'),
            'verification_token_ttl' => max(900, (int) self::value('VERIFICATION_TOKEN_TTL', $env, '86400')),
            'reset_token_ttl' => max(900, (int) self::value('RESET_TOKEN_TTL', $env, '3600')),
            'session_name' => self::value('SESSION_NAME', $env, 'germanpath_session'),
            'session_lifetime' => max(300, (int) self::value('SESSION_LIFETIME', $env, '7200')),
            'upload_max_bytes' => max(1024, (int) self::value('UPLOAD_MAX_BYTES', $env, '10485760')),
            'root_path' => $rootPath,
        ];

        return new self($values);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function environment(): string
    {
        return (string) $this->get('app_env', 'development');
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isDevelopment(): bool
    {
        return in_array($this->environment(), ['development', 'testing'], true);
    }

    public function path(string $key): string
    {
        return (string) $this->get($key);
    }

    /** @param array<string, string> $dotEnv */
    private static function value(string $key, array $dotEnv, string $default): string
    {
        $runtime = getenv($key);
        if ($runtime !== false && $runtime !== '') {
            return $runtime;
        }

        return $dotEnv[$key] ?? $default;
    }

    /** @return array<string, string> */
    private static function readDotEnv(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $values = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (($value[0] ?? '') === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }

        return $values;
    }

    private static function absolutePath(string $rootPath, string $path): string
    {
        if ($path === '' || $path[0] === DIRECTORY_SEPARATOR) {
            return $path;
        }

        return $rootPath . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private static function toBool(string $value): bool
    {
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }
}
