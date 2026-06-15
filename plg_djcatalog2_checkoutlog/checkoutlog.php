<?php
/**
 * DJ Catalog2 Checkout Logger Plugin
 *
 * Logs two types of event to #__djc2_checkout_log:
 *   cart_summary  – fired every time the basket page recalculates totals after
 *                   a delivery method is selected (delivery cost visible to user).
 *                   NOTE: the postcode is filtered client-side by the component JS,
 *                   so it is not available to PHP at this stage.
 *   order_placed  – fired when a checkout form is submitted and the order is saved.
 *                   The billing postcode and all price fields are captured here.
 *
 * @package   plg_djcatalog2_checkoutlog
 * @license   GNU/GPL
 */

defined('_JEXEC') or die;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

class PlgDjcatalog2Checkoutlog extends CMSPlugin
{
    protected $autoloadLanguage = true;

    // -------------------------------------------------------------------------
    // Event: cart summary recalculated (basket page)
    // Signature matches what cart controller passes via triggerEvent:
    //   triggerEvent('onCartAfterGetSummary', ['djcatalog2.cart.get_summary', $output])
    // The controller loops over all responses and replaces $output, so we MUST
    // return the (unmodified) $output array.
    // -------------------------------------------------------------------------
    public function onCartAfterGetSummary($context, $output)
    {
        if (!$this->params->get('log_cart_summary', 1) || $context !== 'djcatalog2.cart.get_summary') {
            return $output;
        }

        try {
            // Re-use the already-hydrated basket object that the cart controller
            // just saved to session – getInstance(true) reads from session cache.
            $basket = Djcatalog2HelperCart::getInstance(true);

            $deliveryId   = isset($output['delivery']) ? (int) $output['delivery'] : 0;
            $deliveryName = !empty($basket->delivery) ? (string) $basket->delivery->name : '';
            $productTotal = isset($basket->product_total['gross']) ? (float) $basket->product_total['gross'] : 0.0;
            $deliveryCost = (!empty($basket->delivery) && isset($basket->delivery->_prices['total']['gross']))
                ? (float) $basket->delivery->_prices['total']['gross'] : 0.0;
            $paymentCost  = (!empty($basket->payment) && isset($basket->payment->_prices['total']['gross']))
                ? (float) $basket->payment->_prices['total']['gross'] : 0.0;
            $grandTotal   = isset($basket->total['gross']) ? (float) $basket->total['gross'] : 0.0;

            $this->writeLog([
                'event_type'      => 'cart_summary',
                'delivery_method' => $deliveryName,
                'delivery_id'     => $deliveryId,
                'products_total'  => $productTotal,
                'delivery_cost'   => $deliveryCost,
                'payment_cost'    => $paymentCost,
                'grand_total'     => $grandTotal,
                // postcode is not sent to the server in getSummary AJAX calls;
                // it is used client-side only to filter delivery options in the JS.
                'postcode'        => '',
            ]);
        } catch (\Exception $e) {
            // Never break the checkout flow
        }

        return $output;
    }

    // -------------------------------------------------------------------------
    // Event: order saved (checkout form submission)
    // Triggered by the order model with context 'com_djcatalog2.order' (backend)
    // or 'com_djcatalog2.orderform' (frontend checkout).
    // $data is the Table object (Djcatalog2TableOrders).
    // -------------------------------------------------------------------------
    public function onContentAfterSave($context, $data, $isNew, $extra = null)
    {
        if (!$this->params->get('log_orders', 1)) {
            return;
        }

        $logContexts = ['com_djcatalog2.order', 'com_djcatalog2.orderform'];

        if (!in_array($context, $logContexts, true) || !$isNew) {
            return;
        }

        try {
            $this->writeLog([
                'event_type'      => 'order_placed',
                // Billing postcode from the checkout form
                'postcode'        => isset($data->postcode) ? (string) $data->postcode : '',
                // Delivery method
                'delivery_method' => isset($data->delivery_method) ? (string) $data->delivery_method : '',
                'delivery_id'     => isset($data->delivery_method_id) ? (int) $data->delivery_method_id : 0,
                // Price breakdown (all gross / inc. VAT values)
                // products_total = grand_total minus delivery and payment fees
                'products_total'  => isset($data->grand_total, $data->delivery_total, $data->payment_total)
                    ? (float) $data->grand_total - (float) $data->delivery_total - (float) $data->payment_total
                    : (float) ($data->total ?? 0.0),
                'delivery_cost'   => isset($data->delivery_total) ? (float) $data->delivery_total : 0.0,
                'payment_cost'    => isset($data->payment_total)  ? (float) $data->payment_total  : 0.0,
                'grand_total'     => isset($data->grand_total)    ? (float) $data->grand_total    : 0.0,
                'currency'        => isset($data->currency)       ? (string) $data->currency      : '',
                'order_id'        => isset($data->id)             ? (int) $data->id               : 0,
                'order_number'    => isset($data->order_number)   ? (string) $data->order_number  : '',
            ]);
        } catch (\Exception $e) {
            // Never break the order saving flow
        }
    }

    // -------------------------------------------------------------------------
    // Internal helper: write one row to the log table
    // -------------------------------------------------------------------------
    protected function writeLog(array $fields): void
    {
        $app  = Factory::getApplication();
        $user = Factory::getUser();
        $db   = Factory::getDbo();
        $date = Factory::getDate();

        $defaults = [
            'event_type'      => '',
            'session_id'      => $app->getSession()->getId(),
            'user_id'         => (int) $user->id,
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
            'created_at'      => $date->toSql(),
        ];

        $db->insertObject('#__djc2_checkout_log', (object) array_merge($defaults, $fields));
    }
}
