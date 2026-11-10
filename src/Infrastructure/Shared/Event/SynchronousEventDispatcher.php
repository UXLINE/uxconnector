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

use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\EventDispatcherInterface;

/**
 * Minimal, synchronous, in-process Event Dispatcher.
 *
 * Handlers are registered per Domain Event class name and invoked in
 * registration order, immediately and synchronously. This is a
 * deliberate starting point (RULE-061 : the Dispatcher owns the
 * ordering/synchronicity policy, not the Services) - a queued/async
 * dispatcher can replace this implementation later without touching
 * a single Application Service, since both share
 * EventDispatcherInterface.
 *
 * A failing Handler does not prevent subsequent Handlers from
 * running: exceptions are collected and re-thrown only after every
 * registered Handler for the event has been invoked, so one broken
 * Handler cannot silently swallow another one's execution.
 */
final class SynchronousEventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<class-string<DomainEvent>, list<callable(DomainEvent): void>>
     */
    private array $listeners = [];

    /**
     * @param class-string<DomainEvent> $eventClass
     * @param callable(DomainEvent): void $listener
     */
    public function subscribe(string $eventClass, callable $listener): void
    {
        $this->listeners[$eventClass][] = $listener;
    }

    public function dispatch(DomainEvent $event): void
    {
        $eventClass = $event::class;

        if (!isset($this->listeners[$eventClass])) {
            return;
        }

        $failures = [];

        foreach ($this->listeners[$eventClass] as $listener) {
            try {
                $listener($event);
            } catch (\Throwable $exception) {
                $failures[] = $exception;
            }
        }

        if ($failures !== []) {
            throw new \GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\EventListenerFailedException(
                $eventClass,
                $failures
            );
        }
    }
}
