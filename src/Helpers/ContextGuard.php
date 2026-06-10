<?php

namespace EbitOlx\Helpers;

defined( 'ABSPATH' ) || exit;

class ContextGuard {

    /**
     * Check if the current request is an automated context
     * where product hooks should NOT fire (cron, import, REST, CLI).
     *
     * Replaces the 4 duplicated constant-check blocks.
     */
    public static function isAutomatedContext(): bool {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return true;
        }
        if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) {
            return true;
        }
        if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
            return true;
        }
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return true;
        }
        return false;
    }

    /**
     * Check if the current request is an autosave.
     */
    public static function isAutosave(): bool {
        return defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE;
    }

    /**
     * Check if the current user can manage the plugin.
     */
    public static function canManage(): bool {
        return current_user_can( 'manage_options' );
    }
}
