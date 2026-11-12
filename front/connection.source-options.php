<?php

/**
 * Read-only REST v1 Fields/search-options diagnostic.
 * The endpoint and credentials come exclusively from an existing connection;
 * no user-supplied URL, path or token is accepted.
 */

declare(strict_types=1);

use Glpi\Application\View\TemplateRenderer;
use GlpiPlugin\Uxconnector\Domain\Engine\Enum\AuthMethod;
use GlpiPlugin\Uxconnector\Domain\Engine\ValueObject\AuthConfigId;
use GlpiPlugin\Uxconnector\Infrastructure\Engine\EngineServiceProvider;
use GlpiPlugin\Uxconnector\Infrastructure\GlpiRestV1\GlpiRestV1LocationSearchOptionCatalog;

Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!is_int($id)) {
    throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException('Invalid connection ID.');
}

$connection = EngineServiceProvider::authConfigRepository()->findById(new AuthConfigId($id));
if ($connection === null || $connection->authMethod() !== AuthMethod::GLPI_REST_V1) {
    throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException('GLPI REST v1 connection not found.');
}

$report = null;
try {
    $catalog = new GlpiRestV1LocationSearchOptionCatalog(EngineServiceProvider::httpEngine());
    $report = $catalog->inspect($connection);
} catch (\Throwable) {
    // Transport errors may contain credentials. Render only the generic message.
}

$fields = [];
if ($report !== null) {
    $logicalNames = array_unique(array_merge(array_keys($report['resolved']), $report['missing'], array_keys(
        $report['ambiguous']
    )));
    sort($logicalNames);
    foreach ($logicalNames as $logical) {
        $ambiguous = $report['ambiguous'][$logical] ?? null;
        $resolved = $report['resolved'][$logical] ?? null;
        $fields[] = [
            'name' => $logical,
            'option' => $resolved ?? ($ambiguous !== null ? implode(', ', $ambiguous) : '-'),
            'status' => $ambiguous !== null ? 'AMBIGUOUS' : ($resolved !== null ? 'OK' : 'MISSING'),
        ];
    }
}

Html::header(
    __('Source Fields diagnosis', 'uxconnector'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginUxconnectorMenu',
    'connections'
);
TemplateRenderer::getInstance()->display('@uxconnector/connection/source-options.html.twig', [
    'connection_id' => $id,
    'report' => $report,
    'fields' => $fields,
]);
Html::footer();
