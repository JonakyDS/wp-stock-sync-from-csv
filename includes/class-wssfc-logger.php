<?php
/**
 * Logger class for WP Stock Sync From CSV
 *
 * @package WP_Stock_Sync_From_CSV
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Logger class
 */
class WSSFC_Logger {

    /**
     * Log levels
     */
    const LEVEL_INFO    = 'info';
    const LEVEL_SUCCESS = 'success';
    const LEVEL_WARNING = 'warning';
    const LEVEL_ERROR   = 'error';

    /**
     * Database table name
     *
     * @var string
     */
    private $table_name;

    /**
     * Current run ID
     *
     * @var string
     */
    private $current_run_id = '';

    /**
     * Constructor
     */
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'wssfc_logs';
    }

    /**
     * Start a new sync run
     *
     * @param string $trigger How the sync was triggered (scheduled, manual).
     * @return string The run ID
     */
    public function start_run( $trigger = 'manual' ) {
        $this->current_run_id = $this->generate_run_id();
        
        $this->info(
            sprintf( __( 'Starting %s sync', 'wp-stock-sync-from-csv' ), $trigger ),
            array( 'trigger' => $trigger )
        );

        return $this->current_run_id;
    }

    /**
     * End the current sync run
     *
     * @param string $status Run status (success, failed).
     * @param array  $stats  Run statistics.
     */
    public function end_run( $status = 'success', $stats = array() ) {
        if ( 'success' === $status ) {
            $this->success( __( 'Sync completed successfully', 'wp-stock-sync-from-csv' ), $stats );
        } else {
            $this->error( __( 'Sync failed', 'wp-stock-sync-from-csv' ), $stats );
        }
        $this->current_run_id = '';
    }

    /**
     * Generate a unique run ID
     *
     * @return string
     */
    private function generate_run_id() {
        return sprintf(
            '%s-%s',
            date( 'Ymd-His' ),
            substr( md5( uniqid( '', true ) ), 0, 8 )
        );
    }

    /**
     * Set current run ID (for resuming)
     *
     * @param string $run_id Run ID.
     */
    public function set_run_id( $run_id ) {
        $this->current_run_id = $run_id;
    }

    /**
     * Get current run ID
     *
     * @return string
     */
    public function get_run_id() {
        return $this->current_run_id;
    }

    /**
     * Log an info message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function info( $message, $context = array() ) {
        $this->log( self::LEVEL_INFO, $message, $context );
    }

    /**
     * Log a success message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function success( $message, $context = array() ) {
        $this->log( self::LEVEL_SUCCESS, $message, $context );
    }

    /**
     * Log a warning message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function warning( $message, $context = array() ) {
        $this->log( self::LEVEL_WARNING, $message, $context );
    }

    /**
     * Log an error message
     *
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    public function error( $message, $context = array() ) {
        $this->log( self::LEVEL_ERROR, $message, $context );
    }

    /**
     * Write log entry to database
     *
     * @param string $level   Log level.
     * @param string $message Log message.
     * @param array  $context Additional context data.
     */
    private function log( $level, $message, $context = array() ) {
        global $wpdb;

        // Ensure table exists
        if ( ! $this->table_exists() ) {
            return;
        }

        // Prepare context
        $context_json = ! empty( $context ) ? wp_json_encode( $context, JSON_PRETTY_PRINT ) : '';

        // Insert log entry
        $wpdb->insert(
            $this->table_name,
            array(
                'run_id'    => $this->current_run_id,
                'timestamp' => current_time( 'mysql' ),
                'level'     => $level,
                'message'   => $message,
                'context'   => $context_json,
            ),
            array( '%s', '%s', '%s', '%s', '%s' )
        );

        // Also write to WordPress debug log if enabled
        if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
            $log_message = sprintf(
                '[WP Stock Sync From CSV] [%s] [%s] %s',
                $this->current_run_id ?: 'no-run',
                strtoupper( $level ),
                $message
            );

            if ( ! empty( $context ) ) {
                $log_message .= ' | Context: ' . wp_json_encode( $context );
            }

            error_log( $log_message );
        }

        // Cleanup old logs periodically
        $this->maybe_cleanup_old_logs();
    }

    /**
     * Check if table exists
     *
     * @return bool
     */
    public function table_exists() {
        global $wpdb;
        $result = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table_name ) );
        return $result === $this->table_name;
    }

    /**
     * Maybe cleanup old logs
     */
    private function maybe_cleanup_old_logs() {
        // Only run cleanup 1% of the time
        if ( wp_rand( 1, 100 ) !== 1 ) {
            return;
        }

        $this->cleanup_old_logs();
    }

    /**
     * Cleanup old logs (older than 30 days)
     */
    public function cleanup_old_logs() {
        global $wpdb;

        $days_to_keep = apply_filters( 'wssfc_logs_retention_days', 30 );
        
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$this->table_name} WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
                $days_to_keep
            )
        );
    }

    /**
     * Get logs for a specific run
     *
     * @param string $run_id Run ID.
     * @return array
     */
    public function get_logs_for_run( $run_id ) {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE run_id = %s ORDER BY timestamp ASC",
                $run_id
            )
        );
    }

    /**
     * Get sync runs with pagination
     *
     * @param int $per_page Number of runs per page.
     * @param int $offset   Offset for pagination.
     * @return array
     */
    public function get_sync_runs( $per_page = 10, $offset = 0 ) {
        global $wpdb;

        $query = "
            SELECT 
                run_id,
                MIN(timestamp) as started_at,
                MAX(timestamp) as ended_at,
                TIMESTAMPDIFF(SECOND, MIN(timestamp), MAX(timestamp)) as duration,
                COUNT(*) as log_count,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning_count,
                MAX(CASE WHEN level = 'success' AND message LIKE '%completed%' THEN context ELSE NULL END) as final_stats,
                MAX(CASE WHEN level = 'info' AND message LIKE '%Starting%' THEN 
                    JSON_UNQUOTE(JSON_EXTRACT(context, '$.trigger'))
                ELSE NULL END) as trigger_type,
                CASE 
                    WHEN SUM(CASE WHEN level = 'error' AND message LIKE '%failed%' THEN 1 ELSE 0 END) > 0 THEN 'failed'
                    WHEN SUM(CASE WHEN level = 'success' AND message LIKE '%completed%' THEN 1 ELSE 0 END) > 0 THEN 'success'
                    WHEN run_id = '' THEN 'orphan'
                    ELSE 'in-progress'
                END as status
            FROM {$this->table_name}
            GROUP BY run_id
            ORDER BY started_at DESC
            LIMIT %d OFFSET %d
        ";

        $results = $wpdb->get_results( $wpdb->prepare( $query, $per_page, $offset ) );

        // Decode final_stats JSON for each result
        foreach ( $results as &$run ) {
            if ( ! empty( $run->final_stats ) ) {
                $run->final_stats = json_decode( $run->final_stats, true );
            }
            // Map trigger_type to trigger for compatibility
            $run->trigger = $run->trigger_type ?: 'unknown';
        }

        return $results;
    }

    /**
     * Get total sync runs count
     *
     * @return int
     */
    public function get_sync_runs_count() {
        global $wpdb;

        return (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT run_id) FROM {$this->table_name}"
        );
    }

    /**
     * Get log counts by level
     *
     * @return array
     */
    public function get_log_counts() {
        global $wpdb;

        $counts = array(
            'runs'    => 0,
            'success' => 0,
            'warning' => 0,
            'error'   => 0,
        );

        // Get unique run count
        $counts['runs'] = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT run_id) FROM {$this->table_name} WHERE run_id != ''"
        );

        // Get counts by level
        $level_counts = $wpdb->get_results(
            "SELECT level, COUNT(*) as count FROM {$this->table_name} GROUP BY level",
            OBJECT_K
        );

        foreach ( $level_counts as $level => $data ) {
            if ( isset( $counts[ $level ] ) ) {
                $counts[ $level ] = (int) $data->count;
            }
        }

        return $counts;
    }

    /**
     * Clear all logs
     *
     * @return bool
     */
    public function clear_all_logs() {
        global $wpdb;
        return $wpdb->query( "TRUNCATE TABLE {$this->table_name}" ) !== false;
    }
}
