<?php

namespace EbitOlx\License;

defined( 'ABSPATH' ) || exit;

/**
 * Komunikacija sa SaaS serverom za validaciju licence.
 * Rezultati se kešuju u WP transient (1 sat).
 */
class LicenseClient {

    private const OPTION_KEY     = 'drtechno_olx_license_key';
    private const SERVER_URL_KEY = 'drtechno_olx_server_url';
    private const CACHE_KEY      = 'drtechno_olx_license_cache';
    private const CACHE_TTL      = 3600; // 1 sat

    /**
     * Validira licencu sa SaaS serverom. Vraća keširane podatke ako su svježi.
     *
     * @param bool $force_refresh Ignoriši keš
     * @return array{valid: bool, plan?: string, features?: array, expires_at?: string, status?: string, reason?: string}
     */
    public function validate( bool $force_refresh = false ): array {
        if ( ! $force_refresh ) {
            $cached = get_transient( self::CACHE_KEY );
            if ( $cached !== false ) {
                return $cached;
            }
        }

        $license_key = get_option( self::OPTION_KEY, '' );
        $server_url  = get_option( self::SERVER_URL_KEY, '' );

        if ( empty( $license_key ) || empty( $server_url ) ) {
            $result = [
                'valid'  => false,
                'reason' => 'Licencni ključ ili server URL nisu podešeni.',
            ];
            set_transient( self::CACHE_KEY, $result, 300 );
            return $result;
        }

        $response = wp_remote_post(
            rtrim( $server_url, '/' ) . '/api/v1/',
            [
                'timeout'   => 15,
                'sslverify' => true, // Eksplicitno — TLS verifikacija se nikad ne smije gasiti.
                'headers'   => [
                    'Content-Type'  => 'application/json',
                    'X-API-Version' => '1',
                ],
                'body'      => wp_json_encode( [
                    'action'      => 'license/validate',
                    'license_key' => $license_key,
                    'site_url'    => home_url(),
                    'data'        => new \stdClass(),
                ] ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            // Na grešku mreže, vrati stale keš ako postoji
            $stale = get_option( self::CACHE_KEY . '_last_valid' );
            if ( $stale ) {
                set_transient( self::CACHE_KEY, $stale, 300 );
                return $stale;
            }
            return [
                'valid'  => false,
                'reason' => 'Greška mreže: ' . $response->get_error_message(),
            ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 || (isset($body['error']) && $body['error'] === true) ) {
            $reason = $body['message'] ?? $body['data']['reason'] ?? 'Server greška (HTTP ' . $code . ')';
            $result = [ 'valid' => false, 'reason' => $reason ];
            set_transient( self::CACHE_KEY, $result, 300 );
            return $result;
        }

        $data = $body['data'] ?? [];

        $result = [
            'valid'               => true,
            'plan'                => $data['plan'] ?? 'starter',
            'max_products'        => (int) ( $data['max_products'] ?? 0 ),
            'expires_at'          => $data['expires_at'] ?? '',
            'sync_count'          => (int) ( $data['sync_count'] ?? 0 ),
            'article_count'       => (int) ( $data['article_count'] ?? 0 ),
            'daily_sync_count'    => (int) ( $data['daily_sync_count'] ?? 0 ),
            'status'              => $data['status'] ?? 'unknown',
            'olx_connected'       => ! empty( $data['olx_connected'] ),
            'shop_username'       => $data['shop_username'] ?? '',
            'features'            => $data['features'] ?? [],
            'max_daily_syncs'     => (int) ( $data['max_daily_syncs'] ?? 0 ),
            'quota_type'          => $data['quota_type'] ?? 'syncs',
            'daily_sync_reset_at' => $data['daily_sync_reset_at'] ?? '',
            'sync_count_reset_at' => $data['sync_count_reset_at'] ?? '',
            'server_time'         => $data['server_time'] ?? '',
        ];

        set_transient( self::CACHE_KEY, $result, self::CACHE_TTL );
        update_option( self::CACHE_KEY . '_last_valid', $result, false );
        update_option( 'drtechno_olx_license_features', $result['features'] );

        return $result;
    }

    /**
     * Dohvati licencni ključ iz opcija.
     */
    public function getLicenseKey(): string {
        return get_option( self::OPTION_KEY, '' );
    }

    /**
     * Sačuvaj licencni ključ i server URL.
     */
    public function saveCredentials( string $license_key, string $server_url ): void {
        update_option( self::OPTION_KEY, sanitize_text_field( $license_key ) );
        update_option( self::SERVER_URL_KEY, esc_url_raw( $server_url ) );
        delete_transient( self::CACHE_KEY );
    }

    /**
     * Obriši keširane podatke o licenci.
     */
    public function clearCache(): void {
        delete_transient( self::CACHE_KEY );
    }
}
