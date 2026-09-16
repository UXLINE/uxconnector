<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Infrastructure\Connection\ConnectionControllerFactory;

Session::checkRight('plugin_uxconnector_authconfig', READ);
$controller = ConnectionControllerFactory::create();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Interaction instantiation is intentionally dispatched before connection-level
    // lifecycle actions. Standard interaction forms can carry their own activation
    // preference and must never be mistaken for an AuthConfig activation request.
   if (isset($_POST['instantiate'])) {
      Session::checkRight('plugin_uxconnector_syncjob', CREATE); $controller->instantiate($_POST); return; }
   if (isset($_POST['test'])) {
      Session::checkRight('plugin_uxconnector_authconfig', UPDATE); $controller->test($_POST); return; }
   if (isset($_POST['activate'])) {
      Session::checkRight('plugin_uxconnector_authconfig', UPDATE); $controller->activate($_POST); return; }
   if (isset($_POST['disable'])) {
      Session::checkRight('plugin_uxconnector_authconfig', UPDATE); $controller->disable($_POST); return; }
   if (isset($_POST['delete'])) {
      Session::checkRight('plugin_uxconnector_authconfig', PURGE); $controller->delete($_POST); return; }
   if (isset($_POST['assign_connector'])) {
      Session::checkRight('plugin_uxconnector_authconfig', UPDATE); $controller->assignConnector($_POST); return; }
   foreach (['bulk_activate_jobs','bulk_pause_jobs'] as $action) {
      if (isset($_POST[$action])) {
         Session::checkRight('plugin_uxconnector_syncjob', UPDATE); $controller->bulkJobAction($_POST, $action); return; }
   }
   foreach (['activate_job','pause_job','run_job'] as $action) {
      if (isset($_POST[$action])) {
         Session::checkRight('plugin_uxconnector_syncjob', UPDATE); $controller->jobAction($_POST, $action); return; }
   }
   if (isset($_POST['delete_job'])) {
      Session::checkRight('plugin_uxconnector_syncjob', PURGE); $controller->jobAction($_POST, 'delete_job'); return; }
}

$id = (int) ($_GET['id'] ?? 0);
Html::header(__('Connection', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'connections');
$controller->show($id);
Html::footer();
