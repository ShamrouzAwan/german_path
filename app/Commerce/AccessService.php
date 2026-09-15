<?php

declare(strict_types=1);

namespace GermanPath\Commerce;

use GermanPath\Audit\AuditService;
use PDO;

final class AccessService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly AuditService $audit
    ) {
    }

    public function hasAccess(int $userId, string $courseId, ?string $offerId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM course_access ca
                INNER JOIN users u ON u.id = ca.user_id
                WHERE ca.user_id = :user_id AND ca.course_id = :course_id
                  AND ca.status = :status AND ca.revoked_at IS NULL
                  AND u.is_active = 1
                  AND ca.starts_at <= :now
                  AND (ca.expires_at IS NULL OR ca.expires_at > :now)';
        $params = [
            ':user_id' => $userId,
            ':course_id' => $courseId,
            ':status' => 'active',
            ':now' => gmdate('c'),
        ];
        if ($offerId !== null) {
            $sql .= ' AND ca.offer_id = :offer_id';
            $params[':offer_id'] = $offerId;
        }
        $statement = $this->pdo->prepare($sql);
        $statement->execute($params);
        return (int) $statement->fetchColumn() > 0;
    }

    public function grantManual(int $adminUserId, int $userId, string $courseId, string $offerId, ?string $expiresAt, ?string $note = null): int
    {
        $this->assertAdmin($adminUserId);
        $accessId = $this->insertAccess($userId, $courseId, $offerId, 'manual', null, $expiresAt, $adminUserId);
        $this->recordEvent($accessId, $adminUserId, 'granted', $note);
        $this->audit->record($adminUserId, 'course_access_granted', 'course_access', (string) $accessId, [
            'user_id' => $userId,
            'course_id' => $courseId,
            'source' => 'manual',
        ]);
        return $accessId;
    }

    public function revoke(int $adminUserId, int $accessId, ?string $note = null): void
    {
        $this->assertAdmin($adminUserId);
        $statement = $this->pdo->prepare(
            "UPDATE course_access SET status = 'revoked', revoked_at = :revoked_at, revoked_by = :revoked_by
             WHERE id = :id AND status = 'active'"
        );
        $statement->execute([
            ':revoked_at' => gmdate('c'),
            ':revoked_by' => $adminUserId,
            ':id' => $accessId,
        ]);
        if ($statement->rowCount() > 0) {
            $this->recordEvent($accessId, $adminUserId, 'revoked', $note);
            $this->audit->record($adminUserId, 'course_access_revoked', 'course_access', (string) $accessId);
        }
    }

    private function insertAccess(int $userId, string $courseId, string $offerId, string $source, ?int $submissionId, ?string $expiresAt, int $grantedBy): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO course_access
             (user_id, course_id, offer_id, source, payment_submission_id, starts_at, expires_at, status, granted_by, created_at, updated_at)
             VALUES (:user_id, :course_id, :offer_id, :source, :submission_id, :starts_at, :expires_at, :status, :granted_by, :created_at, :updated_at)'
        );
        $now = gmdate('c');
        $statement->execute([
            ':user_id' => $userId,
            ':course_id' => $courseId,
            ':offer_id' => $offerId,
            ':source' => $source,
            ':submission_id' => $submissionId,
            ':starts_at' => $now,
            ':expires_at' => $expiresAt,
            ':status' => 'active',
            ':granted_by' => $grantedBy,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function grantPurchase(int $adminUserId, int $userId, int $submissionId, string $courseId, string $offerId, ?string $expiresAt): int
    {
        $this->assertAdmin($adminUserId);
        $accessId = $this->insertAccess($userId, $courseId, $offerId, 'purchase', $submissionId, $expiresAt, $adminUserId);
        $this->recordEvent($accessId, $adminUserId, 'granted', 'Payment approved.');
        return $accessId;
    }

    private function recordEvent(int $accessId, int $actorUserId, string $event, ?string $note): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO course_access_events (course_access_id, actor_user_id, event, note, created_at)
             VALUES (:access_id, :actor_user_id, :event, :note, :created_at)'
        );
        $statement->execute([
            ':access_id' => $accessId,
            ':actor_user_id' => $actorUserId,
            ':event' => $event,
            ':note' => $note,
            ':created_at' => gmdate('c'),
        ]);
    }

    private function assertAdmin(int $userId): void
    {
        $statement = $this->pdo->prepare("SELECT role FROM users WHERE id = :id AND is_active = 1");
        $statement->execute([':id' => $userId]);
        if ($statement->fetchColumn() !== 'admin') {
            throw new PaymentException('Administrator authorization is required.');
        }
    }
}
