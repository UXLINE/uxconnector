<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Bootstrap;

use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Gateway\GlpiCopilotGateway;
use GlpiPlugin\Uxconnector\Presentation\Copilot\CopilotGatewayResponder;

/** Composition root for the stateless Copilot HTTP boundary. */
final class CopilotGatewayResponderFactory
{
    public static function create(): CopilotGatewayResponder
    {
        return new CopilotGatewayResponder(new GlpiCopilotGateway());
    }
}
