/**
 * FE WooCommerce Tax CABYS JavaScript
 */

(function($) {
    'use strict';

    let searchTimeout;
    const searchDelay = 500; // milliseconds

    $(document).ready(function() {
        const $searchInput = $('#fe-woo-cabys-search-input');
        const $results = $('#fe-woo-cabys-results');
        const $spinner = $('.fe-woo-cabys-search .spinner');
        const $selectedInput = $('#fe-woo-cabys-selected');

        // Prevent form submission on Enter
        $searchInput.on('keypress', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                return false;
            }
        });

        // Search on input with debounce
        $searchInput.on('input', function() {
            const query = $(this).val().trim();

            clearTimeout(searchTimeout);

            if (query.length < 2) {
                // Stop spinner and show selected codes when search is empty
                $spinner.removeClass('is-active');
                initializeSelectedCodes();
                return;
            }

            $spinner.addClass('is-active');

            searchTimeout = setTimeout(function() {
                searchCABYS(query);
            }, searchDelay);
        });

        // Handle checkbox changes
        $results.on('change', '.fe-woo-cabys-checkbox', function() {
            updateSelectedCodes();
        });

        /**
         * Search CABYS codes via AJAX
         *
         * @param {string} query Search query
         */
        function searchCABYS(query) {
            $.ajax({
                url: feWooTaxCABYS.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'fe_woo_search_cabys',
                    nonce: feWooTaxCABYS.nonce,
                    query: query
                },
                success: function(response) {
                    $spinner.removeClass('is-active');

                    if (response.success && response.data.results) {
                        displayResults(response.data.results);
                    } else {
                        var message = response.data.message || feWooTaxCABYS.strings.error;
                        var hint = response.data.hint || '';
                        showError(message, hint);
                    }
                },
                error: function() {
                    $spinner.removeClass('is-active');
                    showError(feWooTaxCABYS.strings.error);
                }
            });
        }

        /**
         * Display search results
         *
         * @param {Array|Object} results Array of CABYS codes or response object
         */
        function displayResults(results) {
            $results.empty();

            // Handle different response formats
            let items = results;

            // If results is an object with a 'cabys' or 'data' property, use that
            if (results && typeof results === 'object' && !Array.isArray(results)) {
                items = results.cabys || results.data || results.results || [];
            }

            // Ensure items is an array
            if (!Array.isArray(items)) {
                console.error('Invalid results format:', results);
                items = [];
            }

            const selectedCodes = getSelectedCodes();

            // If no results, show selected codes and "no results" message
            if (items.length === 0) {
                // First, display already selected codes
                if (selectedCodes.length > 0) {
                    selectedCodes.forEach(function(item) {
                        renderCABYSItem(item, true);
                    });
                }

                // Then show "no results" message
                $results.append(
                    '<div class="fe-woo-cabys-no-results">' +
                    feWooTaxCABYS.strings.noResults +
                    '</div>'
                );
                return;
            }

            // Create sets for efficient lookups
            const selectedCodigoSet = new Set(selectedCodes.map(function(s) {
                return s.codigo;
            }));
            const resultsCodigoSet = new Set(items.map(function(i) {
                return i.codigo;
            }));

            // First, display selected codes that are NOT in current search results
            selectedCodes.forEach(function(item) {
                if (!resultsCodigoSet.has(item.codigo)) {
                    renderCABYSItem(item, true);
                }
            });

            // Then, display search results (marking those that are selected)
            items.forEach(function(item) {
                const isSelected = selectedCodigoSet.has(item.codigo);
                renderCABYSItem(item, isSelected);
            });
        }

        /**
         * Render a single CABYS item
         *
         * @param {Object} item CABYS code data
         * @param {boolean} isSelected Whether the item is selected
         */
        function renderCABYSItem(item, isSelected) {
            const codeData = {
                codigo: item.codigo,
                descripcion: item.descripcion,
                impuesto: item.impuesto || 13.0
            };

            const $item = $('<label>', {
                'class': 'fe-woo-cabys-item'
            });

            const $checkbox = $('<input>', {
                type: 'checkbox',
                name: 'fe_woo_cabys_codes[]',
                value: JSON.stringify(codeData),
                'class': 'fe-woo-cabys-checkbox',
                checked: isSelected
            });

            const $code = $('<span>', {
                'class': 'fe-woo-cabys-code',
                text: item.codigo
            });

            const $description = $('<span>', {
                'class': 'fe-woo-cabys-description',
                text: item.descripcion
            });

            $item.append($checkbox, $code, $description);
            $results.append($item);
        }

        /**
         * Show error message
         *
         * @param {string} message Error message
         * @param {string} hint Optional hint message
         */
        function showError(message, hint) {
            var html = '<div class="fe-woo-cabys-error">' + message;

            if (hint) {
                html += '<div class="fe-woo-cabys-hint" style="margin-top: 10px; font-size: 12px; opacity: 0.8;">' +
                        '<strong>💡 Sugerencia:</strong> ' + hint +
                        '</div>';
            }

            html += '</div>';

            $results.html(html);
        }

        /**
         * Get currently selected CABYS codes
         *
         * @returns {Array} Array of selected codes
         */
        function getSelectedCodes() {
            try {
                const selected = $selectedInput.val();
                return selected ? JSON.parse(selected) : [];
            } catch (e) {
                console.error('Error parsing selected codes:', e);
                return [];
            }
        }

        /**
         * Update the hidden input with selected codes
         */
        function updateSelectedCodes() {
            const selected = [];

            $results.find('.fe-woo-cabys-checkbox:checked').each(function() {
                try {
                    const codeData = JSON.parse($(this).val());
                    selected.push(codeData);
                } catch (e) {
                    console.error('Error parsing code data:', e);
                }
            });

            $selectedInput.val(JSON.stringify(selected));
        }

        /**
         * Initialize with previously selected codes
         */
        function initializeSelectedCodes() {
            const selectedCodes = getSelectedCodes();

            $results.empty();

            if (selectedCodes.length > 0) {
                selectedCodes.forEach(function(item) {
                    renderCABYSItem(item, true);
                });
            }
        }

        // Initialize on page load
        initializeSelectedCodes();
    });

})(jQuery);
