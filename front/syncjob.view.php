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

use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\Sync\SyncServiceProvider;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\EntityAssignmentStrategyRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\FieldMappingRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\PaginationConfigRequestParser;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncJobController;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\SyncReadAccess;

Session::checkRight('plugin_uxconnector_syncjob', READ);
SyncReadAccess::requireJob((int) ($_GET['id'] ?? 0));

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
    SyncServiceProvider::getSyncJobService(),
    SyncServiceProvider::queueSyncJobService(),
    null,
    SyncServiceProvider::fieldsTargetCatalog(),
);

Html::header(
    __('Synchronization job', 'uxconnector'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginUxconnectorMenu',
    'syncjob',
);

$controller->showDetails($_GET);

Html::footer();
