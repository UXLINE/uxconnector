<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\OutboundConfigRequestParser;
use PHPUnit\Framework\TestCase;

final class OutboundConfigRequestParserTest extends TestCase
{
   public function testAdvancedEditorCreatesGenericOutboundDefinition(): void {
       $config = (new OutboundConfigRequestParser())->parse([
           'outbound_create_method' => 'post',
           'outbound_create_path' => '/objects',
           'outbound_create_body' => '{"name":"@field:name"}',
           'outbound_update_method' => 'patch',
           'outbound_update_path' => '/objects/{{external_id}}',
           'outbound_update_body' => '{"name":"@field:name"}',
           'outbound_response_external_id_path' => 'id',
       ]);

       $payload = $config->toArray();
       self::assertSame('POST', $payload['create']['method']);
       self::assertSame('PATCH', $payload['update']['method']);
       self::assertSame('@field:name', $payload['create']['body']['name']);
       self::assertSame('id', $payload['response_external_id_path']);
   }

   public function testMalformedJsonBodyIsRejected(): void {
       $this->expectException(\RuntimeException::class);
       (new OutboundConfigRequestParser())->parse([
           'outbound_create_method' => 'POST',
           'outbound_create_path' => '/objects',
           'outbound_create_body' => '{invalid}',
       ]);
   }
}
