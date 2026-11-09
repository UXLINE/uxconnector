<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\Copilot\Gateway;

use GlpiPlugin\Uxconnector\Application\Copilot\Gateway\CopilotGatewayInterface;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\CopilotServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Security\CopilotApiKeyAuthenticator;
use GlpiPlugin\Uxconnector\Infrastructure\Copilot\Semantic\SemanticServiceProvider;

/** Infrastructure adapter wiring GLPI persistence/security to the Copilot port. */
final class GlpiCopilotGateway implements CopilotGatewayInterface
{
    /** @return array<string,mixed>|null */
    public function authenticate(string $apiKey): ?array
    {
        return (new CopilotApiKeyAuthenticator())->authenticate($apiKey);
    }

    public function capabilities(array $client): array
    {
        return CopilotServiceProvider::providerForClient($client)->capabilities();
    }

    public function searchTickets(array $client, array $payload): array
    {
        return CopilotServiceProvider::searchTicketsService($client)->execute($payload);
    }

    public function ticketContext(array $client, int $ticketId): array
    {
        return CopilotServiceProvider::getTicketContextService($client)->execute($ticketId);
    }

    public function analytics(array $client, string $tool, array $payload): array
    {
        $service = CopilotServiceProvider::analyticsService($client);

        return match ($tool) {
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
            'semantic_ticket_classification' => SemanticServiceProvider::analysisService()->execute($payload),
            'semantic_prepare' => SemanticServiceProvider::prepareClassificationService()->execute($payload),
            'semantic_finalize' => SemanticServiceProvider::finalizeClassificationService()->execute($payload),
            default => throw new \InvalidArgumentException('Unknown analytics tool.'),
        };
    }
}
