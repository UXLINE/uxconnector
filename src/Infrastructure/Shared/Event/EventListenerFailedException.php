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

namespace GlpiPlugin\Uxconnector\Infrastructure\Shared\Event;

use Throwable;

/**
 * Raised by SynchronousEventDispatcher when one or more listeners
 * threw while handling an event. Carries every underlying failure so
 * none of them is silently lost.
 */
final class EventListenerFailedException extends \RuntimeException
{
    /**
     * @param class-string $eventClass
     * @param list<Throwable> $failures
     */
   public function __construct(
        string $eventClass,
        private readonly array $failures
    ) {
       parent::__construct(sprintf(
           '%d listener(s) failed while handling "%s".',
           count($failures),
           $eventClass
       ), 0, $failures[0] ?? null);
   }

    /**
     * @return list<Throwable>
     */
   public function failures(): array {
       return $this->failures;
   }
}
