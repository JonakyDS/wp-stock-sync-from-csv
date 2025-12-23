/**
 * WP Stock Sync From CSV Admin JavaScript
 *
 * @package WP_Stock_Sync_From_CSV
 */

(function ($) {
    'use strict';

    /**
     * Admin functionality
     */
    const WSSFCAdmin = {
        /**
         * Initialize
         */
        init: function () {
            this.bindEvents();
        },

        /**
         * Bind event handlers
         */
        bindEvents: function () {
            // Test CSV connection
            $('#wssfc-test-connection').on('click', this.testConnection.bind(this));

            // Run sync manually
            $('#wssfc-run-sync').on('click', this.runSync.bind(this));

            // Clear logs
            $('#wssfc-clear-logs').on('click', this.clearLogs.bind(this));

            // Refresh logs
            $('#wssfc-refresh-logs').on('click', this.refreshLogs.bind(this));

            // View context modal
            $(document).on('click', '.wssfc-view-context', this.showContextModal.bind(this));

            // Close modal
            $(document).on('click', '.wssfc-modal-close, .wssfc-modal', this.closeModal.bind(this));
            $(document).on('click', '.wssfc-modal-content', function (e) {
                e.stopPropagation();
            });

            // ESC key to close modal
            $(document).on('keydown', function (e) {
                if (e.key === 'Escape') {
                    WSSFCAdmin.closeModal();
                }
            });

            // Toggle sync run details
            $(document).on('click', '.wssfc-run-header', this.toggleRunDetails.bind(this));
            $(document).on('click', '.wssfc-run-toggle', function (e) {
                e.stopPropagation();
                WSSFCAdmin.toggleRunDetails.call(WSSFCAdmin, e);
            });

            // Toggle custom interval field visibility
            this.toggleCustomInterval();
            $('#wssfc_schedule').on('change', this.toggleCustomInterval.bind(this));
        },

        /**
         * Toggle custom interval field visibility
         */
        toggleCustomInterval: function () {
            const schedule = $('#wssfc_schedule').val();
            const $customField = $('#wssfc_custom_interval_minutes').closest('tr');

            if (schedule === 'wssfc_custom') {
                $customField.show();
            } else {
                $customField.hide();
            }
        },

        /**
         * Show result message
         *
         * @param {string} message Message to display
         * @param {string} type Message type (success, error, loading)
         */
        showResult: function (message, type) {
            const $result = $('#wssfc-action-result');
            $result
                .removeClass('success error loading')
                .addClass(type)
                .html(message)
                .show();

            if (type !== 'loading') {
                setTimeout(function () {
                    $result.fadeOut();
                }, 10000);
            }
        },

        /**
         * Test CSV connection
         *
         * @param {Event} e Click event
         */
        testConnection: function (e) {
            e.preventDefault();

            const $button = $('#wssfc-test-connection');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wssfc-spinning"></span> ' +
                wssfc_admin.strings.testing
            );

            this.showResult(wssfc_admin.strings.testing, 'loading');

            $.ajax({
                url: wssfc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wssfc_test_connection',
                    nonce: wssfc_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WSSFCAdmin.showResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data,
                            'success'
                        );
                    } else {
                        WSSFCAdmin.showResult(
                            '<span class="dashicons dashicons-warning"></span> ' + wssfc_admin.strings.error + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WSSFCAdmin.showResult(
                        '<span class="dashicons dashicons-warning"></span> ' + wssfc_admin.strings.error + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Run sync manually
         *
         * @param {Event} e Click event
         */
        runSync: function (e) {
            e.preventDefault();

            const $button = $('#wssfc-run-sync');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wssfc-spinning"></span> ' +
                wssfc_admin.strings.syncing
            );

            this.showResult(wssfc_admin.strings.syncing, 'loading');

            $.ajax({
                url: wssfc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wssfc_run_sync',
                    nonce: wssfc_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        WSSFCAdmin.showResult(
                            '<span class="dashicons dashicons-yes-alt"></span> ' + response.data,
                            'success'
                        );
                        // Refresh page after successful sync to update status cards
                        setTimeout(function () {
                            location.reload();
                        }, 2000);
                    } else {
                        WSSFCAdmin.showResult(
                            '<span class="dashicons dashicons-warning"></span> ' + wssfc_admin.strings.error + ' ' + response.data,
                            'error'
                        );
                    }
                },
                error: function (xhr, status, error) {
                    WSSFCAdmin.showResult(
                        '<span class="dashicons dashicons-warning"></span> ' + wssfc_admin.strings.error + ' ' + error,
                        'error'
                    );
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Clear logs
         *
         * @param {Event} e Click event
         */
        clearLogs: function (e) {
            e.preventDefault();

            if (!confirm(wssfc_admin.strings.confirm_clear)) {
                return;
            }

            const $button = $('#wssfc-clear-logs');
            const originalText = $button.html();

            $button.prop('disabled', true).html(
                '<span class="dashicons dashicons-update wssfc-spinning"></span> ' +
                wssfc_admin.strings.clearing
            );

            $.ajax({
                url: wssfc_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'wssfc_clear_logs',
                    nonce: wssfc_admin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert(wssfc_admin.strings.error + ' ' + response.data);
                    }
                },
                error: function (xhr, status, error) {
                    alert(wssfc_admin.strings.error + ' ' + error);
                },
                complete: function () {
                    $button.prop('disabled', false).html(originalText);
                }
            });
        },

        /**
         * Refresh logs page
         *
         * @param {Event} e Click event
         */
        refreshLogs: function (e) {
            e.preventDefault();
            location.reload();
        },

        /**
         * Toggle run details
         *
         * @param {Event} e Click event
         */
        toggleRunDetails: function (e) {
            e.preventDefault();

            const $run = $(e.target).closest('.wssfc-sync-run');
            const $details = $run.find('.wssfc-run-details');
            const $toggle = $run.find('.wssfc-run-toggle');

            $details.slideToggle(200);
            $toggle.attr('aria-expanded', $details.is(':visible'));
        },

        /**
         * Show context modal
         *
         * @param {Event} e Click event
         */
        showContextModal: function (e) {
            e.preventDefault();
            e.stopPropagation();

            const context = $(e.currentTarget).data('context');
            let formatted;

            try {
                const parsed = typeof context === 'string' ? JSON.parse(context) : context;
                formatted = JSON.stringify(parsed, null, 2);
            } catch (error) {
                formatted = context;
            }

            $('#wssfc-context-content').text(formatted);
            $('#wssfc-context-modal').show();
        },

        /**
         * Close modal
         *
         * @param {Event} e Click event
         */
        closeModal: function (e) {
            if (e) {
                e.preventDefault();
            }
            $('#wssfc-context-modal').hide();
        }
    };

    // Initialize on document ready
    $(document).ready(function () {
        WSSFCAdmin.init();
    });

})(jQuery);
