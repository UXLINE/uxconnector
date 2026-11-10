<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Command;

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\TestPath;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;

/**
 * Input contract for editing an existing connection.
 *
 * A null plaintextSecret means "keep the currently stored secret". A new
 * non-null value replaces it after protection by the credential vault.
 */
final readonly class UpdateAuthConfigCommand
{
    public function __construct(
        public AuthConfigId $id,
        public AuthConfigName $name,
        public Endpoint $endpoint,
        public AuthMethod $authMethod,
        public TestPath $testPath,
        public ?string $plaintextSecret = null
    ) {
    }
}
