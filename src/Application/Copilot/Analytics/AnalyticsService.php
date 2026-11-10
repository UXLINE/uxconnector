<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Application\Copilot\Analytics;

use GlpiPlugin\Uxconnector\Domain\Copilot\Analytics\ItsmAnalyticsProviderInterface;

final readonly class AnalyticsService
{
    public function __construct(private ItsmAnalyticsProviderInterface $provider)
    {
    }

    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function ticketSummary(array $criteria): array
    {
        return $this->provider->ticketSummary($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function ticketTrends(array $criteria): array
    {
        return $this->provider->ticketTrends($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function categories(array $criteria): array
    {
        return $this->provider->categoryAnalytics($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function sla(array $criteria): array
    {
        return $this->provider->slaAnalytics($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function slaBreaches(array $criteria): array
    {
        $result = $this->provider->slaAnalytics($criteria);
        return [
            'period' => $result['period'] ?? [],
            'tickets_scanned' => $result['tickets_scanned'] ?? 0,
            'tto_breaches' => $result['tto']['breached'] ?? 0,
            'ttr_breaches' => $result['ttr']['breached'] ?? 0,
            'tto_currently_breached' => $result['tto']['currently_breached'] ?? 0,
            'ttr_currently_breached' => $result['ttr']['currently_breached'] ?? 0,
            'tto_not_evaluable' => $result['tto']['not_evaluable'] ?? 0,
            'ttr_not_evaluable' => $result['ttr']['not_evaluable'] ?? 0,
            'breaches' => $result['breaches'] ?? [],
            'note' => $result['note'] ?? null,
        ];
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function groups(array $criteria): array
    {
        return $this->provider->groupAnalytics($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function technicians(array $criteria): array
    {
        return $this->provider->technicianAnalytics($criteria);
    }
    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function compare(array $criteria): array
    {
        return $this->provider->comparePeriods($criteria);
    }

    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function listIncidentologyGroups(array $criteria): array
    {
        return $this->provider->listIncidentologyGroups($criteria);
    }

    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function incidentologyGlobal(array $criteria): array
    {
        return $this->provider->incidentologyGlobal($criteria);
    }

    /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
    public function incidentologyTypologies(array $criteria): array
    {
        return $this->provider->incidentologyTypologies($criteria);
    }
}
