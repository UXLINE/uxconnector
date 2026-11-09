<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Sync\Enum;

/** Lifecycle of one synchronization execution. */
enum SyncExecutionStatus: string
{
    case PENDING = 'pending';
    case RUNNING = 'running';
    case SUCCESS = 'success';
    /** Kept for backward compatibility with 2.18.3 history rows. */
    case PARTIAL = 'partial';
    case SUCCESS_WITH_WARNINGS = 'success_with_warnings';
    case FAILED = 'failed';
    case INTERRUPTED = 'interrupted';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'En attente',
            self::RUNNING => 'En cours',
            self::SUCCESS => 'Succès',
            self::PARTIAL => 'Succès partiel',
            self::SUCCESS_WITH_WARNINGS => 'Succès avec avertissements',
            self::FAILED => 'Échec',
            self::INTERRUPTED => 'Interrompu / reprenable',
        };
    }

    public function isFinished(): bool
    {
        return in_array($this, [
            self::SUCCESS,
            self::PARTIAL,
            self::SUCCESS_WITH_WARNINGS,
            self::FAILED,
            self::INTERRUPTED,
        ], true);
    }

    public function isResumable(): bool
    {
        return $this === self::INTERRUPTED;
    }
}
