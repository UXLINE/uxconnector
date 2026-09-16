<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Application\Engine\Service\ListAuthConfigsService;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ActivateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\CreateSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Command\PauseSyncJobCommand;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ActivateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\CreateSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ExecuteSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\GetSyncJobService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncExecutionsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\ListSyncJobsService;
use GlpiPlugin\Uxconnector\Application\Sync\Service\PauseSyncJobService;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\InteractionTrigger;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\Policy\TargetFieldPolicy;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\CollectionPath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\OutboundConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\PaginationConfig;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobId;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Presentation\Controller\AbstractController;
use Session;

/**
 * Presentation entry point for the Sync bounded context's SyncJob
 * screens.
 *
 * RULE-CTRL-001/002/003 : orchestrates Application Services and hands
 * their DTOs/results to a template or a redirect — no business rule,
 * no SQL, no third-party API call ever happens here.
 */
final class SyncJobController extends AbstractController
{
   public function __construct(
        private readonly ListSyncJobsService $listSyncJobs,
        private readonly CreateSyncJobService $createSyncJob,
        private readonly ListAuthConfigsService $listAuthConfigs,
        private readonly FieldMappingRequestParser $fieldMappingParser,
        private readonly ActivateSyncJobService $activateSyncJob,
        private readonly PauseSyncJobService $pauseSyncJob,
        private readonly ExecuteSyncJobService $executeSyncJob,
        private readonly EntityAssignmentStrategyRequestParser $entityAssignmentStrategyParser,
        private readonly ListSyncExecutionsService $listSyncExecutions,
        private readonly ?PaginationConfigRequestParser $paginationConfigParser = null,
        private readonly ?GetSyncJobService $getSyncJob = null
    ) {
   }

    /**
     * Displays every configured SyncJob.
     */
   public function index(): void {
       $syncJobs = $this->listSyncJobs->execute();

       $this->render('sync/syncjob/list.html.twig', [
           'syncjobs'    => $syncJobs,
           'can_create'  => Session::haveRight('plugin_uxconnector_syncjob', CREATE),
           'can_update'  => Session::haveRight('plugin_uxconnector_syncjob', UPDATE),
       ]);
   }

    /**
     * Displays the creation form.
     *
     * The AuthConfig dropdown is populated from ListAuthConfigsService
     * (Engine bounded context) — the same cross-context Application-layer
     * meeting point already used by ExecuteSyncJobService, here for
     * reading rather than resolving a reference at execution time.
     *
     * CORRECTIF P0 (revue de code externe) : seule la direction
     * réellement exécutée par ExecuteSyncJobService (INBOUND) est
     * proposée (SyncDirection::isImplemented()) — avant ce correctif,
     * OUTBOUND et BIDIRECTIONAL étaient sélectionnables mais
     * s'exécutaient silencieusement comme INBOUND.
     */
    /** @param array<string,mixed> $defaults */
   public function showCreateForm(array $defaults = []): void {
       $targetFieldPolicy = new TargetFieldPolicy();
       $targetFieldCatalog = [];

      foreach (TargetItemtype::cases() as $itemtype) {
          $targetFieldCatalog[] = [
              'value' => $itemtype->value,
              'label' => $itemtype->label(),
              'writable' => $targetFieldPolicy->allowedWritableFields($itemtype),
              'matchable' => $targetFieldPolicy->allowedMatchFields($itemtype),
          ];
      }

       $this->render('sync/syncjob/form.html.twig', [
           'authConfigs'    => $this->listAuthConfigs->execute(),
           'directions'     => [SyncDirection::INBOUND, SyncDirection::OUTBOUND],
           'triggers'       => InteractionTrigger::cases(),
           'targetItemtypes' => TargetItemtype::cases(),
           'targetFieldCatalog' => $targetFieldCatalog,
           'defaults' => [
               'auth_config_id' => (int) ($defaults['auth_config_id'] ?? 0),
           ],
       ]);
   }

    /**
     * Handles the creation form submission.
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
   public function create(array $request): void {
      try {
          [$direction, $trigger] = $this->resolveAdvancedMode($request);
          $this->assertAdvancedInteractionCombination($direction, $trigger);

          $resourcePath = trim((string) ($request['resource_path'] ?? ''));
         if ($trigger === InteractionTrigger::WEBHOOK && $resourcePath === '') {
            $resourcePath = '/webhook';
         }
         if ($direction === SyncDirection::OUTBOUND && $resourcePath === '') {
             $resourcePath = '/outbound';
         }

          $collectionPath = $direction === SyncDirection::OUTBOUND
              ? CollectionPath::root()
              : new CollectionPath((string) ($request['collection_path'] ?? '$'));
          $pagination = ($direction === SyncDirection::INBOUND && $trigger === InteractionTrigger::POLLING)
              ? ($this->paginationConfigParser ?? new PaginationConfigRequestParser())->parse($request)
              : PaginationConfig::none();
          $outbound = $direction === SyncDirection::OUTBOUND
              ? (new OutboundConfigRequestParser())->parse($request)
              : OutboundConfig::none();
          $entityAssignment = $this->entityAssignmentStrategyParser->parse($request);
         if ($direction === SyncDirection::OUTBOUND && $entityAssignment !== null && !$entityAssignment->isFixed()) {
             throw new \RuntimeException('Advanced outbound interactions support only a fixed GLPI entity as source scope.');
         }

          $command = new CreateSyncJobCommand(
              new SyncJobName((string) ($request['name'] ?? '')),
              new AuthConfigReference((int) ($request['auth_config_reference'] ?? 0)),
              $direction,
              new ResourcePath($resourcePath),
              TargetItemtype::from((string) ($request['target_itemtype'] ?? '')),
              new MatchField((string) ($request['match_field'] ?? '')),
              new ExternalIdField((string) ($request['external_id_field'] ?? '')),
              $direction === SyncDirection::INBOUND ? $this->fieldMappingParser->parse($request) : [],
              $entityAssignment,
              $collectionPath,
              $pagination,
              null,
              null,
              $trigger,
              trim((string) ($request['external_object_type'] ?? '')) ?: (string) ($request['target_itemtype'] ?? 'record'),
              $outbound,
              $trigger === InteractionTrigger::WEBHOOK ? bin2hex(random_bytes(32)) : null
          );

          $job = $this->createSyncJob->execute($command);

          Session::addMessageAfterRedirect(
              $trigger === InteractionTrigger::WEBHOOK
                  ? __('Webhook interaction created. Open the connection to copy its generated webhook endpoint.', 'uxconnector')
                  : __('Interaction created.', 'uxconnector')
          );

          $connectionId = (int) ($request['auth_config_reference'] ?? 0);
          $this->redirect($connectionId > 0
              ? '/plugins/uxconnector/front/connection.php?id=' . $connectionId
              : '/plugins/uxconnector/front/syncjob.php');
      } catch (\Throwable $exception) {
          Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);

          $connectionId = (int) ($request['auth_config_reference'] ?? 0);
          $this->redirect('/plugins/uxconnector/front/syncjob.form.php' . ($connectionId > 0 ? '?auth_config_id=' . $connectionId : ''));
      }
   }

       /**
    * @param array<string, mixed> $request
    *
    * @return array{SyncDirection, InteractionTrigger}
    */
   private function resolveAdvancedMode(array $request): array {
       $mode = trim((string) ($request['interaction_mode'] ?? ''));
      if ($mode !== '') {
          return match ($mode) {
              'inbound_polling' => [SyncDirection::INBOUND, InteractionTrigger::POLLING],
              'inbound_webhook' => [SyncDirection::INBOUND, InteractionTrigger::WEBHOOK],
              'outbound_glpi_event' => [SyncDirection::OUTBOUND, InteractionTrigger::GLPI_EVENT],
              default => throw new \RuntimeException('Unknown advanced interaction mode.'),
          };
      }

       return [
           SyncDirection::from((string) ($request['direction'] ?? SyncDirection::INBOUND->value)),
           InteractionTrigger::from((string) ($request['trigger_type'] ?? InteractionTrigger::POLLING->value)),
       ];
   }

   private function assertAdvancedInteractionCombination(SyncDirection $direction, InteractionTrigger $trigger): void {
      if ($direction === SyncDirection::INBOUND && !in_array($trigger, [InteractionTrigger::POLLING, InteractionTrigger::WEBHOOK], true)) {
          throw new \RuntimeException('Advanced inbound interactions support polling or webhook triggers.');
      }
      if ($direction === SyncDirection::OUTBOUND && $trigger !== InteractionTrigger::GLPI_EVENT) {
          throw new \RuntimeException('Advanced outbound interactions are triggered by GLPI item events.');
      }
   }

    /**
     * CYCLE D'ACTIVATION (issu des deux relectures externes) : sans ce
     * point d'entrée, un SyncJob créé depuis l'UI restait bloqué en
     * DRAFT pour toujours — SyncJobCronTask ne reprend que les jobs
     * retournés par findRunnable().
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
   public function activate(array $request): void {
      try {
          $id = new SyncJobId((int) ($request['id'] ?? 0));
          $this->activateSyncJob->execute(new ActivateSyncJobCommand($id));

          Session::addMessageAfterRedirect(__('Synchronization job activated.', 'uxconnector'));
      } catch (\Throwable $exception) {
          Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
      }

       $this->redirect('/plugins/uxconnector/front/syncjob.php');
   }

    /**
     * @param array<string, mixed> $request Typically $_POST.
     */
   public function pause(array $request): void {
      try {
          $id = new SyncJobId((int) ($request['id'] ?? 0));
          $this->pauseSyncJob->execute(new PauseSyncJobCommand($id));

          Session::addMessageAfterRedirect(__('Synchronization job paused.', 'uxconnector'));
      } catch (\Throwable $exception) {
          Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
      }

       $this->redirect('/plugins/uxconnector/front/syncjob.php');
   }


   /** @param array<string, mixed> $request */
   public function bulkActivate(array $request): void {
      try {
         $ids = $this->selectedJobIds($request);
         $activated = 0;
         $failed = 0;

         foreach ($ids as $rawId) {
            try {
               $this->activateSyncJob->execute(new ActivateSyncJobCommand(new SyncJobId($rawId)));
               $activated++;
            } catch (\Throwable) {
               $failed++;
            }
         }

         $message = sprintf(
            __('Bulk activation finished: %d activated, %d failed.', 'uxconnector'),
            $activated,
            $failed
         );
         if ($failed === 0) {
            Session::addMessageAfterRedirect($message);
         } else {
            Session::addMessageAfterRedirect($message, false, WARNING);
         }
      } catch (\Throwable $exception) {
         Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
      }
      $this->redirect('/plugins/uxconnector/front/syncjob.php');
   }

   /** @param array<string, mixed> $request */
   public function bulkPause(array $request): void {
      try {
         $ids = $this->selectedJobIds($request);
         $paused = 0;
         $failed = 0;

         foreach ($ids as $rawId) {
            try {
               $this->pauseSyncJob->execute(new PauseSyncJobCommand(new SyncJobId($rawId)));
               $paused++;
            } catch (\Throwable) {
               $failed++;
            }
         }

         $message = sprintf(
            __('Bulk pause finished: %d paused, %d failed.', 'uxconnector'),
            $paused,
            $failed
         );
         if ($failed === 0) {
            Session::addMessageAfterRedirect($message);
         } else {
            Session::addMessageAfterRedirect($message, false, WARNING);
         }
      } catch (\Throwable $exception) {
         Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
      }
      $this->redirect('/plugins/uxconnector/front/syncjob.php');
   }

   /**
    * @param array<string, mixed> $request
    * @return list<int>
    */
   private function selectedJobIds(array $request): array {
      $rawIds = $request['ids'] ?? [];
      if (!is_array($rawIds)) {
         throw new \RuntimeException(__('Invalid job selection.', 'uxconnector'));
      }

      $ids = [];
      foreach (array_slice($rawIds, 0, 500) as $rawId) {
         if (!is_scalar($rawId)) {
            continue;
         }
         $id = (int) $rawId;
         if ($id > 0) {
            $ids[$id] = $id;
         }
      }
      if ($ids === []) {
         throw new \RuntimeException(__('Select at least one synchronization job.', 'uxconnector'));
      }

      return array_values($ids);
   }

    /**
     * Runs a SyncJob immediately, outside of the hourly schedule —
     * reuses ExecuteSyncJobService directly rather than a dedicated
     * "RunNow" Service: the use case is identical to what
     * SyncJobCronTask triggers, only the caller differs.
     *
     * @param array<string, mixed> $request Typically $_POST.
     */
   public function runNow(array $request): void {
      try {
          $id = new SyncJobId((int) ($request['id'] ?? 0));
          $execution = $this->executeSyncJob->execute(new ExecuteSyncJobCommand($id));

          Session::addMessageAfterRedirect(sprintf(
              __('Execution finished: %s (%d processed, %d failed).', 'uxconnector'),
              $execution->status()->label(),
              $execution->itemsProcessed(),
              $execution->itemsFailed()
          ));
      } catch (\Throwable $exception) {
          Session::addMessageAfterRedirect($exception->getMessage(), false, ERROR);
      }

       $this->redirect('/plugins/uxconnector/front/syncjob.php');
   }
   /**
       * Displays the complete persisted configuration of one SyncJob.
       *
       * @param array<string, mixed> $request Typically $_GET.
       */
   public function showDetails(array $request): void {
      if ($this->getSyncJob === null) {
         throw new \LogicException('GetSyncJobService is not configured.');
      }

      $id = new SyncJobId((int) ($request['id'] ?? 0));

      $this->render('sync/syncjob/view.html.twig', [
         'syncjob' => $this->getSyncJob->execute($id),
         'can_update' => Session::haveRight(
            'plugin_uxconnector_syncjob',
            UPDATE
         ),
      ]);
   }
   /**
    * Displays the execution history of a synchronization job.
    *
    * Pure read action: READ permission is sufficient.
    *
    * @param array<string, mixed> $request Typically $_GET.
    */
   public function history(array $request): void {
       $id = new SyncJobId((int) ($request['id'] ?? 0));

       $this->render('sync/syncjob/history.html.twig', [
           'syncJobId'  => $id->value(),
           'executions' => $this->listSyncExecutions->execute($id),
       ]);
   }
}
