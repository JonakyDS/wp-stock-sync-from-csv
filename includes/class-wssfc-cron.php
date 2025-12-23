<?php
/**
 * Cron class for WP Stock Sync From CSV
 *
 * @package WP_Stock_Sync_From_CSV
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Cron class for scheduling and running sync tasks
 */
class WSSFC_Cron {

    /**
     * Stock Syncer instance
     *
     * @var WSSFC_Stock_Syncer
     */
    private $stock_syncer;

    /**
     * Logger instance
     *
     * @var WSSFC_Logger
     */
    private $logger;

    /**
     * Cron hook name
     *
     * @var string
     */
    const CRON_HOOK = 'wssfc_sync_event';

    /**
     * Constructor
     *
     * @param WSSFC_Stock_Syncer $stock_syncer Stock Syncer instance.
     * @param WSSFC_Logger       $logger       Logger instance.
     */
    public function __construct( $stock_syncer, $logger ) {
        $this->stock_syncer = $stock_syncer;
        $this->logger       = $logger;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Register custom cron schedules
        add_filter( 'cron_schedules', array( $this, 'add_cron_schedules' ) );

        // Register cron event handler
        add_action( self::CRON_HOOK, array( $this, 'execute_sync' ) );

        // Schedule initial event if enabled
        add_action( 'init', array( $this, 'maybe_schedule_event' ) );
    }

    /**
     * Add custom cron schedules
     *
     * @param array $schedules Existing schedules.
     * @return array
     */
    public function add_cron_schedules( $schedules ) {
        // Add weekly schedule if not exists
        if ( ! isset( $schedules['weekly'] ) ) {
            $schedules['weekly'] = array(
                'interval' => WEEK_IN_SECONDS,
                'display'  => __( 'Once Weekly', 'wp-stock-sync-from-csv' ),
            );
        }

        // Add every 5 minutes schedule
        if ( ! isset( $schedules['every_5_minutes'] ) ) {
            $schedules['every_5_minutes'] = array(
                'interval' => 5 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 5 Minutes', 'wp-stock-sync-from-csv' ),
            );
        }

        // Add every 15 minutes schedule
        if ( ! isset( $schedules['every_15_minutes'] ) ) {
            $schedules['every_15_minutes'] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 15 Minutes', 'wp-stock-sync-from-csv' ),
            );
        }

        // Add every 30 minutes schedule
        if ( ! isset( $schedules['every_30_minutes'] ) ) {
            $schedules['every_30_minutes'] = array(
                'interval' => 30 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 30 Minutes', 'wp-stock-sync-from-csv' ),
            );
        }

        // Add custom interval schedule
        $settings = get_option( 'wssfc_settings', array() );
        $custom_minutes = isset( $settings['custom_interval_minutes'] ) ? absint( $settings['custom_interval_minutes'] ) : 0;
        
        if ( $custom_minutes > 0 ) {
            $schedules['wssfc_custom'] = array(
                'interval' => $custom_minutes * MINUTE_IN_SECONDS,
                'display'  => sprintf( __( 'Every %d minutes', 'wp-stock-sync-from-csv' ), $custom_minutes ),
            );
        }

        return $schedules;
    }

    /**
     * Maybe schedule event on init
     */
    public function maybe_schedule_event() {
        $settings = get_option( 'wssfc_settings', array() );

        if ( empty( $settings['enabled'] ) ) {
            return;
        }

        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            $this->schedule( $settings['schedule'] ?? 'hourly' );
        }
    }

    /**
     * Schedule the sync event
     *
     * @param string $recurrence Schedule recurrence.
     * @return bool
     */
    public function schedule( $recurrence = 'hourly' ) {
        // Clear any existing schedule
        $this->unschedule();

        // Schedule new event starting 1 minute from now
        $first_run = time() + MINUTE_IN_SECONDS;
        $result = wp_schedule_event( $first_run, $recurrence, self::CRON_HOOK );

        if ( $result ) {
            $this->logger->info(
                sprintf( __( 'Sync scheduled: %s', 'wp-stock-sync-from-csv' ), $recurrence )
            );
        }

        return $result !== false;
    }

    /**
     * Unschedule the sync event
     */
    public function unschedule() {
        $timestamp = wp_next_scheduled( self::CRON_HOOK );

        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, self::CRON_HOOK );
            $this->logger->info( __( 'Sync unscheduled', 'wp-stock-sync-from-csv' ) );
        }

        // Also clear all scheduled hooks with this name
        wp_clear_scheduled_hook( self::CRON_HOOK );
    }

    /**
     * Reschedule the sync event
     *
     * @param bool   $enabled    Whether sync is enabled.
     * @param string $recurrence Schedule recurrence.
     */
    public function reschedule( $enabled, $recurrence ) {
        if ( $enabled ) {
            $this->schedule( $recurrence );
        } else {
            $this->unschedule();
        }
    }

    /**
     * Execute the sync (cron handler)
     */
    public function execute_sync() {
        // Increase execution time for scheduled runs
        if ( function_exists( 'set_time_limit' ) ) {
            @set_time_limit( 300 ); // 5 minutes
        }

        // Increase memory limit if possible
        if ( function_exists( 'wp_raise_memory_limit' ) ) {
            wp_raise_memory_limit( 'cron' );
        }

        // Disable user abort for cron
        if ( function_exists( 'ignore_user_abort' ) ) {
            @ignore_user_abort( true );
        }

        $this->run_sync( 'scheduled' );
    }

    /**
     * Run the sync process
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     * @return array Result array.
     */
    public function run_sync( $trigger = 'manual' ) {
        $start_time = microtime( true );

        // Start a new run and get the run ID
        $run_id = $this->logger->start_run( $trigger );

        try {
            // Check if WooCommerce is active
            if ( ! class_exists( 'WooCommerce' ) ) {
                $message = __( 'WooCommerce is not active.', 'wp-stock-sync-from-csv' );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
                return array(
                    'success' => false,
                    'message' => $message,
                );
            }

            // Check settings
            $settings = get_option( 'wssfc_settings', array() );

            if ( empty( $settings['csv_url'] ) ) {
                $message = __( 'CSV URL is not configured.', 'wp-stock-sync-from-csv' );
                $this->logger->end_run( 'failed', array( 'reason' => $message ) );
                return array(
                    'success' => false,
                    'message' => $message,
                );
            }

            // Run the sync
            $result = $this->stock_syncer->sync();

            // Calculate duration
            $duration = round( microtime( true ) - $start_time, 2 );

            if ( $result['success'] ) {
                // Log success
                $stats = array_merge(
                    $result['stats'] ?? array(),
                    array(
                        'trigger'          => $trigger,
                        'duration_seconds' => $duration,
                    )
                );

                // Update last run info
                $this->update_last_run( 'success', $result['stats']['updated_count'] ?? 0, $stats );

                // End the run with success
                $this->logger->end_run( 'success', $stats );

                return array(
                    'success' => true,
                    'message' => $result['message'],
                    'stats'   => $stats,
                );
            } else {
                // Log failure
                $this->update_last_run( 'failed', 0 );
                $this->logger->end_run( 'failed', array( 'reason' => $result['message'] ) );

                return array(
                    'success' => false,
                    'message' => $result['message'],
                );
            }

        } catch ( Exception $e ) {
            $message = sprintf( __( 'Sync failed with exception: %s', 'wp-stock-sync-from-csv' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'exception' => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_run( 'failed', 0 );
            return array(
                'success' => false,
                'message' => $message,
            );
        } catch ( Error $e ) {
            $message = sprintf( __( 'Sync failed with error: %s', 'wp-stock-sync-from-csv' ), $e->getMessage() );
            $this->logger->error( $message, array(
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ) );
            $this->logger->end_run( 'failed', array( 'reason' => $message ) );
            $this->update_last_run( 'failed', 0 );
            return array(
                'success' => false,
                'message' => $message,
            );
        }
    }

    /**
     * Update last run information
     *
     * @param string $status          Run status.
     * @param int    $products_synced Number of products synced.
     * @param array  $stats           Additional statistics.
     */
    private function update_last_run( $status, $products_synced, $stats = array() ) {
        $last_run = array(
            'time'            => time(),
            'status'          => $status,
            'products_synced' => $products_synced,
            'stats'           => $stats,
        );

        update_option( 'wssfc_last_run', $last_run );
    }

    /**
     * Get last run information
     *
     * @return array
     */
    public function get_last_run() {
        return get_option( 'wssfc_last_run', array() );
    }

    /**
     * Get next scheduled run time
     *
     * @return int|false Timestamp or false if not scheduled.
     */
    public function get_next_run() {
        return wp_next_scheduled( self::CRON_HOOK );
    }

    /**
     * Check if sync is currently running
     *
     * @return bool
     */
    public function is_running() {
        return get_transient( 'wssfc_sync_running' ) === 'yes';
    }

    /**
     * Set sync running state
     *
     * @param bool $running Whether sync is running.
     */
    public function set_running( $running ) {
        if ( $running ) {
            set_transient( 'wssfc_sync_running', 'yes', HOUR_IN_SECONDS );
        } else {
            delete_transient( 'wssfc_sync_running' );
        }
    }

    /**
     * Get schedule options
     *
     * @return array
     */
    public static function get_schedule_options() {
        return array(
            'every_5_minutes'  => __( 'Every 5 Minutes', 'wp-stock-sync-from-csv' ),
            'every_15_minutes' => __( 'Every 15 Minutes', 'wp-stock-sync-from-csv' ),
            'every_30_minutes' => __( 'Every 30 Minutes', 'wp-stock-sync-from-csv' ),
            'hourly'           => __( 'Hourly', 'wp-stock-sync-from-csv' ),
            'twicedaily'       => __( 'Twice Daily', 'wp-stock-sync-from-csv' ),
            'daily'            => __( 'Daily', 'wp-stock-sync-from-csv' ),
            'weekly'           => __( 'Weekly', 'wp-stock-sync-from-csv' ),
            'wssfc_custom'     => __( 'Custom Interval', 'wp-stock-sync-from-csv' ),
        );
    }

    /**
     * Get schedule display name
     *
     * @param string $schedule Schedule key.
     * @return string
     */
    public static function get_schedule_display_name( $schedule ) {
        $options = self::get_schedule_options();
        return $options[ $schedule ] ?? $schedule;
    }

    /**
     * Get sync statistics for dashboard
     *
     * @return array
     */
    public function get_sync_stats() {
        $last_run = $this->get_last_run();
        $next_run = $this->get_next_run();
        $settings = get_option( 'wssfc_settings', array() );

        return array(
            'enabled'         => ! empty( $settings['enabled'] ),
            'schedule'        => $settings['schedule'] ?? 'hourly',
            'schedule_label'  => self::get_schedule_display_name( $settings['schedule'] ?? 'hourly' ),
            'last_run_time'   => $last_run['time'] ?? null,
            'last_run_status' => $last_run['status'] ?? null,
            'last_run_count'  => $last_run['products_synced'] ?? 0,
            'next_run_time'   => $next_run,
            'is_running'      => $this->is_running(),
        );
    }
}
