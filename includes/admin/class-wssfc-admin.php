<?php
/**
 * Admin class for WP Stock Sync From CSV
 *
 * @package WP_Stock_Sync_From_CSV
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin class
 */
class WSSFC_Admin {

    /**
     * Logger instance
     *
     * @var WSSFC_Logger
     */
    private $logger;

    /**
     * Stock Syncer instance
     *
     * @var WSSFC_Stock_Syncer
     */
    private $stock_syncer;

    /**
     * Cron instance
     *
     * @var WSSFC_Cron
     */
    private $cron;

    /**
     * Constructor
     *
     * @param WSSFC_Logger       $logger       Logger instance.
     * @param WSSFC_Stock_Syncer $stock_syncer Stock Syncer instance.
     * @param WSSFC_Cron         $cron         Cron instance.
     */
    public function __construct( $logger, $stock_syncer, $cron ) {
        $this->logger       = $logger;
        $this->stock_syncer = $stock_syncer;
        $this->cron         = $cron;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers
        add_action( 'wp_ajax_wssfc_test_connection', array( $this, 'ajax_test_connection' ) );
        add_action( 'wp_ajax_wssfc_run_sync', array( $this, 'ajax_run_sync' ) );
        add_action( 'wp_ajax_wssfc_clear_logs', array( $this, 'ajax_clear_logs' ) );
        add_action( 'wp_ajax_wssfc_get_logs', array( $this, 'ajax_get_logs' ) );

        // Settings link on plugins page
        add_filter( 'plugin_action_links_' . WSSFC_PLUGIN_BASENAME, array( $this, 'add_settings_link' ) );
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        add_menu_page(
            __( 'Stock Sync CSV', 'wp-stock-sync-from-csv' ),
            __( 'Stock Sync CSV', 'wp-stock-sync-from-csv' ),
            'manage_woocommerce',
            'wp-stock-sync-from-csv',
            array( $this, 'render_settings_page' ),
            'dashicons-update',
            58
        );

        add_submenu_page(
            'wp-stock-sync-from-csv',
            __( 'Settings', 'wp-stock-sync-from-csv' ),
            __( 'Settings', 'wp-stock-sync-from-csv' ),
            'manage_woocommerce',
            'wp-stock-sync-from-csv',
            array( $this, 'render_settings_page' )
        );

        add_submenu_page(
            'wp-stock-sync-from-csv',
            __( 'Logs', 'wp-stock-sync-from-csv' ),
            __( 'Logs', 'wp-stock-sync-from-csv' ),
            'manage_woocommerce',
            'wp-stock-sync-from-csv-logs',
            array( $this, 'render_logs_page' )
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting(
            'wssfc_settings_group',
            'wssfc_settings',
            array( $this, 'sanitize_settings' )
        );

        // CSV Settings Section
        add_settings_section(
            'wssfc_csv_section',
            __( 'CSV Source Settings', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_csv_section' ),
            'wp-stock-sync-from-csv'
        );

        add_settings_field(
            'csv_url',
            __( 'CSV URL', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_text_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_csv_section',
            array(
                'field'       => 'csv_url',
                'type'        => 'url',
                'placeholder' => 'https://example.com/stock.csv',
                'description' => __( 'The full URL to your CSV file containing stock data.', 'wp-stock-sync-from-csv' ),
            )
        );

        add_settings_field(
            'sku_column',
            __( 'SKU Column Header', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_text_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_csv_section',
            array(
                'field'       => 'sku_column',
                'placeholder' => 'sku',
                'description' => __( 'The header name of the SKU column in your CSV file.', 'wp-stock-sync-from-csv' ),
            )
        );

        add_settings_field(
            'quantity_column',
            __( 'Quantity Column Header', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_text_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_csv_section',
            array(
                'field'       => 'quantity_column',
                'placeholder' => 'quantity',
                'description' => __( 'The header name of the quantity/stock column in your CSV file.', 'wp-stock-sync-from-csv' ),
            )
        );

        // Schedule Settings Section
        add_settings_section(
            'wssfc_schedule_section',
            __( 'Sync Schedule', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_schedule_section' ),
            'wp-stock-sync-from-csv'
        );

        add_settings_field(
            'schedule',
            __( 'Sync Frequency', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_schedule_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_schedule_section'
        );

        add_settings_field(
            'custom_interval_minutes',
            __( 'Custom Interval (minutes)', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_text_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_schedule_section',
            array(
                'field'       => 'custom_interval_minutes',
                'type'        => 'number',
                'placeholder' => '60',
                'description' => __( 'Only used when "Custom Interval" is selected above. Minimum: 1 minute.', 'wp-stock-sync-from-csv' ),
            )
        );

        // Enable/Disable Section
        add_settings_section(
            'wssfc_enable_section',
            __( 'Sync Status', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_enable_section' ),
            'wp-stock-sync-from-csv'
        );

        add_settings_field(
            'enabled',
            __( 'Enable Automatic Sync', 'wp-stock-sync-from-csv' ),
            array( $this, 'render_checkbox_field' ),
            'wp-stock-sync-from-csv',
            'wssfc_enable_section',
            array(
                'field'       => 'enabled',
                'description' => __( 'Enable or disable automatic scheduled stock sync.', 'wp-stock-sync-from-csv' ),
            )
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Input settings.
     * @return array
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();
        $old_settings = get_option( 'wssfc_settings', array() );

        $sanitized['csv_url']         = esc_url_raw( $input['csv_url'] ?? '' );
        $sanitized['sku_column']      = sanitize_text_field( $input['sku_column'] ?? 'sku' );
        $sanitized['quantity_column'] = sanitize_text_field( $input['quantity_column'] ?? 'quantity' );
        $sanitized['schedule']        = sanitize_key( $input['schedule'] ?? 'hourly' );
        
        // Handle custom interval minutes (1-43200 minutes = 1 min to 30 days)
        $custom_minutes = absint( $input['custom_interval_minutes'] ?? 60 );
        $sanitized['custom_interval_minutes'] = max( 1, min( 43200, $custom_minutes ) );
        
        $sanitized['enabled'] = ! empty( $input['enabled'] );

        // Update cron schedule if changed
        $schedule_changed = $sanitized['enabled'] !== ( $old_settings['enabled'] ?? false ) ||
                           $sanitized['schedule'] !== ( $old_settings['schedule'] ?? 'hourly' ) ||
                           ( $sanitized['schedule'] === 'wssfc_custom' && 
                             $sanitized['custom_interval_minutes'] !== ( $old_settings['custom_interval_minutes'] ?? 60 ) );

        if ( $schedule_changed ) {
            $this->cron->reschedule( $sanitized['enabled'], $sanitized['schedule'] );
        }

        $this->logger->info( __( 'Settings updated', 'wp-stock-sync-from-csv' ) );

        return $sanitized;
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_assets( $hook ) {
        if ( strpos( $hook, 'wp-stock-sync-from-csv' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'wssfc-admin',
            WSSFC_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            WSSFC_VERSION
        );

        wp_enqueue_script(
            'wssfc-admin',
            WSSFC_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WSSFC_VERSION,
            true
        );

        wp_localize_script( 'wssfc-admin', 'wssfc_admin', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'wssfc_admin_nonce' ),
            'strings'  => array(
                'testing'       => __( 'Testing connection...', 'wp-stock-sync-from-csv' ),
                'syncing'       => __( 'Running sync...', 'wp-stock-sync-from-csv' ),
                'success'       => __( 'Success!', 'wp-stock-sync-from-csv' ),
                'error'         => __( 'Error:', 'wp-stock-sync-from-csv' ),
                'confirm_clear' => __( 'Are you sure you want to clear all logs?', 'wp-stock-sync-from-csv' ),
                'clearing'      => __( 'Clearing logs...', 'wp-stock-sync-from-csv' ),
                'cleared'       => __( 'Logs cleared successfully.', 'wp-stock-sync-from-csv' ),
                'loading'       => __( 'Loading...', 'wp-stock-sync-from-csv' ),
            ),
        ) );
    }

    /**
     * Add settings link on plugins page
     *
     * @param array $links Plugin links.
     * @return array
     */
    public function add_settings_link( $links ) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url( 'admin.php?page=wp-stock-sync-from-csv' ),
            __( 'Settings', 'wp-stock-sync-from-csv' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        $settings = get_option( 'wssfc_settings', array() );
        $next_run = wp_next_scheduled( 'wssfc_sync_event' );
        $last_run = get_option( 'wssfc_last_run', array() );
        ?>
        <div class="wrap wssfc-admin-wrap">
            <h1>
                <span class="dashicons dashicons-update"></span>
                <?php esc_html_e( 'WP Stock Sync From CSV Settings', 'wp-stock-sync-from-csv' ); ?>
            </h1>

            <!-- Status Cards -->
            <div class="wssfc-status-cards">
                <div class="wssfc-card wssfc-card-status">
                    <h3><?php esc_html_e( 'Sync Status', 'wp-stock-sync-from-csv' ); ?></h3>
                    <div class="wssfc-status-indicator <?php echo ! empty( $settings['enabled'] ) ? 'active' : 'inactive'; ?>">
                        <span class="status-dot"></span>
                        <span class="status-text">
                            <?php echo ! empty( $settings['enabled'] ) 
                                ? esc_html__( 'Active', 'wp-stock-sync-from-csv' ) 
                                : esc_html__( 'Inactive', 'wp-stock-sync-from-csv' ); ?>
                        </span>
                    </div>
                    <?php if ( $next_run ) : ?>
                        <p class="next-run">
                            <strong><?php esc_html_e( 'Next run:', 'wp-stock-sync-from-csv' ); ?></strong><br>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $next_run ) ); ?>
                        </p>
                    <?php endif; ?>
                </div>

                <div class="wssfc-card wssfc-card-last-run">
                    <h3><?php esc_html_e( 'Last Run', 'wp-stock-sync-from-csv' ); ?></h3>
                    <?php if ( ! empty( $last_run ) ) : ?>
                        <p>
                            <strong><?php esc_html_e( 'Time:', 'wp-stock-sync-from-csv' ); ?></strong>
                            <?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last_run['time'] ?? 0 ) ); ?>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Status:', 'wp-stock-sync-from-csv' ); ?></strong>
                            <span class="wssfc-badge <?php echo esc_attr( $last_run['status'] ?? 'unknown' ); ?>">
                                <?php echo esc_html( ucfirst( $last_run['status'] ?? __( 'Unknown', 'wp-stock-sync-from-csv' ) ) ); ?>
                            </span>
                        </p>
                        <p>
                            <strong><?php esc_html_e( 'Products Updated:', 'wp-stock-sync-from-csv' ); ?></strong>
                            <?php echo esc_html( $last_run['products_synced'] ?? 0 ); ?>
                        </p>
                    <?php else : ?>
                        <p class="no-data"><?php esc_html_e( 'No sync has been run yet.', 'wp-stock-sync-from-csv' ); ?></p>
                    <?php endif; ?>
                </div>

                <div class="wssfc-card wssfc-card-actions">
                    <h3><?php esc_html_e( 'Quick Actions', 'wp-stock-sync-from-csv' ); ?></h3>
                    <button type="button" id="wssfc-test-connection" class="button button-secondary">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        <?php esc_html_e( 'Test CSV Connection', 'wp-stock-sync-from-csv' ); ?>
                    </button>
                    <button type="button" id="wssfc-run-sync" class="button button-primary">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Run Sync Now', 'wp-stock-sync-from-csv' ); ?>
                    </button>
                    <div id="wssfc-action-result" class="wssfc-action-result"></div>
                </div>
            </div>

            <!-- Settings Form -->
            <form method="post" action="options.php" class="wssfc-settings-form">
                <?php
                settings_fields( 'wssfc_settings_group' );
                do_settings_sections( 'wp-stock-sync-from-csv' );
                submit_button( __( 'Save Settings', 'wp-stock-sync-from-csv' ) );
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render logs page
     */
    public function render_logs_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        // Pagination settings
        $per_page     = 10;
        $current_page = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset       = ( $current_page - 1 ) * $per_page;

        $sync_runs   = $this->logger->get_sync_runs( $per_page, $offset );
        $total_runs  = $this->logger->get_sync_runs_count();
        $total_pages = ceil( $total_runs / $per_page );
        $log_counts  = $this->logger->get_log_counts();
        ?>
        <div class="wrap wssfc-admin-wrap">
            <h1>
                <span class="dashicons dashicons-list-view"></span>
                <?php esc_html_e( 'Sync Logs', 'wp-stock-sync-from-csv' ); ?>
            </h1>

            <div class="wssfc-logs-header">
                <div class="wssfc-logs-filters">
                    <div class="wssfc-logs-stats">
                        <span class="wssfc-logs-stat runs">
                            <span class="count"><?php echo esc_html( $log_counts['runs'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Runs', 'wp-stock-sync-from-csv' ); ?>
                        </span>
                        <span class="wssfc-logs-stat success">
                            <span class="count"><?php echo esc_html( $log_counts['success'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Success', 'wp-stock-sync-from-csv' ); ?>
                        </span>
                        <span class="wssfc-logs-stat warning">
                            <span class="count"><?php echo esc_html( $log_counts['warning'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Warnings', 'wp-stock-sync-from-csv' ); ?>
                        </span>
                        <span class="wssfc-logs-stat error">
                            <span class="count"><?php echo esc_html( $log_counts['error'] ?? 0 ); ?></span> 
                            <?php esc_html_e( 'Errors', 'wp-stock-sync-from-csv' ); ?>
                        </span>
                    </div>
                    <button type="button" id="wssfc-refresh-logs" class="button">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e( 'Refresh', 'wp-stock-sync-from-csv' ); ?>
                    </button>
                </div>
                <button type="button" id="wssfc-clear-logs" class="button button-secondary">
                    <span class="dashicons dashicons-trash"></span>
                    <?php esc_html_e( 'Clear All Logs', 'wp-stock-sync-from-csv' ); ?>
                </button>
            </div>

            <div class="wssfc-sync-runs-container">
                <?php if ( empty( $sync_runs ) ) : ?>
                    <div class="wssfc-no-logs">
                        <span class="dashicons dashicons-info-outline"></span>
                        <p><?php esc_html_e( 'No sync runs found. Run a sync to see activity here.', 'wp-stock-sync-from-csv' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php foreach ( $sync_runs as $run ) : 
                        $run_logs = $this->logger->get_logs_for_run( $run->run_id );
                        $status_class = $run->status;
                        $is_orphan = ( $run->run_id === '' || $run->run_id === '__orphan__' );
                        
                        if ( $is_orphan ) {
                            $trigger_label = __( 'System', 'wp-stock-sync-from-csv' );
                            $trigger_icon = 'info-outline';
                        } elseif ( 'manual' === $run->trigger ) {
                            $trigger_label = __( 'Manual', 'wp-stock-sync-from-csv' );
                            $trigger_icon = 'admin-users';
                        } elseif ( 'scheduled' === $run->trigger ) {
                            $trigger_label = __( 'Scheduled', 'wp-stock-sync-from-csv' );
                            $trigger_icon = 'clock';
                        } else {
                            $trigger_label = __( 'Unknown', 'wp-stock-sync-from-csv' );
                            $trigger_icon = 'editor-help';
                        }
                    ?>
                        <div class="wssfc-sync-run <?php echo esc_attr( $status_class ); ?>" data-run-id="<?php echo esc_attr( $run->run_id ); ?>">
                            <div class="wssfc-run-header">
                                <div class="wssfc-run-status">
                                    <span class="wssfc-status-icon <?php echo esc_attr( $status_class ); ?>">
                                        <?php if ( 'success' === $status_class ) : ?>
                                            <span class="dashicons dashicons-yes-alt"></span>
                                        <?php elseif ( 'failed' === $status_class ) : ?>
                                            <span class="dashicons dashicons-dismiss"></span>
                                        <?php elseif ( 'orphan' === $status_class ) : ?>
                                            <span class="dashicons dashicons-info-outline"></span>
                                        <?php else : ?>
                                            <span class="dashicons dashicons-update"></span>
                                        <?php endif; ?>
                                    </span>
                                </div>
                                <div class="wssfc-run-info">
                                    <div class="wssfc-run-title">
                                        <strong>
                                            <?php 
                                            if ( 'success' === $status_class ) {
                                                esc_html_e( 'Sync Completed Successfully', 'wp-stock-sync-from-csv' );
                                            } elseif ( 'failed' === $status_class ) {
                                                esc_html_e( 'Sync Failed', 'wp-stock-sync-from-csv' );
                                            } elseif ( 'orphan' === $status_class ) {
                                                esc_html_e( 'System Logs', 'wp-stock-sync-from-csv' );
                                            } else {
                                                esc_html_e( 'Sync In Progress', 'wp-stock-sync-from-csv' );
                                            }
                                            ?>
                                        </strong>
                                    </div>
                                    <div class="wssfc-run-meta">
                                        <span class="wssfc-run-trigger">
                                            <span class="dashicons dashicons-<?php echo esc_attr( $trigger_icon ); ?>"></span>
                                            <?php echo esc_html( $trigger_label ); ?>
                                        </span>
                                        <span class="wssfc-run-time">
                                            <span class="dashicons dashicons-calendar-alt"></span>
                                            <?php 
                                            $display_time = $is_orphan ? $run->ended_at : $run->started_at;
                                            ?>
                                            <?php echo esc_html( wp_date( 'M j, Y', strtotime( $display_time ) ) ); ?>
                                            <?php esc_html_e( 'at', 'wp-stock-sync-from-csv' ); ?>
                                            <?php echo esc_html( wp_date( 'H:i:s', strtotime( $display_time ) ) ); ?>
                                        </span>
                                        <?php if ( ! $is_orphan && $run->duration ) : ?>
                                        <span class="wssfc-run-duration">
                                            <span class="dashicons dashicons-backup"></span>
                                            <?php 
                                            if ( $run->duration < 60 ) {
                                                printf( esc_html__( '%d sec', 'wp-stock-sync-from-csv' ), $run->duration );
                                            } else {
                                                printf( esc_html__( '%d min %d sec', 'wp-stock-sync-from-csv' ), floor( $run->duration / 60 ), $run->duration % 60 );
                                            }
                                            ?>
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="wssfc-run-summary">
                                    <?php if ( $run->final_stats ) : ?>
                                        <div class="wssfc-run-stats">
                                            <?php if ( isset( $run->final_stats['updated_count'] ) ) : ?>
                                                <span class="wssfc-stat-item updated">
                                                    <span class="dashicons dashicons-upload"></span>
                                                    <?php printf( esc_html__( '%d updated', 'wp-stock-sync-from-csv' ), $run->final_stats['updated_count'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                            <?php if ( isset( $run->final_stats['not_found_count'] ) && $run->final_stats['not_found_count'] > 0 ) : ?>
                                                <span class="wssfc-stat-item not-found">
                                                    <span class="dashicons dashicons-dismiss"></span>
                                                    <?php printf( esc_html__( '%d not found', 'wp-stock-sync-from-csv' ), $run->final_stats['not_found_count'] ); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="wssfc-run-badges">
                                        <?php if ( $run->error_count > 0 ) : ?>
                                            <span class="wssfc-badge error"><?php echo esc_html( $run->error_count ); ?> <?php esc_html_e( 'errors', 'wp-stock-sync-from-csv' ); ?></span>
                                        <?php endif; ?>
                                        <?php if ( $run->warning_count > 0 ) : ?>
                                            <span class="wssfc-badge warning"><?php echo esc_html( $run->warning_count ); ?> <?php esc_html_e( 'warnings', 'wp-stock-sync-from-csv' ); ?></span>
                                        <?php endif; ?>
                                        <span class="wssfc-badge info"><?php echo esc_html( $run->log_count ); ?> <?php esc_html_e( 'logs', 'wp-stock-sync-from-csv' ); ?></span>
                                    </div>
                                </div>
                                <button type="button" class="wssfc-run-toggle" aria-expanded="false">
                                    <span class="dashicons dashicons-arrow-down-alt2"></span>
                                </button>
                            </div>
                            <div class="wssfc-run-details" style="display: none;">
                                <table class="wssfc-logs-table">
                                    <thead>
                                        <tr>
                                            <th class="column-time"><?php esc_html_e( 'Time', 'wp-stock-sync-from-csv' ); ?></th>
                                            <th class="column-level"><?php esc_html_e( 'Level', 'wp-stock-sync-from-csv' ); ?></th>
                                            <th class="column-message"><?php esc_html_e( 'Message', 'wp-stock-sync-from-csv' ); ?></th>
                                            <th class="column-context"><?php esc_html_e( 'Details', 'wp-stock-sync-from-csv' ); ?></th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ( $run_logs as $log ) : 
                                            $timestamp = strtotime( $log->timestamp );
                                        ?>
                                            <tr class="wssfc-log-row" data-level="<?php echo esc_attr( $log->level ); ?>">
                                                <td class="column-time">
                                                    <span class="log-time"><?php echo esc_html( wp_date( 'H:i:s', $timestamp ) ); ?></span>
                                                </td>
                                                <td class="column-level">
                                                    <span class="wssfc-badge <?php echo esc_attr( $log->level ); ?>">
                                                        <?php echo esc_html( ucfirst( $log->level ) ); ?>
                                                    </span>
                                                </td>
                                                <td class="column-message"><?php echo esc_html( $log->message ); ?></td>
                                                <td class="column-context">
                                                    <?php if ( ! empty( $log->context ) ) : ?>
                                                        <button type="button" class="button button-small wssfc-view-context" 
                                                                data-context="<?php echo esc_attr( $log->context ); ?>">
                                                            <span class="dashicons dashicons-visibility"></span>
                                                            <?php esc_html_e( 'View', 'wp-stock-sync-from-csv' ); ?>
                                                        </button>
                                                    <?php else : ?>
                                                        <span class="wssfc-text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?php if ( $total_pages > 1 ) : ?>
                <div class="wssfc-pagination">
                    <span class="wssfc-pagination-info">
                        <?php
                        printf(
                            esc_html__( 'Page %1$d of %2$d (%3$d total runs)', 'wp-stock-sync-from-csv' ),
                            $current_page,
                            $total_pages,
                            $total_runs
                        );
                        ?>
                    </span>
                    <div class="wssfc-pagination-links">
                        <?php
                        $base_url = admin_url( 'admin.php?page=wp-stock-sync-from-csv-logs' );
                        
                        if ( $current_page > 1 ) : ?>
                            <a href="<?php echo esc_url( $base_url ); ?>" class="wssfc-page-link first">
                                <span class="dashicons dashicons-controls-skipback"></span>
                            </a>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page - 1, $base_url ) ); ?>" class="wssfc-page-link prev">
                                <span class="dashicons dashicons-arrow-left-alt2"></span>
                            </a>
                        <?php endif;
                        
                        $start_page = max( 1, $current_page - 2 );
                        $end_page   = min( $total_pages, $current_page + 2 );
                        
                        if ( $start_page > 1 ) {
                            echo '<span class="wssfc-page-ellipsis">...</span>';
                        }
                        
                        for ( $i = $start_page; $i <= $end_page; $i++ ) :
                            if ( $i === $current_page ) : ?>
                                <span class="wssfc-page-link current"><?php echo esc_html( $i ); ?></span>
                            <?php else : ?>
                                <a href="<?php echo esc_url( add_query_arg( 'paged', $i, $base_url ) ); ?>" class="wssfc-page-link">
                                    <?php echo esc_html( $i ); ?>
                                </a>
                            <?php endif;
                        endfor;
                        
                        if ( $end_page < $total_pages ) {
                            echo '<span class="wssfc-page-ellipsis">...</span>';
                        }
                        
                        if ( $current_page < $total_pages ) : ?>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $current_page + 1, $base_url ) ); ?>" class="wssfc-page-link next">
                                <span class="dashicons dashicons-arrow-right-alt2"></span>
                            </a>
                            <a href="<?php echo esc_url( add_query_arg( 'paged', $total_pages, $base_url ) ); ?>" class="wssfc-page-link last">
                                <span class="dashicons dashicons-controls-skipforward"></span>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Context Modal -->
            <div id="wssfc-context-modal" class="wssfc-modal" style="display: none;">
                <div class="wssfc-modal-content">
                    <div class="wssfc-modal-header">
                        <h2><?php esc_html_e( 'Log Details', 'wp-stock-sync-from-csv' ); ?></h2>
                        <button type="button" class="wssfc-modal-close">&times;</button>
                    </div>
                    <div class="wssfc-modal-body">
                        <pre id="wssfc-context-content"></pre>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render CSV section description
     */
    public function render_csv_section() {
        echo '<p>' . esc_html__( 'Configure the CSV source for stock synchronization. The CSV should have at least two columns: one for SKU and one for quantity.', 'wp-stock-sync-from-csv' ) . '</p>';
    }

    /**
     * Render schedule section description
     */
    public function render_schedule_section() {
        echo '<p>' . esc_html__( 'Configure how often the stock should be synchronized from the CSV.', 'wp-stock-sync-from-csv' ) . '</p>';
    }

    /**
     * Render enable section description
     */
    public function render_enable_section() {
        echo '<p>' . esc_html__( 'Enable or disable the automatic sync feature.', 'wp-stock-sync-from-csv' ) . '</p>';
    }

    /**
     * Render text field
     *
     * @param array $args Field arguments.
     */
    public function render_text_field( $args ) {
        $settings = get_option( 'wssfc_settings', array() );
        $field    = $args['field'] ?? '';
        $type     = $args['type'] ?? 'text';
        $value    = $settings[ $field ] ?? '';
        ?>
        <input 
            type="<?php echo esc_attr( $type ); ?>"
            id="wssfc_<?php echo esc_attr( $field ); ?>"
            name="wssfc_settings[<?php echo esc_attr( $field ); ?>]"
            value="<?php echo esc_attr( $value ); ?>"
            placeholder="<?php echo esc_attr( $args['placeholder'] ?? '' ); ?>"
            class="regular-text"
        />
        <?php if ( ! empty( $args['description'] ) ) : ?>
            <p class="description"><?php echo esc_html( $args['description'] ); ?></p>
        <?php endif;
    }

    /**
     * Render schedule field
     */
    public function render_schedule_field() {
        $settings = get_option( 'wssfc_settings', array() );
        $current  = $settings['schedule'] ?? 'hourly';
        $options  = WSSFC_Cron::get_schedule_options();
        ?>
        <select id="wssfc_schedule" name="wssfc_settings[schedule]">
            <?php foreach ( $options as $value => $label ) : ?>
                <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>>
                    <?php echo esc_html( $label ); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php esc_html_e( 'How often should the stock be synchronized from the CSV file.', 'wp-stock-sync-from-csv' ); ?></p>
        <?php
    }

    /**
     * Render checkbox field
     *
     * @param array $args Field arguments.
     */
    public function render_checkbox_field( $args ) {
        $settings = get_option( 'wssfc_settings', array() );
        $field    = $args['field'] ?? '';
        $checked  = ! empty( $settings[ $field ] );
        ?>
        <label for="wssfc_<?php echo esc_attr( $field ); ?>">
            <input 
                type="checkbox"
                id="wssfc_<?php echo esc_attr( $field ); ?>"
                name="wssfc_settings[<?php echo esc_attr( $field ); ?>]"
                value="1"
                <?php checked( $checked ); ?>
            />
            <?php if ( ! empty( $args['description'] ) ) : ?>
                <?php echo esc_html( $args['description'] ); ?>
            <?php endif; ?>
        </label>
        <?php
    }

    /**
     * AJAX: Test CSV connection
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'wssfc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-stock-sync-from-csv' ) );
        }

        $result = $this->stock_syncer->test_connection();

        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Run sync manually
     */
    public function ajax_run_sync() {
        check_ajax_referer( 'wssfc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-stock-sync-from-csv' ) );
        }

        $result = $this->cron->run_sync( 'manual' );

        if ( $result['success'] ) {
            wp_send_json_success( $result['message'] );
        } else {
            wp_send_json_error( $result['message'] );
        }
    }

    /**
     * AJAX: Clear logs
     */
    public function ajax_clear_logs() {
        check_ajax_referer( 'wssfc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-stock-sync-from-csv' ) );
        }

        $result = $this->logger->clear_all_logs();

        if ( $result ) {
            wp_send_json_success( __( 'Logs cleared successfully.', 'wp-stock-sync-from-csv' ) );
        } else {
            wp_send_json_error( __( 'Failed to clear logs.', 'wp-stock-sync-from-csv' ) );
        }
    }

    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer( 'wssfc_admin_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-stock-sync-from-csv' ) );
        }

        $per_page = isset( $_POST['per_page'] ) ? absint( $_POST['per_page'] ) : 10;
        $page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
        $offset   = ( $page - 1 ) * $per_page;

        $runs = $this->logger->get_sync_runs( $per_page, $offset );

        wp_send_json_success( $runs );
    }
}
