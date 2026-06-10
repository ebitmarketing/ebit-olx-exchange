<?php

namespace EbitOlx\Api;

defined( 'ABSPATH' ) || exit;

/**
 * Handles direct communication with the OLX API from the WooCommerce client site.
 * Responsibilities:
 * - Holding and injecting the OLX API Bearer token.
 * - Auto-authenticating when the token expires (401).
 * - Sending HTTP requests directly to olx.ba, bypassing the middleware server.
 */
class OlxApiClient {

    public const OLX_BASE_URL = 'https://olx.ba/api';
    private const TOKEN_OPTION = 'drtechno_olx_local_token';

    /**
     * @var string|null
     */
    private ?string $token = null;

    public function __construct() {
        $this->token = get_option( self::TOKEN_OPTION, null );
    }

    /**
     * Send an HTTP request to the OLX API.
     * Automatically handles authentication and a single retry on 401 errors.
     *
     * @param string $endpoint API endpoint (e.g., '/listings/123/images'). Starts with a slash.
     * @param string $method HTTP Method (GET, POST, PUT, DELETE)
     * @param array|null $body Payload for POST/PUT requests
     * @param bool $isRetry True if this is an automatic retry after a 401, to prevent infinite loops.
     * @return array{error: bool, data: array|null, message: string, raw_data: array|null}
     */
    public function request( string $endpoint, string $method = 'GET', ?array $body = null, bool $isRetry = false ): array {
        if ( ! $this->token ) {
            $authResult = $this->authenticate();
            if ( ! $authResult['success'] ) {
                return [ 'error' => true, 'message' => $authResult['message'], 'data' => null, 'raw_data' => null ];
            }
        }

        $url = self::OLX_BASE_URL . $endpoint;
        
        $args = [
            'method'    => $method,
            'timeout'   => 30,
            'sslverify' => true, // Eksplicitno — TLS verifikacija se nikad ne smije gasiti.
            'headers'   => [
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $this->token,
            ],
        ];

        if ( ( $method === 'POST' || $method === 'PUT' ) && $body !== null ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return [ 'error' => true, 'message' => $response->get_error_message(), 'data' => null, 'raw_data' => null ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $decoded = json_decode( wp_remote_retrieve_body( $response ), true );

        // Handle Token Expiration (401 Unauthorized)
        if ( $code === 401 && ! $isRetry ) {
            // Clear expired token
            $this->token = null;
            delete_option( self::TOKEN_OPTION );
            
            // Try to re-authenticate
            $authResult = $this->authenticate();
            if ( $authResult['success'] ) {
                return $this->request( $endpoint, $method, $body, true ); // Retry once
            }
        }

        if ( $code !== 200 && $code !== 201 && $code !== 204 ) {
            $msg = $decoded['message'] ?? ( 'HTTP Error ' . $code );
            return [ 'error' => true, 'message' => $msg, 'data' => null, 'raw_data' => $decoded ];
        }

        return [
            'error'    => false,
            'message'  => '',
            'data'     => $decoded['data'] ?? $decoded,
            'raw_data' => $decoded,
        ];
    }

    /**
     * Re-autentikacija sa klijenta nije podržana: OLX lozinka se NE čuva
     * lokalno (Phase 1+). Token se dobija isključivo kroz server (auth/connect)
     * nakon ručne prijave u Postavkama.
     *
     * @return array{success: bool, message: string}
     */
    private function authenticate(): array {
        return [
            'success' => false,
            'message' => 'OLX sesija je istekla. Idite na Postavke → OLX prijava i ponovo se ulogujte.',
        ];
    }
}
