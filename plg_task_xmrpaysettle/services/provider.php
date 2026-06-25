<?php

/**
 * Service provider for the xmr-pay settlement task plugin (Joomla 5 namespaced plugin bootstrap).
 */

defined('_JEXEC') or die;

use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;
use XmrPay\Plugin\Task\XmrpaySettle\Extension\XmrpaySettle;

return new class implements ServiceProviderInterface {
    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $plugin = new XmrpaySettle(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('task', 'xmrpaysettle')
                );
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
