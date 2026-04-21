<?php
/**
 * FE WooCommerce Factura Generator
 *
 * Generates electronic invoice XML according to Hacienda v4.4 specifications
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Factura_Generator Class
 *
 * Builds XML structure for Costa Rican electronic invoices
 */
class FE_Woo_Factura_Generator {

    /**
     * Document type codes
     */
    const DOC_TYPE_FACTURA_ELECTRONICA = '01';
    const DOC_TYPE_NOTA_DEBITO = '02';
    const DOC_TYPE_NOTA_CREDITO = '03';
    const DOC_TYPE_TIQUETE_ELECTRONICO = '04';

    /**
     * Currency codes
     */
    const CURRENCY_CRC = 'CRC';
    const CURRENCY_USD = 'USD';

    /**
     * Sales condition codes
     */
    const SALES_CONDITION_CASH = '01'; // Contado
    const SALES_CONDITION_CREDIT = '02'; // Crédito
    const SALES_CONDITION_CONSIGNMENT = '03'; // Consignación
    const SALES_CONDITION_APART = '04'; // Apartado
    const SALES_CONDITION_LEASE = '05'; // Arrendamiento
    const SALES_CONDITION_OTHER = '99'; // Otros

    /**
     * Payment method codes
     */
    const PAYMENT_CASH = '01'; // Efectivo
    const PAYMENT_CARD = '02'; // Tarjeta
    const PAYMENT_CHECK = '03'; // Cheque
    const PAYMENT_TRANSFER = '04'; // Transferencia
    const PAYMENT_OTHER = '99'; // Otros

    /**
     * Reference codes for credit/debit notes
     */
    const REFERENCE_ANULA = '01'; // Anula documento de referencia
    const REFERENCE_CORRIGE_TEXTO = '02'; // Corrige texto documento referencia
    const REFERENCE_CORRIGE_MONTO = '03'; // Corrige monto
    const REFERENCE_OTRO_DOCUMENTO = '04'; // Referencia a otro documento
    const REFERENCE_SUSTITUYE_CONTINGENCIA = '05'; // Sustituye comprobante provisional por contingencia
    const REFERENCE_OTROS = '99'; // Otros

    /**
     * Prepare emisor data from emisor object
     *
     * @param object $emisor Emisor object from database
     * @return array Emisor data array
     */
    public static function prepare_emisor_data($emisor) {
        return [
            'nombre_legal' => $emisor->nombre_legal,
            'cedula_juridica' => $emisor->cedula_juridica,
            'tipo_identificacion' => $emisor->tipo_identificacion ?? '02',
            'codigo_provincia' => $emisor->codigo_provincia,
            'codigo_canton' => $emisor->codigo_canton,
            'codigo_distrito' => $emisor->codigo_distrito,
            'codigo_barrio' => $emisor->codigo_barrio,
            'direccion' => $emisor->direccion,
            'telefono' => $emisor->telefono,
            'email' => $emisor->email,
            'actividad_economica' => $emisor->actividad_economica,
        ];
    }

    /**
     * Generate factura or tiquete XML from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $document_type Document type: 'factura' or 'tiquete' (default: 'tiquete')
     * @param int      $emisor_id Optional emisor ID (if null, uses parent)
     * @param array    $line_items Optional specific line items to include
     * @return array Array with 'success', 'clave', 'xml' keys
     */
    public static function generate_from_order($order, $document_type = 'tiquete', $emisor_id = null, $line_items = null, $include_shipping = false) {
        try {
            // Check if there are any taxable items — skip invoice if all items have tax_status 'none'
            $items_to_check = $line_items !== null ? $line_items : $order->get_items();
            $has_taxable_items = false;
            foreach ($items_to_check as $item) {
                $product = $item->get_product();
                if (!$product || $product->get_tax_status() !== 'none') {
                    $has_taxable_items = true;
                    break;
                }
            }
            if (!$has_taxable_items) {
                return [
                    'success' => false,
                    'error' => __('Todos los productos de esta orden tienen estado de impuesto "ninguno". No se genera documento electrónico.', 'fe-woo'),
                    'skipped' => true,
                ];
            }

            // Get emisor data
            $emisor_data = null;
            $is_parent_emisor = false; // Safe default: require explicit confirmation of parent status
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    throw new Exception("Emisor #{$emisor_id} not found");
                }
                $emisor_data = self::prepare_emisor_data($emisor);
                $is_parent_emisor = !empty($emisor->is_parent);
            } else {
                // No emisor provided — use parent emisor and confirm it IS parent
                $is_parent_emisor = (FE_Woo_Emisor_Manager::get_parent_emisor() !== null);
            }

            // Exonerations only apply to the default (parent) emisor
            $apply_exoneracion = $is_parent_emisor;

            // Generate unique clave (invoice key)
            $clave = self::generate_clave($order, $document_type, $emisor_data);

            // For partial line items, include shipping only if flagged
            $effective_include_shipping = ($line_items !== null) ? $include_shipping : true;

            // Build XML structure
            $xml = self::build_xml($order, $clave, $document_type, null, $line_items, $emisor_data, $effective_include_shipping, $apply_exoneracion);

            return [
                'success' => true,
                'clave' => $clave,
                'xml' => $xml,
                'document_type' => $document_type,
                'emisor_id' => $emisor_id,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate nota de crédito or nota de débito XML from WooCommerce order
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $note_type Note type: 'nota_credito' or 'nota_debito'
     * @param array    $reference_data Reference document data
     *                 - referenced_clave: Clave of the document being referenced
     *                 - referenced_date: Date of referenced document
     *                 - referenced_type: Type of referenced document (01-05, 99)
     *                 - reference_code: Reason code (01-Anula, 02-Corrige, etc.)
     *                 - reference_reason: Text description of reason
     * @param array    $line_items Optional: Array of line items for partial notes
     * @param int|null $emisor_id Optional: Emisor ID for multi-emisor support
     * @return array Array with 'success', 'clave', 'xml' keys
     */
    public static function generate_nota_from_order($order, $note_type = 'nota_credito', $reference_data = [], $emisor_id = null, $line_items = null) {
        try {
            // Validate reference data
            if (empty($reference_data['referenced_clave'])) {
                throw new Exception('Referenced document clave is required');
            }
            if (empty($reference_data['referenced_date'])) {
                throw new Exception('Referenced document date is required');
            }
            if (empty($reference_data['referenced_type'])) {
                throw new Exception('Referenced document type is required');
            }
            if (empty($reference_data['reference_code'])) {
                throw new Exception('Reference code is required');
            }
            if (empty($reference_data['reference_reason'])) {
                throw new Exception('Reference reason is required');
            }

            // Get emisor data if emisor_id provided
            $emisor_data = null;
            $is_parent_emisor = false; // Safe default: require explicit confirmation of parent status
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    throw new Exception("Emisor #{$emisor_id} not found");
                }
                $emisor_data = self::prepare_emisor_data($emisor);
                $is_parent_emisor = !empty($emisor->is_parent);
            } else {
                // No emisor provided — use parent emisor and confirm it IS parent
                $is_parent_emisor = (FE_Woo_Emisor_Manager::get_parent_emisor() !== null);
            }

            // Exonerations only apply to the default (parent) emisor
            $apply_exoneracion = $is_parent_emisor;

            // Generate unique clave for the note
            $clave = self::generate_clave($order, $note_type, $emisor_data);

            // Build XML structure with emisor data
            $xml = self::build_xml($order, $clave, $note_type, $reference_data, $line_items, $emisor_data, true, $apply_exoneracion);

            return [
                'success' => true,
                'clave' => $clave,
                'xml' => $xml,
                'document_type' => $note_type,
                'reference_data' => $reference_data,
                'emisor_id' => $emisor_id,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate unique clave (invoice key)
     *
     * Format: [Country][Day][Month][Year][ID][Consecutive][Situation][Security Code]
     * Total: 50 characters
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $document_type Document type: 'factura' or 'tiquete'
     * @param array    $emisor_data Optional emisor data with 'cedula' key for multi-emisor
     * @return string 50-character clave
     */
    private static function generate_clave($order, $document_type = 'tiquete', $emisor_data = null) {
        $country = '506'; // Costa Rica (3 digits)

        $date = $order->get_date_created();
        $day = $date->format('d'); // 2 digits
        $month = $date->format('m'); // 2 digits
        $year = $date->format('y'); // 2 digits

        // Use emisor-specific cedula if provided, otherwise fall back to global config
        $cedula = (!empty($emisor_data['cedula_juridica']))
            ? $emisor_data['cedula_juridica']
            : FE_Woo_Hacienda_Config::get_cedula_juridica();
        $id = str_pad($cedula, 12, '0', STR_PAD_LEFT); // 12 digits

        // Consecutive number - use order number
        $consecutive = str_pad($order->get_id(), 20, '0', STR_PAD_LEFT); // 20 digits

        $situation = '1'; // 1 = Normal, 2 = Contingencia, 3 = Sin internet

        // Security code - random 8 digits
        $security_code = str_pad(wp_rand(1, 99999999), 8, '0', STR_PAD_LEFT); // 8 digits

        $clave = $country . $day . $month . $year . $id . $consecutive . $situation . $security_code;

        return $clave;
    }

    /**
     * Build XML structure for factura, tiquete, or note
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $clave Invoice key
     * @param string   $document_type Document type: 'factura', 'tiquete', 'nota_credito', 'nota_debito'
     * @param array    $reference_data Reference data for notes (optional)
     * @param array    $line_items Line items for partial notes (optional)
     * @return string XML string
     */
    private static function build_xml($order, $clave, $document_type = 'tiquete', $reference_data = [], $line_items = null, $emisor_data = null, $include_shipping = true, $apply_exoneracion = true) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Root element - different for each document type
        if ($document_type === 'factura') {
            $root = $xml->createElement('FacturaElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/facturaElectronica');
        } elseif ($document_type === 'nota_credito') {
            $root = $xml->createElement('NotaCreditoElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaCreditoElectronica');
        } elseif ($document_type === 'nota_debito') {
            $root = $xml->createElement('NotaDebitoElectronica');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/notaDebitoElectronica');
        } else {
            $root = $xml->createElement('TiqueteElectronico');
            $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/tiqueteElectronico');
        }
        $root->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->appendChild($root);

        // Clave
        $root->appendChild($xml->createElement('Clave', $clave));

        // ProveedorSistemas (only for Tiquete - cedula of the system provider)
        if ($document_type === 'tiquete') {
            // Use the company's cedula as the provider system ID
            $root->appendChild($xml->createElement('ProveedorSistemas', FE_Woo_Hacienda_Config::get_cedula_juridica()));
        }

        // CodigoActividad - use emisor-specific activity code if available
        $activity_code_value = (!empty($emisor_data['actividad_economica']))
            ? $emisor_data['actividad_economica']
            : FE_Woo_Hacienda_Config::get_economic_activity();

        if ($document_type === 'factura') {
            $root->appendChild($xml->createElement('CodigoActividad', $activity_code_value));
        } else {
            $root->appendChild($xml->createElement('CodigoActividadEmisor', $activity_code_value));
        }

        // NumeroConsecutivo
        $consecutive = self::generate_consecutive($order, $document_type);
        $root->appendChild($xml->createElement('NumeroConsecutivo', $consecutive));

        // FechaEmision
        $date = $order->get_date_created()->format('c'); // ISO 8601
        $root->appendChild($xml->createElement('FechaEmision', $date));

        // Emisor (Sender - Company)
        $emisor = self::build_emisor($xml, $emisor_data);
        $root->appendChild($emisor);

        // Receptor (Receiver - Customer) - optional for Tiquete
        if ($document_type === 'factura') {
            $receptor = self::build_receptor($xml, $order);
            $root->appendChild($receptor);
        }

        // CondicionVenta
        $root->appendChild($xml->createElement('CondicionVenta', self::get_sales_condition($order)));

        // MedioPago (only for Factura)
        if ($document_type === 'factura') {
            $root->appendChild($xml->createElement('MedioPago', self::get_payment_method($order)));
        }

        // DetalleServicio (Line Items)
        $detalle = self::build_detalle_servicio($xml, $order, $line_items, $include_shipping, $apply_exoneracion);
        $root->appendChild($detalle);

        // ResumenFactura (Invoice Summary)
        $resumen = self::build_resumen($xml, $order, $line_items, $include_shipping, $apply_exoneracion);
        $root->appendChild($resumen);

        // InformacionReferencia (only for notes)
        if ($document_type === 'nota_credito' || $document_type === 'nota_debito') {
            $info_referencia = self::build_informacion_referencia($xml, $reference_data);
            $root->appendChild($info_referencia);
        }

        // Otros – include customer's economic activity code when provided.
        // The Hacienda FE v4.4 schema does not have a CodigoActividad field inside
        // Receptor, so OtroTexto inside Otros is the standards-compliant way to carry
        // this information in the document.
        $activity_code = $order->get_meta('_fe_woo_activity_code');
        if (!empty($activity_code)) {
            $otros = $xml->createElement('Otros');
            $otros->appendChild(
                $xml->createElement('OtroTexto', 'Código Actividad Económica Receptor: ' . $activity_code)
            );
            $root->appendChild($otros);
        }

        return $xml->saveXML();
    }

    /**
     * Generate consecutive number
     *
     * @param WC_Order $order WooCommerce order
     * @param string   $document_type Document type: 'factura', 'tiquete', 'nota_credito', 'nota_debito'
     * @return string Consecutive number
     */
    private static function generate_consecutive($order, $document_type = 'tiquete') {
        // Format: SSSEEETTNNNNNNNNNN
        // SSS = Sucursal (3 digits) - Branch
        // EEE = Terminal (3 digits) - Terminal/POS
        // TT = Document Type (2 digits) - 01=Factura, 02=Nota Débito, 03=Nota Crédito, 04=Tiquete
        // NNNNNNNNNN = Consecutive (10 digits)

        $sucursal = '001'; // Default branch
        $terminal = '001'; // Default terminal/POS

        // For notes, add timestamp to order ID to ensure uniqueness when multiple notes per order
        if ($document_type === 'nota_credito' || $document_type === 'nota_debito') {
            $base_number = $order->get_id() . time();
            $consecutive = str_pad(substr($base_number, -10), 10, '0', STR_PAD_LEFT);
        } else {
            $consecutive = str_pad($order->get_id(), 10, '0', STR_PAD_LEFT);
        }

        // Get document type code
        $doc_type_map = [
            'factura' => self::DOC_TYPE_FACTURA_ELECTRONICA,
            'nota_debito' => self::DOC_TYPE_NOTA_DEBITO,
            'nota_credito' => self::DOC_TYPE_NOTA_CREDITO,
            'tiquete' => self::DOC_TYPE_TIQUETE_ELECTRONICO,
        ];

        $doc_type_code = isset($doc_type_map[$document_type]) ? $doc_type_map[$document_type] : self::DOC_TYPE_TIQUETE_ELECTRONICO;

        return $sucursal . $terminal . $doc_type_code . $consecutive;
    }

    /**
     * Build Emisor (sender/company) element
     *
     * @param DOMDocument $xml XML document
     * @param array       $emisor_data Optional emisor data (if null, uses config)
     * @return DOMElement Emisor element
     */
    private static function build_emisor($xml, $emisor_data = null) {
        $emisor = $xml->createElement('Emisor');

        // Normalize data source: if no emisor_data, build from config
        if (!$emisor_data) {
            $location = FE_Woo_Hacienda_Config::get_location_codes();
            $emisor_data = [
                'nombre_legal' => FE_Woo_Hacienda_Config::get_company_name(),
                'cedula_juridica' => FE_Woo_Hacienda_Config::get_cedula_juridica(),
                'tipo_identificacion' => '02',
                'codigo_provincia' => $location['province'],
                'codigo_canton' => $location['canton'],
                'codigo_distrito' => $location['district'],
                'codigo_barrio' => $location['neighborhood'],
                'direccion' => FE_Woo_Hacienda_Config::get_address(),
                'telefono' => FE_Woo_Hacienda_Config::get_phone(),
                'email' => FE_Woo_Hacienda_Config::get_email(),
            ];
        }

        // Nombre
        $emisor->appendChild($xml->createElement('Nombre', $emisor_data['nombre_legal']));

        // Identificacion
        $identificacion = $xml->createElement('Identificacion');
        $identificacion->appendChild($xml->createElement('Tipo', $emisor_data['tipo_identificacion'] ?? '02'));
        $identificacion->appendChild($xml->createElement('Numero', $emisor_data['cedula_juridica']));
        $emisor->appendChild($identificacion);

        // Ubicacion
        $ubicacion = $xml->createElement('Ubicacion');
        $ubicacion->appendChild($xml->createElement('Provincia', $emisor_data['codigo_provincia']));
        $ubicacion->appendChild($xml->createElement('Canton', $emisor_data['codigo_canton']));
        $ubicacion->appendChild($xml->createElement('Distrito', $emisor_data['codigo_distrito']));
        if (!empty($emisor_data['codigo_barrio'])) {
            $ubicacion->appendChild($xml->createElement('Barrio', $emisor_data['codigo_barrio']));
        }
        $ubicacion->appendChild($xml->createElement('OtrasSenas', $emisor_data['direccion']));
        $emisor->appendChild($ubicacion);

        // Telefono
        if (!empty($emisor_data['telefono'])) {
            $telefono = $xml->createElement('Telefono');
            $telefono->appendChild($xml->createElement('CodigoPais', '506'));
            $telefono->appendChild($xml->createElement('NumTelefono', $emisor_data['telefono']));
            $emisor->appendChild($telefono);
        }

        // CorreoElectronico
        if (!empty($emisor_data['email'])) {
            $emisor->appendChild($xml->createElement('CorreoElectronico', $emisor_data['email']));
        }

        return $emisor;
    }

    /**
     * Build Receptor (receiver/customer) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @return DOMElement Receptor element
     */
    private static function build_receptor($xml, $order) {
        $receptor = $xml->createElement('Receptor');

        // Get customer factura data from order meta
        $full_name = $order->get_meta('_fe_woo_full_name');
        $id_type = $order->get_meta('_fe_woo_id_type');
        $id_number = $order->get_meta('_fe_woo_id_number');
        $email = $order->get_meta('_fe_woo_invoice_email');
        $phone = $order->get_meta('_fe_woo_phone');

        // Nombre
        $receptor->appendChild($xml->createElement('Nombre', $full_name));

        // Identificacion
        $identificacion = $xml->createElement('Identificacion');
        $identificacion->appendChild($xml->createElement('Tipo', $id_type));
        $identificacion->appendChild($xml->createElement('Numero', preg_replace('/[^0-9A-Za-z]/', '', $id_number)));
        $receptor->appendChild($identificacion);

        // CorreoElectronico
        if (!empty($email)) {
            $receptor->appendChild($xml->createElement('CorreoElectronico', $email));
        }

        // Telefono
        if (!empty($phone)) {
            $telefono = $xml->createElement('Telefono');
            $telefono->appendChild($xml->createElement('CodigoPais', '506'));
            $telefono->appendChild($xml->createElement('NumTelefono', $phone));
            $receptor->appendChild($telefono);
        }

        return $receptor;
    }

    /**
     * Build DetalleServicio (line items) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param array       $line_items Optional line items for partial notes
     * @return DOMElement DetalleServicio element
     */
    private static function build_detalle_servicio($xml, $order, $line_items = null, $include_shipping = true, $apply_exoneracion = true) {
        $detalle_servicio = $xml->createElement('DetalleServicio');

        $line_number = 1;

        // Use custom line items if provided (for partial notes), otherwise use all order items
        $items_to_process = $line_items !== null ? $line_items : $order->get_items();

        // Add order items
        foreach ($items_to_process as $item) {
            // Skip products with tax_status 'none' - they should not appear in electronic invoices
            $product = $item->get_product();
            if ($product && $product->get_tax_status() === 'none') {
                continue;
            }

            $linea_detalle = $xml->createElement('LineaDetalle');

            // NumeroLinea
            $linea_detalle->appendChild($xml->createElement('NumeroLinea', $line_number));
            $sku = $product ? $product->get_sku() : '';
            if (empty($sku)) {
                $sku = $product ? 'PROD-' . $product->get_id() : 'PROD-' . $item->get_product_id();
            }
            $linea_detalle->appendChild($xml->createElement('Codigo', $sku));

            // CodigoComercial (CABYS code if available)
            if ($product && class_exists('FE_Woo_Tax_CABYS')) {
                $cabys_data = FE_Woo_Tax_CABYS::get_product_cabys($product);
                if ($cabys_data && isset($cabys_data['codigo'])) {
                    $codigo_comercial = $xml->createElement('CodigoComercial');
                    $codigo_comercial->appendChild($xml->createElement('Tipo', '04')); // 04 = CABYS
                    $codigo_comercial->appendChild($xml->createElement('CodigoCABYS', $cabys_data['codigo']));
                    $linea_detalle->appendChild($codigo_comercial);
                }
            }

            // Cantidad
            $linea_detalle->appendChild($xml->createElement('Cantidad', $item->get_quantity()));

            // UnidadMedida
            $linea_detalle->appendChild($xml->createElement('UnidadMedida', 'Unid')); // Unit

            // Detalle (Product name)
            $linea_detalle->appendChild($xml->createElement('Detalle', $item->get_name()));

            // PrecioUnitario
            $unit_price = $item->get_subtotal() / $item->get_quantity();
            $linea_detalle->appendChild($xml->createElement('PrecioUnitario', number_format($unit_price, 5, '.', '')));

            // MontoTotal
            $linea_detalle->appendChild($xml->createElement('MontoTotal', number_format($item->get_subtotal(), 5, '.', '')));

            // Descuento (if any)
            if ($item->get_subtotal() != $item->get_total()) {
                $discount = $item->get_subtotal() - $item->get_total();
                $descuento = $xml->createElement('Descuento');
                $descuento->appendChild($xml->createElement('MontoDescuento', number_format($discount, 5, '.', '')));
                $descuento->appendChild($xml->createElement('NaturalezaDescuento', 'Descuento aplicado'));
                $linea_detalle->appendChild($descuento);
            }

            // SubTotal
            $linea_detalle->appendChild($xml->createElement('SubTotal', number_format($item->get_total(), 5, '.', '')));

            // Impuesto (Tax)
            $tax = $item->get_total_tax();

            // Check if order has exemption - this determines the actual tax rate
            // Exonerations only apply to the default (parent) emisor
            $exon_data = null;
            if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
                $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
                if ($exon_data) {
                    $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                    if (!$validation['valid']) {
                        $exon_data = null; // Invalid exemption, treat as normal tax
                    }
                }
            }

            // Calculate tax respecting product tax_status and exemptions
            $subtotal = $item->get_total();
            $tax_info = self::calculate_item_tax_info($item, $product, $subtotal, $tax, $exon_data);
            $tarifa_real = $tax_info['tarifa'];
            $tax_with_exon = $tax_info['tax'];

            if ($tax_with_exon > 0 || ($tarifa_real > 0 && $tax_info['is_taxable'])) {
                $impuesto = $xml->createElement('Impuesto');
                $impuesto->appendChild($xml->createElement('Codigo', '01')); // IVA
                $impuesto->appendChild($xml->createElement('Tarifa', $tarifa_real));
                $impuesto->appendChild($xml->createElement('Monto', number_format($tax_with_exon, 5, '.', '')));

                // Add Exoneracion element with exemption details
                if ($exon_data && $tarifa_real < 13) {
                    $exoneracion_elem = self::build_exoneracion($xml, $order, $subtotal, $tarifa_real);
                    if ($exoneracion_elem) {
                        $impuesto->appendChild($exoneracion_elem);
                    }
                }

                $linea_detalle->appendChild($impuesto);
            }

            // MontoTotalLinea - use recalculated tax if exemption applies
            $line_total = $item->get_total() + $tax_with_exon;
            $linea_detalle->appendChild($xml->createElement('MontoTotalLinea', number_format($line_total, 5, '.', '')));

            $detalle_servicio->appendChild($linea_detalle);
            $line_number++;
        }

        // Add shipping as a line item if applicable and included
        if ($include_shipping && $order->get_shipping_total() > 0) {
            $linea_detalle = $xml->createElement('LineaDetalle');
            $linea_detalle->appendChild($xml->createElement('NumeroLinea', $line_number));
            $linea_detalle->appendChild($xml->createElement('Codigo', 'SHIPPING'));
            $linea_detalle->appendChild($xml->createElement('Cantidad', '1'));
            $linea_detalle->appendChild($xml->createElement('UnidadMedida', 'Sp')); // Service
            $linea_detalle->appendChild($xml->createElement('Detalle', 'Envío'));
            $linea_detalle->appendChild($xml->createElement('PrecioUnitario', number_format($order->get_shipping_total(), 5, '.', '')));
            $linea_detalle->appendChild($xml->createElement('MontoTotal', number_format($order->get_shipping_total(), 5, '.', '')));
            $linea_detalle->appendChild($xml->createElement('SubTotal', number_format($order->get_shipping_total(), 5, '.', '')));

            // Shipping tax - apply exemption if applicable
            // Only add tax element if shipping originally has tax
            $shipping_tax = $order->get_shipping_tax();
            $shipping_subtotal = $order->get_shipping_total();
            $shipping_has_tax = $shipping_tax > 0;

            // Calculate shipping tax using centralized helper (product=false for shipping)
            $shipping_tax_info = self::calculate_item_tax_info(null, false, $shipping_subtotal, $shipping_tax, $shipping_has_tax ? $exon_data : null);
            $tarifa_shipping = $shipping_tax_info['tarifa'];
            $shipping_tax = $shipping_tax_info['tax'];

            // Only add tax element if shipping actually has tax
            if ($shipping_has_tax && ($shipping_tax > 0 || $tarifa_shipping > 0)) {
                $impuesto = $xml->createElement('Impuesto');
                $impuesto->appendChild($xml->createElement('Codigo', '01'));
                $impuesto->appendChild($xml->createElement('Tarifa', $tarifa_shipping));
                $impuesto->appendChild($xml->createElement('Monto', number_format($shipping_tax, 5, '.', '')));

                // Add exemption for shipping if applicable
                if ($exon_data && $tarifa_shipping < 13) {
                    $exoneracion_elem = self::build_exoneracion($xml, $order, $shipping_subtotal, $tarifa_shipping);
                    if ($exoneracion_elem) {
                        $impuesto->appendChild($exoneracion_elem);
                    }
                }

                $linea_detalle->appendChild($impuesto);
            }

            $line_total = $order->get_shipping_total() + $shipping_tax;
            $linea_detalle->appendChild($xml->createElement('MontoTotalLinea', number_format($line_total, 5, '.', '')));

            $detalle_servicio->appendChild($linea_detalle);
        }

        return $detalle_servicio;
    }

    /**
     * Build ResumenFactura (invoice summary) element
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param array       $line_items Optional line items for partial notes
     * @return DOMElement ResumenFactura element
     */
    private static function build_resumen($xml, $order, $line_items = null, $include_shipping = true, $apply_exoneracion = true) {
        $resumen = $xml->createElement('ResumenFactura');

        // Check if order has exemption - recalculate totals if needed
        // Exonerations only apply to the default (parent) emisor
        $exon_data = null;
        if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
            $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
            if ($exon_data) {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                if (!$validation['valid']) {
                    $exon_data = null;
                }
            }
        }

        // If partial note with custom line items, calculate totals from those items
        if ($line_items !== null) {
            $subtotal = 0;
            $total_tax = 0;
            $total = 0;
            $discount = 0;

            foreach ($line_items as $item) {
                // Skip products with tax_status 'none' - not included in electronic invoices
                $product = $item->get_product();
                if ($product && $product->get_tax_status() === 'none') {
                    continue;
                }

                $subtotal += $item->get_subtotal();
                $tax_info = self::calculate_item_tax_info($item, $product, $item->get_total(), $item->get_total_tax(), $exon_data);

                $total_tax += $tax_info['tax'];
                $total += $item->get_total() + $tax_info['tax'];

                if ($item->get_subtotal() != $item->get_total()) {
                    $discount += $item->get_subtotal() - $item->get_total();
                }
            }

            // Include shipping in resumen if flagged (first factura in multi-factura gets it)
            if ($include_shipping && $order->get_shipping_total() > 0) {
                $subtotal += $order->get_shipping_total();
                $original_shipping_tax = $order->get_shipping_tax();
                $shipping_tax_info = self::calculate_item_tax_info(null, false, $order->get_shipping_total(), $original_shipping_tax, $original_shipping_tax > 0 ? $exon_data : null);

                $total_tax += $shipping_tax_info['tax'];
                $total += $order->get_shipping_total() + $shipping_tax_info['tax'];
            }
        } else {
            // Use order totals - recalculate respecting each product's tax_status
            $subtotal = 0;
            $total_tax = 0;
            $total = 0;
            $discount = 0;

            foreach ($order->get_items() as $item) {
                // Skip products with tax_status 'none' - not included in electronic invoices
                $product = $item->get_product();
                if ($product && $product->get_tax_status() === 'none') {
                    continue;
                }

                $subtotal += $item->get_subtotal();
                if ($item->get_subtotal() != $item->get_total()) {
                    $discount += $item->get_subtotal() - $item->get_total();
                }
                $tax_info = self::calculate_item_tax_info($item, $product, $item->get_total(), $item->get_total_tax(), $exon_data);

                $total_tax += $tax_info['tax'];
                $total += $item->get_total() + $tax_info['tax'];
            }

            // Add fees if applicable
            foreach ($order->get_fees() as $fee) {
                $fee_total = $fee->get_total();
                $fee_tax = $fee->get_total_tax();
                $subtotal += $fee_total;
                $total_tax += $fee_tax;
                $total += $fee_total + $fee_tax;
            }

            // Add shipping if applicable
            if ($order->get_shipping_total() > 0) {
                $subtotal += $order->get_shipping_total();
                $original_shipping_tax = $order->get_shipping_tax();
                $shipping_tax_info = self::calculate_item_tax_info(null, false, $order->get_shipping_total(), $original_shipping_tax, $original_shipping_tax > 0 ? $exon_data : null);

                $total_tax += $shipping_tax_info['tax'];
                $total += $order->get_shipping_total() + $shipping_tax_info['tax'];
            }
        }

        // CodigoMoneda
        $currency = $order->get_currency();
        $resumen->appendChild($xml->createElement('CodigoMoneda', $currency));

        // TipoCambio (if not CRC)
        if ($currency !== self::CURRENCY_CRC) {
            // You would get exchange rate from API or settings
            $resumen->appendChild($xml->createElement('TipoCambio', '1.000000'));
        }

        // TotalServGravados
        $resumen->appendChild($xml->createElement('TotalServGravados', number_format($subtotal, 5, '.', '')));

        // TotalServExentos
        $resumen->appendChild($xml->createElement('TotalServExentos', '0.00000'));

        // TotalMercanciasGravadas
        $resumen->appendChild($xml->createElement('TotalMercanciasGravadas', '0.00000'));

        // TotalMercanciasExentas
        $resumen->appendChild($xml->createElement('TotalMercanciasExentas', '0.00000'));

        // TotalGravado
        $resumen->appendChild($xml->createElement('TotalGravado', number_format($subtotal, 5, '.', '')));

        // TotalExento
        $resumen->appendChild($xml->createElement('TotalExento', '0.00000'));

        // TotalVenta
        $resumen->appendChild($xml->createElement('TotalVenta', number_format($subtotal, 5, '.', '')));

        // TotalDescuentos
        $resumen->appendChild($xml->createElement('TotalDescuentos', number_format($discount, 5, '.', '')));

        // TotalVentaNeta
        $resumen->appendChild($xml->createElement('TotalVentaNeta', number_format($subtotal - $discount, 5, '.', '')));

        // TotalImpuesto
        $resumen->appendChild($xml->createElement('TotalImpuesto', number_format($total_tax, 5, '.', '')));

        // TotalComprobante
        $resumen->appendChild($xml->createElement('TotalComprobante', number_format($total, 5, '.', '')));

        return $resumen;
    }

    /**
     * Get sales condition based on payment method
     *
     * @param WC_Order $order WooCommerce order
     * @return string Sales condition code
     */
    private static function get_sales_condition($order) {
        // Default to cash
        return self::SALES_CONDITION_CASH;
    }

    /**
     * Get payment method code
     *
     * @param WC_Order $order WooCommerce order
     * @return string Payment method code
     */
    private static function get_payment_method($order) {
        $payment_method = $order->get_payment_method();

        // Map WooCommerce payment methods to Hacienda codes
        $map = [
            'cod' => self::PAYMENT_CASH,
            'bacs' => self::PAYMENT_TRANSFER,
            'cheque' => self::PAYMENT_CHECK,
        ];

        // Check for card payments
        if (strpos($payment_method, 'stripe') !== false ||
            strpos($payment_method, 'paypal') !== false ||
            strpos($payment_method, 'card') !== false) {
            return self::PAYMENT_CARD;
        }

        return isset($map[$payment_method]) ? $map[$payment_method] : self::PAYMENT_OTHER;
    }

    /**
     * Build Exoneracion (tax exemption) element
     *
     * This method builds the exemption element for the XML invoice according to
     * Costa Rica's Hacienda requirements.
     *
     * Important calculation notes:
     * - The exemption rate (porcentaje) replaces the standard 13% IVA
     * - PorcentajeExoneracion in XML represents the percentage EXEMPTED, not the applied rate
     * - MontoExoneracion is the difference between standard tax (13%) and applied tax
     *
     * Example: If exemption rate is 4%:
     * - Applied IVA rate: 4%
     * - Percentage exempted: 100 - (4/13 * 100) = 69.23%
     * - Exemption amount: (subtotal * 13%) - (subtotal * 4%)
     *
     * @param DOMDocument $xml XML document
     * @param WC_Order    $order WooCommerce order
     * @param float       $subtotal Subtotal amount for this line
     * @param int         $tarifa_aplicada Applied tax rate (0, 1, 2, 4, or 8)
     * @return DOMElement|null Exoneracion element or null if not applicable
     */
    private static function build_exoneracion($xml, $order, $subtotal, $tarifa_aplicada) {
        // Check if order has exemption
        if (!class_exists('FE_Woo_Exoneracion')) {
            return null;
        }

        $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
        if (!$exon_data) {
            return null;
        }

        // Validate exemption before adding to XML
        $validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
        if (!$validation['valid']) {
            return null;
        }

        $exoneracion = $xml->createElement('Exoneracion');

        // TipoDocumento
        $exoneracion->appendChild($xml->createElement('TipoDocumento', $exon_data['tipo']));

        // NumeroDocumento
        $exoneracion->appendChild($xml->createElement('NumeroDocumento', $exon_data['numero']));

        // NombreInstitucion
        $exoneracion->appendChild($xml->createElement('NombreInstitucion', $exon_data['institucion']));

        // FechaEmision
        $fecha_emision = DateTime::createFromFormat('Y-m-d', $exon_data['fecha_emision']);
        if ($fecha_emision) {
            $exoneracion->appendChild($xml->createElement('FechaEmision', $fecha_emision->format('Y-m-d\TH:i:sP')));
        }

        // PorcentajeExoneracion - percentage of tax that is EXEMPTED
        // Formula: 100 - (applied_rate / standard_rate * 100)
        // Example: if applied rate is 4%, then exempted percentage = 100 - (4/13*100) = 69.23%
        $standard_rate = 13;
        $percentage_exempted = $tarifa_aplicada == 0 ? 100 : (100 - ($tarifa_aplicada / $standard_rate * 100));
        $exoneracion->appendChild($xml->createElement('PorcentajeExoneracion', number_format($percentage_exempted, 2, '.', '')));

        // MontoExoneracion - amount of tax EXEMPTED (difference between standard and applied)
        // Formula: (subtotal * standard_rate%) - (subtotal * applied_rate%)
        $tax_standard = ($subtotal * $standard_rate) / 100;
        $tax_applied = ($subtotal * $tarifa_aplicada) / 100;
        $monto_exoneracion = $tax_standard - $tax_applied;
        $exoneracion->appendChild($xml->createElement('MontoExoneracion', number_format($monto_exoneracion, 5, '.', '')));

        return $exoneracion;
    }

    /**
     * Build InformacionReferencia (reference information) element for notes
     *
     * @param DOMDocument $xml XML document
     * @param array       $reference_data Reference data
     *                    - referenced_clave: Clave of referenced document
     *                    - referenced_date: Date of referenced document
     *                    - referenced_type: Type code (01-05, 99)
     *                    - reference_code: Reason code (01-05, 99)
     *                    - reference_reason: Text description
     * @return DOMElement InformacionReferencia element
     */
    private static function build_informacion_referencia($xml, $reference_data) {
        $info_ref = $xml->createElement('InformacionReferencia');

        // TipoDoc - Type of referenced document
        $info_ref->appendChild($xml->createElement('TipoDoc', $reference_data['referenced_type']));

        // Numero - Referenced document number (clave)
        $info_ref->appendChild($xml->createElement('Numero', $reference_data['referenced_clave']));

        // FechaEmision - Referenced document emission date
        // Convert to ISO 8601 format if it's not already
        $fecha = $reference_data['referenced_date'];
        if ($fecha instanceof DateTime) {
            $fecha = $fecha->format('c');
        } elseif (is_string($fecha)) {
            // Try to parse and convert
            $date_obj = DateTime::createFromFormat('Y-m-d H:i:s', $fecha);
            if (!$date_obj) {
                $date_obj = DateTime::createFromFormat('Y-m-d', $fecha);
            }
            if ($date_obj) {
                $fecha = $date_obj->format('c');
            }
        }
        $info_ref->appendChild($xml->createElement('FechaEmision', $fecha));

        // Codigo - Reference code (reason)
        $info_ref->appendChild($xml->createElement('Codigo', $reference_data['reference_code']));

        // Razon - Text description of reason
        $razon = substr($reference_data['reference_reason'], 0, 180); // Max 180 characters
        $info_ref->appendChild($xml->createElement('Razon', $razon));

        return $info_ref;
    }

    /**
     * Valid tax rates accepted by Hacienda for Costa Rica
     */
    private static $valid_hacienda_rates = [0, 1, 2, 4, 8, 13];

    /**
     * Get the tax rate for a product/item, snapped to a valid Hacienda rate.
     *
     * Uses WC_Tax::get_rates() as the primary source. Falls back to calculating
     * from the item's tax/subtotal, then snaps to the nearest valid Hacienda rate.
     *
     * @param WC_Product|false $product   WooCommerce product (or false if deleted)
     * @param float            $item_total Item total (before tax)
     * @param float            $item_tax   Item tax amount
     * @return float Valid Hacienda tax rate
     */
    private static function get_tax_rate_for_item($product, $item_total, $item_tax) {
        // Try WC_Tax::get_rates() first (most reliable source)
        if ($product) {
            $tax_rates = WC_Tax::get_rates($product->get_tax_class());
            if (!empty($tax_rates)) {
                $first_rate = reset($tax_rates);
                return self::snap_to_valid_rate(floatval($first_rate['rate']));
            }
        }

        // Fallback: calculate from actual tax/subtotal
        if ($item_tax > 0 && $item_total > 0) {
            $calculated = ($item_tax / $item_total) * 100;
            return self::snap_to_valid_rate($calculated);
        }

        return 0;
    }

    /**
     * Snap a calculated tax rate to the nearest valid Hacienda rate.
     *
     * Hacienda only accepts specific rate values: 0, 1, 2, 4, 8, 13.
     *
     * @param float $rate Calculated rate
     * @return float Nearest valid Hacienda rate
     */
    private static function snap_to_valid_rate($rate) {
        $closest = 0;
        $min_diff = PHP_FLOAT_MAX;
        foreach (self::$valid_hacienda_rates as $valid) {
            $diff = abs($rate - $valid);
            if ($diff < $min_diff) {
                $min_diff = $diff;
                $closest = $valid;
            }
        }
        return $closest;
    }

    /**
     * Calculate tax info for an item, respecting tax_status and exemptions.
     *
     * @param WC_Order_Item|null $item      Order item (null for shipping)
     * @param WC_Product|false   $product   Product (or false if deleted)
     * @param float              $item_total Item total (before tax)
     * @param float              $item_tax   Original tax from WooCommerce
     * @param array|null         $exon_data  Exemption data or null
     * @return array ['tarifa' => float, 'tax' => float, 'is_taxable' => bool]
     */
    private static function calculate_item_tax_info($item, $product, $item_total, $item_tax, $exon_data) {
        // Determine if taxable: check product tax_status, or fall back to item's tax when product is deleted
        // For shipping ($item=null, $product=false), use $item_tax > 0 to determine taxability
        $is_taxable = $product
            ? $product->get_tax_status() === 'taxable'
            : $item_tax > 0;

        if (!$is_taxable) {
            return ['tarifa' => 0, 'tax' => 0, 'is_taxable' => false];
        }

        $tax_rate = self::get_tax_rate_for_item($product, $item_total, $item_tax);

        // If the product is "taxable" but its tax class resolves to a 0% rate (exempt class),
        // treat it as non-taxable: no tax applied and no exoneration
        if ($tax_rate == 0 && $item_tax == 0) {
            return ['tarifa' => 0, 'tax' => 0, 'is_taxable' => false];
        }

        // Only apply exoneration to items that actually have a tax rate > 0
        if ($exon_data && isset($exon_data['porcentaje']) && $tax_rate > 0) {
            // Taxable with exemption
            $tarifa = intval($exon_data['porcentaje']);
            $tax = ($item_total * $tarifa) / 100;
        } else {
            // Taxable without exemption - use the actual rate, do not default to 13%
            $tarifa = $tax_rate;
            $tax = $item_tax;
        }

        return ['tarifa' => $tarifa, 'tax' => $tax, 'is_taxable' => true];
    }
}
