<?php
/**
 * Plugin Name: EBIT OLX eXchange
 * Description: Sinhronizacija WooCommerce artikala sa OLX.ba putem EBIT Sync servisa. Smart Image Cache. Dinamički Bedž. VIP artikli. Sponzoriranje. Masovni BUMP.
 * Version: 3.0.0
 * Author: Denis Nurboja
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Plugin constants
define( 'EBIT_OLX_VERSION', '3.0.0' );
define( 'EBIT_OLX_FILE', __FILE__ );
define( 'EBIT_OLX_PATH', plugin_dir_path( __FILE__ ) );
define( 'EBIT_OLX_URL', plugin_dir_url( __FILE__ ) );
// Backward compat aliases for PSR-4 modules copied from drtechno-olx-sync
if ( ! defined( 'DRTECHNO_OLX_PATH' ) ) {
    define( 'DRTECHNO_OLX_PATH', EBIT_OLX_PATH );
}
if ( ! defined( 'DRTECHNO_OLX_URL' ) ) {
    define( 'DRTECHNO_OLX_URL', EBIT_OLX_URL );
}
if ( ! defined( 'DRTECHNO_OLX_VERSION' ) ) {
    define( 'DRTECHNO_OLX_VERSION', EBIT_OLX_VERSION );
}

// PSR-4 autoloader: Composer if available, fallback spl_autoload for production
if ( file_exists( EBIT_OLX_PATH . 'vendor/autoload.php' ) ) {
    require_once EBIT_OLX_PATH . 'vendor/autoload.php';
} elseif ( is_dir( EBIT_OLX_PATH . 'src' ) ) {
    spl_autoload_register( function ( $class ) {
        if ( strpos( $class, 'EbitOlx\\' ) !== 0 ) return;
        $rel  = str_replace( [ 'EbitOlx\\', '\\' ], [ '', DIRECTORY_SEPARATOR ], $class );
        $file = EBIT_OLX_PATH . 'src' . DIRECTORY_SEPARATOR . $rel . '.php';
        if ( file_exists( $file ) ) require_once $file;
    } );
}
if ( is_dir( EBIT_OLX_PATH . 'src' ) ) {
    EbitOlx\Plugin::getInstance()->boot();
}

/**
 * Legacy thin shell — drži samo:
 *  - Server proxy (`server_request`) jer ga zovu admin-page.php i tab-sponsor.php
 *  - Lokalna AES-CBC enkripcija lozinke (zovu je admin-page.php i OlxApiClient)
 *  - Admin menu / settings / asset enqueue
 *  - Activation tabela queue
 *  - admin_page_html entrypoint (require includes/admin-page.php)
 *  - License refresh AJAX (još uvijek koristi monolitsku rutu)
 *  - Deaktivacija — clean cron events
 *
 * Sve AJAX, cron, bulk i metabox logike su preseljene u PSR-4 module pod `src/`.
 */
class EBIT_OLX_Exchange {

    private $server_url;
    private $table_prod_queue;

    public function __construct() {
        global $wpdb;
        $this->table_prod_queue = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $this->server_url       = get_option( 'drtechno_olx_server_url', '' );

        add_action( 'admin_menu',           array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init',           array( $this, 'register_settings' ) );
        add_action( 'admin_init',           array( $this, 'check_and_create_tables' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

        add_action( 'wp_ajax_drtechno_refresh_license_monolithic', array( $this, 'ajax_refresh_license_monolithic' ) );
    }

    // =========================================
    // KOMUNIKACIJA SA SERVEROM (proxy za admin-page.php / tab-sponsor.php)
    // PSR-4 kod koristi EbitOlx\Api\ServerClient.
    // =========================================
    private function server_request( $action, $data = [], $timeout_val = 15 ) {
        if ( empty( $this->server_url ) ) {
            return [ 'error' => true, 'message' => 'Server URL nije podešen. Unesite ga u Postavkama (Tab 1).' ];
        }
        $license_key = get_option( 'drtechno_olx_license_key', '' );
        if ( empty( $license_key ) && $action !== 'ping' ) {
            return [ 'error' => true, 'message' => 'Licencni ključ nije podešen. Unesite ga u Postavkama.' ];
        }
        $olx_token = get_option( 'drtechno_olx_api_token', '' );
        if ( ! empty( $olx_token ) ) {
            $data['olx_token'] = $olx_token;
        }
        $response = wp_remote_post( $this->server_url, [
            'body'      => wp_json_encode( [ 'action' => $action, 'license_key' => $license_key, 'site_url' => get_site_url(), 'data' => $data ] ),
            'headers'   => [ 'Content-Type' => 'application/json' ],
            'timeout'   => $timeout_val,
            'sslverify' => true, // Eksplicitno — TLS verifikacija se nikad ne smije gasiti.
        ] );
        if ( is_wp_error( $response ) ) return [ 'error' => true, 'message' => $response->get_error_message() ];
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! $decoded ) return [ 'error' => true, 'message' => 'Neispravan odgovor servera.' ];

        // 401 → poruka korisniku, bez auto-retry (lozinka se više ne čuva lokalno).
        $code = wp_remote_retrieve_response_code( $response );
        if ( $code === 401 ) {
            delete_option( 'drtechno_olx_api_token' );
            return [
                'error'    => true,
                'message'  => 'OLX sesija je istekla. Idite na Postavke → OLX prijava i ponovo se ulogujte.',
                'data'     => null,
                'raw_data' => $decoded,
            ];
        }

        return $decoded;
    }

    // =========================================
    // Napomena: OLX lozinka se NE čuva lokalno (Phase 1+). Stari
    // encrypt_olx_password/decrypt_olx_password helperi su uklonjeni.
    // =========================================

    // =========================================
    // INFRASTRUKTURA (admin menu, settings, assets, table create)
    // =========================================
    public function check_and_create_tables() {
        if ( get_option( 'drtechno_olx_db_version' ) !== '2.0' ) {
            global $wpdb;
            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            $c = $wpdb->get_charset_collate();
            dbDelta( "CREATE TABLE {$this->table_prod_queue} (id bigint(20) NOT NULL AUTO_INCREMENT, post_id bigint(20) NOT NULL, retries int(11) DEFAULT 0, PRIMARY KEY (id), UNIQUE KEY post_id (post_id)) $c;" );
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drtechno_olx_categories" );
            $wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}drtechno_olx_queue" );
            update_option( 'drtechno_olx_db_version', '2.0' );
        }
    }

    /**
     * Dohvati OLX kategorije iz transient keša (koristi admin-page.php).
     */
    public function get_server_categories() {
        $cached = get_transient( 'drtechno_olx_server_cats' );
        return is_array( $cached ) ? $cached : [];
    }

    public function add_admin_menu() {
        add_menu_page( 'EBIT OLX eXchange', 'OLX eXchange', 'manage_options', 'drtechno_olx_sync', array( $this, 'admin_page_html' ), 'dashicons-update', 56 );
    }

    public function register_settings() {
        $s = [ 'drtechno_olx_server_url', 'drtechno_olx_license_key', 'olx_username', 'olx_api_token', 'olx_user_id', 'olx_shop_username', 'olx_account_type', 'drtechno_olx_category_mapping', 'drtechno_olx_brand_mapping', 'drtechno_olx_available_brands', 'drtechno_olx_attribute_mapping', 'drtechno_olx_available_attributes', 'drtechno_olx_category_attributes', 'drtechno_olx_countries_data', 'drtechno_olx_cities_data', 'olx_country_id', 'olx_city_id', 'drtechno_olx_sync_instock_only', 'drtechno_olx_global_prefix', 'drtechno_olx_global_description', 'drtechno_olx_enable_auto_sync', 'drtechno_olx_cron_batch_size', 'drtechno_olx_enable_hide_unhide', 'drtechno_olx_enable_duplicate_check', 'drtechno_olx_enable_daily_populator', 'drtechno_olx_daily_populator_time' ];
        foreach ( $s as $setting ) register_setting( 'drtechno_olx_settings', $setting );
    }

    public function enqueue_admin_scripts( $hook ) {
        if ( $hook === 'toplevel_page_drtechno_olx_sync' || $hook === 'post.php' || $hook === 'post-new.php' ) {
            wp_enqueue_style( 'olx-select2-css', plugins_url( 'assets/css/select2.min.css', __FILE__ ) );
            wp_enqueue_script( 'olx-select2-js', plugins_url( 'assets/js/select2.min.js', __FILE__ ), [ 'jquery' ], '4.1.0', true );
            wp_register_script( 'drtechno-olx-js', false );
            wp_enqueue_script( 'drtechno-olx-js' );
            wp_localize_script( 'drtechno-olx-js', 'olx_sync_vars', [ 'ajaxurl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'olx_sync_nonce' ) ] );
        }
    }

    // =========================================
    // AJAX: refresh licence (legacy ruta, JS još uvijek poziva drtechno_refresh_license_monolithic)
    // =========================================
    private function ajax_check() {
        check_ajax_referer( 'olx_sync_nonce', 'nonce' );
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'Nedozvoljen pristup.' );
    }

    public function ajax_refresh_license_monolithic() {
        $this->ajax_check();
        $check = $this->server_request( 'license/validate' );
        if ( ! $check['error'] ) {
            update_option( 'drtechno_olx_license_details', $check['data'] ?? [] );
            if ( isset( $check['data']['features'] ) ) {
                update_option( 'drtechno_olx_license_features', $check['data']['features'] );
                update_option( 'drtechno_olx_feat_mass_sync', ! empty( $check['data']['features']['mass_sync'] ) ? 1 : 0 );
            }
            wp_send_json_success( 'Licenca uspesno osvjezena.' );
        }
        wp_send_json_error( $check['message'] ?? 'Greska pri osvjezavanju.' );
    }

    // admin_page_html je entrypoint za uključeni includes/admin-page.php
    public function admin_page_html() { require __DIR__ . '/includes/admin-page.php'; }

    // =========================================
    // Deaktivacija — clean cron events
    // =========================================
    public static function on_deactivation() {
        $timestamp = wp_next_scheduled( 'drtechno_olx_batch_worker_event' );
        if ( $timestamp ) wp_unschedule_event( $timestamp, 'drtechno_olx_batch_worker_event' );
        $ts2 = wp_next_scheduled( 'drtechno_olx_daily_populator_event' );
        if ( $ts2 ) wp_unschedule_event( $ts2, 'drtechno_olx_daily_populator_event' );
        $ts3 = wp_next_scheduled( 'drtechno_olx_sponsor_worker_event' );
        if ( $ts3 ) wp_unschedule_event( $ts3, 'drtechno_olx_sponsor_worker_event' );
    }
}

register_deactivation_hook( __FILE__, [ 'EBIT_OLX_Exchange', 'on_deactivation' ] );

new EBIT_OLX_Exchange();
