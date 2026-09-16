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

namespace GlpiPlugin\Uxconnector\Tests\Unit\Infrastructure\Engine\Security;

use GlpiPlugin\Uxconnector\Infrastructure\Engine\Security\OpenSslCredentialVault;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(OpenSslCredentialVault::class)]
final class OpenSslCredentialVaultTest extends TestCase
{
   public function testProtectThenRevealReturnsOriginalSecret(): void {
       $vault = new OpenSslCredentialVault('test-instance-key-1234567890');

       $protected = $vault->protect('super-secret-api-key');

       self::assertNotSame('super-secret-api-key', $protected);
       self::assertSame('super-secret-api-key', $vault->reveal($protected));
   }

   public function testProtectedPayloadIsOpaqueAndReversible(): void {
        $vault = new OpenSslCredentialVault(
            'test-instance-key-1234567890'
        );

        $protected = $vault->protect('anything');

        self::assertNotSame('', $protected);
        self::assertNotSame('anything', $protected);
        self::assertSame('anything', $vault->reveal($protected));
    }

   public function testProtectingTheSameSecretTwiceProducesDifferentPayloads(): void {
       $vault = new OpenSslCredentialVault('test-instance-key-1234567890');

       $first = $vault->protect('same-secret');
       $second = $vault->protect('same-secret');

       // Random IV guarantees payload uniqueness (semantic security).
       self::assertNotSame($first, $second);
       self::assertSame('same-secret', $vault->reveal($first));
       self::assertSame('same-secret', $vault->reveal($second));
   }

   public function testEmptyInstanceKeyIsRejected(): void {
       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::class);

       new OpenSslCredentialVault('');
   }

   public function testRevealFailsOnMalformedPayload(): void {
       $vault = new OpenSslCredentialVault('test-instance-key-1234567890');

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::class);

       $vault->reveal('not-a-valid-payload');
   }

   public function testRevealFailsWhenPayloadIsTamperedWith(): void {
        $vault = new OpenSslCredentialVault(
            'test-instance-key-1234567890'
        );

        $protected = $vault->protect('super-secret-api-key');

        $position = intdiv(strlen($protected), 2);

        $replacement = $protected[$position] === 'A'
            ? 'B'
            : 'A';

        $tampered = substr($protected, 0, $position)
            . $replacement
            . substr($protected, $position + 1);

        $this->expectException(
            \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::class
        );

        $vault->reveal($tampered);
    }

   public function testRevealFailsWithADifferentInstanceKey(): void {
       $vaultA = new OpenSslCredentialVault('instance-key-a');
       $vaultB = new OpenSslCredentialVault('instance-key-b');

       $protected = $vaultA->protect('super-secret-api-key');

       $this->expectException(\GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::class);

       $vaultB->reveal($protected);
   }

   public function testEmptyPlaintextSecretIsRejected(): void {
        $vault = new OpenSslCredentialVault(
            'test-instance-key-1234567890'
        );

        $this->expectException(
            \GlpiPlugin\Uxconnector\Domain\Engine\Exception\CredentialVaultException::class
        );

        $vault->protect('');
    }
}
