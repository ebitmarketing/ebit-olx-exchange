<?php

namespace EbitOlx\Security;

defined( 'ABSPATH' ) || exit;

class InputValidator {

    /**
     * Get and sanitize a value from $_POST.
     *
     * @param string $key
     * @param string $type 'int', 'float', 'text', 'html', 'array', 'bool'
     * @param mixed  $default
     * @return mixed
     */
    public static function post( string $key, string $type = 'text', $default = null ) {
        if ( ! isset( $_POST[ $key ] ) ) {
            return $default;
        }
        return self::sanitize( $_POST[ $key ], $type );
    }

    /**
     * Get and sanitize a value from $_GET.
     */
    public static function get( string $key, string $type = 'text', $default = null ) {
        if ( ! isset( $_GET[ $key ] ) ) {
            return $default;
        }
        return self::sanitize( $_GET[ $key ], $type );
    }

    /**
     * Sanitize a value by type.
     *
     * @param mixed  $value
     * @param string $type
     * @return mixed
     */
    public static function sanitize( $value, string $type ) {
        switch ( $type ) {
            case 'int':
                return intval( $value );

            case 'float':
                return floatval( $value );

            case 'text':
                return sanitize_text_field( $value );

            case 'html':
                return wp_kses_post( $value );

            case 'bool':
                return (bool) $value;

            case 'array':
                if ( is_array( $value ) ) {
                    return array_map( 'sanitize_text_field', $value );
                }
                return [];

            case 'url':
                return esc_url_raw( $value );

            case 'email':
                return sanitize_email( $value );

            default:
                return sanitize_text_field( $value );
        }
    }

    /**
     * Sanitize an array of data using a rules map.
     *
     * @param array $data  Raw data
     * @param array $rules Key => type pairs, e.g. ['post_id' => 'int', 'name' => 'text']
     * @return array Sanitized data (only keys present in rules)
     */
    public static function sanitizeArray( array $data, array $rules ): array {
        $clean = [];
        foreach ( $rules as $key => $type ) {
            if ( isset( $data[ $key ] ) ) {
                $clean[ $key ] = self::sanitize( $data[ $key ], $type );
            }
        }
        return $clean;
    }
}
