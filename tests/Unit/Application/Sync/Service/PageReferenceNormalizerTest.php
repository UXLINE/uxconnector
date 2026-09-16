<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Unit\Application\Sync\Service;

use GlpiPlugin\Uxconnector\Application\Sync\Service\PageReferenceNormalizer;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use PHPUnit\Framework\TestCase;

final class PageReferenceNormalizerTest extends TestCase
{
   private function auth(string $endpoint): AuthConfig {
       return AuthConfig::create(new AuthConfigName('API'), new Endpoint($endpoint), AuthMethod::NONE);
   }

   public function testAbsoluteNextLinkOnSameEndpointBecomesResourcePath(): void {
       self::assertSame(
           '/v1/records?page=2',
           (new PageReferenceNormalizer())->normalize(
               $this->auth('https://api.example.com'),
               'https://api.example.com/v1/records?page=2'
           )
       );
   }

   public function testGatewayBasePathIsRemovedFromAbsoluteNextLink(): void {
       self::assertSame(
           '/rest/items?page=2',
           (new PageReferenceNormalizer())->normalize(
               $this->auth('https://api.example.com/gateway/tenant'),
               'https://api.example.com/gateway/tenant/rest/items?page=2'
           )
       );
   }

   public function testCrossOriginNextLinkIsRejected(): void {
       $this->expectException(\RuntimeException::class);

       (new PageReferenceNormalizer())->normalize(
           $this->auth('https://api.example.com'),
           'https://evil.example.net/records?page=2'
       );
   }
}
