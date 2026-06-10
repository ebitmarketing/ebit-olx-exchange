<?php

namespace EbitOlx\Database;

defined( 'ABSPATH' ) || exit;

class Migrator {

    private const VERSION_OPTION = 'drtechno_olx_db_version';

    /**
     * Register the migration check on admin_init.
     */
    public function register(): void {
        add_action( 'admin_init', [ $this, 'run_pending_migrations' ] );
    }

    /**
     * Run any pending database migrations.
     */
    public function run_pending_migrations(): void {
        $current_version = (int) get_option( self::VERSION_OPTION, 0 );

        // Handle legacy version string '1.4' from the monolith
        $raw = get_option( self::VERSION_OPTION, '' );
        if ( $raw === '1.4' ) {
            $current_version = 1; // Map legacy '1.4' to migration 1
            update_option( self::VERSION_OPTION, 1 );
        }

        $migrations_dir = DRTECHNO_OLX_PATH . 'migrations/';
        if ( ! is_dir( $migrations_dir ) ) {
            return;
        }

        $files = glob( $migrations_dir . '*.php' );
        if ( empty( $files ) ) {
            return;
        }

        sort( $files ); // Ensure numeric order

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        foreach ( $files as $file ) {
            $basename = basename( $file, '.php' );
            // Extract migration number: "001_initial_tables" -> 1
            $migration_number = (int) substr( $basename, 0, 3 );

            if ( $migration_number > $current_version ) {
                $migration = require $file;

                if ( is_callable( $migration ) ) {
                    $migration();
                }

                update_option( self::VERSION_OPTION, $migration_number );
            }
        }
    }
}
