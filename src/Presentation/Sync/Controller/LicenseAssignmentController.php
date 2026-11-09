<?php

declare(strict_types=1);

namespace GlpiPlugin\Uxconnector\Presentation\Sync\Controller;

use GlpiPlugin\Uxconnector\Application\Sync\ReadModel\ListLicenseAssignments;

final class LicenseAssignmentController
{
    public function __construct(private readonly ListLicenseAssignments $service) {}

    public function index(): void
    {
        $rows = $this->service->execute();
        echo '<div class="card p-3"><h3>Affectations Microsoft importées</h3>';
        echo '<p>Les quantités acquises ne proviennent pas des anciens lieux et ne sont jamais déduites de ces affectations.</p>';
        echo '<div class="table-responsive"><table class="table"><thead><tr>';
        foreach (['Produit', 'Bénéficiaire', 'Matricule', 'Fonction', 'Statut', 'Activation', 'Désactivation', 'Prix annuel source', 'Prix mensuel source', 'Pool GLPI', 'Connexion source', 'Lieu source', 'Code DA'] as $header) {
            echo '<th>' . htmlspecialchars($header, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            echo '<tr>';
            foreach (['product', 'beneficiary', 'registration_number', 'job_function', 'assignment_status', 'activation_source', 'deactivation_source', 'annual_price_source', 'monthly_price_source', 'softwarelicenses_id', 'source_authconfig_id', 'source_location_id', 'code_da'] as $column) {
                echo '<td>' . htmlspecialchars((string) ($row[$column] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div></div>';
    }
}
