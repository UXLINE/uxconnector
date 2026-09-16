<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Copilot;

use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Provider\GlpiHlTicketNormalizer;
use PHPUnit\Framework\TestCase;

final class GlpiHlTicketNormalizerTest extends TestCase
{
   public function testNormalizesCommonHlShape(): void {
       $ticket = (new GlpiHlTicketNormalizer())->normalize([
           'id' => 42,
           'name' => 'VPN unavailable',
           'content' => 'Cannot connect from home',
           'status' => ['name' => 'Processing'],
           'priority' => ['name' => 'High'],
           'itilcategory' => ['name' => 'Network > VPN'],
           'entity' => ['name' => 'ROOT'],
           'requester' => ['firstname' => 'John', 'realname' => 'Doe'],
           'date_creation' => '2026-08-26 09:10:00',
       ]);

       self::assertSame(42, $ticket->id);
       self::assertSame('VPN unavailable', $ticket->title);
       self::assertSame('Processing', $ticket->status);
       self::assertSame('Network > VPN', $ticket->category);
       self::assertSame('John Doe', $ticket->requester);
   }
}
