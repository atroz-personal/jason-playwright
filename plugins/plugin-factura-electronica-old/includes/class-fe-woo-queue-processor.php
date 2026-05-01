<?php
/**
 * FE WooCommerce Queue Processor
 *
 * Processes the factura queue via cron job
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Queue_Processor Class
 *
 * Handles cron-based processing of factura queue
 */
class FE_Woo_Queue_Processor {

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'fe_woo_process_queue';

    /**
     * Initialize the processor
     */
    public static function init() {
        // Schedule cron if not already scheduled (hourly)
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }

        // Hook into cron
        add_action(self::CRON_HOOK, [__CLASS__, 'process_queue']);
    }

    /**
     * Process the queue (called by cron)
     */
    public static function process_queue() {
        // Check if processing is already running
        if (get_transient('fe_woo_queue_processing')) {
            return; // Already processing
        }

        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            self::log('Queue processing paused by configuration', 'debug');
            return;
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            self::log('Queue processing skipped: ' . $ready_status['message'], 'error');
            return;
        }

        // Set processing lock
        set_transient('fe_woo_queue_processing', true, 300); // 5 minute lock

        try {
            // Get pending items
            $items = FE_Woo_Queue::get_pending_items(10); // Process 10 at a time

            foreach ($items as $item) {
                self::process_item($item);
            }
        } finally {
            // Release lock
            delete_transient('fe_woo_queue_processing');
        }
    }

    /**
     * Process a single queue item
     *
     * @param object $item Queue item
     */
    private static function process_item($item) {
        // Mark as processing
        FE_Woo_Queue::mark_processing($item->id);

        // Log start
        self::log(sprintf('Processing queue item #%d for order #%d', $item->id, $item->order_id));

        try {
            // Get order
            $order = wc_get_order($item->order_id);

            if (!$order) {
                throw new Exception('Order not found');
            }

            // Get document type from queue item
            $document_type = isset($item->document_type) ? $item->document_type : 'tiquete';

            // Route to appropriate processor based on document type
            if (in_array($document_type, ['nota_credito', 'nota_debito'], true)) {
                // Process credit/debit note via Nota Manager
                self::process_nota_item($order, $item, $document_type);
            } else {
                // Check if this order requires multi-factura processing
                $multi_factura_result = FE_Woo_Multi_Factura_Generator::generate_facturas_for_order($order);

                if (isset($multi_factura_result['error'])) {
                    throw new Exception($multi_factura_result['error']);
                }

                // Check if multiple facturas are needed
                if ($multi_factura_result['multiple']) {
                    // Process multiple facturas
                    self::process_multi_factura($order, $item, $document_type, $multi_factura_result);
                } else {
                    // Single factura processing (original logic)
                    self::process_single_factura($order, $item, $document_type, $multi_factura_result);
                }
            }

        } catch (Exception $e) {
            // Mark as failed
            $error_message = $e->getMessage();
            FE_Woo_Queue::mark_failed($item->id, $error_message, true);

            // Log error
            $doc_type = isset($document_type) ? $document_type : 'tiquete';
            self::log(sprintf('Failed to process order #%d (%s): %s', $item->order_id, $doc_type, $error_message), 'error');

            // Add order note
            if (isset($order) && $order) {
                $doc_label_map = [
                    'factura' => 'Factura Electrónica',
                    'tiquete' => 'Tiquete Electrónico',
                    'nota_credito' => 'Nota de Crédito',
                    'nota_debito' => 'Nota de Débito',
                ];
                $doc_label = isset($doc_label_map[$doc_type]) ? $doc_label_map[$doc_type] : 'Documento';
                $order->add_order_note(
                    sprintf(
                        __('Error al generar %s: %s', 'fe-woo'),
                        $doc_label,
                        $error_message
                    )
                );
            }
        }
    }

    /**
     * Process a nota (credit/debit note) queue item
     *
     * Delegates to FE_Woo_Nota_Manager::process_nota() which handles
     * XML generation, Hacienda submission, and metadata storage.
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type 'nota_credito' or 'nota_debito'
     */
    private static function process_nota_item($order, $item, $document_type) {
        // Parse nota data from queue item
        $nota_data = json_decode($item->factura_data, true);
        if (empty($nota_data)) {
            throw new Exception('Invalid nota data in queue item');
        }

        $note_type = isset($nota_data['note_type']) ? $nota_data['note_type'] : $document_type;
        $emisor_id = isset($nota_data['emisor_id']) ? (int) $nota_data['emisor_id'] : (isset($item->emisor_id) ? (int) $item->emisor_id : 0);
        $referenced_clave = isset($nota_data['referenced_clave']) ? $nota_data['referenced_clave'] : '';
        $reference_code = isset($nota_data['reference_code']) ? $nota_data['reference_code'] : '';
        $reason = isset($nota_data['reason']) ? $nota_data['reason'] : '';
        $additional_notes = isset($nota_data['additional_notes']) ? $nota_data['additional_notes'] : '';

        // Resolve emisor
        $emisor = null;
        if ($emisor_id) {
            $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
        }
        if (!$emisor) {
            $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
        }
        if (!$emisor) {
            throw new Exception(__('No hay emisor configurado para procesar esta nota.', 'fe-woo'));
        }

        if (empty($emisor->api_username) || empty($emisor->api_password)) {
            throw new Exception(sprintf(
                __('El emisor "%s" no tiene credenciales de API configuradas.', 'fe-woo'),
                $emisor->nombre_legal
            ));
        }

        // Build reference data
        $reference_data = isset($nota_data['reference_data']) ? $nota_data['reference_data'] : [
            'referenced_clave' => $referenced_clave,
            'referenced_date' => FE_Woo_Nota_Manager::get_factura_sent_date($order, $referenced_clave),
            'referenced_type' => ($order->get_meta('_fe_woo_document_type') === 'factura') ? '01' : '04',
            'reference_code' => $reference_code,
            'reference_reason' => $reason,
        ];

        self::log(sprintf('Processing nota queue item #%d for order #%d - Type: %s, Emisor: %s, Ref: %s',
            $item->id,
            $order->get_id(),
            $note_type,
            $emisor->nombre_legal,
            substr($referenced_clave, -8)
        ));

        // Delegate to Nota Manager (use_queue=false since we ARE the queue processor)
        $result = FE_Woo_Nota_Manager::process_nota($order, [
            'note_type' => $note_type,
            'reference_data' => $reference_data,
            'emisor_id' => (int) $emisor->id,
            'emisor' => $emisor,
            'reason' => $reason,
            'additional_notes' => $additional_notes,
            'reference_code' => $reference_code,
            'referenced_clave' => $referenced_clave,
        ]);

        if ($result['success']) {
            FE_Woo_Queue::mark_completed($item->id, $result['clave'], '', ['nota' => true]);
            self::log(sprintf('Nota processed successfully for order #%d. Clave: %s', $order->get_id(), $result['clave']));
        } else {
            throw new Exception($result['message']);
        }
    }

    /**
     * Process single factura (original logic)
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     */
    private static function process_single_factura($order, $item, $document_type, $multi_factura_result) {
        // Get the first (and only) factura data
        $factura_data = $multi_factura_result['facturas'][0];
        $emisor_id = $factura_data['emisor_id'];
        $line_items = $factura_data['items'];

        try {
            // Get the emisor object for this factura
            $emisor = null;
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
            }
            if (!$emisor) {
                $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
            }
            if (!$emisor) {
                throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
            }

            // Validate emisor has required credentials before proceeding
            if (empty($emisor->api_username) || empty($emisor->api_password)) {
                throw new Exception(sprintf(
                    __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                    $emisor->nombre_legal
                ));
            }

            // Validate exoneración if applicable (only for factura and only for parent/default emisor)
            if ($document_type === 'factura' && !empty($emisor->is_parent) && class_exists('FE_Woo_Exoneracion') && $order->get_meta('_fe_woo_has_exoneracion') === 'yes') {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($item->order_id);
                if (!$validation['valid']) {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_REJECTED_VALIDATION);
                    $order->save();
                    throw new Exception('Exoneración validation failed: ' . implode(', ', $validation['errors']));
                } else {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_VALID);
                    $order->save();
                }
            }

            // Generate factura or tiquete XML with specific emisor and line items
            $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items);

            if (!$result['success']) {
                // If skipped because all items are non-taxable, mark as completed and return
                if (!empty($result['skipped'])) {
                    FE_Woo_Queue::mark_completed($item->id, '', '', ['skipped' => true]);
                    $order->add_order_note(__('No se generó documento electrónico: todos los productos tienen estado de impuesto "ninguno".', 'fe-woo'));
                    self::log(sprintf('Order #%d skipped - all items have tax_status none', $item->order_id));
                    return;
                }
                throw new Exception($result['error']);
            }

            $clave = $result['clave'];
            $xml = $result['xml'];

            // Send to Hacienda using emisor's specific credentials
            $api_client = new FE_Woo_API_Client();
            $response = $api_client->send_invoice_with_emisor($xml, $emisor);

            if (!$response['success']) {
                // Include detailed error for connection/authentication failures
                $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                if (isset($response['error_detail'])) {
                    $error_message .= ' - ' . $response['error_detail'];
                }
                throw new Exception($error_message);
            }

            // Mark as completed
            FE_Woo_Queue::mark_completed($item->id, $clave, $xml, $response);

            // Save documents to filesystem
            $xml_result = FE_Woo_Document_Storage::save_xml($item->order_id, $xml, $clave);
            if ($xml_result['success']) {
                $order->update_meta_data('_fe_woo_xml_file_path', $xml_result['file_path']);
            }

            // Save Hacienda response as JSON (for reference)
            $acuse_result = FE_Woo_Document_Storage::save_acuse($item->order_id, $response, $clave);
            if ($acuse_result['success']) {
                $order->update_meta_data('_fe_woo_acuse_file_path', $acuse_result['file_path']);
            }

            // Generate Mensaje Receptor XML (acceptance message to Hacienda)
            $mensaje_result = FE_Woo_Mensaje_Receptor::generate_mensaje_receptor($order, $clave, $response, $document_type);
            if ($mensaje_result['success']) {
                self::log(sprintf('Mensaje Receptor generated successfully for order #%d. Clave: %s', $item->order_id, $mensaje_result['clave']));

                $mensaje_save_result = FE_Woo_Document_Storage::save_mensaje_receptor(
                    $item->order_id,
                    $mensaje_result['xml'],
                    $mensaje_result['clave']
                );

                if ($mensaje_save_result['success']) {
                    $order->update_meta_data('_fe_woo_mensaje_receptor_clave', $mensaje_result['clave']);
                    $order->update_meta_data('_fe_woo_mensaje_receptor_xml', $mensaje_result['xml']);
                    $order->update_meta_data('_fe_woo_mensaje_receptor_file_path', $mensaje_save_result['file_path']);
                    $order->save();

                    self::log(sprintf('Mensaje Receptor saved successfully for order #%d', $item->order_id));
                    self::log(sprintf('  - File path: %s', $mensaje_save_result['file_path']));
                    self::log(sprintf('  - File exists: %s', file_exists($mensaje_save_result['file_path']) ? 'YES' : 'NO'));
                    self::log(sprintf('  - Meta saved - Clave: %s', $order->get_meta('_fe_woo_mensaje_receptor_clave')));
                } else {
                    self::log(sprintf('Failed to save Mensaje Receptor for order #%d: %s', $item->order_id, $mensaje_save_result['error']), 'error');
                }
            } else {
                self::log(sprintf('Failed to generate Mensaje Receptor for order #%d: %s', $item->order_id, $mensaje_result['error']), 'error');
            }

            // Generate and save PDF (exonerations only apply to parent/default emisor)
            $apply_exoneracion = $emisor && !empty($emisor->is_parent);
            $emisor_pdf_data = $emisor ? FE_Woo_Factura_Generator::prepare_emisor_data($emisor) : null;
            $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
            if ($pdf_result['success']) {
                $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                $pdf_save_result = FE_Woo_Document_Storage::save_pdf($item->order_id, $pdf_result['pdf_content'], $clave, $is_html);
                if ($pdf_save_result['success']) {
                    $order->update_meta_data('_fe_woo_pdf_file_path', $pdf_save_result['file_path']);
                    $format = $is_html ? 'HTML' : 'PDF';
                    self::log(sprintf('%s document generated and saved for order #%d', $format, $item->order_id), 'debug');
                } else {
                    self::log(sprintf('Failed to save PDF for order #%d: %s', $item->order_id, $pdf_save_result['error']), 'error');
                }
            } else {
                self::log(sprintf('Failed to generate PDF for order #%d: %s', $item->order_id, $pdf_result['error']), 'error');
            }

            // Update order meta
            $order->update_meta_data('_fe_woo_document_type', $document_type);
            $order->update_meta_data('_fe_woo_factura_clave', $clave);
            $order->update_meta_data('_fe_woo_factura_xml', $xml);
            $order->update_meta_data('_fe_woo_factura_status', 'sent');
            $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
            $order->update_meta_data('_fe_woo_hacienda_status', 'procesando'); // 202 = accepted for processing, actual status comes asynchronously
            $order->save();

            // Add order note
            $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
            $order->add_order_note(
                sprintf(
                    __('%s enviado exitosamente. Clave: %s', 'fe-woo'),
                    $document_label,
                    $clave
                )
            );

            self::log(sprintf('Successfully processed order #%d (%s). Clave: %s', $item->order_id, $document_type, $clave));

            // Send email to customer with document attachments
            self::send_factura_email($order, $clave, $document_type);

        } catch (Exception $e) {
            // Mark as failed
            $error_message = $e->getMessage();
            FE_Woo_Queue::mark_failed($item->id, $error_message, true);

            // Log error
            $doc_type = isset($document_type) ? $document_type : 'tiquete';
            self::log(sprintf('Failed to process order #%d (%s): %s', $item->order_id, $doc_type, $error_message), 'error');

            // Add order note
            if (isset($order) && $order) {
                $doc_label = ($doc_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
                $order->add_order_note(
                    sprintf(
                        __('Error al generar %s: %s', 'fe-woo'),
                        $doc_label,
                        $error_message
                    )
                );
            }
        }
    }

    /**
     * Send factura or tiquete email to customer
     *
     * @param WC_Order $order Order object
     * @param string   $clave Invoice clave
     * @param string   $document_type Document type (factura or tiquete)
     */
    private static function send_factura_email($order, $clave, $document_type = 'tiquete') {
        // For tiquete, use billing email; for factura, use invoice email
        if ($document_type === 'factura') {
            $email = $order->get_meta('_fe_woo_invoice_email');
            if (empty($email)) {
                $email = $order->get_billing_email();
            }
        } else {
            $email = $order->get_billing_email();
        }

        if (empty($email)) {
            return;
        }

        $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';

        $subject = sprintf(
            __('%s #%s - %s', 'fe-woo'),
            $document_label,
            $order->get_order_number(),
            get_bloginfo('name')
        );

        $message = sprintf(
            __("Estimado/a %s,\n\nAdjunto encontrará su %s.\n\nClave: %s\nNúmero de Orden: %s\n\nGracias por su compra.\n\n%s", 'fe-woo'),
            $order->get_billing_first_name(),
            $document_label,
            $clave,
            $order->get_order_number(),
            get_bloginfo('name')
        );

        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        // Get document paths from storage
        $attachments = [];
        $document_paths = FE_Woo_Document_Storage::get_document_paths($order->get_id(), $clave);

        self::log(sprintf('Preparing %s email for order #%d to %s', $document_label, $order->get_id(), $email));
        self::log(sprintf('Document paths retrieved: PDF=%s, XML=%s, Mensaje Receptor=%s, Acuse=%s',
            isset($document_paths['pdf']) ? 'YES' : 'NO',
            isset($document_paths['xml']) ? 'YES' : 'NO',
            isset($document_paths['mensaje_receptor']) ? 'YES' : 'NO',
            isset($document_paths['acuse']) ? 'YES' : 'NO'
        ));

        // Attach PDF file (most important for the customer)
        if (isset($document_paths['pdf']) && $document_paths['pdf']) {
            $attachments[] = $document_paths['pdf'];
            self::log(sprintf('  - PDF: %s (exists: %s)', $document_paths['pdf'], file_exists($document_paths['pdf']) ? 'YES' : 'NO'));
        }

        // Attach XML file (original document)
        if (isset($document_paths['xml']) && $document_paths['xml']) {
            $attachments[] = $document_paths['xml'];
            self::log(sprintf('  - XML: %s (exists: %s)', $document_paths['xml'], file_exists($document_paths['xml']) ? 'YES' : 'NO'));
        }

        // Attach Mensaje Receptor XML (acceptance message)
        if (isset($document_paths['mensaje_receptor']) && $document_paths['mensaje_receptor']) {
            $attachments[] = $document_paths['mensaje_receptor'];
            self::log(sprintf('  - Mensaje Receptor: %s (exists: %s)', $document_paths['mensaje_receptor'], file_exists($document_paths['mensaje_receptor']) ? 'YES' : 'NO'));
        }

        // Note: acuse.json is NOT sent to customer - it's only for internal reference

        self::log(sprintf('Total attachments prepared: %d', count($attachments)));

        // Send email
        $email_result = wp_mail($email, $subject, $message, $headers, $attachments);

        if ($email_result) {
            self::log(sprintf('%s email sent successfully to %s for order #%d with %d attachments', $document_label, $email, $order->get_id(), count($attachments)));
        } else {
            self::log(sprintf('FAILED to send %s email to %s for order #%d', $document_label, $email, $order->get_id()), 'error');
        }
    }

    /**
     * Process multiple facturas for a single order
     *
     * @param WC_Order $order Order object
     * @param object   $item Queue item
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     */
    private static function process_multi_factura($order, $item, $document_type, $multi_factura_result) {
        // Delegate to shared helper; on failure it throws so process_item catches it
        $result = self::generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, 'start');

        // Mark queue item as completed
        FE_Woo_Queue::mark_completed($item->id, implode(', ', $result['all_claves']), '', ['multi_factura' => true]);

        // Update order meta
        self::save_multi_factura_order_meta($order, $document_type, $result);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('%d Facturas Electrónicas enviadas exitosamente.', 'fe-woo'),
                count($result['facturas_generated'])
            )
        );

        self::log(sprintf('Successfully processed multi-factura for order #%d. Generated %d facturas.', $order->get_id(), count($result['facturas_generated'])));

        // Send email
        self::send_multi_factura_email($order, $result['facturas_generated'], $document_type);
    }

    /**
     * Core multi-factura generation and sending logic (shared by queue and immediate modes)
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @param string   $log_stage Log stage label
     * @return array Array with 'facturas_generated', 'all_claves', 'first_clave'
     * @throws Exception On failure
     */
    private static function generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, $log_stage = 'start') {
        $facturas_generated = [];
        $all_claves = [];
        $first_clave = null;
        $order_id = $order->get_id();

        // Check for partial retry: skip emisores whose facturas were already sent
        $already_sent_emisor_ids = [];
        $is_partial_retry = $order->get_meta('_fe_woo_multi_factura_partial') === 'yes';

        if ($is_partial_retry) {
            $previous_facturas = $order->get_meta('_fe_woo_facturas_generated');
            if (!empty($previous_facturas) && is_array($previous_facturas)) {
                foreach ($previous_facturas as $prev_factura) {
                    if (!empty($prev_factura['status']) && $prev_factura['status'] === 'sent') {
                        $already_sent_emisor_ids[] = (int) $prev_factura['emisor_id'];
                        // Carry over previously sent facturas into results
                        $facturas_generated[] = $prev_factura;
                        $all_claves[] = $prev_factura['clave'];
                        if ($first_clave === null) {
                            $first_clave = $prev_factura['clave'];
                        }
                    }
                }

                if (!empty($already_sent_emisor_ids)) {
                    self::log(sprintf(
                        'Partial retry for order #%d: skipping %d already-sent emisor(es): %s',
                        $order_id,
                        count($already_sent_emisor_ids),
                        implode(', ', $already_sent_emisor_ids)
                    ));
                }
            }
        }

        // Filter out already-sent facturas
        $facturas_to_process = $multi_factura_result['facturas'];
        if (!empty($already_sent_emisor_ids)) {
            $facturas_to_process = array_filter($facturas_to_process, function ($factura_data) use ($already_sent_emisor_ids) {
                return !in_array((int) $factura_data['emisor_id'], $already_sent_emisor_ids, true);
            });
            $facturas_to_process = array_values($facturas_to_process); // Re-index
        }

        $total_facturas = count($facturas_to_process) + count($already_sent_emisor_ids);
        self::log(sprintf('Processing multi-factura for order #%d: %d facturas to generate (%d already sent)', $order_id, count($facturas_to_process), count($already_sent_emisor_ids)));

        FE_Woo_Multi_Factura_Generator::log_processing($order, $multi_factura_result['facturas'], $log_stage);

        // Create a single API client instance to reuse across all facturas
        $api_client = new FE_Woo_API_Client();

        foreach ($facturas_to_process as $index => $factura_data) {
            try {
                $emisor_id = $factura_data['emisor_id'];
                $line_items = $factura_data['items'];
                $factura_type = $factura_data['type'];

                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
                if (!$emisor) {
                    $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
                }
                if (!$emisor) {
                    throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
                }

                if (empty($emisor->api_username) || empty($emisor->api_password)) {
                    throw new Exception(sprintf(
                        __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                        $emisor->nombre_legal
                    ));
                }

                self::log(sprintf('  Generating factura %d/%d - Emisor: %s (%d items)',
                    $index + 1 + count($already_sent_emisor_ids),
                    $total_facturas,
                    $emisor->nombre_legal,
                    count($line_items)
                ));

                $include_shipping = !empty($factura_data['include_shipping']);
                $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items, $include_shipping);

                if (!$result['success']) {
                    // If skipped because all items for this emisor are non-taxable, skip this factura
                    if (!empty($result['skipped'])) {
                        self::log(sprintf('  Factura %d/%d skipped - all items for emisor %s have tax_status none',
                            $index + 1, $total_facturas, $emisor->nombre_legal));
                        continue;
                    }
                    throw new Exception($result['error']);
                }

                $clave = $result['clave'];
                $xml = $result['xml'];

                if ($first_clave === null) {
                    $first_clave = $clave;
                }

                $response = $api_client->send_invoice_with_emisor($xml, $emisor);

                if (!$response['success']) {
                    $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                    if (isset($response['error_detail'])) {
                        $error_message .= ' - ' . $response['error_detail'];
                    }
                    throw new Exception($error_message);
                }

                // Save documents
                $xml_result = FE_Woo_Document_Storage::save_xml($order_id, $xml, $clave);
                FE_Woo_Document_Storage::save_acuse($order_id, $response, $clave);

                // Generate Mensaje Receptor
                $mensaje_result = FE_Woo_Mensaje_Receptor::generate_mensaje_receptor($order, $clave, $response, $document_type);
                if ($mensaje_result['success']) {
                    FE_Woo_Document_Storage::save_mensaje_receptor($order_id, $mensaje_result['xml'], $mensaje_result['clave']);
                }

                // Generate PDF (exonerations only apply to parent/default emisor)
                $pdf_path = '';
                $emisor_pdf_data = FE_Woo_Factura_Generator::prepare_emisor_data($emisor);
                $apply_exoneracion = $emisor && !empty($emisor->is_parent);
                $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
                if ($pdf_result['success']) {
                    $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                    $pdf_save_result = FE_Woo_Document_Storage::save_pdf($order_id, $pdf_result['pdf_content'], $clave, $is_html);
                    if ($pdf_save_result['success']) {
                        $pdf_path = $pdf_save_result['file_path'] ?? '';
                    }
                }

                $facturas_generated[] = [
                    'emisor_id' => $emisor_id,
                    'emisor_name' => $emisor->nombre_legal,
                    'clave' => $clave,
                    'xml_path' => $xml_result['file_path'] ?? '',
                    'pdf_path' => $pdf_path,
                    'monto' => FE_Woo_Multi_Factura_Generator::calculate_items_total($line_items),
                    'type' => $factura_type,
                    'items_count' => count($line_items),
                    'status' => 'sent',
                    'hacienda_status' => 'procesando',
                    'sent_date' => current_time('mysql'),
                ];

                $all_claves[] = $clave;

                self::log(sprintf('  Factura %d generated successfully. Clave: %s', $index + 1 + count($already_sent_emisor_ids), $clave));

            } catch (Exception $e) {
                // Save partial results so already-sent facturas are tracked
                $new_facturas_count = count($facturas_generated) - count($already_sent_emisor_ids);
                if ($new_facturas_count > 0 || !empty($already_sent_emisor_ids)) {
                    self::log(sprintf('  Partial multi-factura: %d/%d facturas sent before failure for order #%d',
                        count($facturas_generated),
                        $total_facturas,
                        $order_id
                    ), 'error');

                    $order->update_meta_data('_fe_woo_multi_factura', 'yes');
                    $order->update_meta_data('_fe_woo_multi_factura_partial', 'yes');
                    $order->update_meta_data('_fe_woo_facturas_generated', $facturas_generated);
                    $order->update_meta_data('_fe_woo_facturas_count', count($facturas_generated));
                    $order->update_meta_data('_fe_woo_facturas_expected', $total_facturas);
                    $order->save();
                }

                throw new Exception(sprintf('Failed to generate factura %d/%d for emisor #%d: %s',
                    $index + 1 + count($already_sent_emisor_ids),
                    $total_facturas,
                    $emisor_id,
                    $e->getMessage()
                ));
            }
        }

        // Clear partial retry flag on full success
        if ($is_partial_retry) {
            $order->delete_meta_data('_fe_woo_multi_factura_partial');
            $order->save();
            self::log(sprintf('Partial retry completed successfully for order #%d. Cleared partial flag.', $order_id));
        }

        return [
            'facturas_generated' => $facturas_generated,
            'all_claves' => $all_claves,
            'first_clave' => $first_clave,
        ];
    }

    /**
     * Save multi-factura metadata to order
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $result Result from generate_and_send_multi_facturas
     */
    private static function save_multi_factura_order_meta($order, $document_type, $result) {
        $order->update_meta_data('_fe_woo_multi_factura', 'yes');
        $order->update_meta_data('_fe_woo_facturas_generated', $result['facturas_generated']);
        $order->update_meta_data('_fe_woo_facturas_count', count($result['facturas_generated']));
        $order->update_meta_data('_fe_woo_document_type', $document_type);
        $order->update_meta_data('_fe_woo_factura_clave', $result['first_clave']);
        $order->update_meta_data('_fe_woo_factura_status', 'sent');
        $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
        $order->update_meta_data('_fe_woo_hacienda_status', 'procesando');
        $order->save();
    }

    /**
     * Send multi-factura email to customer
     *
     * Sends a separate email for each factura with its documents attached
     *
     * @param WC_Order $order Order object
     * @param array    $facturas_generated Generated facturas data
     * @param string   $document_type Document type
     */
    private static function send_multi_factura_email($order, $facturas_generated, $document_type = 'tiquete') {
        // Get customer email
        if ($document_type === 'factura') {
            $email = $order->get_meta('_fe_woo_invoice_email');
            if (empty($email)) {
                $email = $order->get_billing_email();
            }
        } else {
            $email = $order->get_billing_email();
        }

        if (empty($email)) {
            self::log(sprintf('No email found for order #%d, skipping multi-factura email', $order->get_id()), 'error');
            return;
        }

        $document_label_singular = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
        $headers = ['Content-Type: text/plain; charset=UTF-8'];

        self::log(sprintf(
            'Sending %d separate multi-factura emails for order #%d to %s',
            count($facturas_generated),
            $order->get_id(),
            $email
        ));

        $emails_sent = 0;
        $emails_failed = 0;

        // Send a separate email for each factura
        foreach ($facturas_generated as $factura) {
            $type_label = ($factura['type'] === 'service_charge')
                ? __('Cargo por Servicio', 'fe-woo')
                : __('Productos', 'fe-woo');

            // Build subject for this factura
            $subject = sprintf(
                __('%s #%s - %s (%s) - %s', 'fe-woo'),
                $document_label_singular,
                $order->get_order_number(),
                $factura['emisor_name'],
                $type_label,
                get_bloginfo('name')
            );

            // Build message body for this factura
            $message = sprintf(
                __("Estimado/a %s,\n\nAdjunto encontrará su %s correspondiente a la orden #%s.\n\n", 'fe-woo'),
                $order->get_billing_first_name(),
                $document_label_singular,
                $order->get_order_number()
            );

            $message .= "===========================================\n";
            $message .= sprintf(__("Emisor: %s\n", 'fe-woo'), $factura['emisor_name']);
            $message .= sprintf(__("Tipo: %s\n", 'fe-woo'), $type_label);
            $message .= sprintf(__("Clave: %s\n", 'fe-woo'), $factura['clave']);
            $message .= sprintf(__("Items: %d\n", 'fe-woo'), $factura['items_count']);
            $message .= "===========================================\n\n";

            $message .= sprintf(
                __("Gracias por su compra.\n\n%s", 'fe-woo'),
                get_bloginfo('name')
            );

            // Get document paths for this factura
            $attachments = [];
            $document_paths = FE_Woo_Document_Storage::get_document_paths($order->get_id(), $factura['clave']);

            // Add PDF
            if (!empty($document_paths['pdf']) && file_exists($document_paths['pdf'])) {
                $attachments[] = $document_paths['pdf'];
            }

            // Add XML
            if (!empty($document_paths['xml']) && file_exists($document_paths['xml'])) {
                $attachments[] = $document_paths['xml'];
            }

            // Add Mensaje Receptor
            if (!empty($document_paths['mensaje_receptor']) && file_exists($document_paths['mensaje_receptor'])) {
                $attachments[] = $document_paths['mensaje_receptor'];
            }

            // Send email for this factura
            $email_result = wp_mail($email, $subject, $message, $headers, $attachments);

            if ($email_result) {
                $emails_sent++;
                self::log(sprintf(
                    'Multi-factura email sent successfully for factura %s (emisor: %s) to %s for order #%d with %d attachments',
                    $factura['clave'],
                    $factura['emisor_name'],
                    $email,
                    $order->get_id(),
                    count($attachments)
                ));
            } else {
                $emails_failed++;
                self::log(sprintf(
                    'FAILED to send multi-factura email for factura %s (emisor: %s) to %s for order #%d',
                    $factura['clave'],
                    $factura['emisor_name'],
                    $email,
                    $order->get_id()
                ), 'error');
            }
        }

        self::log(sprintf(
            'Multi-factura email summary for order #%d: %d sent, %d failed',
            $order->get_id(),
            $emails_sent,
            $emails_failed
        ));
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
            $context = ['source' => 'fe-woo-queue-processor'];

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

    /**
     * Process a single order immediately (synchronously)
     *
     * This bypasses the queue system and processes the order directly
     * Handles both single and multi-factura orders
     *
     * @param int  $order_id Order ID
     * @param bool $force Force regeneration even if invoice already exists
     * @return array Result with success boolean and message
     */
    public static function process_order_immediately($order_id, $force = false) {
        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            return [
                'success' => false,
                'message' => __('El procesamiento de facturas está pausado. Por favor, reactive el procesamiento en Configuración de FE para continuar.', 'fe-woo'),
            ];
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            return [
                'success' => false,
                'message' => $ready_status['message'],
            ];
        }

        // Get order
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Order not found', 'fe-woo'),
            ];
        }

        // Check if factura already exists
        $existing_clave = $order->get_meta('_fe_woo_factura_clave');
        if (!empty($existing_clave) && !$force) {
            return [
                'success' => false,
                'message' => __('Esta orden ya tiene una factura electrónica generada.', 'fe-woo'),
            ];
        }

        // If forcing regeneration, clear old invoice data first
        if ($force && !empty($existing_clave)) {
            self::log(sprintf('Forcing regeneration for order #%d (previous clave: %s)', $order_id, $existing_clave));
            self::clear_invoice_data($order_id);
        }

        // Remove from queue if exists
        FE_Woo_Queue::remove_from_queue($order_id);

        // Determine document type
        $document_type = ($order->get_meta('_fe_woo_require_factura') === 'yes') ? 'factura' : 'tiquete';

        self::log(sprintf('Processing order #%d immediately (manual execution)', $order_id));

        // Check if this order requires multi-factura processing
        $multi_factura_result = FE_Woo_Multi_Factura_Generator::generate_facturas_for_order($order);

        if (isset($multi_factura_result['error'])) {
            return [
                'success' => false,
                'message' => $multi_factura_result['error'],
            ];
        }

        // Check if multiple facturas are needed
        if ($multi_factura_result['multiple']) {
            // Process multiple facturas
            return self::process_multi_factura_immediately($order, $document_type, $multi_factura_result);
        } else {
            // Single factura processing
            return self::process_single_factura_immediately($order, $document_type, $multi_factura_result);
        }
    }

    /**
     * Process single factura immediately
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @return array Result with success boolean and message
     */
    private static function process_single_factura_immediately($order, $document_type, $multi_factura_result) {
        $order_id = $order->get_id();

        // Get the first (and only) factura data
        $factura_data = $multi_factura_result['facturas'][0];
        $emisor_id = $factura_data['emisor_id'];
        $line_items = $factura_data['items'];

        try {
            // Get the emisor object for this factura
            $emisor = null;
            if ($emisor_id) {
                $emisor = FE_Woo_Emisor_Manager::get_emisor($emisor_id);
            }
            if (!$emisor) {
                $emisor = FE_Woo_Emisor_Manager::get_parent_emisor();
            }
            if (!$emisor) {
                throw new Exception(__('No hay emisor configurado para procesar esta factura. Configure un emisor por defecto en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'));
            }

            // Validate emisor has required credentials before proceeding
            if (empty($emisor->api_username) || empty($emisor->api_password)) {
                throw new Exception(sprintf(
                    __('El emisor "%s" no tiene credenciales de API configuradas. Configure las credenciales en WooCommerce > Ajustes > Configuración FE.', 'fe-woo'),
                    $emisor->nombre_legal
                ));
            }

            // Validate exoneración if applicable (only for factura and only for parent/default emisor)
            if ($document_type === 'factura' && !empty($emisor->is_parent) && class_exists('FE_Woo_Exoneracion') && $order->get_meta('_fe_woo_has_exoneracion') === 'yes') {
                $validation = FE_Woo_Exoneracion::validate_exoneracion($order_id);
                if (!$validation['valid']) {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_REJECTED_VALIDATION);
                    $order->save();
                    throw new Exception('Exoneración validation failed: ' . implode(', ', $validation['errors']));
                } else {
                    $order->update_meta_data('_fe_woo_exoneracion_status', FE_Woo_Exoneracion::STATUS_VALID);
                    $order->save();
                }
            }

            // Generate factura or tiquete XML with specific emisor and line items
            $result = FE_Woo_Factura_Generator::generate_from_order($order, $document_type, $emisor_id, $line_items);

            if (!$result['success']) {
                // If skipped because all items are non-taxable, return gracefully
                if (!empty($result['skipped'])) {
                    $order->add_order_note(__('No se generó documento electrónico: todos los productos tienen estado de impuesto "ninguno".', 'fe-woo'));
                    self::log(sprintf('Order #%d skipped (immediate) - all items have tax_status none', $order_id));
                    return [
                        'success' => true,
                        'skipped' => true,
                        'message' => $result['error'],
                    ];
                }
                throw new Exception($result['error']);
            }

            $clave = $result['clave'];
            $xml = $result['xml'];

            // Send to Hacienda using emisor's specific credentials
            $api_client = new FE_Woo_API_Client();
            $response = $api_client->send_invoice_with_emisor($xml, $emisor);

            if (!$response['success']) {
                // Include detailed error for connection/authentication failures
                $error_message = $response['message'] ?? __('Error al enviar factura', 'fe-woo');
                if (isset($response['error_detail'])) {
                    $error_message .= ' - ' . $response['error_detail'];
                }
                throw new Exception($error_message);
            }

            // Save documents to filesystem
            $xml_result = FE_Woo_Document_Storage::save_xml($order_id, $xml, $clave);
            if ($xml_result['success']) {
                $order->update_meta_data('_fe_woo_xml_file_path', $xml_result['file_path']);
            }

            // Save Hacienda response as JSON (for reference)
            $acuse_result = FE_Woo_Document_Storage::save_acuse($order_id, $response, $clave);
            if ($acuse_result['success']) {
                $order->update_meta_data('_fe_woo_acuse_file_path', $acuse_result['file_path']);
            }

            // Generate Mensaje Receptor XML (acceptance message to Hacienda)
            $mensaje_result = FE_Woo_Mensaje_Receptor::generate_mensaje_receptor($order, $clave, $response, $document_type);
            if ($mensaje_result['success']) {
                self::log(sprintf('Mensaje Receptor generated successfully for order #%d. Clave: %s', $order_id, $mensaje_result['clave']));

                $mensaje_save_result = FE_Woo_Document_Storage::save_mensaje_receptor(
                    $order_id,
                    $mensaje_result['xml'],
                    $mensaje_result['clave']
                );

                if ($mensaje_save_result['success']) {
                    $order->update_meta_data('_fe_woo_mensaje_receptor_clave', $mensaje_result['clave']);
                    $order->update_meta_data('_fe_woo_mensaje_receptor_xml', $mensaje_result['xml']);
                    $order->update_meta_data('_fe_woo_mensaje_receptor_file_path', $mensaje_save_result['file_path']);
                    $order->save();
                }
            }

            // Generate and save PDF (exonerations only apply to parent/default emisor)
            $apply_exoneracion = $emisor && !empty($emisor->is_parent);
            $emisor_pdf_data = $emisor ? FE_Woo_Factura_Generator::prepare_emisor_data($emisor) : null;
            $pdf_result = FE_Woo_PDF_Generator::generate_pdf($order, $clave, $document_type, false, $line_items, $emisor_pdf_data, $apply_exoneracion);
            if ($pdf_result['success']) {
                $is_html = isset($pdf_result['is_html']) && $pdf_result['is_html'];
                $pdf_save_result = FE_Woo_Document_Storage::save_pdf($order_id, $pdf_result['pdf_content'], $clave, $is_html);
                if ($pdf_save_result['success']) {
                    $order->update_meta_data('_fe_woo_pdf_file_path', $pdf_save_result['file_path']);
                }
            }

            // Update order meta
            $order->update_meta_data('_fe_woo_document_type', $document_type);
            $order->update_meta_data('_fe_woo_factura_clave', $clave);
            $order->update_meta_data('_fe_woo_factura_xml', $xml);
            $order->update_meta_data('_fe_woo_factura_status', 'sent');
            $order->update_meta_data('_fe_woo_factura_sent_date', current_time('mysql'));
            $order->update_meta_data('_fe_woo_hacienda_status', 'procesando');
            $order->save();

            // Add order note
            $document_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';
            $order->add_order_note(
                sprintf(
                    __('%s generado y enviado exitosamente (ejecución manual). Clave: %s', 'fe-woo'),
                    $document_label,
                    $clave
                )
            );

            self::log(sprintf('Successfully processed order #%d immediately (%s). Clave: %s', $order_id, $document_type, $clave));

            // Send email to customer
            self::send_factura_email($order, $clave, $document_type);

            return [
                'success' => true,
                'message' => sprintf(
                    __('%s generado exitosamente. Clave: %s', 'fe-woo'),
                    $document_label,
                    $clave
                ),
                'clave' => $clave,
            ];

        } catch (Exception $e) {
            $error_message = $e->getMessage();
            $doc_label = ($document_type === 'factura') ? 'Factura Electrónica' : 'Tiquete Electrónico';

            // Log error
            self::log(sprintf('Failed to process order #%d immediately: %s', $order_id, $error_message), 'error');

            // Add order note
            $order->add_order_note(
                sprintf(
                    __('Error al generar %s (ejecución manual): %s', 'fe-woo'),
                    $doc_label,
                    $error_message
                )
            );

            return [
                'success' => false,
                'message' => sprintf(
                    __('Error al generar %s: %s', 'fe-woo'),
                    $doc_label,
                    $error_message
                ),
            ];
        }
    }

    /**
     * Process multiple facturas immediately (for manual execution)
     *
     * @param WC_Order $order Order object
     * @param string   $document_type Document type
     * @param array    $multi_factura_result Multi-factura analysis result
     * @return array Result with success boolean and message
     */
    private static function process_multi_factura_immediately($order, $document_type, $multi_factura_result) {
        $order_id = $order->get_id();

        try {
            $result = self::generate_and_send_multi_facturas($order, $document_type, $multi_factura_result, 'immediate_start');
        } catch (Exception $e) {
            $error_message = $e->getMessage();
            self::log($error_message, 'error');

            $order->add_order_note(
                sprintf(
                    __('Error al generar multi-factura (ejecución manual): %s', 'fe-woo'),
                    $error_message
                )
            );

            return [
                'success' => false,
                'message' => $error_message,
            ];
        }

        // Update order meta
        self::save_multi_factura_order_meta($order, $document_type, $result);

        // Add order note
        $order->add_order_note(
            sprintf(
                __('%d Facturas Electrónicas generadas y enviadas exitosamente (ejecución manual).', 'fe-woo'),
                count($result['facturas_generated'])
            )
        );

        self::log(sprintf('Successfully processed multi-factura immediately for order #%d. Generated %d facturas.', $order_id, count($result['facturas_generated'])));

        // Send emails
        self::send_multi_factura_email($order, $result['facturas_generated'], $document_type);

        return [
            'success' => true,
            'message' => sprintf(
                __('%d Facturas generadas exitosamente.', 'fe-woo'),
                count($result['facturas_generated'])
            ),
            'claves' => $result['all_claves'],
            'multi_factura' => true,
        ];
    }

    /**
     * Manual queue processing (for testing or manual trigger)
     *
     * @return array Result with processed count
     */
    public static function manual_process() {
        // Check if processing is paused
        if (FE_Woo_Hacienda_Config::is_processing_paused()) {
            return [
                'success' => false,
                'processed' => 0,
                'message' => __('El procesamiento de facturas está pausado. Por favor, reactive el procesamiento en Configuración de FE para continuar.', 'fe-woo'),
            ];
        }

        // Check if system is ready for processing
        $ready_status = FE_Woo_Hacienda_Config::is_ready_for_processing();
        if (!$ready_status['ready']) {
            return [
                'success' => false,
                'processed' => 0,
                'message' => $ready_status['message'],
            ];
        }

        $items = FE_Woo_Queue::get_pending_items(10);
        $processed = 0;

        foreach ($items as $item) {
            self::process_item($item);
            $processed++;
        }

        return [
            'success' => true,
            'processed' => $processed,
            'message' => sprintf(__('%d items processed', 'fe-woo'), $processed),
        ];
    }

    /**
     * Clear invoice data from order
     *
     * This removes all invoice-related metadata to allow regeneration
     *
     * @param int $order_id Order ID
     */
    public static function clear_invoice_data($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return;
        }

        // Get old clave for note
        $old_clave = $order->get_meta('_fe_woo_factura_clave');

        // Clear all invoice-related meta
        $meta_keys = [
            '_fe_woo_factura_clave',
            '_fe_woo_factura_xml',
            '_fe_woo_factura_status',
            '_fe_woo_factura_sent_date',
            '_fe_woo_hacienda_status',
            '_fe_woo_hacienda_response',
            '_fe_woo_status_last_checked',
            '_fe_woo_xml_file_path',
            '_fe_woo_pdf_file_path',
            '_fe_woo_acuse_file_path',
            '_fe_woo_mensaje_receptor_clave',
            '_fe_woo_mensaje_receptor_xml',
            '_fe_woo_mensaje_receptor_file_path',
            '_fe_woo_document_type',
        ];

        foreach ($meta_keys as $key) {
            $order->delete_meta_data($key);
        }

        $order->save();

        // Add order note
        $order->add_order_note(
            sprintf(
                __('Datos de factura electrónica limpiados para regeneración. Clave anterior: %s', 'fe-woo'),
                $old_clave
            )
        );

        // Remove from queue if exists
        FE_Woo_Queue::remove_from_queue($order_id);

        self::log(sprintf('Cleared invoice data for order #%d (old clave: %s)', $order_id, $old_clave));
    }

    /**
     * Regenerate invoice for an order with updated CABYS codes
     *
     * This clears old invoice data and regenerates with current product CABYS codes
     *
     * @param int $order_id Order ID
     * @return array Result with success boolean and message
     */
    public static function regenerate_invoice($order_id) {
        $order = wc_get_order($order_id);

        if (!$order) {
            return [
                'success' => false,
                'message' => __('Order not found', 'fe-woo'),
            ];
        }

        // Check if invoice exists
        $existing_clave = $order->get_meta('_fe_woo_factura_clave');
        if (empty($existing_clave)) {
            return [
                'success' => false,
                'message' => __('No hay factura electrónica para regenerar. Use el botón EJECUTAR en su lugar.', 'fe-woo'),
            ];
        }

        self::log(sprintf('Regenerating invoice for order #%d (previous clave: %s)', $order_id, $existing_clave));

        // Process with force flag
        return self::process_order_immediately($order_id, true);
    }
}
