<?php
/**
 * WP-CLI Commands for FE Woo
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE Woo WP-CLI Commands
 */
class FE_Woo_CLI {

    /**
     * Migrate current emisor configuration to multi-emisor system
     *
     * ## EXAMPLES
     *
     *     wp fe-woo migrate-emisor
     *     wp fe-woo migrate-emisor --dry-run
     *
     * ## OPTIONS
     *
     * [--dry-run]
     * : Show what would be migrated without making changes
     *
     * @when after_wp_load
     */
    public function migrate_emisor($args, $assoc_args) {
        $dry_run = isset($assoc_args['dry-run']);

        WP_CLI::line('');
        WP_CLI::line('==============================================');
        WP_CLI::line('  FE WOO - MIGRACIÓN A SISTEMA MULTI-EMISOR  ');
        WP_CLI::line('==============================================');
        WP_CLI::line('');

        if ($dry_run) {
            WP_CLI::warning('MODO DRY-RUN: No se realizarán cambios');
            WP_CLI::line('');
        }

        // Check if migration already done
        $existing_parent = FE_Woo_Emisor_Manager::get_parent_emisor();
        if ($existing_parent && !$dry_run) {
            WP_CLI::error('Ya existe un emisor padre. La migración ya fue completada.');
            WP_CLI::line('  Emisor: ' . $existing_parent->nombre_legal);
            WP_CLI::line('  Cédula: ' . $existing_parent->cedula_juridica);
            return;
        }

        // Get current configuration
        WP_CLI::line('📋 Configuración actual:');
        WP_CLI::line('');

        $config = FE_Woo_Hacienda_Config::get_all_config();
        $location = FE_Woo_Hacienda_Config::get_location_codes();

        WP_CLI::line('  Empresa: ' . ($config['company_name'] ?: '(vacío)'));
        WP_CLI::line('  Cédula: ' . ($config['cedula_juridica'] ?: '(vacío)'));
        WP_CLI::line('  Actividad Económica: ' . ($config['economic_activity'] ?: '(vacío)'));
        WP_CLI::line('  Email: ' . ($config['email'] ?: '(vacío)'));
        WP_CLI::line('  Teléfono: ' . ($config['phone'] ?: '(vacío)'));
        WP_CLI::line('  Ubicación: Prov=' . $location['province'] . ' Cant=' . $location['canton'] . ' Dist=' . $location['district']);
        WP_CLI::line('  Certificado: ' . ($config['certificate_path'] && file_exists($config['certificate_path']) ? '✓ Presente' : '✗ No encontrado'));
        WP_CLI::line('');

        // Validate configuration
        $validation = FE_Woo_Hacienda_Config::validate_configuration();
        if (!empty($validation)) {
            WP_CLI::warning('⚠ La configuración actual tiene errores:');
            foreach ($validation as $error) {
                WP_CLI::line('  - ' . $error);
            }
            WP_CLI::line('');

            if (!$dry_run && !WP_CLI\Utils\get_flag_value($assoc_args, 'force', false)) {
                WP_CLI::error('La configuración está incompleta. Use --force para migrar de todos modos.');
                return;
            }
        } else {
            WP_CLI::success('✓ Configuración válida');
            WP_CLI::line('');
        }

        if ($dry_run) {
            WP_CLI::line('📝 Se crearía el siguiente emisor padre:');
            WP_CLI::line('');
            WP_CLI::line('  Nombre Legal: ' . $config['company_name']);
            WP_CLI::line('  Cédula Jurídica: ' . $config['cedula_juridica']);
            WP_CLI::line('  Actividad Económica: ' . $config['economic_activity']);
            WP_CLI::line('  Email: ' . $config['email']);
            WP_CLI::line('  Teléfono: ' . $config['phone']);
            WP_CLI::line('  Dirección: ' . $config['address']);
            WP_CLI::line('');
            WP_CLI::success('DRY-RUN completado. Use el comando sin --dry-run para ejecutar la migración.');
            return;
        }

        // Execute migration
        WP_CLI::line('🚀 Ejecutando migración...');
        WP_CLI::line('');

        $result = FE_Woo_Emisor_Manager::migrate_current_emisor_to_parent();

        if ($result['success']) {
            WP_CLI::success('✓ Migración completada exitosamente');
            WP_CLI::line('');
            WP_CLI::line('  Emisor Padre ID: ' . $result['emisor_id']);
            WP_CLI::line('  Fecha: ' . current_time('mysql'));
            WP_CLI::line('');
            WP_CLI::success('El sistema ahora está configurado para multi-emisor');
        } else {
            WP_CLI::error('Error en la migración:');
            if (isset($result['errors'])) {
                foreach ($result['errors'] as $error) {
                    WP_CLI::line('  - ' . $error);
                }
            }
        }
    }

    /**
     * List all emisores
     *
     * ## OPTIONS
     *
     * [--format=<format>]
     * : Output format (table, json, csv, yaml)
     * ---
     * default: table
     * options:
     *   - table
     *   - json
     *   - csv
     *   - yaml
     * ---
     *
     * ## EXAMPLES
     *
     *     wp fe-woo list_emisores
     *     wp fe-woo list_emisores --format=json
     *
     * @when after_wp_load
     */
    public function list_emisores($args, $assoc_args) {
        $emisores = FE_Woo_Emisor_Manager::get_all_emisores(false);

        if (empty($emisores)) {
            WP_CLI::warning('No hay emisores configurados');
            return;
        }

        $format = \WP_CLI\Utils\get_flag_value($assoc_args, 'format', 'table');

        $items = [];
        foreach ($emisores as $emisor) {
            $items[] = [
                'ID' => $emisor->id,
                'Tipo' => $emisor->is_parent ? '⭐ PADRE' : 'Hijo',
                'Nombre' => $emisor->nombre_legal,
                'Cédula' => $emisor->cedula_juridica,
                'Email' => $emisor->email,
                'Estado' => $emisor->active ? 'Activo' : 'Inactivo',
            ];
        }

        \WP_CLI\Utils\format_items($format, $items, ['ID', 'Tipo', 'Nombre', 'Cédula', 'Email', 'Estado']);
    }

    /**
     * Show system status
     *
     * ## EXAMPLES
     *
     *     wp fe-woo status
     *
     * @when after_wp_load
     */
    public function status($args, $assoc_args) {
        WP_CLI::line('');
        WP_CLI::line('==============================================');
        WP_CLI::line('  FE WOO - ESTADO DEL SISTEMA                ');
        WP_CLI::line('==============================================');
        WP_CLI::line('');

        // Check database tables
        global $wpdb;
        $emisores_table = $wpdb->prefix . 'fe_woo_emisores';
        $queue_table = $wpdb->prefix . 'fe_woo_factura_queue';

        $tables_exist = [
            'emisores' => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $emisores_table)) === $emisores_table,
            'queue' => $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $queue_table)) === $queue_table,
        ];

        WP_CLI::line('📊 Tablas de Base de Datos:');
        WP_CLI::line('  Emisores: ' . ($tables_exist['emisores'] ? '✓ Existe' : '✗ No existe'));
        WP_CLI::line('  Cola: ' . ($tables_exist['queue'] ? '✓ Existe' : '✗ No existe'));
        WP_CLI::line('');

        // Check emisores
        if ($tables_exist['emisores']) {
            $emisores_count = count(FE_Woo_Emisor_Manager::get_all_emisores(true));
            $parent_emisor = FE_Woo_Emisor_Manager::get_parent_emisor();

            WP_CLI::line('👥 Emisores:');
            WP_CLI::line('  Total activos: ' . $emisores_count);
            WP_CLI::line('  Emisor padre: ' . ($parent_emisor ? '✓ Configurado (' . $parent_emisor->nombre_legal . ')' : '✗ No configurado'));
            WP_CLI::line('');
        }

        // Check service charge products
        $service_charge_products = get_posts([
            'post_type' => 'product',
            'posts_per_page' => -1,
            'meta_query' => [
                [
                    'key' => '_fe_woo_is_service_charge',
                    'value' => 'yes',
                ],
            ],
        ]);

        WP_CLI::line('💰 Productos Cargo por Servicio:');
        if (!empty($service_charge_products)) {
            foreach ($service_charge_products as $product_post) {
                $product = wc_get_product($product_post->ID);
                if ($product) {
                    WP_CLI::line('  ✓ ' . $product->get_name() . ' (ID: ' . $product_post->ID . ')');
                }
            }
        } else {
            WP_CLI::line('  ✗ Ningún producto marcado como cargo por servicio');
        }
        WP_CLI::line('');

        // Check queue
        if ($tables_exist['queue']) {
            $pending = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'pending'));
            $processing = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'processing'));
            $failed = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$queue_table} WHERE status = %s", 'failed'));

            WP_CLI::line('📋 Cola de Procesamiento:');
            WP_CLI::line('  Pendientes: ' . $pending);
            WP_CLI::line('  Procesando: ' . $processing);
            WP_CLI::line('  Fallidos: ' . $failed);
            WP_CLI::line('');
        }

        // Check configuration
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        WP_CLI::line('⚙️  Estado de Configuración:');
        if ($ready_status['ready']) {
            WP_CLI::success('✓ ' . $ready_status['message']);
        } else {
            WP_CLI::warning('⚠ ' . $ready_status['message']);
        }
        WP_CLI::line('');
    }
}

// Register WP-CLI commands if available
if (defined('WP_CLI') && WP_CLI) {
    WP_CLI::add_command('fe-woo', 'FE_Woo_CLI');
}
