<?php

namespace BarlowsWoodyard\Plugin\DJCatalog2\CheckoutLog\Extension;

// phpcs:disable PSR1.Files.SideEffects
defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

use Djcatalog2HelperCart;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * DJ Catalog2 Checkout Logger
 *
 * Logs two event types into #__djc2_checkout_log:
 *
 *   cart_summary  — triggered every time the basket page calls getSummary()
 *                   after a delivery method is selected.  The postcode is
 *                   handled client-side by the component JS (not sent to PHP
 *                   at this stage), so only price values are captured.
 *
 *   order_placed  — triggered when the checkout form is submitted and saved.
 *                   The billing postcode and the full price breakdown are
 *                   available here.
 */
final class CheckoutLog extends CMSPlugin implements SubscriberInterface
{
    protected $autoloadLanguage = true;

    // -------------------------------------------------------------------------
    // SubscriberInterface – declare which events we care about
    // -------------------------------------------------------------------------

    public static function getSubscribedEvents(): array
    {
        return [
            'onCartAfterGetSummary' => 'handleCartSummary',
            'onContentAfterSave'    => 'handleContentAfterSave',
        ];
    }

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    /**
     * Basket-page delivery selection: capture price totals.
     *
     * The cart controller fires this event as:
     *   triggerEvent('onCartAfterGetSummary', ['djcatalog2.cart.get_summary', $output])
     *
     * We are a logging-only observer: we do not modify $output and therefore
     * do not add to the event's 'result' bag.  If no other plugin adds to
     * 'result' either, triggerEvent returns [] and the controller's foreach
     * is a no-op — $output is left exactly as it was before the trigger call,
     * which is the correct behaviour.
     */
    public function handleCartSummary(Event $event): void
    {
        if (!(bool) $this->params->get('log_cart_summary', 1)) {
            return;
        }

        $context = $event->getArgument(0);
        $output  = $event->getArgument(1, []);

        if ($context !== 'djcatalog2.cart.get_summary') {
            return;
        }

        try {
            $basket = Djcatalog2HelperCart::getInstance(true);

            $this->writeLog([
                'event_type'      => 'cart_summary',
                'delivery_method' => !empty($basket->delivery) ? (string) $basket->delivery->name : '',
                'delivery_id'     => (int) ($output['delivery'] ?? 0),
                'products_total'  => (float) ($basket->product_total['gross'] ?? 0.0),
                'delivery_cost'   => isset($basket->delivery->_prices['total']['gross'])
                    ? (float) $basket->delivery->_prices['total']['gross'] : 0.0,
                'payment_cost'    => isset($basket->payment->_prices['total']['gross'])
                    ? (float) $basket->payment->_prices['total']['gross'] : 0.0,
                'grand_total'     => (float) ($basket->total['gross'] ?? 0.0),
            ]);
        } catch (\Throwable) {
            // Never disrupt the checkout flow
        }
    }

    /**
     * Checkout form submitted: capture postcode + full price breakdown.
     *
     * The order model fires this as:
     *   triggerEvent('onContentAfterSave', ['com_djcatalog2.order', $table, $isNew, $data])
     *
     * $table is Djcatalog2TableOrders and already has all order fields persisted.
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
                // Products total = grand total minus delivery and payment fees
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
                'event_type'      => '',
                'session_id'      => $app->getSession()->getId(),
                'user_id'         => (int) ($app->getIdentity()->id ?? 0),
                'postcode'        => '',
                'delivery_method' => '',
                'delivery_id'     => 0,
                'products_total'  => 0.0,
                'delivery_cost'   => 0.0,
                'payment_cost'    => 0.0,
                'grand_total'     => 0.0,
                'currency'        => '',
                'order_id'        => 0,
                'order_number'    => '',
                'ip_address'      => $app->input->server->get('REMOTE_ADDR', '', 'string'),
                'created_at'      => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            ],
            $fields
        );

        $this->getDatabase()->insertObject('#__djc2_checkout_log', $record);
    }
}
