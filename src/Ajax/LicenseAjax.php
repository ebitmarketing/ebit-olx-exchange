<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\License\LicenseClient;
use EbitOlx\License\FeatureManager;

/**
 * AJAX endpoint-i za upravljanje licencom:
 *  - Snimanje licencnih kredencijala
 *  - Validacija / osvježavanje licence
 *  - Dohvaćanje statusa funkcionalnosti
 */
class LicenseAjax extends AjaxHandler {

    private LicenseClient $license;
    private FeatureManager $features;
    private ServerClient $api;

    public function __construct( LicenseClient $license, FeatureManager $features, ServerClient $api ) {
        $this->license  = $license;
        $this->features = $features;
        $this->api      = $api;
    }

    public function register(): void {
        $this->action( 'drtechno_save_license',     'saveLicense' );
        $this->action( 'drtechno_validate_license',  'validateLicense' );
        $this->action( 'drtechno_get_features',      'getFeatures' );
        $this->action( 'drtechno_test_connection',   'testConnection' );
        $this->action( 'drtechno_dashboard_stats',   'dashboardStats' );
    }

    /**
     * Snimi licencni ključ i server URL.
     */
    public function saveLicense(): void {
        $this->verify();

        $license_key = isset( $_POST['license_key'] ) ? sanitize_text_field( $_POST['license_key'] ) : '';
        $server_url  = isset( $_POST['server_url'] ) ? esc_url_raw( $_POST['server_url'] ) : '';

        if ( empty( $license_key ) || empty( $server_url ) ) {
            wp_send_json_error( 'Molimo unesite licencni ključ i server URL.' );
        }

        $this->license->saveCredentials( $license_key, $server_url );

        $result = $this->features->refresh();

        if ( $result['valid'] ) {
            update_option( 'drtechno_olx_license_features', $result['features'] ?? [] );
            wp_send_json_success( [
                'message'  => 'Licenca uspješno aktivirana! Plan: ' . strtoupper( $result['plan'] ),
                'plan'     => $result['plan'],
                'features' => $result['features'],
            ] );
        } else {
            wp_send_json_error( $result['reason'] ?? 'Licenca nije validna.' );
        }
    }

    /**
     * Validiraj trenutnu licencu (forsira osvježavanje sa servera).
     */
    public function validateLicense(): void {
        $this->verify();

        $result = $this->features->refresh();

        if ( $result['valid'] ) {
            update_option( 'drtechno_olx_license_features', $result['features'] ?? [] );
            wp_send_json_success( [
                'plan'     => $result['plan'],
                'status'   => $result['status'],
                'expires'  => $result['expires_at'],
                'features' => $result['features'],
                'quota'    => $this->features->getQuota(),
            ] );
        } else {
            wp_send_json_error( $result['reason'] ?? 'Licenca nije validna.' );
        }
    }

    /**
     * Dohvati feature flagove za trenutni plan (iz keša).
     */
    public function getFeatures(): void {
        $this->verify();

        wp_send_json_success( [
            'valid'    => $this->features->isLicenseValid(),
            'plan'     => $this->features->getPlan(),
            'features' => $this->features->getFeatures(),
        ] );
    }

    /**
     * Testiraj konekciju sa serverom (ping).
     */
    public function testConnection(): void {
        $this->verify();

        $resp = $this->api->request( 'ping' );

        if ( $resp['error'] ) {
            wp_send_json_error( $resp['message'] ?? 'Server nedostupan.' );
        }

        wp_send_json_success( $resp['data'] ?? [] );
    }

    /**
     * Dashboard pregled — license + live OLX (5min cache) + lokalni WP brojači.
     * POST: force=1 → invalidira oba kesha.
     */
    public function dashboardStats(): void {
        $this->verify();

        $force = ! empty( $_POST['force'] );

        $licenseData = $this->license->validate( $force );

        $olx_cache_key = 'drtechno_olx_dashboard_live';
        if ( $force ) {
            delete_transient( $olx_cache_key );
        }

        $olx = get_transient( $olx_cache_key );
        if ( $olx === false ) {
            $credits        = $this->api->request( 'credits/balance' );
            $limits         = $this->api->request( 'refresh_limits' );
            $listing_limits = $this->api->request( 'listing_limits' );
            $active         = $this->api->request( 'user_listings/active_count' );

            $cat_limits = [];
            if ( empty( $listing_limits['error'] ) && is_array( $listing_limits['data'] ?? null ) ) {
                foreach ( $listing_limits['data'] as $cat_key => $row ) {
                    if ( ! is_array( $row ) ) continue;
                    $cat_limits[ $cat_key ] = [
                        'limit'    => (int) ( $row['limit'] ?? 0 ),
                        'listings' => (int) ( $row['listings'] ?? 0 ),
                    ];
                }
            }

            $listing_count = null;
            if ( empty( $active['error'] ) ) {
                $listing_count = (int) ( $active['data']['total'] ?? 0 );
            } elseif ( empty( $limits['error'] ) ) {
                $listing_count = (int) ( $limits['data']['listing_count'] ?? 0 );
            }

            $olx = [
                'credits'       => empty( $credits['error'] ) ? (int) ( $credits['data']['credits'] ?? 0 ) : null,
                'listing_count' => $listing_count,
                'free_limit'    => empty( $limits['error'] )  ? (int) ( $limits['data']['free_limit'] ?? 0 ) : 0,
                'free_count'    => empty( $limits['error'] )  ? (int) ( $limits['data']['free_count'] ?? 0 ) : 0,
                'paid_count'    => empty( $limits['error'] )  ? (int) ( $limits['data']['paid_count'] ?? 0 ) : 0,
                'cat_limits'    => $cat_limits,
                'fetched_at'    => time(),
            ];
            set_transient( $olx_cache_key, $olx, 5 * MINUTE_IN_SECONDS );
        }

        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT pm.meta_value AS status, COUNT(DISTINCT p.ID) AS cnt
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_olx_status'
             WHERE p.post_type = 'product' AND p.post_status = 'publish'
             GROUP BY pm.meta_value",
            ARRAY_A
        );
        $local = [ 'synced' => 0, 'error' => 0, 'hidden' => 0, 'other' => 0 ];
        foreach ( (array) $rows as $r ) {
            $s = $r['status'] ?? '';
            if ( $s === 'synced' ) {
                $local['synced'] = (int) $r['cnt'];
            } elseif ( $s === 'error' ) {
                $local['error'] = (int) $r['cnt'];
            } elseif ( $s === 'hidden' ) {
                $local['hidden'] = (int) $r['cnt'];
            } else {
                $local['other'] += (int) $r['cnt'];
            }
        }
        $queue_table   = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $local['queue'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$queue_table}" );

        wp_send_json_success( [
            'license' => $licenseData,
            'olx'     => $olx,
            'local'   => $local,
        ] );
    }
}
