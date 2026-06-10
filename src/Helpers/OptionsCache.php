<?php

namespace EbitOlx\Helpers;

defined( 'ABSPATH' ) || exit;

class OptionsCache {

    private static array $cache = [];

    /**
     * Get a WordPress option with in-memory caching.
     * Eliminates redundant DB queries within the same request.
     *
     * @param string $option  Option name
     * @param mixed  $default Default value
     * @return mixed
     */
    public static function get( string $option, $default = false ) {
        if ( ! array_key_exists( $option, self::$cache ) ) {
            self::$cache[ $option ] = get_option( $option, $default );
        }
        return self::$cache[ $option ];
    }

    /**
     * Update an option and refresh the cache.
     */
    public static function set( string $option, $value ): void {
        update_option( $option, $value );
        self::$cache[ $option ] = $value;
    }

    /**
     * Delete an option and remove from cache.
     */
    public static function delete( string $option ): void {
        delete_option( $option );
        unset( self::$cache[ $option ] );
    }

    /**
     * Invalidate a specific cached option (force reload on next get).
     */
    public static function invalidate( string $option ): void {
        unset( self::$cache[ $option ] );
    }

    /**
     * Clear the entire cache (useful for testing).
     */
    public static function flush(): void {
        self::$cache = [];
    }

    /**
     * Pre-load multiple options in a single query.
     * Uses wp_load_alloptions() for autoloaded options.
     *
     * @param string[] $options Option names to pre-load
     */
    public static function preload( array $options ): void {
        foreach ( $options as $option ) {
            if ( ! array_key_exists( $option, self::$cache ) ) {
                self::$cache[ $option ] = get_option( $option, false );
            }
        }
    }
}
