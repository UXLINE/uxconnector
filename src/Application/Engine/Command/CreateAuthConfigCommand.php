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

namespace GlpiPlugin\Uxconnector\Application\Engine\Command;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Input contract of the "create an AuthConfig" use case.
 *
 * RULE (Command Object pattern, ADR "un Command par cas d'usage") :
 * immutable, strongly typed, and completely ignorant of HTTP -
 * whoever builds it (a Controller, a REST endpoint, a CLI command)
 * is responsible for converting raw input into Value Objects before
 * reaching this class.
 *
 * $plaintextSecret is the *only* place in the whole call chain where
 * a secret exists in clear form outside of the CredentialVault; it is
 * never logged, never stored as-is, and is discarded by
 * CreateAuthConfigService as soon as CredentialVaultInterface::protect()
 * has been called.
 */
final readonly class CreateAuthConfigCommand
{
    public function __construct(
        public AuthConfigName $name,
        public Endpoint $endpoint,
        public AuthMethod $authMethod,
        public ?string $plaintextSecret = null,
        public ?TestPath $testPath = null,
        public string $connectorId = 'custom'
    ) {
    }
}
