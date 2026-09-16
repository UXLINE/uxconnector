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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Domain\Shared\ValueObject;

use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Endpoint::class)]
final class EndpointTest extends TestCase
{
   public function testValidHttpsEndpointIsAccepted(): void {
       $endpoint = new Endpoint('https://graph.microsoft.com/v1.0');

       self::assertSame('https://graph.microsoft.com/v1.0', $endpoint->value());
       self::assertTrue($endpoint->isSecure());
   }

   public function testTrailingSlashIsStripped(): void {
       $endpoint = new Endpoint('https://api.example.com/v2/');

       self::assertSame('https://api.example.com/v2', $endpoint->value());
   }

   public function testSurroundingWhitespaceIsTrimmed(): void {
       $endpoint = new Endpoint('  https://api.example.com  ');

       self::assertSame('https://api.example.com', $endpoint->value());
   }

   public function testEmptyEndpointIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new Endpoint('   ');
   }

    #[DataProvider('invalidUrlProvider')]
   public function testInvalidUrlIsRejected(string $value): void {
       $this->expectException(\InvalidArgumentException::class);

       new Endpoint($value);
   }

    /**
     * @return iterable<string, array{0: string}>
     */
   public static function invalidUrlProvider(): iterable {
       yield 'not a url' => ['not-a-url'];
       yield 'ftp scheme' => ['ftp://files.example.com'];
       yield 'javascript scheme' => ['javascript:alert(1)'];
   }


   public function testEmbeddedCredentialsAreRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new Endpoint('https://user:secret@example.com/api');
   }

   public function testQueryAndFragmentAreRejectedFromBaseEndpoint(): void {
      foreach (['https://api.example.com?token=secret', 'https://api.example.com/#fragment'] as $value) {
         try {
            new Endpoint($value);
            self::fail('Expected invalid endpoint: ' . $value);
         } catch (\InvalidArgumentException) {
             self::addToAssertionCount(1);
         }
      }
   }

   public function testEndpointExceedingMaxLengthIsRejected(): void {
       $this->expectException(\InvalidArgumentException::class);

       new Endpoint('https://example.com/' . str_repeat('a', 250));
   }

   public function testEqualsComparesByValue(): void {
       $first = new Endpoint('https://api.example.com');
       $second = new Endpoint('https://api.example.com/');
       $different = new Endpoint('https://other.example.com');

       self::assertTrue($first->equals($second));
       self::assertFalse($first->equals($different));
   }

   public function testToStringReturnsValue(): void {
       $endpoint = new Endpoint('https://api.example.com');

       self::assertSame('https://api.example.com', (string) $endpoint);
   }
}
