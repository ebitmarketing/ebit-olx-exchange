<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxMetaHelper;

/**
 * Handles mass manual BUMP (refresh) AJAX endpoints.
 */
class BumpAjax extends AjaxHandler {

    private ServerClient $api;

    public function __construct( ServerClient $api ) {
        $this->api = $api;
    }

    public function register(): void {
        $this->action( 'drtechno_mass_bump', 'massBump' );
        $this->action( 'drtechno_single_bump', 'singleBump' );
        $this->action( 'drtechno_filtered_bump_start',   'filteredBumpStart' );
        $this->action( 'drtechno_filtered_bump_process', 'filteredBumpProcess' );
    }

    /**
     * Bump (refresh) multiple OLX listings at once.
     * Expects POST: post_ids[] (array of WP post IDs)
     */
    public function massBump(): void {
        ob_start();
        $this->verify();

        $post_ids = isset( $_POST['post_ids'] ) ? array_map( 'intval', (array) $_POST['post_ids'] ) : [];
        if ( empty( $post_ids ) ) {
            ob_end_clean();
            wp_send_json_error( 'Niste odabrali niti jedan artikal.' );
        }

        $results  = [];
        $success  = 0;
        $failed   = 0;

        foreach ( $post_ids as $post_id ) {
            $olx_id = get_post_meta( $post_id, '_olx_article_id', true );
            if ( ! $olx_id ) {
                $results[] = [
                    'post_id' => $post_id,
                    'success' => false,
                    'message' => 'Artikal nije objavljen na OLX-u.',
                ];
                $failed++;
                continue;
            }

            $response = $this->api->massBump( $olx_id );

            if ( ! $response['error'] ) {
                $results[] = [
                    'post_id' => $post_id,
                    'olx_id'  => $olx_id,
                    'success' => true,
                    'message' => 'Bump uspjesan.',
                ];
                $success++;
            } else {
                $results[] = [
                    'post_id' => $post_id,
                    'olx_id'  => $olx_id,
                    'success' => false,
                    'message' => $response['message'] ?? 'Greska pri BUMP-u.',
                ];
                $failed++;
            }
        }

        ob_end_clean();
        wp_send_json_success( [
            'results' => $results,
            'summary' => sprintf( 'Bump zavrsen: %d uspjesno, %d neuspjesno.', $success, $failed ),
        ] );
    }

    /**
     * Bump a single OLX listing.
     * Expects POST: post_id
     */
    public function singleBump(): void {
        ob_start();
        $this->verify();

        $post_id = intval( $_POST['post_id'] );
        $olx_id  = get_post_meta( $post_id, '_olx_article_id', true );

        if ( ! $olx_id ) {
            ob_end_clean();
            wp_send_json_error( 'Artikal nije objavljen na OLX-u.' );
        }

        $response = $this->api->refresh( $olx_id );
        ob_end_clean();

        if ( ! $response['error'] ) {
            wp_send_json_success( 'Bump uspjesan za OLX ID: ' . $olx_id );
        } else {
            wp_send_json_error( $response['message'] ?? 'Greska pri BUMP-u.' );
        }
    }

    /**
     * Pripremi red čekanja za Filtered BUMP.
     * POST: bump_action (refresh|delete), wc_cat_id, olx_status (active|hidden|all), brand_name
     */
    public function filteredBumpStart(): void {
        ob_start();
        $this->verify();

        $features = get_option( 'drtechno_olx_license_features', [] );
        if ( empty( $features['mass_bump'] ) ) {
            ob_end_clean();
            wp_send_json_error( 'Vaš plan ne uključuje funkciju Filtered BUMP.' );
        }

        $action = sanitize_text_field( $_POST['bump_action'] ?? 'refresh' );
        if ( ! in_array( $action, [ 'refresh', 'delete' ], true ) ) {
            ob_end_clean();
            wp_send_json_error( 'Nepoznata akcija.' );
        }

        $wc_cat_id  = intval( $_POST['wc_cat_id'] ?? 0 );
        $olx_status = sanitize_text_field( $_POST['olx_status'] ?? 'active' );
        $brand_name = sanitize_text_field( $_POST['brand_name'] ?? '' );

        $meta_query = [
            'relation' => 'AND',
            [ 'key' => '_olx_article_id', 'compare' => 'EXISTS' ],
        ];

        if ( $olx_status === 'active_hidden' ) {
            $meta_query[] = [ 'key' => '_olx_status', 'value' => [ 'active', 'hidden' ], 'compare' => 'IN' ];
        } elseif ( in_array( $olx_status, [ 'active', 'hidden' ], true ) ) {
            $meta_query[] = [ 'key' => '_olx_status', 'value' => $olx_status ];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => $meta_query,
        ];

        if ( $wc_cat_id > 0 ) {
            $args['tax_query'] = [
                [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => [ $wc_cat_id ] ],
            ];
        }

        if ( ! empty( $brand_name ) ) {
            $existing_tax = $args['tax_query'] ?? [];
            $existing_tax[] = [ 'taxonomy' => 'product_brand', 'field' => 'slug', 'terms' => [ $brand_name ] ];
            $args['tax_query'] = $existing_tax;
        }

        $pids = get_posts( $args );
        set_transient( 'drtechno_olx_filtered_bump_queue', [ 'pids' => $pids, 'action' => $action ], HOUR_IN_SECONDS );

        ob_end_clean();
        $action_label = $action === 'delete' ? 'brisanje + ponovnu objavu' : 'osvježavanje (BUMP)';
        wp_send_json_success( 'Pronađeno ' . count( $pids ) . ' artikala za ' . $action_label . '.' );
    }

    /**
     * Obradi jedan artikal iz Filtered BUMP reda čekanja.
     */
    public function filteredBumpProcess(): void {
        ob_start();
        $this->verify();
        global $wpdb;

        $queue = get_transient( 'drtechno_olx_filtered_bump_queue' );
        if ( empty( $queue['pids'] ) ) {
            ob_end_clean();
            wp_send_json_success( [ 'status' => 'complete', 'message' => 'Filtered BUMP završen!' ] );
        }

        $pids   = $queue['pids'];
        $action = $queue['action'] ?? 'refresh';
        $pid    = array_shift( $pids );
        set_transient( 'drtechno_olx_filtered_bump_queue', [ 'pids' => $pids, 'action' => $action ], HOUR_IN_SECONDS );

        $olx_id = OlxMetaHelper::getOlxId( $pid );
        if ( ! $olx_id ) {
            ob_end_clean();
            wp_send_json_success( [
                'status'  => 'processing',
                'message' => '<span style="color:#888;">Preskočeno — Nije na OLX-u (ID: ' . intval( $pid ) . ')</span>',
                'left'    => count( $pids ),
            ] );
        }

        if ( $action === 'refresh' ) {
            $resp = $this->api->massBump( $olx_id );
            if ( ! $resp['error'] ) {
                OlxMetaHelper::setLastSync( $pid );
                $msg = '<span style="color:green;">&#10003; BUMP uspješan (ID: ' . intval( $pid ) . ')</span>';
            } else {
                $msg = '<span style="color:#d63638;">&#10007; BUMP greška (ID: ' . intval( $pid ) . ') — ' . esc_html( $resp['message'] ?? 'Limit?' ) . '</span>';
            }
        } else {
            $resp = $this->api->delete( $olx_id );
            if ( ! $resp['error'] ) {
                OlxMetaHelper::clearAllMeta( $pid );
                $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
                $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $pid ) );
                $msg = '<span style="color:#0071a1;">&#8635; Obrisano + vraćeno u red za ponovnu objavu (ID: ' . intval( $pid ) . ')</span>';
            } else {
                $msg = '<span style="color:#d63638;">&#10007; Greška pri brisanju (ID: ' . intval( $pid ) . ') — ' . esc_html( $resp['message'] ?? '' ) . '</span>';
            }
        }

        ob_end_clean();
        wp_send_json_success( [ 'status' => 'processing', 'message' => $msg, 'left' => count( $pids ) ] );
    }
}
