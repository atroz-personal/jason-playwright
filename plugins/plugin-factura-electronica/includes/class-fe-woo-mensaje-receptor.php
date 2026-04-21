<?php
/**
 * FE WooCommerce Mensaje Receptor Generator
 *
 * Generates Mensaje Receptor (acceptance/rejection message) XML for Hacienda v4.4
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Mensaje_Receptor Class
 *
 * Generates acceptance/rejection messages according to Hacienda v4.4 specifications
 */
class FE_Woo_Mensaje_Receptor {

    /**
     * Message type constants
     */
    const MENSAJE_ACEPTACION = '1';
    const MENSAJE_ACEPTACION_PARCIAL = '2';
    const MENSAJE_RECHAZO = '3';

    /**
     * Generate Mensaje Receptor XML
     *
     * @param WC_Order $order Order object
     * @param string   $clave_referencia Clave of the original document (factura/tiquete)
     * @param array    $hacienda_response Response from Hacienda
     * @param string   $document_type Document type (factura or tiquete)
     * @return array Result with 'success', 'clave', 'xml' keys
     */
    public static function generate_mensaje_receptor($order, $clave_referencia, $hacienda_response, $document_type = 'tiquete') {
        try {
            // Determine message type based on Hacienda response
            $mensaje_tipo = self::determine_mensaje_tipo($hacienda_response);

            // Build XML structure. Per Hacienda v4.4, <Clave> in MensajeReceptor
            // must match the referenced document's clave, so we pass it through.
            $xml = self::build_xml($order, $clave_referencia, $mensaje_tipo, $hacienda_response, $document_type);

            return [
                'success' => true,
                'clave' => $clave_referencia,
                'xml' => $xml,
                'mensaje_tipo' => $mensaje_tipo,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Determine message type based on Hacienda response
     *
     * @param array $hacienda_response Hacienda API response
     * @return string Message type code
     */
    private static function determine_mensaje_tipo($hacienda_response) {
        // If response indicates success, it's accepted
        if (isset($hacienda_response['success']) && $hacienda_response['success']) {
            return self::MENSAJE_ACEPTACION;
        }

        // Check for specific status in response data
        if (isset($hacienda_response['data']['ind-estado'])) {
            $estado = strtolower($hacienda_response['data']['ind-estado']);
            if ($estado === 'aceptado' || $estado === 'procesando') {
                return self::MENSAJE_ACEPTACION;
            } elseif ($estado === 'rechazado') {
                return self::MENSAJE_RECHAZO;
            }
        }

        // Default to acceptance
        return self::MENSAJE_ACEPTACION;
    }

    /**
     * Build Mensaje Receptor XML structure
     *
     * @param WC_Order $order Order object
     * @param string   $clave_referencia Original document clave (also used as <Clave>)
     * @param string   $mensaje_tipo Message type
     * @param array    $hacienda_response Hacienda response
     * @param string   $document_type Document type
     * @return string XML string
     */
    private static function build_xml($order, $clave_referencia, $mensaje_tipo, $hacienda_response, $document_type) {
        $xml = new DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        // Root element
        $root = $xml->createElement('MensajeReceptor');
        $root->setAttribute('xmlns', 'https://cdn.comprobanteselectronicos.go.cr/xml-schemas/v4.4/mensajeReceptor');
        $root->setAttribute('xmlns:xsd', 'http://www.w3.org/2001/XMLSchema');
        $root->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->appendChild($root);

        // Clave (same as the referenced document)
        $root->appendChild($xml->createElement('Clave', $clave_referencia));

        // NumeroCedulaEmisor: cédula of the emisor of the original document,
        // decoded from positions 9..20 of the 50-char clave.
        $cedula_emisor = self::extract_cedula_from_clave($clave_referencia);
        $root->appendChild($xml->createElement('NumeroCedulaEmisor', $cedula_emisor));

        // FechaEmisionDoc (current date)
        $fecha_emision = current_time('c'); // ISO 8601
        $root->appendChild($xml->createElement('FechaEmisionDoc', $fecha_emision));

        // Mensaje (1=Aceptado, 2=Aceptación Parcial, 3=Rechazado)
        $root->appendChild($xml->createElement('Mensaje', $mensaje_tipo));

        // DetalleMensaje (optional - details about acceptance/rejection)
        $detalle_mensaje = self::get_detalle_mensaje($mensaje_tipo, $hacienda_response);
        if (!empty($detalle_mensaje)) {
            $root->appendChild($xml->createElement('DetalleMensaje', $detalle_mensaje));
        }

        // MontoTotalImpuesto
        $root->appendChild($xml->createElement('MontoTotalImpuesto', number_format($order->get_total_tax(), 5, '.', '')));

        // TotalFactura
        $root->appendChild($xml->createElement('TotalFactura', number_format($order->get_total(), 5, '.', '')));

        // NumeroCedulaReceptor: cédula of the party accepting the document
        // (the customer on the factura; on tiquete the receptor is typically the same emisor).
        $cedula_receptor = $order->get_meta('_fe_woo_id_number');
        if (empty($cedula_receptor)) {
            $cedula_receptor = $cedula_emisor;
        } else {
            $cedula_receptor = preg_replace('/[^0-9]/', '', $cedula_receptor);
        }
        $root->appendChild($xml->createElement('NumeroCedulaReceptor', $cedula_receptor));

        // NumeroConsecutivoReceptor (consecutive for this mensaje receptor)
        $consecutivo = self::generate_consecutive_receptor($order);
        $root->appendChild($xml->createElement('NumeroConsecutivoReceptor', $consecutivo));

        return $xml->saveXML();
    }

    /**
     * Extract the cédula jurídica of the emisor encoded in a 50-char clave.
     *
     * Clave layout: [país:3][día:2][mes:2][año:2][cédula:12][consec:20][sit:1][seg:8]
     * Cédula occupies positions 9..20 (zero-indexed, 12 chars), left-padded with zeros.
     *
     * @param string $clave 50-char clave
     * @return string Cédula (digits only, leading zeros stripped). Falls back to the
     *                configured emisor cédula when the clave is malformed — returning
     *                an empty string would produce an XML that Hacienda v4.4 rejects.
     */
    private static function extract_cedula_from_clave($clave) {
        $digits = preg_replace('/[^0-9]/', '', (string) $clave);
        if (strlen($digits) < 21) {
            if (function_exists('error_log')) {
                error_log('[FE_Woo] extract_cedula_from_clave: clave corta (' . strlen($digits) . '), usando fallback config');
            }
            return preg_replace('/[^0-9]/', '', (string) FE_Woo_Hacienda_Config::get_cedula_juridica());
        }
        return ltrim(substr($digits, 9, 12), '0');
    }

    /**
     * Get detail message based on message type
     *
     * @param string $mensaje_tipo Message type
     * @param array  $hacienda_response Hacienda response
     * @return string Detail message
     */
    private static function get_detalle_mensaje($mensaje_tipo, $hacienda_response) {
        switch ($mensaje_tipo) {
            case self::MENSAJE_ACEPTACION:
                return 'Comprobante aceptado y procesado correctamente por Hacienda';

            case self::MENSAJE_ACEPTACION_PARCIAL:
                return 'Comprobante aceptado parcialmente';

            case self::MENSAJE_RECHAZO:
                $mensaje = 'Comprobante rechazado';
                if (isset($hacienda_response['message'])) {
                    $mensaje .= ': ' . $hacienda_response['message'];
                }
                return $mensaje;

            default:
                return '';
        }
    }

    /**
     * Generate consecutive number for mensaje receptor
     *
     * @param WC_Order $order Order object
     * @return string Consecutive number
     */
    private static function generate_consecutive_receptor($order) {
        // Format: SSSEEETTNNNNNNNNNN
        // SSS = Sucursal (3 digits) - Branch
        // EEE = Terminal (3 digits) - Terminal/POS
        // TT = Document Type (2 digits) - 05=Mensaje Receptor
        // NNNNNNNNNN = Consecutive (10 digits)

        $sucursal = '001'; // Default branch
        $terminal = '001'; // Default terminal/POS
        $doc_type = '05'; // Mensaje Receptor
        $consecutive = str_pad($order->get_id(), 10, '0', STR_PAD_LEFT);

        return $sucursal . $terminal . $doc_type . $consecutive;
    }

    /**
     * Get message type label
     *
     * @param string $mensaje_tipo Message type code
     * @return string Message type label
     */
    public static function get_mensaje_tipo_label($mensaje_tipo) {
        $labels = [
            self::MENSAJE_ACEPTACION => __('Aceptación', 'fe-woo'),
            self::MENSAJE_ACEPTACION_PARCIAL => __('Aceptación Parcial', 'fe-woo'),
            self::MENSAJE_RECHAZO => __('Rechazo', 'fe-woo'),
        ];

        return isset($labels[$mensaje_tipo]) ? $labels[$mensaje_tipo] : $mensaje_tipo;
    }
}
