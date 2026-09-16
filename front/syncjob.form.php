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

Session::checkRight('plugin_uxconnector_syncjob', CREATE);

$controller = new SyncJobController(
    SyncServiceProvider::listSyncJobsService(),
    SyncServiceProvider::createSyncJobService(),
    EngineServiceProvider::listAuthConfigsService(),
    new FieldMappingRequestParser(),
    SyncServiceProvider::activateSyncJobService(),
    SyncServiceProvider::pauseSyncJobService(),
    SyncServiceProvider::executeSyncJobService(),
    new EntityAssignmentStrategyRequestParser(),
    SyncServiceProvider::listSyncExecutionsService(),
    new PaginationConfigRequestParser()
);

if (isset($_POST['add'])) {
    // POST handlers may redirect. Keep the response body empty until the
    // controller has completed the write operation.
    $controller->create($_POST);
} else {
    Html::header(
        __('New synchronization job', 'uxconnector'),
        $_SERVER['PHP_SELF'],
        'tools',
        'PluginUxconnectorMenu',
        'syncjob'
    );
    $controller->showCreateForm($_GET);
    Html::footer();
}
