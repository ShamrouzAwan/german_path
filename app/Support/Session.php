<?php

declare(strict_types=1);

namespace GermanPath\Support;

use GermanPath\Config\Config;

final class Session
{
    public static function start(Config $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $siteUrl = (string) $config->get('site_url', '');
        $secure = str_starts_with(strtolower($siteUrl), 'https://');
        session_name((string) $config->get('session_name', 'germanpath_session'));
        session_set_cookie_params([
            'lifetime' => (int) $config->get('session_lifetime', 7200),
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();

        if (isset($_SESSION['authenticated_at'])
            && is_int($_SESSION['authenticated_at'])
            && $_SESSION['authenticated_at'] + (int) $config->get('session_lifetime', 7200) < time()
        ) {
            self::logout();
        }
    }

    public static function csrfToken(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['_csrf'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        return is_string($token)
            && isset($_SESSION['_csrf'])
            && is_string($_SESSION['_csrf'])
            && hash_equals($_SESSION['_csrf'], $token);
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        $_SESSION['authenticated_at'] = time();
    }

    public static function userId(): ?int
    {
        return isset($_SESSION['user_id']) && is_int($_SESSION['user_id'])
            ? $_SESSION['user_id']
            : null;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }
}
