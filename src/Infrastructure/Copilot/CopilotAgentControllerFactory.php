<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot;

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Copilot\Controller\CopilotAgentController;

final class CopilotAgentControllerFactory
{
   public static function create(): CopilotAgentController {
       return new CopilotAgentController(
           CopilotServiceProvider::listClientsService(),
           CopilotServiceProvider::createClientService(),
           CopilotServiceProvider::setClientActiveService(),
           CopilotServiceProvider::deleteClientService(),
           EngineServiceProvider::listAuthConfigsService(),
       );
   }
}
