<?php
// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BarlowsWoodyard\Plugin\DJCatalog2\CheckoutLog\Extension\CheckoutLog;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

/**
 * Service provider returned to the Joomla DI container.
 *
 * Joomla 5 calls this file when it boots plugins that carry a
 * services/provider.php.  We wire up all dependencies here so the plugin
 * class itself only receives typed interfaces, not static singletons.
 */
return new class implements ServiceProviderInterface {

    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $plugin = new CheckoutLog(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('djcatalog2', 'checkoutlog')
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
