<?php
/**
 * GitHub Plugin Updater
 *
 * Enables automatic updates from GitHub releases.
 *
 * @package WP_Stock_Sync_From_CSV
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class WSSFC_GitHub_Updater
 *
 * Handles checking for updates from GitHub and enabling WordPress native update functionality.
 */
class WSSFC_GitHub_Updater {

    /**
     * GitHub repository owner/name
     *
     * @var string
     */
    private $repo = 'JonakyDS/wp-stock-sync-from-csv';

    /**
     * Plugin slug
     *
     * @var string
     */
    private $slug;

    /**
     * Plugin basename
     *
     * @var string
     */
    private $basename;

    /**
     * Current plugin version
     *
     * @var string
     */
    private $version;

    /**
     * GitHub API response cache key
     *
     * @var string
     */
    private $cache_key = 'wssfc_github_update_check';

    /**
     * Cache duration in seconds (6 hours)
     *
     * @var int
     */
    private $cache_duration = 21600;

    /**
     * Constructor
     */
    public function __construct() {
        $this->slug     = 'wp-stock-sync-from-csv';
        $this->basename = WSSFC_PLUGIN_BASENAME;
        $this->version  = WSSFC_VERSION;

        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check for updates
        add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'check_for_update' ) );

        // Plugin information popup
        add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );

        // After update, clear cache
        add_action( 'upgrader_process_complete', array( $this, 'clear_cache' ), 10, 2 );

        // Add plugin action links
        add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );

        // Rename the downloaded folder to match plugin slug
        add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );

        // Enable auto-updates UI for this plugin
        add_filter( 'plugins_auto_update_enabled', '__return_true' );
        
        // Add plugin to the list of auto-updatable plugins
        add_filter( 'auto_update_plugin', array( $this, 'auto_update_plugin' ), 10, 2 );
        
        // Inject update info into transient so auto-update UI shows
        add_filter( 'site_transient_update_plugins', array( $this, 'inject_update_info' ) );
    }

    /**
     * Allow auto-updates for this plugin
     *
     * @param bool|null $update Whether to update.
     * @param object    $item   The update offer.
     * @return bool|null
     */
    public function auto_update_plugin( $update, $item ) {
        if ( isset( $item->slug ) && $this->slug === $item->slug ) {
            // Check if user has enabled auto-updates for this plugin
            $auto_updates = (array) get_site_option( 'auto_update_plugins', array() );
            if ( in_array( $this->basename, $auto_updates, true ) ) {
                return true;
            }
        }
        return $update;
    }

    /**
     * Inject update info into the update_plugins transient
     * This ensures the auto-update UI is displayed
     *
     * @param object $transient The update_plugins transient.
     * @return object
     */
    public function inject_update_info( $transient ) {
        if ( ! is_object( $transient ) ) {
            $transient = new stdClass();
        }

        // Ensure no_update array exists and our plugin is in it (for auto-update UI)
        if ( ! isset( $transient->no_update ) ) {
            $transient->no_update = array();
        }

        // If plugin is not in response (no update available), add to no_update for auto-update UI
        if ( ! isset( $transient->response[ $this->basename ] ) && ! isset( $transient->no_update[ $this->basename ] ) ) {
            $transient->no_update[ $this->basename ] = (object) array(
                'id'            => "github.com/{$this->repo}",
                'slug'          => $this->slug,
                'plugin'        => $this->basename,
                'new_version'   => $this->version,
                'url'           => "https://github.com/{$this->repo}",
                'package'       => '',
            );
        }

        return $transient;
    }

    /**
     * Check GitHub for updates
     *
     * @param object $transient Update transient.
     * @return object Modified transient.
     */
    public function check_for_update( $transient ) {
        if ( empty( $transient->checked ) ) {
            return $transient;
        }

        $release = $this->get_latest_release();

        if ( $release && isset( $release->tag_name ) ) {
            $latest_version = ltrim( $release->tag_name, 'v' );

            if ( version_compare( $this->version, $latest_version, '<' ) ) {
                $download_url = $this->get_download_url( $release );

                if ( $download_url ) {
                    $transient->response[ $this->basename ] = (object) array(
                        'slug'        => $this->slug,
                        'plugin'      => $this->basename,
                        'new_version' => $latest_version,
                        'url'         => "https://github.com/{$this->repo}",
                        'package'     => $download_url,
                        'icons'       => array(),
                        'banners'     => array(),
                        'tested'      => get_bloginfo( 'version' ),
                        'requires'    => '5.8',
                        'requires_php'=> '7.4',
                    );
                }
            }
        }

        return $transient;
    }

    /**
     * Get the latest release from GitHub
     *
     * @return object|false Release object or false on failure.
     */
    private function get_latest_release() {
        $cached = get_transient( $this->cache_key );

        if ( false !== $cached ) {
            return $cached;
        }

        $response = wp_remote_get(
            "https://api.github.com/repos/{$this->repo}/releases/latest",
            array(
                'headers' => array(
                    'Accept'     => 'application/vnd.github.v3+json',
                    'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ),
                ),
                'timeout' => 10,
            )
        );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            // Cache failure for 1 hour to prevent hammering the API
            set_transient( $this->cache_key, false, HOUR_IN_SECONDS );
            return false;
        }

        $release = json_decode( wp_remote_retrieve_body( $response ) );

        if ( $release && isset( $release->tag_name ) ) {
            set_transient( $this->cache_key, $release, $this->cache_duration );
            return $release;
        }

        return false;
    }

    /**
     * Get download URL from release
     *
     * @param object $release Release object.
     * @return string|false Download URL or false.
     */
    private function get_download_url( $release ) {
        // First, try to find the plugin zip in release assets
        if ( ! empty( $release->assets ) ) {
            foreach ( $release->assets as $asset ) {
                if ( preg_match( '/wp-stock-sync-from-csv.*\.zip$/i', $asset->name ) ) {
                    return $asset->browser_download_url;
                }
            }
        }

        // Fallback to zipball URL
        if ( isset( $release->zipball_url ) ) {
            return $release->zipball_url;
        }

        return false;
    }

    /**
     * Plugin information popup
     *
     * @param false|object|array $result The result object or array.
     * @param string            $action The API action.
     * @param object            $args   Plugin API arguments.
     * @return false|object Plugin info or false.
     */
    public function plugin_info( $result, $action, $args ) {
        if ( 'plugin_information' !== $action || $this->slug !== $args->slug ) {
            return $result;
        }

        $release = $this->get_latest_release();

        if ( ! $release ) {
            return $result;
        }

        $latest_version = ltrim( $release->tag_name, 'v' );

        return (object) array(
            'name'              => 'WP Stock Sync From CSV',
            'slug'              => $this->slug,
            'version'           => $latest_version,
            'author'            => '<a href="https://jonakyds.com">Jonaky Adhikary</a>',
            'author_profile'    => 'https://jonakyds.com',
            'homepage'          => "https://github.com/{$this->repo}",
            'short_description' => 'Automatically syncs WooCommerce product stock levels from a remote CSV file.',
            'sections'          => array(
                'description'  => 'WP Stock Sync From CSV automatically syncs WooCommerce product stock levels from a remote CSV file on a scheduled basis.',
                'changelog'    => $this->parse_changelog( $release->body ?? '' ),
                'installation' => '<ol>
                    <li>Download the plugin zip file</li>
                    <li>Go to WordPress Admin → Plugins → Add New → Upload Plugin</li>
                    <li>Choose the downloaded zip file and click Install Now</li>
                    <li>Activate the plugin</li>
                </ol>',
            ),
            'download_link'     => $this->get_download_url( $release ),
            'requires'          => '5.8',
            'tested'            => get_bloginfo( 'version' ),
            'requires_php'      => '7.4',
            'last_updated'      => $release->published_at ?? '',
            'banners'           => array(),
        );
    }

    /**
     * Parse changelog from release body
     *
     * @param string $body Release body (markdown).
     * @return string HTML changelog.
     */
    private function parse_changelog( $body ) {
        if ( empty( $body ) ) {
            return '<p>No changelog available.</p>';
        }

        // Convert markdown to basic HTML
        $html = esc_html( $body );
        $html = nl2br( $html );
        $html = preg_replace( '/^- (.+)$/m', '<li>$1</li>', $html );
        $html = preg_replace( '/(<li>.*<\/li>)/s', '<ul>$1</ul>', $html );
        $html = preg_replace( '/## (.+)<br \/>/', '<h4>$1</h4>', $html );

        return $html;
    }

    /**
     * Fix source directory name after download
     *
     * GitHub zipball creates folder like "owner-repo-hash", we need to rename it.
     *
     * @param string      $source        File source location.
     * @param string      $remote_source Remote file source location.
     * @param WP_Upgrader $upgrader      WP_Upgrader instance.
     * @param array       $args          Extra arguments.
     * @return string|WP_Error Modified source or error.
     */
    public function fix_source_dir( $source, $remote_source, $upgrader, $args ) {
        global $wp_filesystem;

        // Only process our plugin
        if ( ! isset( $args['plugin'] ) || $args['plugin'] !== $this->basename ) {
            return $source;
        }

        $source_base = basename( $source );

        // Check if source needs to be renamed (GitHub zipball format)
        if ( strpos( $source_base, $this->slug ) === false ) {
            $new_source = trailingslashit( $remote_source ) . $this->slug . '/';

            if ( $wp_filesystem->move( $source, $new_source ) ) {
                return $new_source;
            }

            return new WP_Error(
                'rename_failed',
                __( 'Unable to rename the update folder.', 'wp-stock-sync-from-csv' )
            );
        }

        return $source;
    }

    /**
     * Add plugin row meta links
     *
     * @param array  $links Plugin meta links.
     * @param string $file  Plugin file.
     * @return array Modified links.
     */
    public function plugin_row_meta( $links, $file ) {
        if ( $this->basename === $file ) {
            $links[] = sprintf(
                '<a href="%s" target="_blank">%s</a>',
                "https://github.com/{$this->repo}",
                __( 'View on GitHub', 'wp-stock-sync-from-csv' )
            );
        }

        return $links;
    }

    /**
     * Clear update cache
     *
     * @param WP_Upgrader $upgrader WP_Upgrader instance.
     * @param array       $options  Update options.
     */
    public function clear_cache( $upgrader, $options ) {
        if ( 'update' === $options['action'] && 'plugin' === $options['type'] ) {
            delete_transient( $this->cache_key );
        }
    }

    /**
     * Force check for updates (can be called manually)
     *
     * @return object|false Latest release or false.
     */
    public function force_check() {
        delete_transient( $this->cache_key );
        return $this->get_latest_release();
    }
}
