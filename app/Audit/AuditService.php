<?php

declare(strict_types=1);

namespace GermanPath\Audit;

use GermanPath\Support\Logger;
use PDO;

final class AuditService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Logger $logger
    ) {
    }

    /** @param array<string, scalar|null> $metadata */
    public function record(
        ?int $actorUserId,
        string $action,
        ?string $entityType = null,
        ?string $entityId = null,
        array $metadata = []
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, metadata_json, created_at)
             VALUES (:actor_user_id, :action, :entity_type, :entity_id, :metadata_json, :created_at)'
        );
        $statement->execute([
            ':actor_user_id' => $actorUserId,
            ':action' => $action,
            ':entity_type' => $entityType,
            ':entity_id' => $entityId,
            ':metadata_json' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            ':created_at' => gmdate('c'),
        ]);
        $this->logger->info('Audit event recorded', [
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }
}
