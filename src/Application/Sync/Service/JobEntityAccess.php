<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Sync\Repository\SyncJobRepositoryInterface;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\EntityAssignmentStrategy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;

/** Authorizes user-driven job operations against all entities a configuration can target. */
final readonly class JobEntityAccess
{
    /** @param \Closure(list<int>|null): bool $hasAccess Null means the job has no fixed entity scope. */
    public function __construct(
        private SyncJobRepositoryInterface $jobs,
        private \Closure $hasAccess
    ) {
    }

    public function assertJobId(SyncJobId $id): void
    {
        $job = $this->jobs->findById($id);
        if ($job === null) {
            throw SyncJobNotFoundException::forId($id);
        }
        $this->assertStrategy($job->entityAssignmentStrategy());
    }

    public function assertStrategy(?EntityAssignmentStrategy $strategy): void
    {
        if (!$this->canAccessStrategy($strategy)) {
            throw new \DomainException(
                'The interaction targets entities outside your active GLPI scope.',
            );
        }
    }

    public function canAccessStrategy(?EntityAssignmentStrategy $strategy): bool
    {
        $ids = null;
        if ($strategy !== null) {
            $ids = array_values($strategy->valueToEntityId());
            foreach ([$strategy->fixedEntityId(), $strategy->defaultEntityId()] as $id) {
                if ($id !== null) {
                    $ids[] = $id;
                }
            }
        }
        return $ids !== [] && ($this->hasAccess)($ids);
    }
}
