/**
 * FE WooCommerce Order Admin JavaScript
 *
 * Handles AJAX interactions for electronic invoice generation
 *
 * @package FE_Woo
 */

(function($) {
    'use strict';

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        initEjecutarButton();
        initDownloadAllButton();
        initDownloadAllMultiButton();
        initDownloadSingleFacturaButton();
        initDownloadNotaDocsButton();
        initGenerateNoteButton();
        initReasonCounter();
    });

    /**
     * Initialize EJECUTAR button
     */
    function initEjecutarButton() {
        $('.fe-woo-ejecutar-factura').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.executing);

            // Send AJAX request
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_manual_execute_factura',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        showMessage(response.data.message, 'success');

                        // Reload page after 2 seconds to show updated status
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Initialize Download All button
     */
    function initDownloadAllButton() {
        $('.fe-woo-download-all').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip);

            // Send AJAX request to create ZIP
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_documents',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Show message to user
     *
     * @param {string} message Message text
     * @param {string} type Message type ('success' or 'error')
     */
    function showMessage(message, type) {
        var messageClass = type === 'success' ? 'notice-success' : 'notice-error';

        var $notice = $('<div class="notice ' + messageClass + ' is-dismissible"><p>' + message + '</p><button type="button" class="notice-dismiss"><span class="screen-reader-text">Descartar este aviso.</span></button></div>');

        // Remove any existing FE Woo notices first
        $('.wrap .notice.fe-woo-notice').remove();

        // Insert message at the top of the page, right after h1
        var $wrap = $('.wrap');
        if ($wrap.length) {
            var $h1 = $wrap.find('h1, h2').first();
            if ($h1.length) {
                $h1.after($notice);
            } else {
                $wrap.prepend($notice);
            }
        } else {
            // Fallback: insert after first heading found
            $('h1, h2').first().after($notice);
        }

        // Add custom class for easy identification
        $notice.addClass('fe-woo-notice');

        // Initialize WordPress dismiss button functionality
        $notice.find('.notice-dismiss').on('click', function() {
            $notice.fadeOut(function() {
                $(this).remove();
            });
        });

        // Auto-dismiss after 8 seconds
        setTimeout(function() {
            if ($notice.is(':visible')) {
                $notice.fadeOut(function() {
                    $(this).remove();
                });
            }
        }, 8000);

        // Scroll to top to show message
        $('html, body').animate({
            scrollTop: 0
        }, 300);
    }

    /**
     * Initialize Download All Multi-Factura button
     */
    function initDownloadAllMultiButton() {
        $('.fe-woo-download-all-multi').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip || 'Preparando descarga...');

            // Send AJAX request to create ZIP with all multi-factura documents
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_multi_factura',
                    order_id: orderId,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Initialize Download Single Factura button (for individual facturas in multi-factura orders)
     */
    function initDownloadSingleFacturaButton() {
        $('.fe-woo-download-single-factura').on('click', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.html();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.html('<span class="dashicons dashicons-update" style="font-size: 14px; vertical-align: text-top; animation: rotation 1s infinite linear;"></span> ...');

            // Send AJAX request to create ZIP (reuses existing download_all_documents action)
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_all_documents',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.html(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.html(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.html(originalText);
                }
            });
        });
    }

    /**
     * Initialize Download Nota Documents button
     * Uses event delegation to support dynamically rendered nota buttons
     */
    function initDownloadNotaDocsButton() {
        $(document).on('click', '.fe-woo-download-nota-docs', function(e) {
            e.preventDefault();

            var $button = $(this);
            var orderId = $button.data('order-id');
            var clave = $button.data('clave');
            var originalText = $button.text();

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text(feWooOrderAdmin.i18n.preparingZip || 'Preparando ZIP...');

            // Send AJAX request to create ZIP with this note's documents
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_download_nota_docs',
                    order_id: orderId,
                    clave: clave,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Trigger download by navigating to download URL
                        window.location.href = response.data.download_url;

                        // Re-enable button after a delay
                        setTimeout(function() {
                            $button.prop('disabled', false);
                            $button.text(originalText);
                        }, 1000);
                    } else {
                        // Show error message
                        showMessage(response.data.message, 'error');

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    showMessage(feWooOrderAdmin.i18n.error, 'error');

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

    /**
     * Initialize character counter for reason fields
     * Uses event delegation with class-based selectors to support multiple nota forms
     */
    function initReasonCounter() {
        $(document).on('input', '.fe-woo-note-reason', function() {
            var length = $(this).val().length;
            $(this).closest('.fe-woo-nota-form-container').find('.fe-woo-reason-counter').text(length + '/180');
        });
    }

    /**
     * Initialize Generate Note button
     * Uses event delegation and scoped selectors to support multiple nota forms
     * (one per factura in multi-factura orders, plus single-factura orders)
     */
    function initGenerateNoteButton() {
        $(document).on('click', '.fe-woo-generate-note', function(e) {
            e.preventDefault();

            var $button = $(this);
            var $container = $button.closest('.fe-woo-nota-form-container');
            var orderId = $button.data('order-id');
            var referencedClave = $button.data('clave');
            var emisorId = $button.data('emisor-id') || 0;

            // Read form values from scoped container
            var noteType = $container.find('.fe-woo-note-type').val();
            var referenceCode = $container.find('.fe-woo-reference-code').val();
            var reason = $container.find('.fe-woo-note-reason').val().trim();
            var additionalNotes = $container.find('.fe-woo-note-additional').val().trim();
            var originalText = $button.text();
            var $messageBox = $container.find('.fe-woo-note-message');

            // Validate inputs
            if (!reason) {
                $messageBox.removeClass('notice-success').addClass('notice-error')
                    .html('<strong>Error:</strong> La razón es obligatoria.')
                    .show();
                return;
            }

            if (reason.length > 180) {
                $messageBox.removeClass('notice-success').addClass('notice-error')
                    .html('<strong>Error:</strong> La razón no puede exceder 180 caracteres.')
                    .show();
                return;
            }

            // Disable button and show loading state
            $button.prop('disabled', true);
            $button.text('Generando...');
            $messageBox.hide();

            // Send AJAX request
            $.ajax({
                url: feWooOrderAdmin.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_generate_nota',
                    order_id: orderId,
                    referenced_clave: referencedClave,
                    emisor_id: emisorId,
                    note_type: noteType,
                    reference_code: referenceCode,
                    reason: reason,
                    additional_notes: additionalNotes,
                    nonce: feWooOrderAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Show success message
                        $messageBox.removeClass('notice-error').addClass('notice-success')
                            .html('<strong>' + response.data.message + '</strong>')
                            .show();

                        // Clear form
                        $container.find('.fe-woo-note-reason').val('');
                        $container.find('.fe-woo-note-additional').val('');
                        $container.find('.fe-woo-reason-counter').text('0/180');

                        // Reload page after 2 seconds to show updated list
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        // Show error message
                        $messageBox.removeClass('notice-success').addClass('notice-error')
                            .html('<strong>Error:</strong> ' + response.data.message)
                            .show();

                        // Re-enable button
                        $button.prop('disabled', false);
                        $button.text(originalText);
                    }
                },
                error: function() {
                    // Show error message
                    $messageBox.removeClass('notice-success').addClass('notice-error')
                        .html('<strong>Error:</strong> Error de conexión al servidor.')
                        .show();

                    // Re-enable button
                    $button.prop('disabled', false);
                    $button.text(originalText);
                }
            });
        });
    }

})(jQuery);
