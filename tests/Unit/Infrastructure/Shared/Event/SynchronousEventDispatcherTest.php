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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Shared\Event;

use DateTimeImmutable;
use GlpiPlugin\Uxconnector\Domain\Shared\Event\DomainEvent;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\EventListenerFailedException;
use GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\SynchronousEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SynchronousEventDispatcher::class)]
#[CoversClass(EventListenerFailedException::class)]
final class SynchronousEventDispatcherTest extends TestCase
{
   public function testDispatchInvokesRegisteredListener(): void {
       $dispatcher = new SynchronousEventDispatcher();
       $received = null;

       $dispatcher->subscribe(FakeEvent::class, function (DomainEvent $event) use (&$received): void {
           $received = $event;
       });

       $event = new FakeEvent();
       $dispatcher->dispatch($event);

       self::assertSame($event, $received);
   }

   public function testDispatchInvokesMultipleListenersInOrder(): void {
       $dispatcher = new SynchronousEventDispatcher();
       $calls = [];

       $dispatcher->subscribe(FakeEvent::class, function () use (&$calls): void {
           $calls[] = 'first';
       });
       $dispatcher->subscribe(FakeEvent::class, function () use (&$calls): void {
           $calls[] = 'second';
       });

       $dispatcher->dispatch(new FakeEvent());

       self::assertSame(['first', 'second'], $calls);
   }

   public function testDispatchDoesNothingWhenNoListenerIsRegistered(): void {
       $dispatcher = new SynchronousEventDispatcher();

       $dispatcher->dispatch(new FakeEvent());

       $this->addToAssertionCount(1); // no exception, no crash
   }

   public function testOneFailingListenerDoesNotPreventOthersFromRunning(): void {
       $dispatcher = new SynchronousEventDispatcher();
       $secondRan = false;

       $dispatcher->subscribe(FakeEvent::class, function (): void {
           throw new \RuntimeException('boom');
       });
       $dispatcher->subscribe(FakeEvent::class, function () use (&$secondRan): void {
           $secondRan = true;
       });

      try {
          $dispatcher->dispatch(new FakeEvent());
      } catch (\GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\EventListenerFailedException) {
          self::addToAssertionCount(1);
      }

       self::assertTrue($secondRan);
   }

   public function testFailingListenerRaisesEventListenerFailedException(): void {
       $dispatcher = new SynchronousEventDispatcher();

       $dispatcher->subscribe(FakeEvent::class, function (): void {
           throw new \RuntimeException('boom');
       });

       $this->expectException(\GlpiPlugin\Uxconnector\Infrastructure\Shared\Event\EventListenerFailedException::class);

       $dispatcher->dispatch(new FakeEvent());
   }
}

/**
 * @internal test double
 */
final readonly class FakeEvent implements DomainEvent
{
   private DateTimeImmutable $occurredAt;

   public function __construct() {
       $this->occurredAt = new DateTimeImmutable();
   }

   public function occurredAt(): DateTimeImmutable {
       return $this->occurredAt;
   }

   public function name(): string {
       return 'test.fake_event';
   }
}
