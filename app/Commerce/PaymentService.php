<?php

declare(strict_types=1);

namespace GermanPath\Commerce;

use GermanPath\Audit\AuditService;
use PDO;
use DateTimeImmutable;
use DateTimeZone;

final class PaymentService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CourseOfferService $offers,
        private readonly AccessService $access,
        private readonly AuditService $audit
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function paymentMethods(): array
    {
        return $this->pdo->query(
            'SELECT * FROM payment_methods WHERE enabled = 1 ORDER BY sort_order, id'
        )->fetchAll();
    }

    public function submit(
        int $userId,
        string $courseId,
        string $offerId,
        string $duration,
        string $paymentMethod,
        string $payerName,
        string $payerAccount,
        string $transactionReference,
        string $paidAt,
        ?string $note = null
    ): int {
        $resolved = $this->offers->resolve($courseId, $offerId, $duration);
        $methodStatement = $this->pdo->prepare('SELECT * FROM payment_methods WHERE slug = :slug AND enabled = 1 LIMIT 1');
        $methodStatement->execute([':slug' => $paymentMethod]);
        $method = $methodStatement->fetch();
        if ($method === false) {
            throw new PaymentException('The selected payment method is not available.');
        }
        foreach ([
            'payer name' => $payerName,
            'payer account' => $payerAccount,
            'transaction reference' => $transactionReference,
            'payment time' => $paidAt,
        ] as $label => $value) {
            if (trim($value) === '' || strlen($value) > 200) {
                throw new PaymentException("Please provide a valid {$label}.");
            }
        }

        $userCheck = $this->pdo->prepare('SELECT is_active FROM users WHERE id = :id');
        $userCheck->execute([':id' => $userId]);
        if ((int) $userCheck->fetchColumn() !== 1) {
            throw new PaymentException('Your account cannot submit a payment.');
        }

        $option = $resolved['option'];
        $statement = $this->pdo->prepare(
            'INSERT INTO payment_submissions
             (user_id, course_id, offer_id, duration, payment_method, payer_name, payer_account, transaction_reference,
              amount_cents, currency, paid_at, note, status, created_at, updated_at)
             VALUES (:user_id, :course_id, :offer_id, :duration, :payment_method, :payer_name, :payer_account,
              :transaction_reference, :amount_cents, :currency, :paid_at, :note, :status, :created_at, :updated_at)'
        );
        $now = gmdate('c');
        $statement->execute([
            ':user_id' => $userId,
            ':course_id' => $courseId,
            ':offer_id' => $offerId,
            ':duration' => $duration,
            ':payment_method' => $paymentMethod,
            ':payer_name' => trim($payerName),
            ':payer_account' => trim($payerAccount),
            ':transaction_reference' => trim($transactionReference),
            ':amount_cents' => $option['amount_cents'],
            ':currency' => $option['currency'] ?? 'EUR',
            ':paid_at' => $paidAt,
            ':note' => $note === null ? null : trim($note),
            ':status' => 'pending',
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit->record($userId, 'payment_submitted', 'payment_submission', (string) $id, [
            'course_id' => $courseId,
            'amount_cents' => (int) $option['amount_cents'],
            'currency' => $option['currency'] ?? 'EUR',
        ]);
        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function pendingSubmissions(int $adminUserId): array
    {
        $this->assertAdmin($adminUserId);
        return $this->pdo->query(
            "SELECT ps.*, u.email, u.display_name
             FROM payment_submissions ps INNER JOIN users u ON u.id = ps.user_id
             WHERE ps.status = 'pending' ORDER BY ps.created_at ASC"
        )->fetchAll();
    }

    public function approve(int $adminUserId, int $submissionId, ?string $adminNote = null): int
    {
        $this->assertAdmin($adminUserId);
        $submission = $this->submission($submissionId);
        if ($submission === null || $submission['status'] !== 'pending') {
            throw new PaymentException('This payment submission is no longer pending.');
        }
        $resolved = $this->offers->resolve((string) $submission['course_id'], (string) $submission['offer_id'], (string) $submission['duration']);
        $expiresAt = $this->expiryFor((string) $submission['duration']);
        $this->pdo->beginTransaction();
        try {
            $accessId = $this->access->grantPurchase(
                $adminUserId,
                (int) $submission['user_id'],
                $submissionId,
                (string) $submission['course_id'],
                (string) $submission['offer_id'],
                $expiresAt
            );
            $statement = $this->pdo->prepare(
                "UPDATE payment_submissions SET status = 'approved', admin_note = :admin_note,
                 reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, updated_at = :updated_at WHERE id = :id"
            );
            $statement->execute([
                ':admin_note' => $adminNote === null ? null : trim($adminNote),
                ':reviewed_by' => $adminUserId,
                ':reviewed_at' => gmdate('c'),
                ':updated_at' => gmdate('c'),
                ':id' => $submissionId,
            ]);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
        $this->audit->record($adminUserId, 'payment_approved', 'payment_submission', (string) $submissionId, [
            'course_id' => $resolved['course']['id'],
            'access_id' => $accessId,
        ]);
        return $accessId;
    }

    public function reject(int $adminUserId, int $submissionId, ?string $adminNote = null): void
    {
        $this->assertAdmin($adminUserId);
        $submission = $this->submission($submissionId);
        if ($submission === null || $submission['status'] !== 'pending') {
            throw new PaymentException('This payment submission is no longer pending.');
        }
        $statement = $this->pdo->prepare(
            "UPDATE payment_submissions SET status = 'rejected', admin_note = :admin_note,
             reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, updated_at = :updated_at WHERE id = :id AND status = 'pending'"
        );
        $statement->execute([
            ':admin_note' => $adminNote === null ? null : trim($adminNote),
            ':reviewed_by' => $adminUserId,
            ':reviewed_at' => gmdate('c'),
            ':updated_at' => gmdate('c'),
            ':id' => $submissionId,
        ]);
        $this->audit->record($adminUserId, 'payment_rejected', 'payment_submission', (string) $submissionId);
    }

    /** @return array<string, mixed>|null */
    public function submission(int $id): ?array
    {
        $statement = $this->pdo->prepare('SELECT * FROM payment_submissions WHERE id = :id');
        $statement->execute([':id' => $id]);
        $submission = $statement->fetch();
        return $submission === false ? null : $submission;
    }

    private function expiryFor(string $duration): ?string
    {
        if ($duration === 'lifetime') {
            return null;
        }
        if (preg_match('/^([1-9][0-9]*)_days$/', $duration, $matches) !== 1) {
            throw new PaymentException('The selected access duration is invalid.');
        }
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . $matches[1] . ' days')
            ->format('c');
    }

    private function assertAdmin(int $userId): void
    {
        $statement = $this->pdo->prepare('SELECT role FROM users WHERE id = :id AND is_active = 1');
        $statement->execute([':id' => $userId]);
        if ($statement->fetchColumn() !== 'admin') {
            throw new PaymentException('Administrator authorization is required.');
        }
    }
}
