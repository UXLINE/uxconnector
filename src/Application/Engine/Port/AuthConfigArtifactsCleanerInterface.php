<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Engine\Port;

use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;

/** Removes plugin-internal artifacts owned by a Connection/AuthConfig. */
interface AuthConfigArtifactsCleanerInterface
{
    public function cleanup(AuthConfigId $authConfigId): void;
}
