<?php
/**
 * FE WooCommerce Tax CABYS Integration
 *
 * Adds CABYS (Costa Rica tax classification codes) functionality to WooCommerce Tax Settings
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Tax_CABYS Class
 *
 * Integrates CABYS code search and automatic tax creation in WooCommerce Tax Settings
 */
class FE_Woo_Tax_CABYS {

    /**
     * Option name for storing selected CABYS codes
     */
    const OPTION_NAME = 'fe_woo_selected_cabys_codes';

    /**
     * Option name for storing CABYS tax class slugs
     * This helps us track which classes are CABYS vs user-created
     */
    const CABYS_SLUGS_OPTION = 'fe_woo_cabys_tax_class_slugs';

    /**
     * Initialize the class
     */
    public static function init() {
        // Add CABYS section to Tax settings
        add_action('woocommerce_settings_tax_options_end', [__CLASS__, 'render_cabys_section']);

        // Save CABYS selections
        add_action('woocommerce_update_options_tax', [__CLASS__, 'save_cabys_selections']);

        // AJAX handlers
        add_action('wp_ajax_fe_woo_search_cabys', [__CLASS__, 'ajax_search_cabys']);

        // Enqueue scripts and styles
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    /**
     * Enqueue necessary scripts and styles
     *
     * @param string $hook Current admin page hook
     */
    public static function enqueue_scripts($hook) {
        // Only load on WooCommerce Tax settings page
        if ($hook !== 'woocommerce_page_wc-settings') {
            return;
        }

        if (!isset($_GET['tab']) || $_GET['tab'] !== 'tax') {
            return;
        }

        wp_enqueue_style(
            'fe-woo-tax-cabys',
            FE_WOO_PLUGIN_URL . 'assets/css/tax-cabys.css',
            [],
            FE_WOO_VERSION
        );

        wp_enqueue_script(
            'fe-woo-tax-cabys',
            FE_WOO_PLUGIN_URL . 'assets/js/tax-cabys.js',
            ['jquery'],
            FE_WOO_VERSION,
            true
        );

        wp_localize_script('fe-woo-tax-cabys', 'feWooTaxCABYS', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('fe_woo_tax_cabys'),
            'strings' => [
                'searching' => __('Buscando...', 'fe-woo'),
                'noResults' => __('No se encontraron resultados', 'fe-woo'),
                'error' => __('Error al buscar códigos CABYS', 'fe-woo'),
            ],
        ]);
    }

    /**
     * Render CABYS section in Tax settings
     */
    public static function render_cabys_section() {
        $selected_codes = get_option(self::OPTION_NAME, []);
        ?>
        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label><?php esc_html_e('Cabys', 'fe-woo'); ?></label>
                        <?php echo wc_help_tip(__('Busca y selecciona códigos CABYS de Costa Rica. Se crearán automáticamente taxes para cada código seleccionado.', 'fe-woo')); ?>
                    </th>
                    <td class="forminp forminp-text">
                        <div id="fe-woo-cabys-section">
                            <!-- Search field -->
                            <div class="fe-woo-cabys-search">
                                <input
                                    type="text"
                                    id="fe-woo-cabys-search-input"
                                    placeholder="<?php esc_attr_e('Buscar por descripción o código completo (13 dígitos)...', 'fe-woo'); ?>"
                                    class="regular-text"
                                />
                                <span class="spinner"></span>
                            </div>

                            <!-- Results container -->
                            <div id="fe-woo-cabys-results" class="fe-woo-cabys-results">
                                <?php if (!empty($selected_codes)) : ?>
                                    <?php foreach ($selected_codes as $code_data) : ?>
                                        <label class="fe-woo-cabys-item">
                                            <input
                                                type="checkbox"
                                                name="fe_woo_cabys_codes[]"
                                                value="<?php echo esc_attr(json_encode($code_data)); ?>"
                                                checked="checked"
                                                class="fe-woo-cabys-checkbox"
                                            />
                                            <span class="fe-woo-cabys-code"><?php echo esc_html($code_data['codigo']); ?></span>
                                            <span class="fe-woo-cabys-description"><?php echo esc_html($code_data['descripcion']); ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>

                            <!-- Hidden field to track selected codes -->
                            <input type="hidden" name="fe_woo_cabys_selected" id="fe-woo-cabys-selected" value="<?php echo esc_attr(json_encode($selected_codes)); ?>" />
                        </div>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php
    }

    /**
     * AJAX handler for CABYS search
     */
    public static function ajax_search_cabys() {
        check_ajax_referer('fe_woo_tax_cabys', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => __('Permisos insuficientes', 'fe-woo')]);
        }

        $query = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';

        if (empty($query)) {
            wp_send_json_error(['message' => __('Se requiere búsqueda', 'fe-woo')]);
        }

        // Get configured CABYS API endpoint
        $api_endpoint = FE_Woo_Hacienda_Config::get_cabys_api_endpoint();

        // Determine search strategy based on query
        $is_numeric = ctype_digit(trim($query));
        $is_complete_code = $is_numeric && strlen(trim($query)) === 13;

        // Strategy:
        // 1. If 13 digits: use 'codigo' (exact match)
        // 2. If partial digits: try 'q' (may fail but worth trying)
        // 3. If text: use 'q' (description search)
        if ($is_complete_code) {
            $search_param = 'codigo';
        } else {
            $search_param = 'q';
        }

        // Call Hacienda API
        $response = wp_remote_get(
            add_query_arg($search_param, $query, $api_endpoint),
            [
                'timeout' => 15,
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]
        );

        if (is_wp_error($response)) {
            wp_send_json_error([
                'message' => $response->get_error_message(),
            ]);
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            wp_send_json_error([
                'message' => __('Respuesta JSON inválida de la API', 'fe-woo'),
            ]);
        }

        // Check for API errors (like 400 Bad Request)
        if (isset($data['code']) && $data['code'] >= 400) {
            // Provide helpful message for partial code searches
            if ($is_numeric && !$is_complete_code) {
                wp_send_json_error([
                    'message' => sprintf(
                        /* translators: %s: the search query */
                        __('Los códigos CABYS deben tener 13 dígitos completos. "%s" es un código parcial. Intente buscar por descripción del producto o servicio en su lugar.', 'fe-woo'),
                        $query
                    ),
                    'hint' => __('Ejemplo: "servicios eventos", "blusa", "jugo tomate"', 'fe-woo'),
                ]);
            } else {
                wp_send_json_error([
                    'message' => isset($data['status']) ? $data['status'] : __('Error en la búsqueda', 'fe-woo'),
                ]);
            }
        }

        // Handle different response formats
        // When searching by 'q' (description): {"total": N, "cabys": [...]}
        // When searching by 'codigo' (code): [...]
        $cabys_list = [];
        $total = 0;

        if (is_array($data)) {
            if (isset($data['cabys']) && is_array($data['cabys'])) {
                // Response format with 'cabys' property (search by description)
                $cabys_list = $data['cabys'];
                $total = isset($data['total']) ? $data['total'] : count($cabys_list);
            } elseif (isset($data[0])) {
                // Direct array response (search by code)
                $cabys_list = $data;
                $total = count($cabys_list);
            }
        }

        wp_send_json_success([
            'results' => $cabys_list,
            'total' => $total,
        ]);
    }

    /**
     * Save CABYS selections and create taxes
     */
    public static function save_cabys_selections() {
        // Use the hidden field that JavaScript maintains
        $selected_json = isset($_POST['fe_woo_cabys_selected']) ? stripslashes($_POST['fe_woo_cabys_selected']) : '';

        // Parse the selected codes from the hidden field
        $selected_codes = [];
        if (!empty($selected_json)) {
            $decoded = json_decode($selected_json, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $selected_codes = $decoded;
            }
        }

        // If no codes selected, clear everything
        if (empty($selected_codes)) {
            update_option(self::OPTION_NAME, []);
            // Sync to remove all CABYS classes from database
            self::sync_cabys_tax_classes();
            return;
        }

        // Create or update taxes for each selected code
        foreach ($selected_codes as $code_data) {
            if (isset($code_data['codigo'])) {
                // Create or update tax for this CABYS code
                self::create_tax_for_cabys($code_data);
            }
        }

        // Save selected codes
        update_option(self::OPTION_NAME, $selected_codes);

        // Sync tax classes to WooCommerce
        self::sync_cabys_tax_classes();
    }

    /**
     * Create or update a WooCommerce tax for a CABYS code
     *
     * @param array $cabys_data CABYS code data from API
     */
    private static function create_tax_for_cabys($cabys_data) {
        global $wpdb;

        $codigo = $cabys_data['codigo'];
        $descripcion = $cabys_data['descripcion'];

        // Determine tax rate based on CABYS data
        // This is a simplified example - adjust based on actual CABYS structure
        $impuesto = isset($cabys_data['impuesto']) ? floatval($cabys_data['impuesto']) : 13.0;

        // Create tax class name with same format as sync_cabys_tax_classes()
        // Format: Solo DESCRIPCIÓN (sin "Tarifas" ni código)
        // WooCommerce agrega "Tarifas" automáticamente al mostrar el tab
        $tax_class_name = $descripcion;

        // Truncar a 35 caracteres si es necesario
        if (mb_strlen($tax_class_name) > 35) {
            $tax_class_name = mb_substr($tax_class_name, 0, 32) . '...';
        }

        $tax_class_slug = sanitize_title($tax_class_name);

        // Tax rate name: Código CABYS - Descripción
        $tax_rate_name = $codigo . ' - ' . $descripcion;

        // Check if tax rate already exists for this CABYS code
        $existing_tax = $wpdb->get_row($wpdb->prepare(
            "SELECT tax_rate_id FROM {$wpdb->prefix}woocommerce_tax_rates
            WHERE tax_rate_class = %s",
            $tax_class_slug
        ));

        if ($existing_tax) {
            // Update existing tax rate
            $wpdb->update(
                $wpdb->prefix . 'woocommerce_tax_rates',
                [
                    'tax_rate_country' => '',   // Vacío para aplicar a todos los países
                    'tax_rate_state' => '',     // Vacío para aplicar a todos los estados
                    'tax_rate' => $impuesto,
                    'tax_rate_name' => $tax_rate_name,
                ],
                ['tax_rate_id' => $existing_tax->tax_rate_id],
                ['%s', '%s', '%f', '%s'],
                ['%d']
            );

        } else {
            // Create new tax rate
            $wpdb->insert(
                $wpdb->prefix . 'woocommerce_tax_rates',
                [
                    'tax_rate_country' => '',   // Vacío para aplicar a todos los países
                    'tax_rate_state' => '',     // Vacío para aplicar a todos los estados
                    'tax_rate' => $impuesto,
                    'tax_rate_name' => $tax_rate_name,
                    'tax_rate_priority' => 1,
                    'tax_rate_compound' => 0,   // Sin seleccionar
                    'tax_rate_shipping' => 0,   // Sin seleccionar
                    'tax_rate_order' => 0,
                    'tax_rate_class' => $tax_class_slug,
                ],
                ['%s', '%s', '%f', '%s', '%d', '%d', '%d', '%d', '%s']
            );

        }

        // Clear WooCommerce tax cache
        \WC_Cache_Helper::invalidate_cache_group('taxes');
    }

    /**
     * Sync all CABYS tax classes to WooCommerce
     * This clears old CABYS classes and adds current ones
     *
     * IMPORTANT: CABYS classes are stored ONLY in wc_tax_rate_classes table,
     * NOT in the woocommerce_tax_classes option to avoid duplication
     */
    private static function sync_cabys_tax_classes() {
        global $wpdb;

        // Get selected codes
        $selected_codes = get_option(self::OPTION_NAME, []);

        // Get the list of CABYS slugs we've created previously
        $cabys_slugs = get_option(self::CABYS_SLUGS_OPTION, []);

        // Get only the tax classes that we know are CABYS (from our tracked list)
        if (!empty($cabys_slugs)) {
            $placeholders = implode(',', array_fill(0, count($cabys_slugs), '%s'));
            $existing_cabys_classes = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$wpdb->prefix}wc_tax_rate_classes WHERE slug IN ($placeholders)",
                    ...$cabys_slugs
                ),
                ARRAY_A
            );
        } else {
            $existing_cabys_classes = [];
        }

        // Remove all existing CABYS tax classes
        foreach ($existing_cabys_classes as $class) {
            // Delete tax rates associated with this class first
            $wpdb->delete(
                $wpdb->prefix . 'woocommerce_tax_rates',
                ['tax_rate_class' => $class['slug']],
                ['%s']
            );

            // Delete the tax class itself
            $wpdb->delete(
                $wpdb->prefix . 'wc_tax_rate_classes',
                ['tax_rate_class_id' => $class['tax_rate_class_id']],
                ['%d']
            );
        }

        // Track new CABYS slugs
        $new_cabys_slugs = [];

        // Add current CABYS classes to the database table
        foreach ($selected_codes as $cabys) {
            if (isset($cabys['codigo']) && isset($cabys['descripcion'])) {
                // Crear nombre con solo la DESCRIPCIÓN (sin "Tarifas" ni código)
                // WooCommerce agrega "Tarifas" automáticamente al mostrar el tab
                $class_name = $cabys['descripcion'];

                // Truncar a 35 caracteres si es necesario
                if (mb_strlen($class_name) > 35) {
                    $class_name = mb_substr($class_name, 0, 32) . '...';
                }

                $class_slug = sanitize_title($class_name);

                // Track this slug as a CABYS class
                $new_cabys_slugs[] = $class_slug;

                // Check if this class already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT tax_rate_class_id FROM {$wpdb->prefix}wc_tax_rate_classes WHERE slug = %s",
                    $class_slug
                ));

                // Only create if it doesn't exist
                if (!$exists) {
                    $wpdb->insert(
                        $wpdb->prefix . 'wc_tax_rate_classes',
                        [
                            'name' => $class_name,
                            'slug' => $class_slug,
                        ],
                        ['%s', '%s']
                    );
                }

                // Create or update the default tax rate for this CABYS class
                self::create_tax_for_cabys($cabys);
            }
        }

        // Update the list of CABYS slugs
        update_option(self::CABYS_SLUGS_OPTION, $new_cabys_slugs);

        // Clear WooCommerce cache
        delete_transient('wc_tax_classes');
        \WC_Cache_Helper::invalidate_cache_group('taxes');
        wp_cache_delete('tax-rate-classes', 'taxes');
    }

    /**
     * Get CABYS code for a product.
     *
     * Looks up by tax class slug in selected CABYS list.
     *
     * @param WC_Product $product Product object
     * @return array|null Array with 'codigo', 'descripcion', 'impuesto' or null if not found
     */
    public static function get_product_cabys($product) {
        if (!$product) {
            return null;
        }

        $tax_class = $product->get_tax_class();
        if ($tax_class === '') {
            return null;
        }

        return self::get_cabys_by_tax_class($tax_class);
    }

    /**
     * Get CABYS code data by tax class slug
     *
     * @param string $tax_class_slug Tax class slug
     * @return array|null Array with 'codigo', 'descripcion', 'impuesto' or null if not found
     */
    public static function get_cabys_by_tax_class($tax_class_slug) {
        global $wpdb;

        // First try: look up the tax rate name from WooCommerce tax rates table
        // Tax rate names are stored as "CABYS_CODE - Description"
        $tax_rate_name = $wpdb->get_var($wpdb->prepare(
            "SELECT tax_rate_name FROM {$wpdb->prefix}woocommerce_tax_rates WHERE tax_rate_class = %s LIMIT 1",
            $tax_class_slug
        ));

        if ($tax_rate_name) {
            // Extract the CABYS code from the name (format: "4931400000000 - Description")
            $parts = explode(' - ', $tax_rate_name, 2);
            if (isset($parts[0]) && ctype_digit(trim($parts[0])) && strlen(trim($parts[0])) === 13) {
                $cabys_code = trim($parts[0]);
                $selected_codes = get_option(self::OPTION_NAME, []);
                foreach ($selected_codes as $cabys_data) {
                    if (isset($cabys_data['codigo']) && $cabys_data['codigo'] === $cabys_code) {
                        return $cabys_data;
                    }
                }
            }
        }

        // Second try: match by reconstructed slug from description
        $selected_codes = get_option(self::OPTION_NAME, []);
        foreach ($selected_codes as $cabys_data) {
            if (isset($cabys_data['codigo']) && isset($cabys_data['descripcion'])) {
                $class_name = $cabys_data['descripcion'];
                if (mb_strlen($class_name) > 35) {
                    $class_name = mb_substr($class_name, 0, 32) . '...';
                }
                $class_slug = sanitize_title($class_name);
                if ($class_slug === $tax_class_slug) {
                    return $cabys_data;
                }
            }
        }

        return null;
    }
}
