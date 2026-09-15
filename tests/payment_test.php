<?php

declare(strict_types=1);

ob_start();
putenv('APP_ENV=testing');
putenv('DB_PATH=storage/payment-test.sqlite');
putenv('LOG_PATH=storage/payment-test.log');
putenv('ERROR_LOG_PATH=storage/payment-test-errors.log');
putenv('AUTO_MIGRATE=true');
putenv('MAIL_DRIVER=log');

$root = dirname(__DIR__);
$databasePath = $root . '/storage/payment-test.sqlite';
foreach ([$databasePath, $databasePath . '-wal', $databasePath . '-shm'] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}

$app = require $root . '/app/bootstrap.php';
$auth = $app['auth'];
$payments = $app['payments'];
$access = $app['access'];
$db = $app['db'];

function payment_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    echo "PASS: {$message}\n";
}

$student = $auth->register('student@example.com', 'a-very-strong-password', 'Student');
$auth->verifyToken($student['verification_token']);
$admin = $auth->register('admin@example.com', 'another-strong-password', 'Admin');
$auth->verifyToken($admin['verification_token']);
$db->prepare("UPDATE users SET role = 'admin' WHERE id = :id")->execute([':id' => $admin['user']['id']]);

$methods = $payments->paymentMethods();
payment_check(count($methods) === 1 && $methods[0]['slug'] === 'bank_transfer', 'enabled payment methods are configurable');

$submissionId = $payments->submit(
    (int) $student['user']['id'],
    'course-b1-german',
    'offer-b1-anna',
    '30_days',
    'bank_transfer',
    'Student Name',
    'payer-123',
    'txn-123',
    '2026-09-14T10:00:00Z',
    'Development payment'
);
$submission = $payments->submission($submissionId);
payment_check($submission['status'] === 'pending', 'payment submission starts pending');
payment_check(!$access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna'), 'pending payment grants no access');

$unauthorizedRejected = false;
try {
    $payments->approve((int) $student['user']['id'], $submissionId);
} catch (GermanPath\Commerce\PaymentException) {
    $unauthorizedRejected = true;
}
payment_check($unauthorizedRejected, 'non-admin payment approval is rejected');

$accessId = $payments->approve((int) $admin['user']['id'], $submissionId, 'Payment reviewed.');
payment_check($accessId > 0, 'admin approval creates course access');
payment_check($access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna'), 'approved payment grants version-specific access');

$db->prepare("UPDATE course_access SET expires_at = '2020-01-01T00:00:00+00:00' WHERE id = :id")->execute([':id' => $accessId]);
payment_check(!$access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna'), 'expired access is denied');

$lifetimeSubmission = $payments->submit(
    (int) $student['user']['id'],
    'course-b1-german',
    'offer-b1-lukas',
    'lifetime',
    'bank_transfer',
    'Student Name',
    'payer-123',
    'txn-456',
    '2026-09-14T10:05:00Z'
);
$lifetimeAccessId = $payments->approve((int) $admin['user']['id'], $lifetimeSubmission);
payment_check($access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-lukas'), 'lifetime access remains active');

$rejectedSubmission = $payments->submit(
    (int) $student['user']['id'],
    'course-b1-german',
    'offer-b1-anna',
    '90_days',
    'bank_transfer',
    'Student Name',
    'payer-123',
    'txn-789',
    '2026-09-14T10:10:00Z'
);
$payments->reject((int) $admin['user']['id'], $rejectedSubmission, 'Could not verify payment.');
payment_check($payments->submission($rejectedSubmission)['status'] === 'rejected', 'admin can reject a payment');

$manualAccessId = $access->grantManual((int) $admin['user']['id'], (int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna', null, 'Support grant');
payment_check($access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna'), 'admin can grant manual access');
$access->revoke((int) $admin['user']['id'], $manualAccessId, 'Manual revocation test');
payment_check(!$access->hasAccess((int) $student['user']['id'], 'course-b1-german', 'offer-b1-anna'), 'admin can revoke access');

$auditCount = $db->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('payment_submitted', 'payment_approved', 'payment_rejected', 'course_access_granted', 'course_access_revoked')")->fetchColumn();
payment_check((int) $auditCount >= 7, 'payment and access actions are audited');

echo "Payment tests completed.\n";
ob_end_flush();
