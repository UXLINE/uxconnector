<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Repository;

use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;

/** Read model used by event-driven runtimes without widening SyncJobRepository. */
interface InteractionLookupRepositoryInterface
{
   public function findByWebhookKey(string $webhookKey): ?SyncJob;

    /** @return list<SyncJob> */
   public function findActiveForGlpiItem(string $targetItemtype): array;
}
