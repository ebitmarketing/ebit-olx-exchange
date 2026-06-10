<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;

/**
 * Handles category/brand/attribute/location lookup AJAX endpoints.
 * Ported from EBIT_OLX_Exchange monolith to PSR-4.
 */
class CategoryAjax extends AjaxHandler {

    private ServerClient $api;

    public function __construct( ServerClient $api ) {
        $this->api = $api;
    }

    public function register(): void {
        $this->action( 'drtechno_fetch_categories',     'fetchCategories' );
        $this->action( 'drtechno_fetch_brands',         'fetchBrands' );
        $this->action( 'drtechno_fetch_attributes',     'fetchAttributes' );
        $this->action( 'drtechno_fetch_locations',      'fetchLocations' );
        $this->action( 'drtechno_get_cat_default_form', 'getCatDefaultForm' );
    }

    /**
     * Preuzmi sve leaf kategorije i povuci brendove/atribute za mapirane kategorije.
     */
    public function fetchCategories(): void {
        $this->verify();

        delete_transient( 'drtechno_olx_server_cats' );

        $resp = $this->api->request( 'categories/leaves' );
        if ( $resp['error'] || empty( $resp['data'] ) ) {
            wp_send_json_error( $resp['message'] ?? 'Nije moguće preuzeti kategorije. Provjerite da je crawler pokrenut na serveru.' );
        }

        $cats = $resp['data'];
        set_transient( 'drtechno_olx_server_cats', $cats, HOUR_IN_SECONDS );

        // Automatski povuci brendove i atribute za sve mapirane kategorije
        $mapped_cats = array_values( array_unique( array_filter( get_option( 'drtechno_olx_category_mapping', [] ) ) ) );
        $brands      = get_option( 'drtechno_olx_available_brands', [] );
        $all_attrs   = get_option( 'drtechno_olx_category_attributes', [] );
        $meta_count  = 0;

        foreach ( $mapped_cats as $cat_id ) {
            $cid = intval( $cat_id );

            $br = $this->api->request( 'brands', [ 'cat_id' => $cid ] );
            if ( ! $br['error'] && ! empty( $br['data'] ) ) {
                foreach ( $br['data'] as $b ) {
                    if ( isset( $b['id'], $b['name'] ) ) {
                        $brands[ $b['id'] ] = $b['name'];
                    }
                }
            }

            $at = $this->api->request( 'attributes', [ 'cat_id' => $cid ] );
            if ( ! $at['error'] && isset( $at['data'] ) ) {
                $all_attrs[ $cid ] = $at['data'];
            }

            $meta_count++;
            if ( $meta_count >= 15 ) break;
        }

        asort( $brands );
        update_option( 'drtechno_olx_available_brands', $brands );
        update_option( 'drtechno_olx_category_attributes', $all_attrs );

        $msg = count( $cats ) . ' kategorija';
        if ( $meta_count > 0 ) {
            $msg .= ' + brendovi/atributi za ' . $meta_count . ' mapiranih kategorija';
        }

        wp_send_json_success( 'Preuzeto: ' . $msg );
    }

    /**
     * Preuzmi brendove za konkretnu OLX kategoriju.
     */
    public function fetchBrands(): void {
        $this->verify();

        $cat_id = intval( $_POST['cat_id'] ?? 0 );
        $resp   = $this->api->request( 'brands', [ 'cat_id' => $cat_id ] );

        if ( $resp['error'] ) {
            wp_send_json_error( $resp['message'] ?? 'Greška.' );
        }

        $brands = get_option( 'drtechno_olx_available_brands', [] );
        foreach ( $resp['data'] as $b ) {
            if ( isset( $b['id'], $b['name'] ) ) {
                $brands[ $b['id'] ] = $b['name'];
            }
        }
        asort( $brands );
        update_option( 'drtechno_olx_available_brands', $brands );

        wp_send_json_success( 'Brendovi preuzeti.' );
    }

    /**
     * Preuzmi atribute za konkretnu OLX kategoriju.
     */
    public function fetchAttributes(): void {
        $this->verify();

        $cat_id = intval( $_POST['cat_id'] ?? 0 );
        $resp   = $this->api->request( 'attributes', [ 'cat_id' => $cat_id ] );

        if ( $resp['error'] ) {
            wp_send_json_error( $resp['message'] ?? 'Greška.' );
        }

        $all             = get_option( 'drtechno_olx_category_attributes', [] );
        $all[ $cat_id ]  = $resp['data'];
        update_option( 'drtechno_olx_category_attributes', $all );

        wp_send_json_success( 'Atributi preuzeti.' );
    }

    /**
     * Preuzmi listu zemalja i gradova.
     */
    public function fetchLocations(): void {
        $this->verify();

        $resp = $this->api->request( 'locations' );

        if ( $resp['error'] ) {
            wp_send_json_error( $resp['message'] ?? 'Greška.' );
        }

        update_option( 'drtechno_olx_countries_data', $resp['data']['countries'] ?? [] );
        update_option( 'drtechno_olx_cities_data',    $resp['data']['cities']    ?? [] );

        wp_send_json_success( 'Lokacije preuzete.' );
    }

    /**
     * Vrati HTML formu za zadane atribute kategorije.
     */
    public function getCatDefaultForm(): void {
        $this->verify();

        $wc_cat_id  = intval( $_POST['wc_cat_id'] );
        $olx_cat_id = intval( $_POST['olx_cat_id'] );

        $all_cat_attrs = get_option( 'drtechno_olx_category_attributes', [] );
        $attributes    = $all_cat_attrs[ $olx_cat_id ] ?? [];

        if ( empty( $attributes ) ) {
            wp_send_json_error( 'Nema atributa za ovu kategoriju. Pokrenite preuzimanje Meta podataka.' );
        }

        $default_attrs  = get_option( 'drtechno_olx_default_attributes', [] );
        $saved_for_cat  = $default_attrs[ $wc_cat_id ] ?? [];

        ob_start();
        echo '<form method="post">';
        wp_nonce_field( 'olx_save_default_attrs_action', 'olx_save_default_attrs_nonce' );
        echo '<input type="hidden" name="wc_category_id" value="' . esc_attr( $wc_cat_id ) . '">';
        echo '<table class="form-table"><tbody>';

        $sval = $saved_for_cat['olx_state'] ?? '';
        echo '<tr><th><label>Zadano Stanje</label></th><td>';
        echo '<select name="olx_state" style="width:100%;max-width:300px;">';
        echo '<option value="">— Nema (prazno) —</option>';
        echo '<option value="new" '   . selected( $sval, 'new',  false ) . '>Novo</option>';
        echo '<option value="used" '  . selected( $sval, 'used', false ) . '>Korišteno</option>';
        echo '</select></td></tr>';

        foreach ( $attributes as $a ) {
            $s        = $a['name'];
            $s_attr   = esc_attr( $s );
            $req      = $a['required'] ? ' <span style="color:red;">*</span>' : '';
            $curr_val = $saved_for_cat[ $s ] ?? '';

            echo '<tr><th><label>' . esc_html( $a['display_name'] ) . $req . '</label></th><td>';

            if ( $a['input_type'] === 'select' && ! empty( $a['options'] ) ) {
                echo '<select name="olx_attr[' . $s_attr . ']" style="width:100%;max-width:300px;">';
                echo '<option value="">— Nema (prazno) —</option>';
                foreach ( $a['options'] as $o ) {
                    echo '<option value="' . esc_attr( $o ) . '" ' . selected( $curr_val, $o, false ) . '>' . esc_html( $o ) . '</option>';
                }
                echo '</select>';
            } elseif ( $a['input_type'] === 'checkbox' ) {
                echo '<select name="olx_attr[' . $s_attr . ']">';
                echo '<option value="">— Nema (prazno) —</option>';
                echo '<option value="1" ' . selected( $curr_val, '1', false ) . '>Da</option>';
                echo '</select>';
            } else {
                $type = ( $a['type'] === 'number' ) ? 'number' : 'text';
                echo '<input type="' . esc_attr( $type ) . '" name="olx_attr[' . $s_attr . ']" value="' . esc_attr( $curr_val ) . '" class="regular-text">';
            }

            echo '</td></tr>';
        }

        echo '</tbody></table>';
        echo '<p class="submit"><input type="submit" name="olx_save_default_attrs_submit" class="button button-primary" value="Sačuvaj zadane atribute"></p>';
        echo '</form>';

        wp_send_json_success( ob_get_clean() );
    }
}
