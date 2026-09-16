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

/**
 * Port allowing an Application Service to publish a Domain Event
 * without knowing which (if any) Handler will react to it.
 *
 * RULE-061 : Domain Events are created exclusively by aggregates,
 * published exclusively by Application Services, and consumed
 * exclusively by decoupled Handlers registered against the concrete
 * implementation of this interface.
 */
interface EventDispatcherInterface
{
   public function dispatch(DomainEvent $event): void;
}
