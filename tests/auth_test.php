<?php

declare(strict_types=1);

ob_start();

putenv('APP_ENV=testing');
putenv('DB_PATH=storage/auth-test.sqlite');
putenv('LOG_PATH=storage/auth-test.log');
putenv('ERROR_LOG_PATH=storage/auth-test-errors.log');
putenv('AUTO_MIGRATE=true');
putenv('MAIL_DRIVER=log');

$root = dirname(__DIR__);
$databasePath = $root . '/storage/auth-test.sqlite';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$app = require $root . '/app/bootstrap.php';
$auth = $app['auth'];
GermanPath\Support\Session::logout();
GermanPath\Support\Session::start($app['config']);

function auth_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$registration = $auth->register('Alice@Example.com', 'a-very-strong-password', 'Alice');
$user = $registration['user'];
$verificationToken = $registration['verification_token'];
auth_check($user['email'] === 'alice@example.com', 'registration normalizes email');
auth_check($user['password_hash'] !== 'a-very-strong-password', 'password is never stored in plaintext');
$storedToken = $app['db']->query("SELECT token_hash FROM auth_tokens WHERE purpose = 'email_verification' LIMIT 1")->fetchColumn();
auth_check($storedToken === hash('sha256', $verificationToken) && $storedToken !== $verificationToken, 'verification token is stored hashed');

$auth->verifyToken($verificationToken);
$verified = $app['db']->query("SELECT email_verified_at FROM users WHERE id = " . (int) $user['id'])->fetchColumn();
auth_check(is_string($verified) && $verified !== '', 'email verification updates the account');

$duplicateRejected = false;
try {
    $auth->register('alice@example.com', 'another-strong-password', 'Another Alice');
} catch (GermanPath\Auth\AuthException) {
    $duplicateRejected = true;
}
auth_check($duplicateRejected, 'duplicate registration is rejected');

$auth->login('ALICE@example.com', 'a-very-strong-password');
auth_check(GermanPath\Support\Session::userId() === (int) $user['id'], 'login stores the user ID in the session');
auth_check($auth->currentUser()['display_name'] === 'Alice', 'current user can be loaded from the session');
auth_check(GermanPath\Support\Session::verifyCsrf(GermanPath\Support\Session::csrfToken()), 'session CSRF token verifies');

$auth->updateProfile((int) $user['id'], 'Alice Schmidt');
auth_check($auth->currentUser()['display_name'] === 'Alice Schmidt', 'profile name can be updated');

$emailChangeToken = $auth->requestEmailChange((int) $user['id'], 'alice-new@example.com', 'a-very-strong-password');
$auth->verifyToken($emailChangeToken);
auth_check($auth->currentUser()['email'] === 'alice-new@example.com', 'email change requires a token and updates the email');

$reset = $auth->requestPasswordReset('alice-new@example.com');
auth_check(is_array($reset) && isset($reset['reset_token']), 'password reset creates a token');
$auth->resetPassword($reset['reset_token'], 'a-new-strong-password');
$auth->logout();
GermanPath\Support\Session::start($app['config']);
$auth->login('alice-new@example.com', 'a-new-strong-password');
auth_check(GermanPath\Support\Session::userId() === (int) $user['id'], 'password reset allows login with the new password');

$tokenReuseRejected = false;
try {
    $auth->resetPassword($reset['reset_token'], 'another-strong-password');
} catch (GermanPath\Auth\AuthException) {
    $tokenReuseRejected = true;
}
auth_check($tokenReuseRejected, 'password reset tokens are single-use');

$rateLimitTriggered = false;
for ($attempt = 0; $attempt < 5; $attempt++) {
    try {
        $auth->login('unknown@example.com', 'wrong-password', '203.0.113.10');
    } catch (GermanPath\Auth\AuthException) {
        // Expected invalid credentials.
    }
}
try {
    $auth->login('unknown@example.com', 'wrong-password', '203.0.113.10');
} catch (GermanPath\Auth\AuthException $exception) {
    $rateLimitTriggered = str_contains($exception->getMessage(), 'Too many');
}
auth_check($rateLimitTriggered, 'repeated login failures are throttled');
$attemptCount = $app['db']->query("SELECT COUNT(*) FROM login_attempts WHERE ip_address = '203.0.113.10'")->fetchColumn();
auth_check((int) $attemptCount === 5, 'throttled attempts are persisted without an extra retry');
$auditCount = $app['db']->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('login_succeeded', 'login_failed', 'login_throttled')")->fetchColumn();
auth_check((int) $auditCount >= 7, 'authentication events are written to the audit log');

$auth->logout();
auth_check(GermanPath\Support\Session::userId() === null, 'logout clears the authenticated session');
echo "Auth tests completed.\n";
ob_end_flush();
