<?php
/**
 * Plugin Name: Factura Electronica Para WooCommerce
 * Plugin URI: https://example.com
 * Description: Connecta las ordenes de Woo con Factura Electronica Costa Rica
 * Version: 1.0.0
 * Author: Jason Acuna
 * Author URI: https://example.com
 * Text Domain: fe-woo
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants.
define('FE_WOO_VERSION', '1.2.1');
define('FE_WOO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('FE_WOO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('FE_WOO_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * Check if WooCommerce is active
 */
if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    add_action('admin_notices', 'fe_woo_woocommerce_missing_notice');
    return;
}

/**
 * Display admin notice if WooCommerce is not active
 */
function fe_woo_woocommerce_missing_notice() {
    ?>
    <div class="error">
        <p><?php esc_html_e('Factura Electrónica para WooCommerce requiere que WooCommerce esté instalado y activo.', 'fe-woo'); ?></p>
    </div>
    <?php
}

/**
 * IMPORTANTE: No necesitamos agregar tabs manualmente porque WooCommerce
 * ya los crea automáticamente desde la tabla wc_tax_rate_classes
 */
add_filter('woocommerce_get_settings_tax', 'fe_woo_add_cabys_tax_settings', 10, 2);

function fe_woo_add_cabys_tax_settings($settings, $current_section) {
    global $wpdb;

    $tax_class = $current_section;

    // Check if this tax class exists in our tax rates table
    $exists = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s",
        $tax_class
    ));

    if (!$exists) {
        return $settings;
    }

    // Check if this is a CABYS code (starts with digits)
    if (!preg_match('/^\d{4,}/', $tax_class)) {
        return $settings;
    }

    // Return tax_rates field for this CABYS section
    return [
        [
            'type' => 'tax_rates',
        ],
    ];
}

/**
 * Declare compatibility with WooCommerce features
 */
add_action('before_woocommerce_init', function() {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('orders_cache', __FILE__, true);
    }
});

/**
 * Force Spanish locale for this plugin
 */
add_filter('plugin_locale', function($locale, $domain) {
    if ($domain === 'fe-woo') {
        return 'es_ES';
    }
    return $locale;
}, 10, 2);

/**
 * Load plugin textdomain
 */
function fe_woo_load_textdomain() {
    load_plugin_textdomain('fe-woo', false, dirname(FE_WOO_PLUGIN_BASENAME) . '/languages');
}
add_action('plugins_loaded', 'fe_woo_load_textdomain');

/**
 * Initialize the plugin
 */
function fe_woo_init() {
    // Check if database needs updating
    fe_woo_check_version();

    // Load configuration class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-hacienda-config.php';

    // Load emisor manager class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-emisor-manager.php';

    // Load product emisor class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-product-emisor.php';

    // Load multi-factura generator class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-multi-factura-generator.php';

    // Load WP-CLI commands
    if (defined('WP_CLI') && WP_CLI) {
        require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-cli.php';
    }

    // Load certificate handler
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-certificate-handler.php';

    // Load API client
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-api-client.php';

    // Load REST API (mock endpoints for local testing)
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-rest-api.php';

    // Load settings class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-settings.php';

    // Load checkout fields class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-checkout.php';

    // Load My Account class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-my-account.php';

    // Load queue management classes
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-queue.php';
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-factura-generator.php';
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-queue-processor.php';

    // Load exoneración (tax exemption) class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-exoneracion.php';

    // Load order admin class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-order-admin.php';

    // Load document storage class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-document-storage.php';

    // Load PDF generator class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-pdf-generator.php';

    // Load mensaje receptor generator class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-mensaje-receptor.php';

    // Load Tax CABYS integration class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-tax-cabys.php';

    // Load CABYS watcher class (monitors product updates to reset pending invoices)
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-cabys-watcher.php';

    // Load Nota (Credit/Debit Note) Manager class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-nota-manager.php';

    // Load Proforma management class
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-proforma.php';

    // Initialize certificate handler
    FE_Woo_Certificate_Handler::init();

    // Initialize REST API
    FE_Woo_REST_API::init();

    // Initialize settings
    FE_Woo_Settings::init();

    // Initialize checkout fields
    FE_Woo_Checkout::init();

    // Initialize My Account endpoint
    FE_Woo_My_Account::init();

    // Initialize queue system
    FE_Woo_Queue::init();
    FE_Woo_Queue_Processor::init();

    // Initialize exoneración (tax exemption)
    FE_Woo_Exoneracion::init();

    // Initialize order admin
    FE_Woo_Order_Admin::init();

    // Initialize document storage
    FE_Woo_Document_Storage::init();

    // Initialize Tax CABYS integration
    FE_Woo_Tax_CABYS::init();

    // Initialize CABYS watcher (automatic pending invoice reset on product updates)
    FE_Woo_CABYS_Watcher::init();

    // Initialize Nota (Credit/Debit Note) Manager
    FE_Woo_Nota_Manager::init();

    // Initialize Proforma management
    FE_Woo_Proforma::init();

    // Initialize Product Emisor
    FE_Woo_Product_Emisor::init();

    // Register AJAX handler for document downloads
    add_action('wp_ajax_fe_woo_download_document', [FE_Woo_Document_Storage::class, 'handle_download_request']);
}
add_action('woocommerce_init', 'fe_woo_init');

/**
 * Check plugin version and update database if needed
 */
function fe_woo_check_version() {
    $current_version = get_option('fe_woo_version', '0.0.0');

    if (version_compare($current_version, FE_WOO_VERSION, '<')) {
        global $wpdb;

        // Version upgrade detected - update database
        require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-queue.php';
        FE_Woo_Queue::create_queue_table();

        // Create emisores table
        require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-emisor-manager.php';
        FE_Woo_Emisor_Manager::create_table();

        // Ensure additional columns exist in queue table (dbDelta may not add them reliably)
        $table_name = $wpdb->prefix . 'fe_woo_factura_queue';
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'");
        if ($table_exists) {
            $column_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'emisor_id'");
            if (!$column_exists) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN emisor_id bigint(20) UNSIGNED DEFAULT NULL AFTER document_type");
                $wpdb->query("CREATE INDEX idx_emisor_id ON {$table_name} (emisor_id)");
            }

            $ref_clave_exists = $wpdb->get_var("SHOW COLUMNS FROM {$table_name} LIKE 'referenced_clave'");
            if (!$ref_clave_exists) {
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN referenced_clave varchar(50) DEFAULT NULL AFTER clave");
                $wpdb->query("ALTER TABLE {$table_name} ADD COLUMN reference_code varchar(10) DEFAULT NULL AFTER referenced_clave");
                $wpdb->query("CREATE INDEX idx_referenced_clave ON {$table_name} (referenced_clave)");
            }

            // Add UNIQUE constraint for nota idempotency (prevents race condition duplicates)
            $unique_exists = $wpdb->get_var("SHOW INDEX FROM {$table_name} WHERE Key_name = 'idx_nota_unique'");
            if (!$unique_exists) {
                $wpdb->query("CREATE UNIQUE INDEX idx_nota_unique ON {$table_name} (order_id, document_type, referenced_clave, reference_code)");
            }
        }

        // Update stored version
        update_option('fe_woo_version', FE_WOO_VERSION);
    }
}

/**
 * Plugin activation hook
 */
function fe_woo_activate() {
    // Load required classes
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-queue.php';
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-queue-processor.php';
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-my-account.php';
    require_once FE_WOO_PLUGIN_DIR . 'includes/class-fe-woo-emisor-manager.php';

    // Create queue table
    FE_Woo_Queue::create_queue_table();

    // Create emisores table
    FE_Woo_Emisor_Manager::create_table();

    // Flush rewrite rules for My Account endpoint
    FE_Woo_My_Account::flush_rewrite_rules();

    // Clear any existing cron jobs first
    wp_clear_scheduled_hook('fe_woo_process_queue');

    // Schedule queue processing cron (hourly)
    wp_schedule_event(time(), 'hourly', 'fe_woo_process_queue');

    // Set initial version
    if (!get_option('fe_woo_version')) {
        update_option('fe_woo_version', FE_WOO_VERSION);
    }
}
register_activation_hook(__FILE__, 'fe_woo_activate');


/**
 * Plugin deactivation hook
 */
function fe_woo_deactivate() {
    // Clear scheduled cron for queue processing
    $timestamp = wp_next_scheduled('fe_woo_process_queue');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'fe_woo_process_queue');
    }
}
register_deactivation_hook(__FILE__, 'fe_woo_deactivate');

/**
 * Add settings link on plugin page
 */
function fe_woo_settings_link($links) {
    $settings_link = '<a href="' . admin_url('admin.php?page=wc-settings&tab=fe') . '">' . __('Configuración', 'fe-woo') . '</a>';
    array_unshift($links, $settings_link);
    return $links;
}
add_filter('plugin_action_links_' . FE_WOO_PLUGIN_BASENAME, 'fe_woo_settings_link');
