# WP Stock Sync From CSV

Automatically sync WooCommerce product stock levels from a remote CSV file.

## Description

WP Stock Sync From CSV is a WordPress plugin that periodically synchronizes your WooCommerce product stock levels from an external CSV file. Simply configure the CSV URL and column mappings, and the plugin will automatically keep your stock levels up to date.

## Features

- **Remote CSV Support**: Fetch stock data from any publicly accessible CSV URL
- **Configurable Column Mapping**: Set custom header names for SKU and quantity columns
- **Flexible Scheduling**: Choose from multiple sync intervals (5 minutes to weekly) or set a custom interval
- **Manual Sync**: Run synchronization manually at any time
- **Connection Testing**: Test your CSV connection before enabling automatic sync
- **Detailed Logging**: Track all sync operations with comprehensive logs
- **WooCommerce Integration**: Seamlessly integrates with WooCommerce stock management

## Installation

1. Upload the `wp-stock-sync-from-csv` folder to `/wp-content/plugins/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to 'Stock Sync CSV' in the admin menu to configure settings

## Configuration

### CSV Source Settings

- **CSV URL**: The full URL to your CSV file containing stock data
- **SKU Column Header**: The header name of the SKU column in your CSV (default: "sku")
- **Quantity Column Header**: The header name of the quantity column in your CSV (default: "quantity")

### CSV File Format

Your CSV file should have at least two columns:
- One column containing product SKUs
- One column containing stock quantities

Example:
```csv
sku,quantity
PROD-001,50
PROD-002,25
PROD-003,100
```

The column headers can be customized in the settings page.

### Sync Schedule

Choose how often the stock should be synchronized:
- Every 5 minutes
- Every 15 minutes
- Every 30 minutes
- Hourly
- Twice daily
- Daily
- Weekly
- Custom interval (set your own minutes)

## Requirements

- WordPress 5.8 or higher
- WooCommerce 5.0 or higher
- PHP 7.4 or higher

## Changelog

### 1.0.0
- Initial release

## License

GPL v2 or later
