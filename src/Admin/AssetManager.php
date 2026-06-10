<?php

namespace EbitOlx\Admin;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Security\NonceManager;

class AssetManager {

    public function register(): void {
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue( string $hook ): void {
        $allowed_hooks = [
            'toplevel_page_drtechno_olx_sync',
            'post.php',
            'post-new.php',
        ];

        if ( ! in_array( $hook, $allowed_hooks, true ) ) {
            return;
        }

        $plugin_url = DRTECHNO_OLX_URL;
        $version    = DRTECHNO_OLX_VERSION;

        // Select2 (local copy)
        wp_enqueue_style(
            'olx-select2-css',
            $plugin_url . 'assets/css/select2.min.css',
            [],
            '4.1.0'
        );
        wp_enqueue_script(
            'olx-select2-js',
            $plugin_url . 'assets/js/select2.min.js',
            [ 'jquery' ],
            '4.1.0',
            true
        );

        // Plugin admin CSS
        wp_enqueue_style(
            'drtechno-olx-admin-css',
            $plugin_url . 'assets/css/admin.css',
            [],
            $version
        );

        // Localization data (nonce + ajax URL)
        wp_register_script( 'drtechno-olx-vars', false );
        wp_enqueue_script( 'drtechno-olx-vars' );
        wp_localize_script( 'drtechno-olx-vars', 'olx_sync_vars', [
            'ajaxurl'        => admin_url( 'admin-ajax.php' ),
            'nonce'          => NonceManager::create(),
            'font_black_url' => $plugin_url . 'assets/Montserrat-Black.ttf',
            'font_semi_url'  => $plugin_url . 'assets/Montserrat-SemiBold.ttf',
            'badge_std_url'  => $plugin_url . 'assets/badge_template.png',
            'badge_vip_url'  => $plugin_url . 'assets/badge_vip_template.png',
        ] );

        // Context-specific scripts
        if ( $hook === 'toplevel_page_drtechno_olx_sync' ) {
            wp_enqueue_media();
            wp_enqueue_script( 'jquery-ui-draggable' );
            wp_enqueue_script( 'jquery-ui-resizable' );

            wp_enqueue_script(
                'drtechno-olx-mass-ops',
                $plugin_url . 'assets/js/mass-operations.js',
                [ 'jquery', 'olx-select2-js', 'drtechno-olx-vars' ],
                $version,
                true
            );

            wp_enqueue_script(
                'drtechno-olx-sponsor',
                $plugin_url . 'assets/js/sponsor.js',
                [ 'jquery', 'drtechno-olx-vars' ],
                $version,
                true
            );
        }

        if ( $hook === 'post.php' || $hook === 'post-new.php' ) {
            wp_enqueue_script(
                'drtechno-olx-metabox',
                $plugin_url . 'assets/js/metabox.js',
                [ 'jquery', 'drtechno-olx-vars' ],
                $version,
                true
            );
        }
    }
}
