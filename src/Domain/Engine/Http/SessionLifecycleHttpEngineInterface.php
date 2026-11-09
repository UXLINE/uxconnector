<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Engine\Http;

use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;

/** Optional lifecycle capability for authentication schemes owning a remote session. */
interface SessionLifecycleHttpEngineInterface extends HttpEngineInterface
{
    public function closeSession(AuthConfig $authConfig): void;
}
