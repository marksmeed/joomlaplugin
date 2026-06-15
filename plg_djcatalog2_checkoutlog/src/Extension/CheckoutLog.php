<?php

namespace BarlowsWoodyard\Plugin\System\CheckoutLog\Extension;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Joomla\CMS\Document\HtmlDocument;
use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Database\DatabaseAwareInterface;
use Joomla\Database\DatabaseAwareTrait;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * DJ Catalog2 Checkout Logger
 *
 * cart_summary — fired by JS on the checkout page after every getSummary response.
 *                Reaches us via com_ajax (onAjaxCheckoutlog). Includes postcode,
 *                Google Maps distance, available delivery options, and price breakdown.
 * order_placed  — fired by onContentAfterSave when a new order is saved.
 */
final class CheckoutLog extends CMSPlugin implements SubscriberInterface, DatabaseAwareInterface
{
    use DatabaseAwareTrait;

    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeRender'     => 'injectCheckoutScript',
            'onAjaxCheckoutlog'  => 'handleAjaxLog',
            'onContentAfterSave' => 'handleContentAfterSave',
        ];
    }

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    /**
     * Loads checkout-logger.js on any DJ Catalog 2 frontend page.
     * The script patches XHR/fetch globally but only logs data (and starts the
     * Google Maps polling) when the checkout billing postcode field is present.
     */
    public function injectCheckoutScript(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('site')) {
            return;
        }

        $doc = $app->getDocument();

        if (!($doc instanceof HtmlDocument)) {
            return;
        }

        if ($app->input->getCmd('option') !== 'com_djcatalog2') {
            return;
        }

        $doc->addScriptOptions('plg_djcatalog2_checkoutlog', [
            'ajaxUrl' => Uri::root() . 'index.php?option=com_ajax&plugin=checkoutlog&group=system&format=raw',
        ]);

        HTMLHelper::_('script', 'plg_system_checkoutlog/js/checkout-logger.js', ['version' => 'auto', 'relative' => true]);
    }

    /**
     * Called by com_ajax (index.php?option=com_ajax&plugin=checkoutlog&group=djcatalog2).
     * The checkout page JS posts all delivery-quote data here after each getSummary response.
     */
    public function handleAjaxLog(Event $event): void
    {
        if (!(bool) $this->params->get('log_cart_summary', 1)) {
            return;
        }

        try {
            $input = $this->getApplication()->input;

            $deliveryId = $input->getInt('delivery_id', 0);

            // Look up the delivery method name from the ID so we keep the column populated.
            $deliveryMethod = '';
            if ($deliveryId > 0) {
                $db = $this->getDatabase();
                $db->setQuery(
                    $db->getQuery(true)
                        ->select($db->quoteName('name'))
                        ->from($db->quoteName('#__djc2_delivery_methods'))
                        ->where($db->quoteName('id') . ' = ' . $deliveryId)
                );
                $deliveryMethod = (string) ($db->loadResult() ?? '');
            }

            $distanceRaw = $input->getString('distance_km', '');

            $this->writeLog([
                'event_type'            => 'cart_summary',
                'postcode'              => $input->getString('postcode', ''),
                'distance_km'           => $distanceRaw !== '' ? (float) $distanceRaw : null,
                'distance_text'         => $input->getString('distance_text', ''),
                'duration_text'         => $input->getString('duration_text', ''),
                'delivery_options_json' => $input->getString('delivery_options', '') ?: null,
                'delivery_method'       => $deliveryMethod,
                'delivery_id'           => $deliveryId,
                'products_total'        => $input->getFloat('products_total', 0.0),
                'delivery_cost'         => $input->getFloat('delivery_cost', 0.0),
                'payment_cost'          => $input->getFloat('payment_cost', 0.0),
                'grand_total'           => $input->getFloat('grand_total', 0.0),
            ]);
        } catch (\Throwable) {
            // Never disrupt the checkout flow
        }
    }

    /**
     * Checkout form submitted: capture postcode + full price breakdown.
     */
    public function handleContentAfterSave(Event $event): void
    {
        if (!(bool) $this->params->get('log_orders', 1)) {
            return;
        }

        $context = $event->getArgument(0);
        $data    = $event->getArgument(1);
        $isNew   = (bool) $event->getArgument(2, false);

        $logContexts = ['com_djcatalog2.order', 'com_djcatalog2.orderform'];

        if (!in_array($context, $logContexts, true) || !$isNew || $data === null) {
            return;
        }

        try {
            $grandTotal   = (float) ($data->grand_total    ?? 0.0);
            $deliveryCost = (float) ($data->delivery_total ?? 0.0);
            $paymentCost  = (float) ($data->payment_total  ?? 0.0);

            $this->writeLog([
                'event_type'      => 'order_placed',
                'postcode'        => (string) ($data->postcode           ?? ''),
                'delivery_method' => (string) ($data->delivery_method    ?? ''),
                'delivery_id'     => (int)    ($data->delivery_method_id ?? 0),
                'products_total'  => $grandTotal - $deliveryCost - $paymentCost,
                'delivery_cost'   => $deliveryCost,
                'payment_cost'    => $paymentCost,
                'grand_total'     => $grandTotal,
                'currency'        => (string) ($data->currency     ?? ''),
                'order_id'        => (int)    ($data->id           ?? 0),
                'order_number'    => (string) ($data->order_number ?? ''),
            ]);
        } catch (\Throwable) {
            // Never disrupt the order saving flow
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function writeLog(array $fields): void
    {
        $app = $this->getApplication();

        $record = (object) array_merge(
            [
                'event_type'            => '',
                'session_id'            => $app->getSession()->getId(),
                'user_id'               => (int) ($app->getIdentity()->id ?? 0),
                'postcode'              => '',
                'distance_km'           => null,
                'distance_text'         => '',
                'duration_text'         => '',
                'delivery_options_json' => null,
                'delivery_method'       => '',
                'delivery_id'           => 0,
                'products_total'        => 0.0,
                'delivery_cost'         => 0.0,
                'payment_cost'          => 0.0,
                'grand_total'           => 0.0,
                'currency'              => '',
                'order_id'              => 0,
                'order_number'          => '',
                'ip_address'            => $app->input->server->get('REMOTE_ADDR', '', 'string'),
                'created_at'            => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            $fields
        );

        $this->getDatabase()->insertObject('#__djc2_checkout_log', $record);
    }
}
