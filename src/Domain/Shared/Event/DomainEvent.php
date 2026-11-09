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

namespace GlpiPlugin\Uxconnector\Domain\Shared\Event;

use DateTimeImmutable;

/**
 * Marker interface implemented by every Domain Event raised by an
 * aggregate of any bounded context (Engine, Sync, ...).
 *
 * A Domain Event is immutable and represents a fact that already
 * happened in the domain. It never carries a mutable reference to the
 * aggregate that raised it (RULE-VALUEOBJECT-005: no mutable state).
 */
interface DomainEvent
{
    /**
     * Returns the moment the event occurred.
     */
    public function occurredAt(): DateTimeImmutable;

    /**
     * Returns the short, stable, machine-readable name of the event
     * (e.g. "engine.auth_config.created"), used for logging, the Event
     * Dispatcher subscription and audit trails.
     */
    public function name(): string;
}
