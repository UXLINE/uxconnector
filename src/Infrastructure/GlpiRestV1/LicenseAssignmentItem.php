<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1;

/**
 * Per-person Microsoft licence assignment, separate from the GLPI native
 * Software/SoftwareLicense product/pool. All legacy Fields values are retained
 * here; they are NEVER collapsed into the pool or a pseudo-Location.
 */
final class LicenseAssignmentItem extends \CommonDBTM
{
    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_uxconnector_licenseassignments';
    }
}
