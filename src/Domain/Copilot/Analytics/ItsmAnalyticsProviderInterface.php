<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Domain\Copilot\Analytics;

interface ItsmAnalyticsProviderInterface
{
       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function ticketSummary(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function ticketTrends(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function categoryAnalytics(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function slaAnalytics(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function groupAnalytics(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function technicianAnalytics(array $criteria): array;

       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function comparePeriods(array $criteria): array;

    /**
     * Incidentology analysis scoped by initial technical assignment group.
     *
     * @param array<string,mixed> $criteria
     *
     * @return array<string,mixed>
     */
       /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function listIncidentologyGroups(array $criteria): array;

   /**
    * @param array<string, mixed> $criteria
    *
    * @return array<string, mixed>
    */
   public function incidentologyGlobal(array $criteria): array;

    /**
     * Incidentologue analysis by validated qualitative typology mapping.
     *
     * @param array<string,mixed> $criteria
     *
     * @return array<string,mixed>
     */
   public function incidentologyTypologies(array $criteria): array;
}
