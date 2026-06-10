<?php

namespace EbitOlx\Security;

defined( 'ABSPATH' ) || exit;

class NonceManager {

    private const NONCE_ACTION = 'olx_sync_nonce';
    private const NONCE_FIELD  = 'nonce';

    /**
     * Verify an AJAX request nonce and check admin capability.
     * Sends wp_send_json_error and dies if verification fails.
     */
    public static function verifyAjax(): void {
        check_ajax_referer( self::NONCE_ACTION, self::NONCE_FIELD );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Nedozvoljen pristup.' );
        }
    }

    /**
     * Verify an admin form submission nonce.
     *
     * @param string $action The nonce action name (form-specific)
     */
    public static function verifyAdmin( string $action ): void {
        check_admin_referer( $action );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Nedozvoljen pristup.' );
        }
    }

    /**
     * Create a nonce value for AJAX requests.
     */
    public static function create(): string {
        return wp_create_nonce( self::NONCE_ACTION );
    }

    /**
     * Output a hidden nonce field for forms.
     *
     * @param string $action The nonce action name
     */
    public static function field( string $action ): void {
        wp_nonce_field( $action );
    }

    /**
     * Generate a URL with a nonce parameter for destructive GET actions.
     *
     * @param string $base_url
     * @param string $action Nonce action name
     * @return string URL with _wpnonce parameter
     */
    public static function url( string $base_url, string $action ): string {
        return wp_nonce_url( $base_url, $action );
    }
}
