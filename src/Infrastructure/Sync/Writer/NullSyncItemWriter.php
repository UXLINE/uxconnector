<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Sync\Writer;

use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ReconciliationContext;
use GlpiPlugin\Uxconnector\Domain\Sync\Writer\SyncItemWriterInterface;

/**
 * Placeholder SyncItemWriterInterface implementation, kept as an
 * explicit opt-in fallback for callers that have not wired
 * GlpiItemWriter yet.
 *
 * Always reports failure rather than silently pretending to have
 * written data: a SyncJob wired to this writer will correctly show
 * every fetched item as failed in its SyncExecution, which is an
 * honest, visible signal — never a silent no-op that looks like
 * success.
 */
final class NullSyncItemWriter implements SyncItemWriterInterface
{
   public function write(ReconciliationContext $context, array $mappedFields): bool {
       return false;
   }
}
