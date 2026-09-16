<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Copilot\Controller;

use GlpiPlugin\Uxconnector\Application\Copilot\Service\CreateCopilotClientService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\DeleteCopilotClientService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\ListCopilotClientsService;
use GlpiPlugin\Uxconnector\Application\Copilot\Service\SetCopilotClientActiveService;
use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use Session;

final class CopilotAgentController extends AbstractController
{
   public function __construct(
        private readonly ListCopilotClientsService $listClients,
        private readonly CreateCopilotClientService $createClient,
        private readonly SetCopilotClientActiveService $setClientActive,
        private readonly DeleteCopilotClientService $deleteClient,
        private readonly ListAuthConfigsService $listConnections,
    ) {}

   public function index(?string $generatedKey = null): void {
       $connections = [];
      foreach ($this->listConnections->execute() as $connection) {
         if ($connection->status !== 'active') {
            continue;
         }
          $connections[(int) $connection->id] = sprintf('#%d — %s', (int) $connection->id, (string) $connection->name);
      }

       $this->render('copilot/agents.html.twig', [
           'clients' => $this->listClients->execute(),
           'connections' => $connections,
           'generated_key' => $generatedKey,
           'can_create' => Session::haveRight('plugin_uxconnector_authconfig', CREATE),
           'can_update' => Session::haveRight('plugin_uxconnector_authconfig', UPDATE),
           'can_delete' => Session::haveRight('plugin_uxconnector_authconfig', PURGE),
       ]);
   }

   /**
    * @param array<string, mixed> $request
    */
   public function create(array $request): void {
      try {
          $created = $this->createClient->execute(
              (string) ($request['name'] ?? ''),
              (string) ($request['provider_type'] ?? 'local_glpi'),
              (int) ($request['auth_config_reference'] ?? 0),
          );
          // Render immediately so the generated secret never appears in a URL
          // and is only displayed once to the administrator.
          $this->index((string) $created['key']);
      } catch (\Throwable $e) {
          Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
          $this->redirect('/plugins/uxconnector/front/copilot-agents.php');
      }
   }

   /**
    * @param array<string, mixed> $request
    */
   public function setActive(array $request, bool $active): void {
      try {
          $this->setClientActive->execute((int) ($request['id'] ?? 0), $active);
          Session::addMessageAfterRedirect($active ? __('Copilot agent enabled.', 'uxconnector') : __('Copilot agent disabled.', 'uxconnector'));
      } catch (\Throwable $e) {
          Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
      }
       $this->redirect('/plugins/uxconnector/front/copilot-agents.php');
   }

   /**
    * @param array<string, mixed> $request
    */
   public function delete(array $request): void {
      try {
          $this->deleteClient->execute((int) ($request['id'] ?? 0));
          Session::addMessageAfterRedirect(__('Copilot agent deleted.', 'uxconnector'));
      } catch (\Throwable $e) {
          Session::addMessageAfterRedirect($e->getMessage(), false, ERROR);
      }
       $this->redirect('/plugins/uxconnector/front/copilot-agents.php');
   }
}
