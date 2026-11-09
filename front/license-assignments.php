<?php

/** Read-only administrative view; all writes remain in Sync/Application jobs. */
declare(strict_types=1);

use GlpiPlugin\Uxconnector\Application\Sync\ReadModel\ListLicenseAssignments;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\LicenseAssignmentReader;
use GlpiPlugin\Uxconnector\Presentation\Sync\Controller\LicenseAssignmentController;

Session::checkRight('plugin_uxconnector_syncjob', READ);
Html::header(__('Microsoft licence assignments', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'license_assignments');
(new LicenseAssignmentController(new ListLicenseAssignments(new LicenseAssignmentReader($GLOBALS['DB']))))->index();
Html::footer();
