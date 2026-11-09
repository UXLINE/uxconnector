<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Bootstrap\CopilotAgentControllerFactory;

$controller = CopilotAgentControllerFactory::create();

if (isset($_POST['create_agent'])) {
    Session::checkRight('plugin_uxconnector_authconfig', CREATE);
    Html::header(__('AI Agents', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'copilot_agents');
    $controller->create($_POST);
    Html::footer();
    return;
}
if (isset($_POST['enable_agent'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->setActive($_POST, true);
    return;
}
if (isset($_POST['disable_agent'])) {
    Session::checkRight('plugin_uxconnector_authconfig', UPDATE);
    $controller->setActive($_POST, false);
    return;
}
if (isset($_POST['delete_agent'])) {
    Session::checkRight('plugin_uxconnector_authconfig', PURGE);
    $controller->delete($_POST);
    return;
}

Session::checkRight('plugin_uxconnector_authconfig', READ);
Html::header(__('AI Agents', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'copilot_agents');
$controller->index();
Html::footer();
