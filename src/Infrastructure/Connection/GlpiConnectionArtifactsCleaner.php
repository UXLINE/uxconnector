<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Connection;

use GlpiPlugin\Uxconnector\Application\Engine\Port\AuthConfigArtifactsCleanerInterface;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\ObjectLinkItem;

/**
 * Cleans cross-context technical state after all interactions were deleted.
 * ObjectLinks intentionally survive an individual interaction deletion so a
 * new inbound/outbound interaction on the same Connection can reuse them; they
 * are removed only when the Connection itself is permanently deleted.
 */
final class GlpiConnectionArtifactsCleaner implements AuthConfigArtifactsCleanerInterface
{
    public function cleanup(AuthConfigId $authConfigId): void
    {
        $model = new ObjectLinkItem();
        $rows = $model->find(['auth_config_reference' => $authConfigId->value()]);

        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if (!$model->delete(['id' => $id], true)) {
                throw new \RuntimeException(sprintf('Failed to purge ObjectLink #%d while deleting the connection.', $id));
            }
        }
    }
}
