<?php
/**
 * FE WooCommerce CABYS Watcher
 *
 * Monitors product tax class changes and updates pending invoices
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_CABYS_Watcher Class
 *
 * Watches for product tax class changes and resets pending invoices
 */
class FE_Woo_CABYS_Watcher {

    /**
     * Initialize the watcher
     */
    public static function init() {
        // Hook into product updates to detect tax class changes
        add_action('woocommerce_update_product', [__CLASS__, 'on_product_updated'], 10, 1);
        add_action('woocommerce_update_product_variation', [__CLASS__, 'on_product_updated'], 10, 1);
    }

    /**
     * Handle product update
     *
     * Detects if tax class changed and resets pending invoices for affected orders
     *
     * @param int $product_id Product ID
     */
    public static function on_product_updated($product_id) {
        // Get the product
        $product = wc_get_product($product_id);

        if (!$product) {
            return;
        }

        // Check if tax class was actually changed
        // WooCommerce doesn't provide old/new values in this hook, so we'll check pending orders
        // and reset them whenever a product is updated (safer approach)

        // Only proceed if product has a tax class (CABYS code)
        $tax_class = $product->get_tax_class();
        $cabys_data = class_exists('FE_Woo_Tax_CABYS') ? FE_Woo_Tax_CABYS::get_product_cabys($product) : null;

        // Only process if product now has CABYS data
        if (!$cabys_data) {
            return;
        }

        self::log(sprintf(
            'Product #%d updated with CABYS code %s - checking for pending invoices',
            $product_id,
            $cabys_data['codigo']
        ), 'debug');

        // Find all pending queue items for orders containing this product
        $affected_orders = self::find_orders_with_product_in_queue($product_id);

        if (empty($affected_orders)) {
            self::log(sprintf('No pending invoices found for product #%d', $product_id), 'debug');
            return;
        }

        // Reset those queue items
        foreach ($affected_orders as $order_id) {
            self::reset_pending_invoice($order_id, $product_id, $cabys_data);
        }
    }

    /**
     * Find orders with a specific product that have pending invoices in queue
     *
     * @param int $product_id Product ID
     * @return array Array of order IDs
     */
    private static function find_orders_with_product_in_queue($product_id) {
        global $wpdb;

        // Get all pending/retry queue items
        $queue_table = $wpdb->prefix . 'fe_woo_factura_queue';
        $pending_statuses = [FE_Woo_Queue::STATUS_PENDING, FE_Woo_Queue::STATUS_RETRY];

        $placeholders = implode(',', array_fill(0, count($pending_statuses), '%s'));
        $pending_queue_items = $wpdb->get_results($wpdb->prepare(
            "SELECT order_id FROM $queue_table WHERE status IN ($placeholders)",
            ...$pending_statuses
        ));

        if (empty($pending_queue_items)) {
            return [];
        }

        $affected_orders = [];

        // Check each pending order to see if it contains the product
        foreach ($pending_queue_items as $item) {
            $order = wc_get_order($item->order_id);

            if (!$order) {
                continue;
            }

            // Check if order contains this product
            foreach ($order->get_items() as $order_item) {
                $item_product_id = $order_item->get_product_id();
                $item_variation_id = $order_item->get_variation_id();

                if ($item_product_id === $product_id || $item_variation_id === $product_id) {
                    $affected_orders[] = $item->order_id;
                    break; // Found the product in this order
                }
            }
        }

        return $affected_orders;
    }

    /**
     * Reset a pending invoice to use updated CABYS codes
     *
     * @param int   $order_id Order ID
     * @param int   $product_id Product ID that was updated
     * @param array $cabys_data New CABYS data
     */
    private static function reset_pending_invoice($order_id, $product_id, $cabys_data) {
        global $wpdb;

        $queue_table = $wpdb->prefix . 'fe_woo_factura_queue';

        // Reset the queue item to pending status with 0 attempts
        // This will cause it to be reprocessed with fresh product data
        $result = $wpdb->update(
            $queue_table,
            [
                'status' => FE_Woo_Queue::STATUS_PENDING,
                'attempts' => 0,
                'error_message' => null,
                'updated_at' => current_time('mysql'),
            ],
            ['order_id' => $order_id],
            ['%s', '%d', '%s', '%s'],
            ['%d']
        );

        if ($result !== false) {
            // Add order note
            $order = wc_get_order($order_id);
            if ($order) {
                $order->add_order_note(
                    sprintf(
                        __('Factura en cola reiniciada automáticamente. Producto #%d actualizado con CABYS %s - %s', 'fe-woo'),
                        $product_id,
                        $cabys_data['codigo'],
                        $cabys_data['descripcion']
                    )
                );
            }

            self::log(sprintf(
                'Reset pending invoice for order #%d (product #%d updated to CABYS %s)',
                $order_id,
                $product_id,
                $cabys_data['codigo']
            ));
        }
    }

    /**
     * Log message
     *
     * @param string $message Message to log
     * @param string $level Log level (info, error, debug)
     */
    private static function log($message, $level = 'info') {
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $context = ['source' => 'fe-woo-cabys-watcher'];

            switch ($level) {
                case 'error':
                    $logger->error($message, $context);
                    break;
                case 'debug':
                    if (FE_Woo_Hacienda_Config::is_debug_enabled()) {
                        $logger->debug($message, $context);
                    }
                    break;
                default:
                    $logger->info($message, $context);
                    break;
            }
        }
    }
}
