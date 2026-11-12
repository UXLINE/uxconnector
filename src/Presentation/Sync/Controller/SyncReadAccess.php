<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Application\Sync\Exception\SyncJobNotFoundException;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncExecutionId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Applies the existing job entity policy before a page or CSV response is rendered. */
final class SyncReadAccess
{
    public static function requireJob(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundHttpException();
        }

        try {
            SyncServiceProvider::jobEntityAccess()->assertJobId(new SyncJobId($id));
        } catch (SyncJobNotFoundException) {
            throw new NotFoundHttpException();
        } catch (\DomainException) {
            throw new AccessDeniedHttpException();
        }
    }

    public static function requireExecution(int $id): void
    {
        if ($id <= 0) {
            throw new NotFoundHttpException();
        }

        $execution = SyncServiceProvider::syncExecutionRepository()->findById(new SyncExecutionId($id));
        if ($execution === null) {
            throw new NotFoundHttpException();
        }

        self::requireJob($execution->syncJobId()->value());
    }
}
