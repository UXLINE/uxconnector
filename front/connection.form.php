<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Bootstrap\ConnectionControllerFactory;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (isset($_POST['create_connection']) && !isset($_POST['update_connection'])) {
        Session::checkRight('plugin_uxconnector_authconfig', CREATE);
        ConnectionControllerFactory::create()->create($_POST);
        return;
    }

    if (isset($_POST['update_connection']) && !isset($_POST['create_connection'])) {
        Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
        ConnectionControllerFactory::create()->update($_POST);
        return;
    }

    throw new \Symfony\Component\HttpKernel\Exception\BadRequestHttpException();
}

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
Session::checkRight('plugin_uxconnector_authconfig', $isEdit ? UPDATE : CREATE);
$controller = ConnectionControllerFactory::create();

Html::header(
    $isEdit ? __('Edit connection', 'uxconnector') : __('New connection', 'uxconnector'),
    $_SERVER['PHP_SELF'],
    'tools',
    'PluginUxconnectorMenu',
    'connections',
);

if ($isEdit) {
    $controller->showEditForm($id);
} else {
    $controller->showCreateForm($_GET);
}

Html::footer();
