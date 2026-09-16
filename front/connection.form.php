<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Connection\ConnectionControllerFactory;

$controller = ConnectionControllerFactory::create();
$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$isEdit = $id > 0;

Session::checkRight(
    'plugin_uxconnector_authconfig',
    $isEdit ? UPDATE : CREATE
);

if (isset($_POST['create_connection'])) {
    $controller->create($_POST);
    return;
}

if (isset($_POST['update_connection'])) {
    $controller->update($_POST);
    return;
}

Html::header(
    $isEdit ? __('Edit connection', 'uxconnector') : __('New connection', 'uxconnector'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginUxconnectorMenu',
    'connections'
);

if ($isEdit) {
    $controller->showEditForm($id);
} else {
    $controller->showCreateForm($_GET);
}

Html::footer();
