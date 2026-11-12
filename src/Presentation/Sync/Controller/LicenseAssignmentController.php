<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Uxconnector\Application\Sync\ReadModel\ListLicenseAssignments;

final class LicenseAssignmentController
{
    public function __construct(private readonly ListLicenseAssignments $service)
    {
    }

    public function index(): void
    {
        TemplateRenderer::getInstance()->display('@uxconnector/sync/license-assignments.html.twig', [
            'rows' => $this->service->execute(),
        ]);
    }
}
