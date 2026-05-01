<?php
/**
 * FE WooCommerce Document Storage
 *
 * Handles storage and retrieval of electronic invoice documents
 *
 * @package FE_Woo
 */

// Exit if accessed directly.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * FE_Woo_Document_Storage Class
 *
 * Manages file storage for electronic invoice documents (XML, acuse, PDF)
 */
class FE_Woo_Document_Storage {

    /**
     * Base directory for document storage
     *
     * @var string
     */
    private static $base_dir = null;

    /**
     * Base URL for document downloads
     *
     * @var string
     */
    private static $base_url = null;

    /**
     * Initialize document storage
     */
    public static function init() {
        // Set base directory and URL
        $upload_dir = wp_upload_dir();
        self::$base_dir = trailingslashit($upload_dir['basedir']) . 'factura-electronica';
        self::$base_url = trailingslashit($upload_dir['baseurl']) . 'factura-electronica';

        // Create base directory if it doesn't exist
        self::ensure_directory_exists(self::$base_dir);

        // Protect directory with .htaccess
        self::create_htaccess();
    }

    /**
     * Get base directory for document storage
     *
     * @return string Base directory path
     */
    public static function get_base_dir() {
        if (self::$base_dir === null) {
            self::init();
        }
        return self::$base_dir;
    }

    /**
     * Get base URL for document downloads
     *
     * @return string Base URL
     */
    public static function get_base_url() {
        if (self::$base_url === null) {
            self::init();
        }
        return self::$base_url;
    }

    /**
     * Get directory for a specific order
     *
     * @param int $order_id Order ID
     * @return string Order directory path
     */
    public static function get_order_dir($order_id) {
        $base_dir = self::get_base_dir();
        return trailingslashit($base_dir) . 'order-' . $order_id;
    }

    /**
     * Ensure directory exists and is writable
     *
     * @param string $dir Directory path
     * @return bool True if directory exists and is writable
     */
    private static function ensure_directory_exists($dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }

        return is_dir($dir) && is_writable($dir);
    }

    /**
     * Create .htaccess file to protect documents
     */
    private static function create_htaccess() {
        $htaccess_file = self::get_base_dir() . '/.htaccess';

        if (file_exists($htaccess_file)) {
            return; // Already exists
        }

        $htaccess_content = "# Protect electronic invoice documents\n";
        $htaccess_content .= "# Only allow access through WordPress authentication\n\n";
        $htaccess_content .= "<Files ~ \"\\.(xml|pdf|json)$\">\n";
        $htaccess_content .= "    Order deny,allow\n";
        $htaccess_content .= "    Deny from all\n";
        $htaccess_content .= "</Files>\n";

        file_put_contents($htaccess_file, $htaccess_content);
    }

    /**
     * Save XML document for an order
     *
     * @param int    $order_id Order ID
     * @param string $xml_content XML content
     * @param string $clave Document clave
     * @return array Result with 'success' and 'file_path' or 'error'
     */
    public static function save_xml($order_id, $xml_content, $clave) {
        $order_dir = self::get_order_dir($order_id);

        if (!self::ensure_directory_exists($order_dir)) {
            return [
                'success' => false,
                'error' => __('Failed to create order directory', 'fe-woo'),
            ];
        }

        $filename = sanitize_file_name($clave) . '.xml';
        $file_path = trailingslashit($order_dir) . $filename;

        $result = file_put_contents($file_path, $xml_content);

        if ($result === false) {
            return [
                'success' => false,
                'error' => __('Failed to write XML file', 'fe-woo'),
            ];
        }

        return [
            'success' => true,
            'file_path' => $file_path,
            'filename' => $filename,
        ];
    }

    /**
     * Save Hacienda response (acuse) for an order
     *
     * @param int   $order_id Order ID
     * @param array $response Hacienda API response
     * @param string $clave Document clave
     * @return array Result with 'success' and 'file_path' or 'error'
     */
    public static function save_acuse($order_id, $response, $clave) {
        $order_dir = self::get_order_dir($order_id);

        if (!self::ensure_directory_exists($order_dir)) {
            return [
                'success' => false,
                'error' => __('Failed to create order directory', 'fe-woo'),
            ];
        }

        $filename = sanitize_file_name($clave) . '_acuse.json';
        $file_path = trailingslashit($order_dir) . $filename;

        $json_content = wp_json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $result = file_put_contents($file_path, $json_content);

        if ($result === false) {
            return [
                'success' => false,
                'error' => __('Failed to write acuse file', 'fe-woo'),
            ];
        }

        return [
            'success' => true,
            'file_path' => $file_path,
            'filename' => $filename,
        ];
    }

    /**
     * Save the signed MensajeHacienda XML that Hacienda returns inside the
     * `respuesta-xml` field of its JSON acuse. File is named AHC-{clave}.xml
     * to match the convention Hacienda itself uses in production downloads.
     *
     * @param int    $order_id Order ID
     * @param string $xml_content Decoded MensajeHacienda XML
     * @param string $clave Document clave (50 digits)
     * @return array Result with 'success' and 'file_path' or 'error'
     */
    public static function save_acuse_xml($order_id, $xml_content, $clave) {
        $order_dir = self::get_order_dir($order_id);

        if (!self::ensure_directory_exists($order_dir)) {
            return [
                'success' => false,
                'error' => __('Failed to create order directory', 'fe-woo'),
            ];
        }

        $filename = 'AHC-' . sanitize_file_name($clave) . '.xml';
        $file_path = trailingslashit($order_dir) . $filename;

        $result = file_put_contents($file_path, $xml_content);

        if ($result === false) {
            return [
                'success' => false,
                'error' => __('Failed to write acuse XML file', 'fe-woo'),
            ];
        }

        return [
            'success' => true,
            'file_path' => $file_path,
            'filename' => $filename,
        ];
    }

    /**
     * Get XML file path for an order
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return string|null File path or null if not found
     */
    public static function get_xml_path($order_id, $clave) {
        $order_dir = self::get_order_dir($order_id);
        $filename = sanitize_file_name($clave) . '.xml';
        $file_path = trailingslashit($order_dir) . $filename;

        return file_exists($file_path) ? $file_path : null;
    }

    /**
     * Get acuse file path for an order
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return string|null File path or null if not found
     */
    public static function get_acuse_path($order_id, $clave) {
        $order_dir = self::get_order_dir($order_id);
        $filename = sanitize_file_name($clave) . '_acuse.json';
        $file_path = trailingslashit($order_dir) . $filename;

        return file_exists($file_path) ? $file_path : null;
    }

    /**
     * Get the signed MensajeHacienda XML path (AHC-{clave}.xml), if present.
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return string|null File path or null if not found
     */
    public static function get_acuse_xml_path($order_id, $clave) {
        $order_dir = self::get_order_dir($order_id);
        $filename = 'AHC-' . sanitize_file_name($clave) . '.xml';
        $file_path = trailingslashit($order_dir) . $filename;

        return file_exists($file_path) ? $file_path : null;
    }

    /**
     * Save PDF document for an order
     *
     * @param int    $order_id Order ID
     * @param string $pdf_content PDF content
     * @param string $clave Document clave
     * @param bool   $is_html Whether the content is HTML (fallback) or actual PDF
     * @return array Result with 'success' and 'file_path' or 'error'
     */
    public static function save_pdf($order_id, $pdf_content, $clave, $is_html = false) {
        $order_dir = self::get_order_dir($order_id);

        if (!self::ensure_directory_exists($order_dir)) {
            return [
                'success' => false,
                'error' => __('Failed to create order directory', 'fe-woo'),
            ];
        }

        // Use .html extension if it's HTML fallback, .pdf if it's actual PDF
        $extension = $is_html ? 'html' : 'pdf';
        $filename = sanitize_file_name($clave) . '.' . $extension;
        $file_path = trailingslashit($order_dir) . $filename;

        $result = file_put_contents($file_path, $pdf_content);

        if ($result === false) {
            return [
                'success' => false,
                'error' => __('Failed to write PDF file', 'fe-woo'),
            ];
        }

        return [
            'success' => true,
            'file_path' => $file_path,
            'filename' => $filename,
        ];
    }

    /**
     * Get PDF file path for an order
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return string|null File path or null if not found
     */
    public static function get_pdf_path($order_id, $clave) {
        $order_dir = self::get_order_dir($order_id);

        // Check for .pdf first
        $pdf_filename = sanitize_file_name($clave) . '.pdf';
        $pdf_path = trailingslashit($order_dir) . $pdf_filename;

        if (file_exists($pdf_path)) {
            return $pdf_path;
        }

        // Check for .html fallback
        $html_filename = sanitize_file_name($clave) . '.html';
        $html_path = trailingslashit($order_dir) . $html_filename;

        if (file_exists($html_path)) {
            return $html_path;
        }

        return null;
    }

    /**
     * Get all document paths for an order
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return array Array with 'xml' (signed factura), 'acuse' (response JSON),
     *               'acuse_xml' (signed MensajeHacienda), and 'pdf' paths.
     */
    public static function get_document_paths($order_id, $clave) {
        return [
            'xml' => self::get_xml_path($order_id, $clave),
            'acuse' => self::get_acuse_path($order_id, $clave),
            'acuse_xml' => self::get_acuse_xml_path($order_id, $clave),
            'pdf' => self::get_pdf_path($order_id, $clave),
        ];
    }

    /**
     * Check if documents exist for an order
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @return bool True if at least XML exists
     */
    public static function documents_exist($order_id, $clave) {
        $xml_path = self::get_xml_path($order_id, $clave);
        return $xml_path !== null;
    }

    /**
     * Get download URL for a document
     *
     * @param int    $order_id Order ID
     * @param string $clave Document clave
     * @param string $type Document type ('xml' or 'acuse')
     * @return string|null Download URL or null if file doesn't exist
     */
    public static function get_download_url($order_id, $clave, $type = 'xml') {
        $paths = self::get_document_paths($order_id, $clave);

        if (!isset($paths[$type]) || $paths[$type] === null) {
            return null;
        }

        // Generate secure download URL with nonce
        return add_query_arg([
            'action' => 'fe_woo_download_document',
            'order_id' => $order_id,
            'clave' => $clave,
            'type' => $type,
            'nonce' => wp_create_nonce('fe_woo_download_' . $order_id . '_' . $type),
        ], admin_url('admin-ajax.php'));
    }

    /**
     * Handle document download request
     */
    public static function handle_download_request() {
        // Verify user has permission
        if (!current_user_can('edit_shop_orders') && !current_user_can('view_order')) {
            wp_die(__('You do not have permission to download this document.', 'fe-woo'), 403);
        }

        // Get parameters
        $order_id = isset($_GET['order_id']) ? absint($_GET['order_id']) : 0;
        $clave = isset($_GET['clave']) ? sanitize_text_field($_GET['clave']) : '';
        $type = isset($_GET['type']) ? sanitize_text_field($_GET['type']) : 'xml';
        $nonce = isset($_GET['nonce']) ? sanitize_text_field($_GET['nonce']) : '';

        // Verify nonce
        if (!wp_verify_nonce($nonce, 'fe_woo_download_' . $order_id . '_' . $type)) {
            wp_die(__('Invalid security token.', 'fe-woo'), 403);
        }

        // Get file path
        $paths = self::get_document_paths($order_id, $clave);
        if (!isset($paths[$type]) || $paths[$type] === null) {
            wp_die(__('Document not found.', 'fe-woo'), 404);
        }
        $file_path = $paths[$type];

        if (!$file_path) {
            wp_die(__('Document not found.', 'fe-woo'), 404);
        }

        // Verify file exists
        if (!file_exists($file_path)) {
            wp_die(__('File does not exist.', 'fe-woo'), 404);
        }

        // Get filename
        $filename = basename($file_path);

        // Determine content type based on file extension
        $content_type = 'application/octet-stream';
        $extension = strtolower(pathinfo($file_path, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'pdf':
                $content_type = 'application/pdf';
                break;
            case 'html':
                $content_type = 'text/html';
                break;
            case 'xml':
                $content_type = 'application/xml';
                break;
            case 'json':
                $content_type = 'application/json';
                break;
        }

        // Clear any previous output
        if (ob_get_level()) {
            ob_end_clean();
        }

        // Set headers
        header('Content-Type: ' . $content_type);
        header('Content-Disposition: inline; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($file_path));
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Pragma: public');

        // Output file
        readfile($file_path);
        exit;
    }

    /**
     * Delete all documents for an order
     *
     * @param int $order_id Order ID
     * @return bool True on success
     */
    public static function delete_order_documents($order_id) {
        $order_dir = self::get_order_dir($order_id);

        if (!file_exists($order_dir)) {
            return true; // Nothing to delete
        }

        // Delete all files in directory
        $files = glob($order_dir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        // Delete directory
        return rmdir($order_dir);
    }

    /**
     * Get file size in human-readable format
     *
     * @param string $file_path File path
     * @return string File size (e.g., "2.5 KB")
     */
    public static function get_file_size($file_path) {
        if (!file_exists($file_path)) {
            return '';
        }

        $bytes = filesize($file_path);
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2) . ' ' . $units[$i];
    }
}
