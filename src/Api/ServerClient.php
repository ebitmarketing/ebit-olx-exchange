<?php

namespace EbitOlx\Api;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;

/**
 * Centralni klijent za komunikaciju sa SaaS serverom.
 * Zamjenjuje direktne OLX API pozive — SVE ide kroz server.
 */
class ServerClient {

    private static ?self $instance = null;

    public static function getInstance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Pošalji zahtjev na SaaS server.
     *
     * @param string $action  API akcija (sync, delete, refresh, sponsor/price, etc.)
     * @param array  $data    Podaci za akciju
     * @param int    $timeout Timeout u sekundama
     * @return array ['error' => bool, 'data' => mixed, 'message' => string, 'raw_data' => mixed]
     */
    public function request( string $action, array $data = [], int $timeout = 15 ): array {
        $server_url  = OptionsCache::get( 'drtechno_olx_server_url', '' );
        $license_key = OptionsCache::get( 'drtechno_olx_license_key', '' );

        if ( empty( $server_url ) || empty( $license_key ) ) {
            return [
                'error'   => true,
                'message' => 'Server URL ili licencni ključ nije konfigurisan.',
                'data'    => null,
                'raw_data' => null,
            ];
        }

        $api_url = rtrim( $server_url, '/' ) . '/api/v1/index.php';
        $token   = get_option( 'drtechno_olx_api_token', '' );

        if ( $token ) {
            $data['olx_token'] = $token;
        }

        $body = wp_json_encode( [
            'action'      => $action,
            'license_key' => $license_key,
            'site_url'    => get_site_url(),
            'data'        => $data,
        ] );

        $response = wp_remote_post( $api_url, [
            'body'      => $body,
            'headers'   => [
                'Content-Type'  => 'application/json',
                'X-API-Version' => '1',
            ],
            'timeout'   => $timeout,
            'sslverify' => true, // Eksplicitno — TLS verifikacija se nikad ne smije gasiti.
        ] );

        if ( is_wp_error( $response ) ) {
            return [
                'error'    => true,
                'message'  => 'Greška pri komunikaciji sa serverom: ' . $response->get_error_message(),
                'data'     => null,
                'raw_data' => null,
            ];
        }

        $code    = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        // Token expired — obriši i zatraži re-login od korisnika
        if ( $code === 401 ) {
            delete_option( 'drtechno_olx_api_token' );
            return [
                'error'    => true,
                'message'  => 'OLX sesija je istekla. Idite na Postavke → OLX prijava i ponovo se ulogujte.',
                'data'     => null,
                'raw_data' => $decoded,
            ];
        }

        if ( isset( $decoded['error'] ) && $decoded['error'] ) {
            $msg = $decoded['message'] ?? 'Nepoznata greška sa servera.';

            // Reaktivna invalidacija license keša: ako server vrati bilo koju
            // license-related grešku, ne čekaj 1h TTL — odmah istinski refresh.
            if ( self::isLicenseInvalidMessage( $msg ) ) {
                self::invalidateLicenseCache( $msg );
            }

            return [
                'error'    => true,
                'message'  => $msg,
                'data'     => $decoded['data'] ?? null,
                'raw_data' => $decoded,
            ];
        }

        return [
            'error'    => false,
            'message'  => $decoded['message'] ?? 'OK',
            'data'     => $decoded['data'] ?? $decoded,
            'raw_data' => $decoded,
        ];
    }

    /**
     * Detektuje da li server poruka znači da je licenca nevažeća / obrisana / istekla.
     * Pokriva sve LicenseManager::validate() reason stringove iz api/license.php.
     */
    private static function isLicenseInvalidMessage( string $msg ): bool {
        $lower    = function_exists( 'mb_strtolower' ) ? mb_strtolower( $msg ) : strtolower( $msg );
        $patterns = [
            'licenca nije',          // "Licenca nije pronađena", "nije validna", "nije vezana za sajt"
            'licenca je istekla',
            'licenca je suspendovana',
            'licencni ključ',        // "Fali licencni ključ"
            'fali site_url',
            'sajt nije ovlašten',
            'sajt url ne odgovara',
        ];
        foreach ( $patterns as $p ) {
            if ( strpos( $lower, $p ) !== false ) return true;
        }
        return false;
    }

    /**
     * Obriše plugin keš licence i okine hook tako da FeatureManager
     * može odmah resetovati granularne feat_* opcije na 0.
     */
    private static function invalidateLicenseCache( string $reason ): void {
        delete_transient( 'drtechno_olx_license_cache' );
        delete_option( 'drtechno_olx_license_cache_last_valid' );
        do_action( 'ebit_olx_license_invalidated', $reason );
    }

    // ──── Convenience Methods ────

    public function sync( array $productData ): array {
        return $this->request( 'sync', $productData, 30 );
    }

    public function delete( string $olxId, string $title = '' ): array {
        return $this->request( 'delete', [ 'olx_article_id' => $olxId, 'title' => $title ] );
    }

    public function refresh( string $olxId ): array {
        return $this->request( 'refresh', [ 'olx_article_id' => $olxId ] );
    }

    public function hide( string $olxId, string $title = '' ): array {
        return $this->request( 'hide', [ 'olx_article_id' => $olxId, 'title' => $title ] );
    }

    public function unhide( string $olxId, string $title = '' ): array {
        return $this->request( 'unhide', [ 'olx_article_id' => $olxId, 'title' => $title ] );
    }

    public function validateLicense(): array {
        return $this->request( 'license/validate' );
    }

    public function getFeatures(): array {
        return $this->request( 'license/features' );
    }

    public function sponsorPrice( string $olxId, array $params ): array {
        return $this->request( 'sponsor/price', array_merge( [ 'olx_article_id' => $olxId ], $params ) );
    }

    public function sponsorActivate( string $olxId, array $params ): array {
        return $this->request( 'sponsor/activate', [
            'olx_article_id' => $olxId,
            'sponsor_params'  => $params,
        ] );
    }

    public function massBump( string $olxId, string $title = '' ): array {
        return $this->request( 'mass_bump', [ 'olx_article_id' => $olxId, 'title' => $title ] );
    }

    public function cleanup( int $page, array $wcOlxIds ): array {
        return $this->request( 'cleanup', [
            'page'       => $page,
            'wc_olx_ids' => $wcOlxIds,
        ], 60 );
    }

    public function expiredRecovery( int $page, array $wcOlxIds, string $expAction = 'delete' ): array {
        return $this->request( 'expired_recovery', [
            'page'       => $page,
            'wc_olx_ids' => $wcOlxIds,
            'exp_action' => $expAction,
        ], 60 );
    }

    public function authConnect( string $username, string $password ): array {
        return $this->request( 'auth/connect', [
            'username' => $username,
            'password' => $password,
        ], 30 );
    }

    public function getCategories( ?int $parentId = null ): array {
        return $this->request( 'categories', $parentId ? [ 'parent_id' => $parentId ] : [] );
    }

    public function getCategoryLeaves(): array {
        return $this->request( 'categories/leaves' );
    }

    public function getBrands( int $catId ): array {
        return $this->request( 'brands', [ 'cat_id' => $catId ] );
    }

    public function getBrandsCached( int $catId ): array {
        return $this->request( 'brands/cached', [ 'cat_id' => $catId ] );
    }

    public function getAttributes( int $catId ): array {
        return $this->request( 'attributes', [ 'cat_id' => $catId ] );
    }

    public function getAttributesCached( int $catId ): array {
        return $this->request( 'attributes/cached', [ 'cat_id' => $catId ] );
    }

    public function getLocations(): array {
        return $this->request( 'locations' );
    }

    public function getRefreshLimits(): array {
        return $this->request( 'refresh/limits' );
    }

    public function imageUpload( string $olxId, string $imageUrl ): array {
        return $this->request( 'sync', [
            'sub_action'     => 'image_upload',
            'olx_article_id' => $olxId,
            'image_url'      => $imageUrl,
        ], 30 );
    }

    public function imageSetMain( string $olxId, string $imageId ): array {
        return $this->request( 'sync', [
            'sub_action'     => 'image_main',
            'olx_article_id' => $olxId,
            'image_id'       => $imageId,
        ], 15 );
    }

    public function imageDelete( string $olxId, string $imageId ): array {
        return $this->request( 'sync', [
            'sub_action'     => 'image_delete',
            'olx_article_id' => $olxId,
            'image_id'       => $imageId,
        ], 15 );
    }

    public function getListing( string $olxId ): array {
        return $this->request( 'sync', [
            'sub_action'     => 'get_listing',
            'olx_article_id' => $olxId,
        ], 15 );
    }
}
