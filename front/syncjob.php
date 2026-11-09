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
 * RULE-043 : ce fichier initialise GLPI, contrôle les droits, et
 * délègue immédiatement à un contrôleur. Aucun SQL, aucun Repository,
 * aucune Entité, aucun calcul métier ici (RULE-CTRL-001/002/003).
 * -------------------------------------------------------------------------
 */

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\EntityAssignmentStrategyRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\FieldMappingRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\PaginationConfigRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncJobController;

Session::checkRight('plugin_uxconnector_syncjob', READ);

$controller = new SyncJobController(
    SyncServiceProvider::listSyncJobsService(),
    SyncServiceProvider::createSyncJobService(),
    EngineServiceProvider::listAuthConfigsService(),
    new FieldMappingRequestParser(),
    SyncServiceProvider::activateSyncJobService(),
    SyncServiceProvider::pauseSyncJobService(),
    new EntityAssignmentStrategyRequestParser(),
    SyncServiceProvider::listSyncExecutionsService(),
    new PaginationConfigRequestParser(),
    null,
    SyncServiceProvider::queueSyncJobService(),
    SyncServiceProvider::queueFullReplayService(),
    SyncServiceProvider::fieldsTargetCatalog(),
);

// Les actions du cycle d'activation et l'exécution manuelle modifient
// l'état d'une synchronisation existante (ou déclenchent une écriture
// réelle dans GLPI pour runNow) : droit UPDATE requis, distinct du
// droit READ qui suffit pour la simple consultation de la liste.
if (isset($_POST['bulk_activate'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->bulkActivate($_POST);
} elseif (isset($_POST['bulk_pause'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->bulkPause($_POST);
} elseif (isset($_POST['activate'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->activate($_POST);
} elseif (isset($_POST['pause'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->pause($_POST);
} elseif (isset($_POST['run_now'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->runNow($_POST);
} elseif (isset($_POST['full_replay'])) {
    Session::checkRight('plugin_uxconnector_syncjob', UPDATE);
    $controller->fullReplay($_POST);
} elseif (isset($_GET['history'])) {
    // Action de lecture pure : le droit READ, déjà vérifié plus haut,
    // suffit - pas besoin d'UPDATE pour consulter un historique.
    Html::header(
        __('Synchronization history', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'syncjob',
    );
    $controller->history($_GET);
    Html::footer();
} else {
    Html::header(
        __('Synchronization jobs', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'syncjob',
    );
    $controller->index();
    Html::footer();
}
