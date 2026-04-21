<?php
/**
 * FE WooCommerce PDF Generator
 *
 * Generates PDF documents for electronic invoices
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_PDF_Generator Class
 *
 * Generates PDF invoices from WooCommerce orders
 */
class FE_Woo_PDF_Generator {

    /**
     * Resolved logo source, cached per request. null = not yet resolved,
     * false = resolved to "no logo available".
     *
     * @var string|false|null
     */
    private static $logo_src_cache = null;

    /**
     * Generate PDF for an order
     *
     * @param WC_Order $order Order object
     * @param string   $clave Invoice clave
     * @param string   $document_type Document type (factura or tiquete)
     * @param bool     $save_to_disk Deprecated - use FE_Woo_Document_Storage::save_pdf() instead
     * @param array    $line_items Optional specific line items for multi-factura
     * @param array    $emisor_data Optional emisor data for multi-factura
     * @return array Result with 'success' and 'pdf_content' or 'error'
     */
    public static function generate_pdf($order, $clave, $document_type = 'tiquete', $save_to_disk = false, $line_items = null, $emisor_data = null, $apply_exoneracion = true) {
        try {
            // Check if TCPDF is available
            if (!class_exists('TCPDF')) {
                // Try to load TCPDF if it exists in vendor
                $tcpdf_path = WP_PLUGIN_DIR . '/fe_woo/vendor/tecnickcom/tcpdf/tcpdf.php';
                if (file_exists($tcpdf_path)) {
                    require_once $tcpdf_path;
                } else {
                    // Fallback to HTML-based PDF generation
                    return self::generate_html_pdf($order, $clave, $document_type, $line_items, $emisor_data, $apply_exoneracion);
                }
            }

            // Create PDF using TCPDF
            $pdf = new TCPDF('P', 'mm', 'LETTER', true, 'UTF-8', false);

            // Set document information
            $document_labels = [
                'factura' => 'Factura Electrónica',
                'tiquete' => 'Tiquete Electrónico',
                'nota_credito' => 'Nota de Crédito Electrónica',
                'nota_debito' => 'Nota de Débito Electrónica',
            ];
            $document_label = isset($document_labels[$document_type]) ? $document_labels[$document_type] : 'Tiquete Electrónico';
            $pdf->SetCreator('FE WooCommerce');
            $pdf->SetAuthor(FE_Woo_Hacienda_Config::get_company_name());
            $pdf->SetTitle($document_label . ' #' . $order->get_order_number());
            $pdf->SetSubject($document_label);

            // Remove default header/footer
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);

            // Set margins
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 15);

            // Add a page
            $pdf->AddPage();

            // Set font — dejavusans supports full UTF-8 (including ₡ U+20A1)
            $pdf->SetFont('dejavusans', '', 10);

            // Generate HTML content
            $html = self::generate_html_content($order, $clave, $document_type, $line_items, $emisor_data, $apply_exoneracion);

            // Output HTML content
            $pdf->writeHTML($html, true, false, true, false, '');

            // Get PDF content
            $pdf_content = $pdf->Output('', 'S');

            return [
                'success' => true,
                'pdf_content' => $pdf_content,
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate HTML-based PDF (fallback when TCPDF is not available)
     *
     * @param WC_Order $order Order object
     * @param string   $clave Invoice clave
     * @param string   $document_type Document type
     * @param array    $line_items Optional specific line items for multi-factura
     * @param array    $emisor_data Optional emisor data for multi-factura
     * @return array Result with 'success' and 'pdf_content' or 'error'
     */
    private static function generate_html_pdf($order, $clave, $document_type, $line_items = null, $emisor_data = null, $apply_exoneracion = true) {
        // Use DomPDF if available, otherwise generate HTML
        $autoload = WP_PLUGIN_DIR . '/fe_woo/vendor/autoload.php';
        if (file_exists($autoload)) {
            require_once $autoload;
        }

        if (class_exists('Dompdf\Dompdf')) {
            try {
                $dompdf = new \Dompdf\Dompdf();
                $html = self::generate_html_content($order, $clave, $document_type, $line_items, $emisor_data, $apply_exoneracion);

                $full_html = '<!DOCTYPE html>
	<html>
	<head>
	    <meta charset="UTF-8">
	    <style>' . self::get_pdf_styles() . '</style>
	</head>
	<body>' . $html . '</body>
	</html>';

                $dompdf->loadHtml($full_html);
                $dompdf->setPaper('letter', 'portrait');
                $dompdf->render();

                return [
                    'success' => true,
                    'pdf_content' => $dompdf->output(),
                ];
            } catch (Exception $e) {
                // Fall through to HTML generation
                if (function_exists('wc_get_logger')) {
                    $logger = wc_get_logger();
                    $logger->error('DomPDF failed: ' . $e->getMessage(), ['source' => 'fe-woo-pdf']);
                }
            }
        }

        // Fallback: Generate HTML document
        $html = self::generate_html_content($order, $clave, $document_type, $line_items, $emisor_data, $apply_exoneracion);

        $full_html = '<!DOCTYPE html>
	<html>
	<head>
	    <meta charset="UTF-8">
	    <title>' . esc_html($document_type === 'factura' ? 'Factura Electrónica' : 'Tiquete Electrónico') . '</title>
	    <style>
	        @page { margin: 2cm; }
	        ' . self::get_pdf_styles() . '
	        @media print {
	            body { margin: 1cm; }
	        }
	    </style>
	</head>
	<body>
	' . $html . '
	</body>
	</html>';

        // Log that we're using HTML fallback
        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->info('Using HTML fallback for PDF generation. Install TCPDF or DomPDF for better results.', ['source' => 'fe-woo-pdf']);
        }

        return [
            'success' => true,
            'pdf_content' => $full_html,
            'is_html' => true, // Flag to indicate this is HTML, not PDF
        ];
    }

    /**
     * Generate HTML content for the invoice
     *
     * @param WC_Order $order Order object
     * @param string   $clave Invoice clave
     * @param string   $document_type Document type
     * @param array    $line_items Optional specific line items for multi-factura
     * @param array    $emisor_data Optional emisor data for multi-factura
     * @return string HTML content
     */
    private static function generate_html_content($order, $clave, $document_type, $line_items = null, $emisor_data = null, $apply_exoneracion = true) {
        $document_labels = [
            'factura' => 'Factura Electrónica',
            'tiquete' => 'Tiquete Electrónico',
            'nota_credito' => 'Nota de Crédito Electrónica',
            'nota_debito' => 'Nota de Débito Electrónica',
        ];
        $document_label = isset($document_labels[$document_type]) ? $document_labels[$document_type] : 'Tiquete Electrónico';

        // Determine which items to use
        $items_to_display = $line_items !== null ? $line_items : $order->get_items();

        // Get emisor info - use provided emisor_data or default config
        $company_name = FE_Woo_Hacienda_Config::get_company_name();
        $cedula = FE_Woo_Hacienda_Config::get_cedula_juridica();
        $phone = FE_Woo_Hacienda_Config::get_phone();
        $email = FE_Woo_Hacienda_Config::get_email();

        if ($emisor_data) {
            $company_name = $emisor_data['nombre_legal'] ?? $company_name;
            // Accept both 'cedula_juridica' (canonical, from prepare_emisor_data)
            // and 'cedula' (legacy key) to stay consistent with the XML emisor.
            $cedula = $emisor_data['cedula_juridica'] ?? $emisor_data['cedula'] ?? $cedula;
            $phone = $emisor_data['telefono'] ?? $phone;
            $email = $emisor_data['email'] ?? $email;
        }

        $logo_src = self::get_logo_src();

        ob_start();
        ?>
        <div class="header">
            <?php if ($logo_src) : ?>
                <div class="logo"><img src="<?php echo esc_attr($logo_src); ?>" alt="" height="60" /></div>
            <?php endif; ?>
            <div class="company-name"><?php echo esc_html($company_name); ?></div>
            <div>Cédula Jurídica: <?php echo esc_html($cedula); ?></div>
            <?php if ($phone) : ?>
                <div>Teléfono: <?php echo esc_html($phone); ?></div>
            <?php endif; ?>
            <?php if ($email) : ?>
                <div>Email: <?php echo esc_html($email); ?></div>
            <?php endif; ?>
        </div>

        <div class="section">
            <div class="section-title"><?php echo esc_html($document_label); ?></div>
            <div><strong>Número de Orden:</strong> <?php echo esc_html($order->get_order_number()); ?></div>
            <div><strong>Fecha:</strong> <?php echo esc_html($order->get_date_created()->date_i18n('d/m/Y H:i')); ?></div>
            <div><strong>Clave:</strong> <span class="clave"><?php echo esc_html($clave); ?></span></div>
        </div>

        <?php if ($document_type === 'factura') : ?>
            <?php
            $full_name     = $order->get_meta('_fe_woo_full_name');
            $id_type       = $order->get_meta('_fe_woo_id_type');
            $id_number     = $order->get_meta('_fe_woo_id_number');
            $email         = $order->get_meta('_fe_woo_invoice_email');
            $activity_code = $order->get_meta('_fe_woo_activity_code');
            ?>
            <div class="section">
                <div class="section-title">Información del Cliente</div>
                <div><strong>Nombre/Razón Social:</strong> <?php echo esc_html($full_name); ?></div>
                <div><strong>Identificación:</strong> <?php echo esc_html($id_number); ?> (<?php echo esc_html(self::get_id_type_label($id_type)); ?>)</div>
                <?php if ($activity_code) : ?>
                    <div><strong>Código de Actividad Económica:</strong> <?php echo esc_html($activity_code); ?></div>
                <?php endif; ?>
                <?php if ($email) : ?>
                    <div><strong>Email:</strong> <?php echo esc_html($email); ?></div>
                <?php endif; ?>
            </div>
        <?php else : ?>
            <?php
            // For tiquete, prefer the fiscal data captured at checkout (used in XML Receptor)
            // and fall back to billing data when not provided.
            $receptor_name   = $order->get_meta('_fe_woo_full_name');
            $receptor_id     = $order->get_meta('_fe_woo_id_number');
            $receptor_idtype = $order->get_meta('_fe_woo_id_type');
            $receptor_email  = $order->get_meta('_fe_woo_invoice_email');
            if (empty($receptor_name)) {
                $receptor_name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
            }
            if (empty($receptor_email)) {
                $receptor_email = $order->get_billing_email();
            }
            ?>
            <div class="section">
                <div class="section-title">Información del Cliente</div>
                <div><strong>Nombre:</strong> <?php echo esc_html($receptor_name); ?></div>
                <?php if ($receptor_id) : ?>
                    <div><strong>Identificación:</strong> <?php echo esc_html($receptor_id); ?><?php echo $receptor_idtype ? ' (' . esc_html(self::get_id_type_label($receptor_idtype)) . ')' : ''; ?></div>
                <?php endif; ?>
                <?php if ($receptor_email) : ?>
                    <div><strong>Email:</strong> <?php echo esc_html($receptor_email); ?></div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="section">
            <div class="section-title">Detalle de Productos/Servicios</div>
            <table>
                <thead>
                    <tr>
                        <th>Descripción</th>
                        <th>CABYS</th>
                        <th style="text-align: center;">Cantidad</th>
                        <th style="text-align: right;">Precio Unit.</th>
                        <th style="text-align: right;">Total</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items_to_display as $item) : ?>
                        <?php
                        $product = $item->get_product();
                        $cabys_code = '';
                        if ($product && class_exists('FE_Woo_Tax_CABYS')) {
                            $cabys_data = FE_Woo_Tax_CABYS::get_product_cabys($product);
                            if ($cabys_data && isset($cabys_data['codigo'])) {
                                $cabys_code = $cabys_data['codigo'];
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo esc_html($item->get_name()); ?></td>
                            <td style="font-size: 9px;"><?php echo esc_html($cabys_code); ?></td>
                            <td style="text-align: center;"><?php echo esc_html($item->get_quantity()); ?></td>
                            <td style="text-align: right;"><?php echo wc_price($item->get_subtotal() / $item->get_quantity()); ?></td>
                            <td style="text-align: right;"><?php echo wc_price($item->get_total()); ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php
                    // Only show shipping for full orders (not partial multi-factura)
                    if ($line_items === null && $order->get_shipping_total() > 0) :
                    ?>
                        <tr>
                            <td>Envío</td>
                            <td></td>
                            <td style="text-align: center;">1</td>
                            <td style="text-align: right;"><?php echo wc_price($order->get_shipping_total()); ?></td>
                            <td style="text-align: right;"><?php echo wc_price($order->get_shipping_total()); ?></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php
        // Check for exemption data (only for parent/default emisor)
        $exon_data = null;
        if ($apply_exoneracion && class_exists('FE_Woo_Exoneracion')) {
            $exon_data = FE_Woo_Exoneracion::get_exoneracion_data($order->get_id());
            if ($exon_data) {
                $exon_validation = FE_Woo_Exoneracion::validate_exoneracion($order->get_id());
                if (!$exon_validation['valid']) {
                    $exon_data = null;
                }
            }
        }

        // Calculate totals per-item, respecting each product's tax status
        // This mirrors the XML generator logic in calculate_item_tax_info()
        $subtotal = 0;
        $tax_display = 0;
        $total_display = 0;
        $discount = 0;
        $taxable_subtotal = 0; // Only subtotal of items that actually have tax
        $has_mixed_rates = false;

        $items_for_totals = $line_items !== null ? $items_to_display : $order->get_items();

        foreach ($items_for_totals as $item) {
            $product = $item->get_product();

            // Skip products with tax_status 'none' (same as XML generator)
            if ($product && $product->get_tax_status() === 'none') {
                continue;
            }

            $subtotal += $item->get_subtotal();

            if ($item->get_subtotal() != $item->get_total()) {
                $discount += $item->get_subtotal() - $item->get_total();
            }

            // Determine if this item is actually taxable (has a tax rate > 0)
            $item_tax = $item->get_total_tax();
            $is_taxable = ($product && $product->get_tax_status() === 'taxable');
            $item_has_tax = $is_taxable && $item_tax > 0;

            if ($item_has_tax && $exon_data && isset($exon_data['porcentaje'])) {
                // Taxable item with exemption: recalculate tax at exempted rate
                $tarifa_exon = intval($exon_data['porcentaje']);
                $item_tax_recalc = ($item->get_total() * $tarifa_exon) / 100;
                $tax_display += $item_tax_recalc;
                $taxable_subtotal += $item->get_total();
            } else {
                // Non-taxable item or no exemption: use original tax
                $tax_display += $item_tax;
                if ($item_has_tax) {
                    $taxable_subtotal += $item->get_total();
                }
            }
        }

        // Include shipping (only for full orders or first multi-factura)
        if ($line_items === null && $order->get_shipping_total() > 0) {
            $subtotal += $order->get_shipping_total();
            $shipping_tax = $order->get_shipping_tax();
            $shipping_has_tax = $shipping_tax > 0;

            if ($shipping_has_tax && $exon_data && isset($exon_data['porcentaje'])) {
                $tarifa_exon = intval($exon_data['porcentaje']);
                $shipping_tax_recalc = ($order->get_shipping_total() * $tarifa_exon) / 100;
                $tax_display += $shipping_tax_recalc;
                $taxable_subtotal += $order->get_shipping_total();
            } else {
                $tax_display += $shipping_tax;
                if ($shipping_has_tax) {
                    $taxable_subtotal += $order->get_shipping_total();
                }
            }
        }

        $total_display = ($subtotal - $discount) + $tax_display;

        // Determine the display tax rate
        $tarifa_real = 13;
        if ($exon_data && isset($exon_data['porcentaje'])) {
            $tarifa_real = intval($exon_data['porcentaje']);
        }
        ?>

        <div class="section">
            <table>
                <?php
                // Use the per-item calculated subtotal (which already excludes tax_status='none' items)
                $display_subtotal = $subtotal;
                $display_discount = $discount;
                ?>
                <tr>
                    <td style="text-align: right;"><strong>Subtotal:</strong></td>
                    <td style="text-align: right; width: 150px;"><?php echo wc_price($display_subtotal); ?></td>
                </tr>
                <?php if ($display_discount > 0) : ?>
                    <tr>
                        <td style="text-align: right;"><strong>Descuento:</strong></td>
                        <td style="text-align: right;">-<?php echo wc_price($display_discount); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($tax_display > 0 || $tarifa_real === 0) : ?>
                    <tr>
                        <td style="text-align: right;"><strong>IVA (<?php echo esc_html($tarifa_real); ?>%):</strong></td>
                        <td style="text-align: right;"><?php echo wc_price($tax_display); ?></td>
                    </tr>
                <?php endif; ?>
                <?php if ($exon_data && $taxable_subtotal > 0) : ?>
                    <?php
                    // Exoneration amount = tax at 13% on taxable items minus actual tax applied
                    $exon_monto = (($taxable_subtotal * 13) / 100) - $tax_display;
                    ?>
                    <?php if ($exon_monto > 0) : ?>
                    <tr>
                        <td style="text-align: right; color: #27ae60;"><strong>Exoneración aplicada:</strong></td>
                        <td style="text-align: right; color: #27ae60;">-<?php echo wc_price($exon_monto); ?></td>
                    </tr>
                    <?php endif; ?>
                <?php endif; ?>
                <tr class="total-row">
                    <td style="text-align: right;"><strong>TOTAL:</strong></td>
                    <td style="text-align: right;"><strong><?php echo wc_price($total_display); ?></strong></td>
                </tr>
            </table>
        </div>

        <?php if ($exon_data) : ?>
        <div class="section" style="border: 1px solid #27ae60; padding: 10px; background-color: #f0fff4;">
            <div class="section-title" style="color: #27ae60;">Exoneración Fiscal</div>
            <?php
            $tipos_label = class_exists('FE_Woo_Exoneracion') ? FE_Woo_Exoneracion::get_tipos() : [];
            $tipo_label  = isset($tipos_label[$exon_data['tipo']]) ? $tipos_label[$exon_data['tipo']] : $exon_data['tipo'];
            ?>
            <div><strong>Tipo:</strong> <?php echo esc_html($tipo_label); ?></div>
            <div><strong>Nro. Documento:</strong> <?php echo esc_html($exon_data['numero']); ?></div>
            <div><strong>Institución:</strong> <?php echo esc_html($exon_data['institucion']); ?></div>
            <div><strong>Fecha Emisión:</strong> <?php echo esc_html($exon_data['fecha_emision']); ?></div>
            <div><strong>Fecha Vencimiento:</strong> <?php echo esc_html($exon_data['fecha_vencimiento']); ?></div>
            <div><strong>IVA aplicado:</strong> <?php echo esc_html($exon_data['porcentaje']); ?>%</div>
        </div>
        <?php endif; ?>

        <div class="footer">
            <p>Este documento es una representación impresa de la <?php echo esc_html($document_label); ?> autorizada por el Ministerio de Hacienda de Costa Rica.</p>
            <p>Generado el <?php echo esc_html(current_time('d/m/Y H:i')); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Get ID type label
     *
     * @param string $id_type ID type code
     * @return string ID type label
     */
    private static function get_id_type_label($id_type) {
        $labels = [
            '01' => 'Cédula Física',
            '02' => 'Cédula Jurídica',
            '03' => 'DIMEX',
            '04' => 'Pasaporte',
        ];

        return isset($labels[$id_type]) ? $labels[$id_type] : $id_type;
    }

    /**
     * Shared CSS for the PDF / HTML-fallback document body.
     * Kept here so both the DomPDF and HTML-fallback paths stay in sync.
     */
    private static function get_pdf_styles() {
        return '
            body { font-family: Arial, sans-serif; font-size: 12px; line-height: 1.4; margin: 0; }
            .header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 10px; }
            .company-name { font-size: 18px; font-weight: bold; }
            .section { margin-bottom: 15px; }
            .section-title { font-weight: bold; font-size: 14px; margin-bottom: 5px; color: #333; }
            table { width: 100%; border-collapse: collapse; margin-top: 10px; }
            th, td { padding: 8px; text-align: left; border-bottom: 1px solid #ddd; }
            th { background-color: #f5f5f5; font-weight: bold; }
            .total-row { font-weight: bold; background-color: #f9f9f9; }
            .footer { margin-top: 30px; padding-top: 10px; border-top: 1px solid #333; font-size: 10px; text-align: center; }
            .clave { font-size: 9px; word-break: break-all; font-family: monospace; }
            .logo img { max-height: 70px; max-width: 220px; margin-bottom: 8px; }
        ';
    }

    /**
     * Resolve the logo source for the PDF header.
     * Tries, in order: theme custom logo, WooCommerce email header image, site icon.
     * Returns an absolute filesystem path when possible (TCPDF/DomPDF render better
     * from disk than over HTTP); falls back to a URL, or null if none is configured.
     * SVGs are skipped because TCPDF's raster path does not support them.
     *
     * @return string|null
     */
    private static function get_logo_src() {
        // Cache: resolved once per request. Multi-factura generates N PDFs per
        // order and each one would otherwise repeat the DB reads + file_exists().
        if (self::$logo_src_cache !== null) {
            return self::$logo_src_cache === false ? null : self::$logo_src_cache;
        }

        $candidates = [];
        $add_candidate = static function ($value) use (&$candidates) {
            if (empty($value)) {
                return;
            }
            if (!in_array($value, $candidates, true)) {
                $candidates[] = $value;
            }
        };

        // 1. Theme custom logo (Appearance > Customize > Site Identity)
        $logo_id = get_theme_mod('custom_logo');
        if ($logo_id) {
            $add_candidate(get_attached_file($logo_id));
            $image = wp_get_attachment_image_src($logo_id, 'full');
            if ($image && !empty($image[0])) {
                $add_candidate($image[0]);
            }
        }

        // 2. WooCommerce email header image (used for transactional emails).
        // url_to_local_path() may return the same URL back (e.g. Pantheon Global CDN
        // rewriting the uploads baseurl) — dedupe so TCPDF doesn't end up fetching
        // the image over HTTP when a local copy is unreachable.
        $wc_logo = get_option('woocommerce_email_header_image');
        if (!empty($wc_logo)) {
            $add_candidate(self::url_to_local_path($wc_logo));
            $add_candidate($wc_logo);
        }

        // 3. Site icon (favicon)
        $add_candidate(get_site_icon_url());

        foreach ($candidates as $candidate) {
            // Skip SVGs - TCPDF needs raster images for the default image flow.
            if (preg_match('/\.svg(\?|$)/i', $candidate)) {
                continue;
            }

            // Prefer filesystem paths. Return a file:// URI so DomPDF (the
            // HTML-fallback engine) can load the image without enabling
            // isRemoteEnabled/chroot — a bare absolute path silently fails
            // there. TCPDF's writeHTML accepts file:// equally well.
            if (file_exists($candidate)) {
                $resolved = 'file://' . $candidate;
                self::$logo_src_cache = $resolved;
                return $resolved;
            }

            // Only accept remote URLs as last resort
            if (filter_var($candidate, FILTER_VALIDATE_URL)) {
                self::$logo_src_cache = $candidate;
                return $candidate;
            }
        }

        self::$logo_src_cache = false;
        return null;
    }

    /**
     * Map an uploads URL to a local filesystem path when possible.
     *
     * @param string $url
     * @return string Path if mappable, original URL otherwise.
     */
    private static function url_to_local_path($url) {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['baseurl']) && strpos($url, $uploads['baseurl']) === 0) {
            return str_replace($uploads['baseurl'], $uploads['basedir'], $url);
        }
        return $url;
    }

    /**
     * Check if DomPDF is available
     *
     * @return bool
     */
    public static function is_dompdf_available() {
        if (class_exists('Dompdf\Dompdf')) {
            return true;
        }

        $autoload = WP_PLUGIN_DIR . '/fe_woo/vendor/autoload.php';
        return file_exists($autoload);
    }

    /**
     * Check if TCPDF is available
     *
     * @return bool True if TCPDF is available
     */
    public static function is_tcpdf_available() {
        if (class_exists('TCPDF')) {
            return true;
        }

        $tcpdf_path = WP_PLUGIN_DIR . '/fe_woo/vendor/tecnickcom/tcpdf/tcpdf.php';
        return file_exists($tcpdf_path);
    }

    /**
     * Get PDF library status message
     *
     * @return string Status message
     */
    public static function get_library_status() {
        if (self::is_tcpdf_available()) {
            return __('TCPDF library is installed. PDFs will be generated in proper format.', 'fe-woo');
        }

        if (self::is_dompdf_available()) {
            return __('TCPDF not found, but DomPDF appears available. PDFs will be generated using DomPDF. To install TCPDF as well: composer require tecnickcom/tcpdf', 'fe-woo');
        }

        return __('PDF libraries not found. To enable proper PDF generation, install dependencies via Composer in the plugin folder (e.g. cd wp-content/plugins/fe_woo && composer require tecnickcom/tcpdf dompdf/dompdf). As a fallback the plugin will return HTML.', 'fe-woo');
    }
}
