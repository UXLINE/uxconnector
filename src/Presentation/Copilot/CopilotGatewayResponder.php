<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Copilot;

use GlpiPlugin\Uxconnector\Infrastructure\Copilot\CopilotServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Security\CopilotApiKeyAuthenticator;

final class CopilotGatewayResponder
{
   public function capabilities(): void {
       $client = $this->authenticate();
      if ($client === null) {
          return;
      }
       $this->json(200, CopilotServiceProvider::providerForClient($client)->capabilities());
   }

   public function searchTickets(): void {
       $client = $this->authenticate();
      if ($client === null) {
          return;
      }
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
          $this->json(405, ['error' => 'method_not_allowed']);
          return;
      }
       $payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
      if (!is_array($payload)) {
          throw new \InvalidArgumentException('JSON body must be an object.');
      }
       $this->json(200, CopilotServiceProvider::searchTicketsService($client)->execute($payload));
   }

   public function ticketContext(): void {
       $client = $this->authenticate();
      if ($client === null) {
          return;
      }
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
          $this->json(405, ['error' => 'method_not_allowed']);
          return;
      }
       $id = (int) ($_GET['id'] ?? 0);
       $this->json(200, CopilotServiceProvider::getTicketContextService($client)->execute($id));
   }


   public function analytics(string $tool): void {
       $client = $this->authenticate();
      if ($client === null) {
          return;
      }
      if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
          $this->json(405, ['error' => 'method_not_allowed']);
          return;
      }
       $payload = json_decode((string) file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);
      if (!is_array($payload)) {
          throw new \InvalidArgumentException('JSON body must be an object.');
      }
       $service = CopilotServiceProvider::analyticsService($client);
       $result = match ($tool) {
           'ticket_summary' => $service->ticketSummary($payload),
           'ticket_trends' => $service->ticketTrends($payload),
           'categories' => $service->categories($payload),
           'sla' => $service->sla($payload),
           'sla_breaches' => $service->slaBreaches($payload),
           'groups' => $service->groups($payload),
           'technicians' => $service->technicians($payload),
           'compare' => $service->compare($payload),
           'incidentology_groups' => $service->listIncidentologyGroups($payload),
           'incidentology_global' => $service->incidentologyGlobal($payload),
           'incidentology_typologies' => $service->incidentologyTypologies($payload),
           'semantic_ticket_classification' => \GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\SemanticServiceProvider::analysisService()->execute($payload),
           'semantic_prepare' => \GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\SemanticServiceProvider::prepareClassificationService()->execute($payload),
           'semantic_finalize' => \GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\SemanticServiceProvider::finalizeClassificationService()->execute($payload),
           default => throw new \InvalidArgumentException('Unknown analytics tool.'),
       };
         $this->json(200, $result);
   }

    /** @return array<string,mixed>|null */
   private function authenticate(): ?array {
       $key = trim((string) ($_SERVER['HTTP_X_UXCONNECTOR_COPILOT_KEY'] ?? ''));
       $client = (new CopilotApiKeyAuthenticator())->authenticate($key);
      if ($client === null) {
          $this->json(401, ['error' => 'unauthorized']);
          return null;
      }
       return $client;
   }

    /** @param array<string,mixed> $payload */
   private function json(int $status, array $payload): void {
       CopilotHttpStatus::send($status);
       header('Content-Type: application/json; charset=utf-8');
       header('Cache-Control: no-store');
       echo json_encode($payload, JSON_UNESCAPED_SLASHES);
   }
}
