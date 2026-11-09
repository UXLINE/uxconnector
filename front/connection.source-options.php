<?php

/**
 * Read-only REST v1 Fields/search-options diagnostic.
 * The endpoint and credentials come exclusively from an existing connection;
 * no user-supplied URL, path or token is accepted.
 */
declare(strict_types=1);

use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiRestV1LocationSearchOptionCatalog;

Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid connection ID.');
}

$esc = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$connection = EngineServiceProvider::authConfigRepository()->findById(new AuthConfigId($id));
if ($connection === null || $connection->authMethod() !== AuthMethod::GLPI_REST_V1) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('GLPI REST v1 connection not found.');
}

Html::header(__('Source Fields diagnosis', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'connections');
echo '<div class="container-fluid p-3">';
echo '<a class="btn btn-outline-secondary mb-3" href="connection.php?id=' . $id . '">&larr; Back to connection</a>';
echo '<h2>GLPI REST v1 - Location Fields diagnostic (Telecom profile)</h2>';
echo '<p>Read-only discovery using <code>/listSearchOptions/Location</code>. No records, secrets or dropdown option values are displayed.</p>';
try {
    $catalog = new GlpiRestV1LocationSearchOptionCatalog(EngineServiceProvider::httpEngine());
    $report = $catalog->inspect($connection);
    echo '<p><strong>' . (int) $report['total'] . '</strong> search options examined. '
        . count($report['resolved']) . ' mapped; '
        . count($report['missing']) . ' unmatched; '
        . count($report['ambiguous']) . ' ambiguous.</p>';
    echo '<table class="table table-striped table-sm"><thead><tr><th>Logical source field</th><th>Search option ID</th><th>Result</th></tr></thead><tbody>';
    $logicalNames = array_unique(array_merge(array_keys($report['resolved']), $report['missing'], array_keys($report['ambiguous'])));
    sort($logicalNames);
    foreach ($logicalNames as $logical) {
        $state = isset($report['ambiguous'][$logical]) ? 'AMBIGUOUS' : (isset($report['resolved'][$logical]) ? 'OK' : 'MISSING');
        $optionId = isset($report['resolved'][$logical]) ? (string) $report['resolved'][$logical] :
            (isset($report['ambiguous'][$logical]) ? implode(', ', $report['ambiguous'][$logical]) : '-');
        echo '<tr><td><code>' . $esc((string) $logical) . '</code></td><td>' . $esc($optionId) . '</td><td>' . $state . '</td></tr>';
    }
    echo '</tbody></table>';
    echo '<h3>Fields options exposed by the API</h3>';
    echo '<p>Only whitelisted schema metadata is listed (up to 250 Fields options). If a dropdown is absent here, recreating it in the source without checking API exposure will not fix the mapping.</p>';
    echo '<table class="table table-striped table-sm"><thead><tr><th>ID</th><th>Label</th><th>Group</th><th>Table</th><th>Field</th></tr></thead><tbody>';
    foreach ($report['candidates'] as $row) {
        echo '<tr><td>' . (int) $row['id'] . '</td><td>' . $esc($row['name']) . '</td><td>' . $esc($row['group'])
            . '</td><td><code>' . $esc($row['table']) . '</code></td><td><code>' . $esc($row['field']) . '</code></td></tr>';
    }
    echo '</tbody></table>';
} catch (\Throwable $error) {
    // Do not disclose potentially credential-bearing transport errors in an HTML page.
    echo '<div class="alert alert-danger">Could not retrieve source search options. Verify the connection test and PHP/GLPI logs. No data was modified.</div>';
}
echo '</div>';
Html::footer();
