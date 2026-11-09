<?php

declare(strict_types=1);

use GlpiPlugin\Uxconnector\Bootstrap\ConnectionControllerFactory;

Session::checkRight('plugin_uxconnector_authconfig', READ);

Html::header(__('Connections', 'uxconnector'), $_SERVER['PHP_SELF'], 'tools', 'PluginUxconnectorMenu', 'connections');
ConnectionControllerFactory::create()->index();
Html::footer();
