<?php

declare(strict_types=1);

namespace GermanPath\Auth;

use GermanPath\Audit\AuditService;
use GermanPath\Config\Config;
use GermanPath\Mail\EmailService;
use GermanPath\Support\Logger;
use GermanPath\Support\Session;
use PDO;
use PDOException;

final class AuthService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Config $config,
        private readonly Logger $logger,
        private readonly EmailService $email,
        private readonly AuditService $audit
    ) {
    }

    /**
     * The raw token is returned to make deterministic integration tests possible.
     * Controllers never include it in their response.
     *
     * @return array{user: array<string, mixed>, verification_token: string}
     */
    public function register(string $email, string $password, string $displayName): array
    {
        $email = $this->normalizeEmail($email);
        $displayName = trim($displayName);
        $this->validatePassword($password);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Please enter a valid email address.');
        }
        if ($displayName === '' || mb_strlen($displayName) > 80) {
            throw new AuthException('Please enter a display name of 1–80 characters.');
        }

        $existing = $this->findUserByEmail($email);
        if ($existing !== null) {
            throw new AuthException('An account with that email already exists.');
        }

        $now = gmdate('c');
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (email, password_hash, display_name, role, created_at, updated_at)
                 VALUES (:email, :password_hash, :display_name, :role, :created_at, :updated_at)'
            );
            $statement->execute([
                ':email' => $email,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':display_name' => $displayName,
                ':role' => 'student',
                ':created_at' => $now,
                ':updated_at' => $now,
            ]);
            $userId = (int) $this->pdo->lastInsertId();
            $token = $this->createToken($userId, 'email_verification', null, (int) $this->config->get('verification_token_ttl'));
            $this->pdo->commit();
        } catch (PDOException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new AuthException('Registration could not be completed.', 0, $exception);
        }

        $user = $this->findUserById($userId);
        if ($user === null) {
            throw new AuthException('Registration could not be completed.');
        }
        $this->email->sendVerification($email, $token);
        $this->audit->record($userId, 'user_registered', 'user', (string) $userId);
        $this->logger->info('User registered', ['user_id' => $userId]);

        return ['user' => $user, 'verification_token' => $token];
    }

    /** @return array<string, mixed> */
    public function login(string $email, string $password, string $ipAddress = 'unknown'): array
    {
        $email = $this->normalizeEmail($email);
        if ($this->isRateLimited($email, $ipAddress)) {
            $this->audit->record(null, 'login_throttled', 'auth', null, ['ip_address' => $ipAddress]);
            throw new AuthException('Too many login attempts. Please try again later.');
        }

        $user = $this->findUserByEmail($email);
        if ($user === null || !is_string($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
            $this->recordLoginAttempt($email, $ipAddress, false);
            $this->audit->record(
                $user === null ? null : (int) $user['id'],
                'login_failed',
                'user',
                $user === null ? null : (string) $user['id'],
                ['ip_address' => $ipAddress]
            );
            throw new AuthException('Email or password is incorrect.');
        }
        if ((int) $user['is_active'] !== 1) {
            $this->recordLoginAttempt($email, $ipAddress, false);
            throw new AuthException('This account is currently disabled.');
        }

        if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
            $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id');
            $statement->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':updated_at' => gmdate('c'),
                ':id' => $user['id'],
            ]);
        }
        $this->recordLoginAttempt($email, $ipAddress, true);
        Session::login((int) $user['id']);
        $this->audit->record((int) $user['id'], 'login_succeeded', 'user', (string) $user['id'], ['ip_address' => $ipAddress]);
        $this->logger->info('User logged in', ['user_id' => $user['id']]);
        return $user;
    }

    public function logout(): void
    {
        $userId = Session::userId();
        Session::logout();
        if ($userId !== null) {
            $this->audit->record($userId, 'logout', 'user', (string) $userId);
            $this->logger->info('User logged out', ['user_id' => $userId]);
        }
    }

    /** @return array<string, mixed>|null */
    public function currentUser(): ?array
    {
        $userId = Session::userId();
        return $userId === null ? null : $this->findUserById($userId);
    }

    public function verifyToken(string $rawToken): string
    {
        if ($rawToken === '' || strlen($rawToken) < 32) {
            throw new AuthException('This verification link is invalid or expired.');
        }
        $token = $this->findToken($rawToken, ['email_verification', 'email_change']);
        if ($token === null) {
            throw new AuthException('This verification link is invalid or expired.');
        }

        $this->pdo->beginTransaction();
        try {
            $payload = $token['payload_json'] ? json_decode((string) $token['payload_json'], true) : [];
            if ($token['purpose'] === 'email_change') {
                $newEmail = is_array($payload) ? (string) ($payload['email'] ?? '') : '';
                if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                    throw new AuthException('This email change link is invalid.');
                }
                $existingEmail = $this->findUserByEmail($newEmail);
                if ($existingEmail !== null && (int) $existingEmail['id'] !== (int) $token['user_id']) {
                    throw new AuthException('That email address is already in use.');
                }
                $statement = $this->pdo->prepare('UPDATE users SET email = :email, email_verified_at = :verified, updated_at = :updated WHERE id = :id');
                $statement->execute([
                    ':email' => $newEmail,
                    ':verified' => gmdate('c'),
                    ':updated' => gmdate('c'),
                    ':id' => $token['user_id'],
                ]);
                $message = 'Email address changed successfully.';
            } else {
                $statement = $this->pdo->prepare('UPDATE users SET email_verified_at = :verified, updated_at = :updated WHERE id = :id');
                $statement->execute([
                    ':verified' => gmdate('c'),
                    ':updated' => gmdate('c'),
                    ':id' => $token['user_id'],
                ]);
                $message = 'Email address verified successfully.';
            }
            $this->markTokenUsed((int) $token['id']);
            $this->pdo->commit();
            $this->audit->record(
                (int) $token['user_id'],
                $token['purpose'] === 'email_change' ? 'email_changed' : 'email_verified',
                'user',
                (string) $token['user_id']
            );
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($exception instanceof AuthException) {
                throw $exception;
            }
            throw new AuthException('This verification link could not be used.', 0, $exception);
        }

        return $message;
    }

    public function resendVerification(string $email): void
    {
        $user = $this->findUserByEmail($this->normalizeEmail($email));
        if ($user === null || $user['email_verified_at'] !== null) {
            return;
        }
        $token = $this->createToken((int) $user['id'], 'email_verification', null, (int) $this->config->get('verification_token_ttl'));
        $this->email->sendVerification((string) $user['email'], $token);
        $this->audit->record((int) $user['id'], 'email_verification_sent', 'user', (string) $user['id']);
    }

    /** @return array{reset_token: string}|null */
    public function requestPasswordReset(string $email): ?array
    {
        $user = $this->findUserByEmail($this->normalizeEmail($email));
        if ($user === null || (int) $user['is_active'] !== 1) {
            return null;
        }
        $token = $this->createToken((int) $user['id'], 'password_reset', null, (int) $this->config->get('reset_token_ttl'));
        $this->email->sendPasswordReset((string) $user['email'], $token);
        $this->audit->record((int) $user['id'], 'password_reset_requested', 'user', (string) $user['id']);
        return ['reset_token' => $token];
    }

    public function resetPassword(string $rawToken, string $newPassword): void
    {
        $this->validatePassword($newPassword);
        $token = $this->findToken($rawToken, ['password_reset']);
        if ($token === null) {
            throw new AuthException('This password reset link is invalid or expired.');
        }
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated WHERE id = :id');
        $statement->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':updated' => gmdate('c'),
            ':id' => $token['user_id'],
        ]);
        $this->markTokenUsed((int) $token['id']);
        $this->audit->record((int) $token['user_id'], 'password_changed', 'user', (string) $token['user_id']);
    }

    public function updateProfile(int $userId, string $displayName): void
    {
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 80) {
            throw new AuthException('Please enter a display name of 1–80 characters.');
        }
        $statement = $this->pdo->prepare('UPDATE users SET display_name = :display_name, updated_at = :updated WHERE id = :id');
        $statement->execute([':display_name' => $displayName, ':updated' => gmdate('c'), ':id' => $userId]);
        $this->audit->record($userId, 'profile_updated', 'user', (string) $userId);
    }

    public function changePassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->findUserById($userId);
        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new AuthException('Current password is incorrect.');
        }
        $this->validatePassword($newPassword);
        $statement = $this->pdo->prepare('UPDATE users SET password_hash = :password_hash, updated_at = :updated WHERE id = :id');
        $statement->execute([
            ':password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
            ':updated' => gmdate('c'),
            ':id' => $userId,
        ]);
        $this->audit->record($userId, 'password_changed', 'user', (string) $userId);
    }

    public function requestEmailChange(int $userId, string $newEmail, string $currentPassword): string
    {
        $newEmail = $this->normalizeEmail($newEmail);
        $user = $this->findUserById($userId);
        if ($user === null || !password_verify($currentPassword, (string) $user['password_hash'])) {
            throw new AuthException('Current password is incorrect.');
        }
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new AuthException('Please enter a valid email address.');
        }
        if ($this->findUserByEmail($newEmail) !== null) {
            throw new AuthException('An account with that email already exists.');
        }
        $token = $this->createToken($userId, 'email_change', ['email' => $newEmail], (int) $this->config->get('verification_token_ttl'));
        $this->email->sendEmailChange($newEmail, $token);
        $this->audit->record($userId, 'email_change_requested', 'user', (string) $userId);
        return $token;
    }

    /** @return array<string, mixed>|null */
    private function findUserByEmail(string $email): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $statement->execute([':email' => $email]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    /** @return array<string, mixed>|null */
    private function findUserById(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $id]);
        $user = $statement->fetch();
        return $user === false ? null : $user;
    }

    private function normalizeEmail(string $email): string
    {
        return strtolower(trim($email));
    }

    private function validatePassword(string $password): void
    {
        if (strlen($password) < 10 || strlen($password) > 200) {
            throw new AuthException('Password must be between 10 and 200 characters.');
        }
    }

    /** @param array<string, mixed>|null $payload */
    private function createToken(int $userId, string $purpose, ?array $payload, int $ttl): string
    {
        $rawToken = bin2hex(random_bytes(32));
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_tokens (user_id, purpose, token_hash, payload_json, expires_at, created_at)
             VALUES (:user_id, :purpose, :token_hash, :payload_json, :expires_at, :created_at)'
        );
        $statement->execute([
            ':user_id' => $userId,
            ':purpose' => $purpose,
            ':token_hash' => hash('sha256', $rawToken),
            ':payload_json' => $payload === null ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            ':expires_at' => gmdate('c', time() + $ttl),
            ':created_at' => gmdate('c'),
        ]);
        return $rawToken;
    }

    /** @param list<string> $purposes @return array<string, mixed>|null */
    private function findToken(string $rawToken, array $purposes): ?array
    {
        $placeholders = implode(',', array_fill(0, count($purposes), '?'));
        $statement = $this->pdo->prepare(
            "SELECT * FROM auth_tokens
             WHERE token_hash = ? AND used_at IS NULL AND expires_at > ? AND purpose IN ({$placeholders})
             LIMIT 1"
        );
        $statement->execute([hash('sha256', $rawToken), gmdate('c'), ...$purposes]);
        $token = $statement->fetch();
        return $token === false ? null : $token;
    }

    private function markTokenUsed(int $tokenId): void
    {
        $statement = $this->pdo->prepare('UPDATE auth_tokens SET used_at = :used_at WHERE id = :id');
        $statement->execute([':used_at' => gmdate('c'), ':id' => $tokenId]);
    }

    private function isRateLimited(string $email, string $ipAddress): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE email = :email AND ip_address = :ip_address
               AND was_successful = 0 AND created_at > :cutoff'
        );
        $statement->execute([
            ':email' => $email,
            ':ip_address' => $ipAddress,
            ':cutoff' => gmdate('c', time() - 900),
        ]);
        return (int) $statement->fetchColumn() >= 5;
    }

    private function recordLoginAttempt(string $email, string $ipAddress, bool $successful): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO login_attempts (email, ip_address, was_successful, created_at)
             VALUES (:email, :ip_address, :was_successful, :created_at)'
        );
        $statement->execute([
            ':email' => $email,
            ':ip_address' => $ipAddress,
            ':was_successful' => $successful ? 1 : 0,
            ':created_at' => gmdate('c'),
        ]);
        $cutoff = $this->pdo->quote(gmdate('c', time() - 86400));
        $this->pdo->exec("DELETE FROM login_attempts WHERE created_at < {$cutoff}");
    }
}
