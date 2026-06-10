<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

/**
 * Base AJAX handler with common security checks.
 * All concrete AJAX handlers extend this class.
 */
abstract class AjaxHandler {

    /**
     * Register all AJAX actions defined in the subclass.
     */
    abstract public function register(): void;

    /**
     * Verify nonce + capability for every AJAX request.
     *
     * @param string $nonce_action  The nonce action name.
     * @param string $nonce_field   The POST/GET field holding the nonce.
     * @param string $capability    Required WP capability.
     */
    protected function verify( string $nonce_action = 'olx_sync_nonce', string $nonce_field = 'nonce', string $capability = 'manage_options' ): void {
        check_ajax_referer( $nonce_action, $nonce_field );
        if ( ! current_user_can( $capability ) ) {
            wp_send_json_error( 'Nedozvoljen pristup.' );
        }
    }

    /**
     * Shortcut: register a wp_ajax_ action pointing to a method on $this.
     */
    protected function action( string $ajax_action, string $method ): void {
        add_action( 'wp_ajax_' . $ajax_action, [ $this, $method ] );
    }
}
