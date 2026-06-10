<?php

namespace EbitOlx\Security;

defined( 'ABSPATH' ) || exit;

class CredentialManager {

    private const CIPHER = 'aes-256-cbc';
    private const OPTION_PREFIX = 'drtechno_olx_enc_';

    /**
     * Encrypt a value using WordPress auth salt as key.
     */
    public function encrypt( string $plaintext ): string {
        $key = $this->get_key();
        $iv  = random_bytes( openssl_cipher_iv_length( self::CIPHER ) );

        $encrypted = openssl_encrypt( $plaintext, self::CIPHER, $key, 0, $iv );

        return base64_encode( wp_json_encode( [
            'iv'   => base64_encode( $iv ),
            'data' => $encrypted,
        ] ) );
    }

    /**
     * Decrypt a previously encrypted value.
     *
     * @return string|null Null if decryption fails
     */
    public function decrypt( string $ciphertext ): ?string {
        $payload = json_decode( base64_decode( $ciphertext ), true );

        if ( ! $payload || ! isset( $payload['iv'], $payload['data'] ) ) {
            return null;
        }

        $key = $this->get_key();
        $iv  = base64_decode( $payload['iv'] );

        $decrypted = openssl_decrypt( $payload['data'], self::CIPHER, $key, 0, $iv );

        return $decrypted !== false ? $decrypted : null;
    }

    /**
     * Store an encrypted credential in wp_options.
     */
    public function store( string $name, string $value ): void {
        update_option( self::OPTION_PREFIX . $name, $this->encrypt( $value ) );
    }

    /**
     * Retrieve and decrypt a credential from wp_options.
     *
     * @return string|null
     */
    public function retrieve( string $name ): ?string {
        $encrypted = get_option( self::OPTION_PREFIX . $name, '' );

        if ( empty( $encrypted ) ) {
            return null;
        }

        return $this->decrypt( $encrypted );
    }

    /**
     * Obriši sačuvanu OLX lozinku (korisnik se mora ponovo ulogovati).
     */
    public function delete_password(): void {
        delete_option( 'drtechno_olx_password_enc' );
        delete_option( self::OPTION_PREFIX . 'password' );
    }

    /**
     * Get the API token, preferring encrypted storage with fallback to plain text.
     */
    public function get_api_token(): ?string {
        // Try encrypted first
        $token = $this->retrieve( 'api_token' );
        if ( $token !== null ) {
            return $token;
        }

        // Fallback to plain text (pre-migration)
        $plain = get_option( 'olx_api_token', '' );
        return ! empty( $plain ) ? $plain : null;
    }

    /**
     * Store the API token in encrypted form.
     */
    public function set_api_token( string $token ): void {
        $this->store( 'api_token', $token );
        // Keep plain-text option updated for backward compatibility during transition
        update_option( 'olx_api_token', $token );
    }

    private function get_key(): string {
        return substr( hash( 'sha256', wp_salt( 'auth' ) ), 0, 32 );
    }
}
