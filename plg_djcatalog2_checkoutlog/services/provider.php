<?php
// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use BarlowsWoodyard\Plugin\System\CheckoutLog\Extension\CheckoutLog;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\Database\DatabaseInterface;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

// Register PSR-4 namespace manually — the installer normally does this, but
// a manually-deployed plugin (FTP without going through the extension manager)
// will not have an entry in Joomla's autoload map.
\JLoader::registerNamespace(
    'BarlowsWoodyard\\Plugin\\System\\CheckoutLog',
    dirname(__DIR__) . '/src',
    false,
    false,
    'psr4'
);

return new class implements ServiceProviderInterface {

    public function register(Container $container): void
    {
        $container->set(
            PluginInterface::class,
            static function (Container $container): PluginInterface {
                $plugin = new CheckoutLog(
                    $container->get(DispatcherInterface::class),
                    (array) PluginHelper::getPlugin('system', 'checkoutlog')
                );

                $plugin->setApplication(Factory::getApplication());
                $plugin->setDatabase($container->get(DatabaseInterface::class));

                return $plugin;
            }
        );
    }
};
