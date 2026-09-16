<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Engine\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestPath::class)]
final class TestPathTest extends TestCase
{
   public function testEmptyValueDefaultsToRoot(): void {
       self::assertSame('/', (new TestPath(''))->value());
   }

   public function testRelativeApiPathIsAccepted(): void {
       self::assertSame('/rest/api/3/project', (new TestPath('/rest/api/3/project'))->value());
   }

   public function testPathWithoutLeadingSlashIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);
       new TestPath('rest/api/3/project');
   }

   public function testProtocolRelativeUrlIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);
       new TestPath('//evil.example.test/path');
   }
}
