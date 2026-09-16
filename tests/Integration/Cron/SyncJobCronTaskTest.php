<?php

/**
 * -------------------------------------------------------------------------
 * UXConnector plugin for GLPI
 * -------------------------------------------------------------------------
 *
 * @copyright HELPLINE
 * @license   GPL-3.0-or-later
 * -------------------------------------------------------------------------
 *
 * Nécessite une instance GLPI de test réelle (GLPI_ROOT défini) ainsi
 * qu'un serveur HTTP de test accessible en boucle locale
 * (UXCONNECTOR_TEST_HTTP_ENDPOINT, ex. démarré via
 * `php -S 127.0.0.1:8099` servant un payload JSON statique — voir
 * les scripts de smoke test déjà utilisés en développement).
 *
 * CORRECTIF P0 (revue de code externe) : la version précédente de ce
 * fichier appelait directement SyncJobCronTask::cronSyncJob(), qui
 * exécute *tous* les SyncJob actifs de la base — pas seulement celui
 * créé par le test. Lancé sur une instance partagée (helpline-datawise
 * ou toute base contenant déjà des synchronisations réelles actives),
 * ce test aurait déclenché leur exécution en même temps que la sienne.
 * Cette classe distingue désormais explicitement :
 *  - un scénario isolé (ExecuteSyncJobService, ne touche que le job du
 *    test), qui reste le test par défaut ;
 *  - un scénario couvrant le comportement réel de cronSyncJob() (« tous
 *    les jobs actifs »), verrouillé derrière une variable d'environnement
 *    explicite, à n'activer QUE sur une base GLPI jetable et dédiée.
 * Le Computer potentiellement créé par GlpiItemWriter est également
 * nettoyé en tearDown(), ce que la version précédente omettait.
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Tests\Integration\Cron;

use Computer;
use CronTask;
use GlpiPlugin\Uxconnector\Application\Sync\Command\ExecuteSyncJobCommand;
use GlpiPlugin\Uxconnector\Domain\Engine\Entity\AuthConfig;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigName;
use GlpiPlugin\Uxconnector\Domain\Shared\ValueObject\Endpoint;
use GlpiPlugin\Uxconnector\Domain\Sync\Entity\SyncJob;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\SyncDirection;
use GlpiPlugin\Uxconnector\Domain\Sync\Enum\TargetItemtype;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\AuthConfigReference;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ExternalIdField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\FieldMapping;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\MatchField;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\ResourcePath;
use GlpiPlugin\Uxconnector\Domain\Sync\ValueObject\SyncJobName;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionPersistenceMapper;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\Persistence\SyncExecutionRepository;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * @covers \GlpiPlugin\Uxconnector\Infrastructure\Sync\Cron\SyncJobCronTask
 */
#[Group('integration')]
#[CoversClass(SyncJobCronTask::class)]
final class SyncJobCronTaskTest extends TestCase
{
   private const TEST_SERIAL = 'UXCONNECTOR-INTEGRATION-TEST-SERIAL';

   private ?AuthConfig $authConfig = null;
   private ?SyncJob $syncJob = null;

   protected function setUp(): void {
      if (!defined('GLPI_ROOT')) {
          self::markTestSkipped('Requires a real GLPI instance (GLPI_ROOT).');
      }

      if (getenv('UXCONNECTOR_TEST_HTTP_ENDPOINT') === false) {
          self::markTestSkipped(
              'Requires UXCONNECTOR_TEST_HTTP_ENDPOINT (a local test HTTP server ' .
              'serving a JSON array of {"serialNumber": "...", "mail": "..."} items, ' .
              'with at least one item using serialNumber="' . self::TEST_SERIAL . '").'
          );
      }
   }

    /**
     * Safe by construction: executes only the SyncJob created by this
     * test, through ExecuteSyncJobService directly — never touches any
     * other SyncJob that may exist in the database. This is the test
     * that should run in ordinary CI against a shared test database.
     */
   public function testExecuteSyncJobServiceRunsOnlyThisTestsJobAndRecordsAnExecution(): void {
       $this->createFixtures();

       $execution = SyncServiceProvider::executeSyncJobService()->execute(
           new ExecuteSyncJobCommand($this->syncJob->id())
       );

       self::assertNotSame('failed', $execution->status()->value, (string) $execution->errorMessage());

       $history = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());
       self::assertNotEmpty(
           $history->findBySyncJobId($this->syncJob->id()),
           'Expected at least one SyncExecution to have been recorded.'
       );
   }

    /**
     * Covers the actual behaviour of the GLPI scheduler entry point:
     * cronSyncJob() runs EVERY currently active SyncJob, not just one.
     *
     * DANGER : ce test exécute réellement toutes les synchronisations
     * actives de la base ciblée. Il ne doit JAMAIS être lancé contre
     * une instance partagée ou de production — uniquement contre une
     * base GLPI jetable, dédiée aux tests d'intégration, où la
     * présence d'autres SyncJob actifs est soit impossible soit sans
     * conséquence. D'où la double garde : le skip par défaut, levé
     * uniquement par une variable d'environnement au nom explicite.
     */
   public function testCronSyncJobExecutesEveryActiveSyncJobInTheDatabase(): void {
      if (getenv('UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST') !== '1') {
          self::markTestSkipped(
              'SyncJobCronTask::cronSyncJob() executes EVERY active SyncJob ' .
              'currently in the database, not just the one created by this test. ' .
              'Set UXCONNECTOR_ALLOW_DESTRUCTIVE_CRON_TEST=1 only against a ' .
              'disposable, dedicated test GLPI instance — never against a shared ' .
              'or production database.'
          );
      }

       $this->createFixtures();

       $task = new CronTask();
       $returnCode = SyncJobCronTask::cronSyncJob($task);

       self::assertSame(1, $returnCode, 'Expected the CronTask to report it ran at least one job.');

       $history = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());
       self::assertNotEmpty($history->findBySyncJobId($this->syncJob->id()));
   }

   private function createFixtures(): void {
       $authConfigRepository = EngineServiceProvider::authConfigRepository();
       $this->authConfig = AuthConfig::create(
           new AuthConfigName('Integration Test - Cron AuthConfig'),
           new Endpoint((string) getenv('UXCONNECTOR_TEST_HTTP_ENDPOINT')),
           AuthMethod::NONE
       );
       // CORRECTIF (revue de code externe) : sans ces deux appels,
       // l'AuthConfig reste en statut DRAFT — AuthConfig::isUsable()
       // exige ACTIVE, donc ExecuteSyncJobService aurait rejeté
       // l'exécution avec SyncJobAuthConfigUnavailableException et ce
       // test aurait échoué à sa propre assertion de statut.
       $this->authConfig->markTesting();
       $this->authConfig->activate();
       $this->authConfig = $authConfigRepository->save($this->authConfig);

       $syncJobRepository = SyncServiceProvider::syncJobRepository();
       $this->syncJob = SyncJob::create(
           new SyncJobName('Integration Test - Cron SyncJob'),
           new AuthConfigReference($this->authConfig->id()->value()),
           SyncDirection::INBOUND,
           new ResourcePath('/echo-items'),
           TargetItemtype::COMPUTER,
           new MatchField('serial'),
           new ExternalIdField('id'),
           [new FieldMapping('serialNumber', 'serial', required: true)]
       );
       $this->syncJob->activate();
       $this->syncJob = $syncJobRepository->save($this->syncJob);
   }

    /**
     * Cleans up not only the fixtures this test created directly, but
     * also the Computer GlpiItemWriter may have written, and every
     * SyncExecution the run(s) may have produced — the two gaps the
     * code review flagged: "il pollue progressivement la CMDB de
     * test" and the execution history growing unboundedly on a
     * persistent CI database.
     */
   protected function tearDown(): void {
      if ($this->syncJob?->id() !== null) {
          $executionRepository = new SyncExecutionRepository(new SyncExecutionPersistenceMapper());

         foreach ($executionRepository->findBySyncJobId($this->syncJob->id()) as $execution) {
            if ($execution->id() !== null) {
                $executionRepository->delete($execution->id());
            }
         }

          SyncServiceProvider::syncJobRepository()->delete($this->syncJob->id());
      }

      if ($this->authConfig?->id() !== null) {
          EngineServiceProvider::authConfigRepository()->delete($this->authConfig->id());
      }

       $computer = new Computer();
       $rows = $computer->find(['serial' => self::TEST_SERIAL]);

      foreach ($rows as $row) {
          $computer->delete(['id' => $row['id']], true);
      }

       $this->authConfig = null;
       $this->syncJob = null;
   }
}
