<?php

namespace EbitOlx\Logging;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Contracts\LoggerInterface;

class Logger implements LoggerInterface {

    private const LOG_LEVELS = [
        'debug'   => 0,
        'info'    => 1,
        'warning' => 2,
        'error'   => 3,
    ];

    private string $log_dir;
    private int $min_level;

    public function __construct() {
        $upload_dir    = wp_upload_dir();
        $this->log_dir = trailingslashit( $upload_dir['basedir'] ) . 'olx-sync-logs';

        $configured = get_option( 'drtechno_olx_log_level', 'warning' );
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $configured = 'debug';
        }
        $this->min_level = self::LOG_LEVELS[ $configured ] ?? self::LOG_LEVELS['warning'];
    }

    public function debug( string $message, array $context = [] ): void {
        $this->log( 'debug', $message, $context );
    }

    public function info( string $message, array $context = [] ): void {
        $this->log( 'info', $message, $context );
    }

    public function warning( string $message, array $context = [] ): void {
        $this->log( 'warning', $message, $context );
    }

    public function error( string $message, array $context = [] ): void {
        $this->log( 'error', $message, $context );
    }

    private function log( string $level, string $message, array $context ): void {
        if ( ( self::LOG_LEVELS[ $level ] ?? 0 ) < $this->min_level ) {
            return;
        }

        // Try WooCommerce logger first
        if ( function_exists( 'wc_get_logger' ) ) {
            $wc_logger = wc_get_logger();
            $wc_logger->log( $level, $message, array_merge( $context, [ 'source' => 'olx-sync' ] ) );
            return;
        }

        // Fallback to file logger
        $this->write_to_file( $level, $message, $context );
    }

    private function write_to_file( string $level, string $message, array $context ): void {
        if ( ! is_dir( $this->log_dir ) ) {
            wp_mkdir_p( $this->log_dir );

            // Protect log directory
            $htaccess = $this->log_dir . '/.htaccess';
            if ( ! file_exists( $htaccess ) ) {
                file_put_contents( $htaccess, 'deny from all' );
            }
        }

        $file    = $this->log_dir . '/olx-sync-' . gmdate( 'Y-m-d' ) . '.log';
        $time    = gmdate( 'Y-m-d H:i:s' );
        $level   = strtoupper( $level );
        $context_str = ! empty( $context ) ? ' ' . wp_json_encode( $context ) : '';
        $entry   = "[{$time}] [{$level}] {$message}{$context_str}" . PHP_EOL;

        file_put_contents( $file, $entry, FILE_APPEND | LOCK_EX );
    }
}
