<?php
/**
 * Plugin Name: WP Stock Sync From CSV
 * Plugin URI: https://github.com/JonakyDS/wp-stock-sync-from-csv
 * Description: Automatically syncs WooCommerce product stock levels from a remote CSV file.
 * Version: 1.0.3
 * Author: Jonaky Adhikary
 * Author URI: https://jonakyds.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-stock-sync-from-csv
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 *
 * @package WP_Stock_Sync_From_CSV
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin constants
define( 'WSSFC_VERSION', '1.0.3' );
define( 'WSSFC_PLUGIN_FILE', __FILE__ );
define( 'WSSFC_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WSSFC_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WSSFC_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Main plugin class
 */
final class WP_Stock_Sync_From_CSV {

    /**
     * Single instance of the class
     *
     * @var WP_Stock_Sync_From_CSV
     */
    private static $instance = null;

    /**
     * Plugin components
     */
    public $admin;
    public $stock_syncer;
    public $logger;
    public $cron;

    /**
     * Get single instance of the class
     *
     * @return WP_Stock_Sync_From_CSV
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        $this->check_requirements();
        $this->includes();
        $this->init_hooks();
    }

    /**
     * Check plugin requirements
     */
    private function check_requirements() {
        // Check if WooCommerce is active
        add_action( 'admin_init', array( $this, 'check_woocommerce' ) );
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
            deactivate_plugins( WSSFC_PLUGIN_BASENAME );
            return;
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p>
                <strong><?php esc_html_e( 'WP Stock Sync From CSV', 'wp-stock-sync-from-csv' ); ?></strong>
                <?php esc_html_e( 'requires WooCommerce to be installed and activated.', 'wp-stock-sync-from-csv' ); ?>
            </p>
        </div>
        <?php
    }

    /**
     * Include required files
     */
    private function includes() {
        // Core classes
        require_once WSSFC_PLUGIN_DIR . 'includes/class-wssfc-logger.php';
        require_once WSSFC_PLUGIN_DIR . 'includes/class-wssfc-stock-syncer.php';
        require_once WSSFC_PLUGIN_DIR . 'includes/class-wssfc-cron.php';
        require_once WSSFC_PLUGIN_DIR . 'includes/class-wssfc-github-updater.php';

        // Admin classes
        if ( is_admin() ) {
            require_once WSSFC_PLUGIN_DIR . 'includes/admin/class-wssfc-admin.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Initialize components after plugins loaded
        add_action( 'plugins_loaded', array( $this, 'init_components' ), 20 );

        // Activation/Deactivation hooks
        register_activation_hook( WSSFC_PLUGIN_FILE, array( $this, 'activate' ) );
        register_deactivation_hook( WSSFC_PLUGIN_FILE, array( $this, 'deactivate' ) );

        // Load textdomain
        add_action( 'init', array( $this, 'load_textdomain' ) );

        // Declare HPOS compatibility
        add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
    }

    /**
     * Initialize plugin components
     */
    public function init_components() {
        // Initialize GitHub updater (works even without WooCommerce)
        new WSSFC_GitHub_Updater();

        if ( ! class_exists( 'WooCommerce' ) ) {
            return;
        }

        $this->logger       = new WSSFC_Logger();
        $this->stock_syncer = new WSSFC_Stock_Syncer( $this->logger );
        $this->cron         = new WSSFC_Cron( $this->stock_syncer, $this->logger );

        if ( is_admin() ) {
            $this->admin = new WSSFC_Admin( $this->logger, $this->stock_syncer, $this->cron );
        }
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Set default options
        $default_options = array(
            'csv_url'           => '',
            'sku_column'        => 'sku',
            'quantity_column'   => 'quantity',
            'ssl_verify'        => true,
            'schedule'          => 'hourly',
            'custom_interval_minutes' => 60,
            'enabled'           => false,
        );

        if ( ! get_option( 'wssfc_settings' ) ) {
            add_option( 'wssfc_settings', $default_options );
        }

        // Create database table for logs
        $this->create_logs_table();

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Create logs database table
     */
    private function create_logs_table() {
        global $wpdb;

        $table_name      = $wpdb->prefix . 'wssfc_logs';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            run_id varchar(36) NOT NULL DEFAULT '',
            timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level varchar(20) NOT NULL DEFAULT 'info',
            message text NOT NULL,
            context longtext,
            PRIMARY KEY (id),
            KEY run_id (run_id),
            KEY level (level),
            KEY timestamp (timestamp)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled cron events
        wp_clear_scheduled_hook( 'wssfc_sync_event' );

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Load plugin textdomain
     */
    public function load_textdomain() {
        load_plugin_textdomain(
            'wp-stock-sync-from-csv',
            false,
            dirname( WSSFC_PLUGIN_BASENAME ) . '/languages/'
        );
    }

    /**
     * Declare HPOS compatibility
     */
    public function declare_hpos_compatibility() {
        if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
                'custom_order_tables',
                WSSFC_PLUGIN_FILE,
                true
            );
        }
    }

    /**
     * Prevent cloning
     */
    private function __clone() {}

    /**
     * Prevent unserializing
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }
}

/**
 * Returns the main instance of WP_Stock_Sync_From_CSV
 *
 * @return WP_Stock_Sync_From_CSV
 */
function wssfc() {
    return WP_Stock_Sync_From_CSV::instance();
}

// Initialize plugin
wssfc();
