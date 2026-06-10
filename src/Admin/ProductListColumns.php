<?php

namespace EbitOlx\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Adds OLX status column, filter dropdown, and meta-query
 * to the WooCommerce Products list table (edit.php?post_type=product).
 */
class ProductListColumns {

    public function register(): void {
        add_filter( 'manage_edit-product_columns',        [ $this, 'addColumn' ], 10, 1 );
        add_action( 'manage_product_posts_custom_column',  [ $this, 'fillColumn' ], 10, 2 );
        add_action( 'restrict_manage_posts',               [ $this, 'renderFilterDropdown' ] );
        add_action( 'pre_get_posts',                       [ $this, 'applyFilter' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Column header                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * @param array $columns
     * @return array
     */
    public function addColumn( array $columns ): array {
        $new_columns = [];
        foreach ( $columns as $key => $title ) {
            if ( $key === 'date' ) {
                $new_columns['olx_status'] = 'OLX Sync';
            }
            $new_columns[ $key ] = $title;
        }
        return $new_columns;
    }

    /* ------------------------------------------------------------------ */
    /*  Column content                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $column
     * @param int    $post_id
     */
    public function fillColumn( string $column, int $post_id ): void {
        if ( $column !== 'olx_status' ) return;

        $olx_id = get_post_meta( $post_id, '_olx_article_id', true );
        $error  = get_post_meta( $post_id, '_olx_sync_error', true );

        if ( $error ) {
            echo '<span style="color:#d63638; font-weight:bold;">⚠️ Greška</span><br>';
            echo '<small style="color:#666;" title="' . esc_attr( $error ) . '">'
                . esc_html( mb_strimwidth( $error, 0, 25, '...' ) ) . '</small>';
        } elseif ( $olx_id ) {
            $last = get_post_meta( $post_id, '_olx_last_sync', true );
            echo '<span style="color: green;">✓ '
                . ( $last ? date( 'd.m. H:i', strtotime( $last ) ) : 'Da' )
                . '</span><br><small><a href="https://olx.ba/artikal/' . $olx_id
                . '" target="_blank">ID: ' . $olx_id . '</a></small>';
        } else {
            echo '<span style="color: #aaa; font-weight:bold; font-size:16px;">-</span>';
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Filter dropdown                                                   */
    /* ------------------------------------------------------------------ */

    public function renderFilterDropdown(): void {
        global $typenow;
        if ( $typenow !== 'product' ) return;

        $sel = $_GET['olx_sync_filter'] ?? '';
        echo '<select name="olx_sync_filter">'
            . '<option value="">OLX Status (Svi)</option>'
            . '<option value="synced" '  . selected( $sel, 'synced',     false ) . '>Sinhronizirano</option>'
            . '<option value="not_synced" ' . selected( $sel, 'not_synced', false ) . '>Nije na OLX</option>'
            . '<option value="error" '   . selected( $sel, 'error',      false ) . '>Greška / Fale atributi</option>'
            . '</select>';
    }

    /* ------------------------------------------------------------------ */
    /*  Meta-query filter                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * @param \WP_Query $query
     */
    public function applyFilter( $query ): void {
        global $pagenow;

        if (
            ! isset( $_GET['post_type'] )
            || $_GET['post_type'] !== 'product'
            || ! is_admin()
            || $pagenow !== 'edit.php'
            || ! isset( $_GET['olx_sync_filter'] )
            || $_GET['olx_sync_filter'] === ''
            || ! $query->is_main_query()
        ) {
            return;
        }

        $mq = (array) $query->get( 'meta_query' );

        switch ( $_GET['olx_sync_filter'] ) {
            case 'synced':
                $mq[] = [ 'key' => '_olx_article_id', 'compare' => 'EXISTS' ];
                $mq[] = [ 'key' => '_olx_article_id', 'value' => '', 'compare' => '!=' ];
                break;

            case 'error':
                $mq[] = [ 'key' => '_olx_sync_error', 'compare' => 'EXISTS' ];
                $mq[] = [ 'key' => '_olx_sync_error', 'value' => '', 'compare' => '!=' ];
                break;

            default: // not_synced
                $mq[] = [
                    'relation' => 'OR',
                    [ 'key' => '_olx_article_id', 'compare' => 'NOT EXISTS' ],
                    [ 'key' => '_olx_article_id', 'value' => '', 'compare' => '=' ],
                ];
                break;
        }

        $query->set( 'meta_query', $mq );
    }
}
