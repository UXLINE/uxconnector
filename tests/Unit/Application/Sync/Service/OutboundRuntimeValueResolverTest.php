<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Service\OutboundRuntimeValueResolver;
use PHPUnit\Framework\TestCase;

final class OutboundRuntimeValueResolverTest extends TestCase
{
   public function testBodyAndPathAreResolvedWithoutVendorKnowledge(): void {
       $resolver = new OutboundRuntimeValueResolver();
       $fields = ['name' => 'Projet A', 'code' => 'PRJA'];

       self::assertSame(
           ['name' => 'Projet A', 'optional' => null, 'kind' => 'project'],
           $resolver->resolveBody([
               'name' => '@field:name',
               'optional' => '@field_optional:missing',
               'kind' => '@literal:"project"',
           ], $fields)
       );

       self::assertSame(
           '/objects/123/42/PRJA',
           $resolver->resolvePath('/objects/{{external_id}}/{{item_id}}/{{field.code}}', $fields, 42, '123')
       );
   }

   public function testRequiredFieldIsRejectedWhenMissing(): void {
       $this->expectException(\RuntimeException::class);
       (new OutboundRuntimeValueResolver())->resolveBody('@field:missing', []);
   }
}
