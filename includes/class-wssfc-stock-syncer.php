<?php
/**
 * Stock Syncer class for WP Stock Sync From CSV
 *
 * @package WP_Stock_Sync_From_CSV
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Stock Syncer class
 */
class WSSFC_Stock_Syncer {

    /**
     * Logger instance
     *
     * @var WSSFC_Logger
     */
    private $logger;

    /**
     * Constructor
     *
     * @param WSSFC_Logger $logger Logger instance.
     */
    public function __construct( $logger ) {
        $this->logger = $logger;
    }

    /**
     * Sync stock from CSV
     *
     * @return array Result array.
     */
    public function sync() {
        $settings = get_option( 'wssfc_settings', array() );

        $csv_url         = $settings['csv_url'] ?? '';
        $sku_column      = $settings['sku_column'] ?? 'sku';
        $quantity_column = $settings['quantity_column'] ?? 'quantity';

        if ( empty( $csv_url ) ) {
            $this->logger->error( __( 'CSV URL is not configured.', 'wp-stock-sync-from-csv' ) );
            return array(
                'success' => false,
                'message' => __( 'CSV URL is not configured.', 'wp-stock-sync-from-csv' ),
            );
        }

        // Fetch CSV content
        $this->logger->info( sprintf( __( 'Fetching CSV from: %s', 'wp-stock-sync-from-csv' ), $csv_url ) );
        
        $response = wp_remote_get( $csv_url, array(
            'timeout'   => 60,
            'sslverify' => apply_filters( 'wssfc_ssl_verify', true ),
        ) );

        if ( is_wp_error( $response ) ) {
            $error_message = sprintf(
                __( 'Failed to fetch CSV: %s', 'wp-stock-sync-from-csv' ),
                $response->get_error_message()
            );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            $error_message = sprintf(
                __( 'Failed to fetch CSV. HTTP response code: %d', 'wp-stock-sync-from-csv' ),
                $response_code
            );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        $csv_content = wp_remote_retrieve_body( $response );

        if ( empty( $csv_content ) ) {
            $error_message = __( 'CSV content is empty.', 'wp-stock-sync-from-csv' );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        $this->logger->info( __( 'CSV fetched successfully. Parsing content...', 'wp-stock-sync-from-csv' ) );

        // Parse CSV
        $csv_data = $this->parse_csv( $csv_content );

        if ( empty( $csv_data ) ) {
            $error_message = __( 'Failed to parse CSV or CSV is empty.', 'wp-stock-sync-from-csv' );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        // Get headers
        $headers = array_shift( $csv_data );
        $headers = array_map( 'trim', $headers );
        $headers = array_map( 'strtolower', $headers );

        // Find column indexes
        $sku_index      = array_search( strtolower( $sku_column ), $headers );
        $quantity_index = array_search( strtolower( $quantity_column ), $headers );

        if ( $sku_index === false ) {
            $error_message = sprintf(
                __( 'SKU column "%s" not found in CSV. Available columns: %s', 'wp-stock-sync-from-csv' ),
                $sku_column,
                implode( ', ', $headers )
            );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        if ( $quantity_index === false ) {
            $error_message = sprintf(
                __( 'Quantity column "%s" not found in CSV. Available columns: %s', 'wp-stock-sync-from-csv' ),
                $quantity_column,
                implode( ', ', $headers )
            );
            $this->logger->error( $error_message );
            return array(
                'success' => false,
                'message' => $error_message,
            );
        }

        $this->logger->info( sprintf(
            __( 'Found columns - SKU: "%s" (index %d), Quantity: "%s" (index %d)', 'wp-stock-sync-from-csv' ),
            $sku_column,
            $sku_index,
            $quantity_column,
            $quantity_index
        ) );

        // Process each row
        $updated_count  = 0;
        $skipped_count  = 0;
        $not_found_count = 0;
        $error_count    = 0;
        $total_rows     = count( $csv_data );

        $this->logger->info( sprintf( __( 'Processing %d rows from CSV...', 'wp-stock-sync-from-csv' ), $total_rows ) );

        foreach ( $csv_data as $row_index => $row ) {
            // Skip empty rows
            if ( empty( $row ) || ! isset( $row[ $sku_index ] ) ) {
                $skipped_count++;
                continue;
            }

            $sku      = trim( $row[ $sku_index ] );
            $quantity = isset( $row[ $quantity_index ] ) ? trim( $row[ $quantity_index ] ) : '';

            // Skip rows with empty SKU
            if ( empty( $sku ) ) {
                $skipped_count++;
                continue;
            }

            // Validate quantity
            if ( $quantity === '' || ! is_numeric( $quantity ) ) {
                $this->logger->warning( sprintf(
                    __( 'Invalid quantity "%s" for SKU "%s". Skipping.', 'wp-stock-sync-from-csv' ),
                    $quantity,
                    $sku
                ) );
                $skipped_count++;
                continue;
            }

            $quantity = intval( $quantity );

            // Find product by SKU
            $product_id = wc_get_product_id_by_sku( $sku );

            if ( ! $product_id ) {
                $this->logger->warning( sprintf(
                    __( 'Product not found for SKU: %s', 'wp-stock-sync-from-csv' ),
                    $sku
                ) );
                $not_found_count++;
                continue;
            }

            // Get product
            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                $this->logger->warning( sprintf(
                    __( 'Could not load product for ID: %d (SKU: %s)', 'wp-stock-sync-from-csv' ),
                    $product_id,
                    $sku
                ) );
                $error_count++;
                continue;
            }

            // Get current stock
            $current_stock = $product->get_stock_quantity();

            // Skip if stock is already the same
            if ( $current_stock === $quantity ) {
                $skipped_count++;
                continue;
            }

            // Update stock
            try {
                // Enable stock management if not already
                if ( ! $product->get_manage_stock() ) {
                    $product->set_manage_stock( true );
                }

                $product->set_stock_quantity( $quantity );
                $product->save();

                $this->logger->info( sprintf(
                    __( 'Updated stock for SKU "%s" (ID: %d): %s → %d', 'wp-stock-sync-from-csv' ),
                    $sku,
                    $product_id,
                    $current_stock !== null ? $current_stock : 'null',
                    $quantity
                ) );

                $updated_count++;

            } catch ( Exception $e ) {
                $this->logger->error( sprintf(
                    __( 'Failed to update stock for SKU "%s": %s', 'wp-stock-sync-from-csv' ),
                    $sku,
                    $e->getMessage()
                ) );
                $error_count++;
            }
        }

        // Summary
        $stats = array(
            'total_rows'      => $total_rows,
            'updated_count'   => $updated_count,
            'skipped_count'   => $skipped_count,
            'not_found_count' => $not_found_count,
            'error_count'     => $error_count,
        );

        $this->logger->info( sprintf(
            __( 'Sync summary - Total: %d, Updated: %d, Skipped: %d, Not found: %d, Errors: %d', 'wp-stock-sync-from-csv' ),
            $total_rows,
            $updated_count,
            $skipped_count,
            $not_found_count,
            $error_count
        ), $stats );

        $message = sprintf(
            __( 'Stock sync completed. Updated: %d products, Skipped: %d, Not found: %d, Errors: %d', 'wp-stock-sync-from-csv' ),
            $updated_count,
            $skipped_count,
            $not_found_count,
            $error_count
        );

        return array(
            'success' => true,
            'message' => $message,
            'stats'   => $stats,
        );
    }

    /**
     * Parse CSV content
     *
     * @param string $content CSV content.
     * @return array Parsed CSV data.
     */
    private function parse_csv( $content ) {
        $data = array();
        
        // Handle different line endings
        $content = str_replace( array( "\r\n", "\r" ), "\n", $content );
        
        // Split into lines
        $lines = explode( "\n", $content );

        foreach ( $lines as $line ) {
            $line = trim( $line );
            
            if ( empty( $line ) ) {
                continue;
            }

            // Parse CSV line
            $row = str_getcsv( $line );
            
            if ( ! empty( $row ) ) {
                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * Test CSV connection
     *
     * @return array Result array.
     */
    public function test_connection() {
        $settings = get_option( 'wssfc_settings', array() );

        $csv_url         = $settings['csv_url'] ?? '';
        $sku_column      = $settings['sku_column'] ?? 'sku';
        $quantity_column = $settings['quantity_column'] ?? 'quantity';

        if ( empty( $csv_url ) ) {
            return array(
                'success' => false,
                'message' => __( 'CSV URL is not configured.', 'wp-stock-sync-from-csv' ),
            );
        }

        // Fetch CSV content
        $response = wp_remote_get( $csv_url, array(
            'timeout'   => 30,
            'sslverify' => apply_filters( 'wssfc_ssl_verify', true ),
        ) );

        if ( is_wp_error( $response ) ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Failed to connect: %s', 'wp-stock-sync-from-csv' ),
                    $response->get_error_message()
                ),
            );
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        if ( $response_code !== 200 ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'HTTP Error: %d', 'wp-stock-sync-from-csv' ),
                    $response_code
                ),
            );
        }

        $csv_content = wp_remote_retrieve_body( $response );

        if ( empty( $csv_content ) ) {
            return array(
                'success' => false,
                'message' => __( 'CSV content is empty.', 'wp-stock-sync-from-csv' ),
            );
        }

        // Parse CSV to validate
        $csv_data = $this->parse_csv( $csv_content );

        if ( empty( $csv_data ) ) {
            return array(
                'success' => false,
                'message' => __( 'Failed to parse CSV or CSV is empty.', 'wp-stock-sync-from-csv' ),
            );
        }

        // Get headers
        $headers = $csv_data[0];
        $headers = array_map( 'trim', $headers );
        $headers_lower = array_map( 'strtolower', $headers );

        // Check columns
        $sku_found      = in_array( strtolower( $sku_column ), $headers_lower );
        $quantity_found = in_array( strtolower( $quantity_column ), $headers_lower );

        $row_count = count( $csv_data ) - 1; // Exclude header

        if ( ! $sku_found && ! $quantity_found ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Neither SKU column "%s" nor Quantity column "%s" found. Available columns: %s', 'wp-stock-sync-from-csv' ),
                    $sku_column,
                    $quantity_column,
                    implode( ', ', $headers )
                ),
            );
        }

        if ( ! $sku_found ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'SKU column "%s" not found. Available columns: %s', 'wp-stock-sync-from-csv' ),
                    $sku_column,
                    implode( ', ', $headers )
                ),
            );
        }

        if ( ! $quantity_found ) {
            return array(
                'success' => false,
                'message' => sprintf(
                    __( 'Quantity column "%s" not found. Available columns: %s', 'wp-stock-sync-from-csv' ),
                    $quantity_column,
                    implode( ', ', $headers )
                ),
            );
        }

        return array(
            'success' => true,
            'message' => sprintf(
                __( 'Connection successful! Found %d rows with columns: %s', 'wp-stock-sync-from-csv' ),
                $row_count,
                implode( ', ', $headers )
            ),
        );
    }
}
