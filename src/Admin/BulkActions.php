<?php

namespace EbitOlx\Admin;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;

/**
 * Registers OLX-related bulk actions on the Products list table
 * and handles their execution.
 */
class BulkActions {

    private ServerClient $api;

    public function __construct( ServerClient $api ) {
        $this->api = $api;
    }

    public function register(): void {
        add_filter( 'bulk_actions-edit-product',        [ $this, 'registerActions' ] );
        add_filter( 'handle_bulk_actions-edit-product',  [ $this, 'handleActions' ], 10, 3 );
        add_action( 'admin_notices',                     [ $this, 'adminNotices' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Register                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * @param array $bulk_actions
     * @return array
     */
    public function registerActions( array $bulk_actions ): array {
        $bulk_actions['olx_mass_sync']         = 'OLX: Dodaj u red za sinhronizaciju';
        $bulk_actions['olx_bulk_attributes']   = 'OLX: Masovni unos atributa';
        $bulk_actions['olx_mass_bump']         = 'OLX: Osvježi (BUMP) oglase';
        $bulk_actions['olx_bulk_image_sync']   = 'OLX: Ažuriraj slike (Okvir)';
        return $bulk_actions;
    }

    /* ------------------------------------------------------------------ */
    /*  Handle                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $redirect_to
     * @param string $doaction
     * @param int[]  $post_ids
     * @return string
     */
    public function handleActions( string $redirect_to, string $doaction, array $post_ids ): string {
        switch ( $doaction ) {
            case 'olx_mass_sync':
                return $this->handleMassSync( $redirect_to, $post_ids );

            case 'olx_bulk_attributes':
                return $this->handleBulkAttributes( $post_ids );

            case 'olx_mass_bump':
                return $this->handleMassBump( $redirect_to, $post_ids );

            case 'olx_bulk_image_sync':
                return $this->handleBulkImageSync( $redirect_to, $post_ids );
        }

        return $redirect_to;
    }

    /* ------------------------------------------------------------------ */
    /*  Action implementations                                            */
    /* ------------------------------------------------------------------ */

    private function handleMassSync( string $redirect_to, array $post_ids ): string {
        if ( ! get_option('drtechno_olx_feat_mass_sync', 0) ) {
            return add_query_arg( 'olx_mass_sync_failed', '1', $redirect_to );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $count = 0;

        foreach ( $post_ids as $post_id ) {
            if ( get_post_meta( $post_id, '_olx_exclude_sync', true ) === 'yes' ) continue;
            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $post_id ) );
            $count++;
        }

        return add_query_arg( 'olx_queued', $count, $redirect_to );
    }

    private function handleBulkAttributes( array $post_ids ): string {
        $ids = implode( ',', array_map( 'intval', $post_ids ) );
        return admin_url( 'admin.php?page=drtechno_olx_sync&tab=bulk_attributes&ids=' . $ids );
    }

    private function handleMassBump( string $redirect_to, array $post_ids ): string {
        $bump_success = 0;
        $bump_failed  = 0;

        foreach ( $post_ids as $post_id ) {
            $olx_id = get_post_meta( $post_id, '_olx_article_id', true );
            if ( $olx_id ) {
                $resp = $this->api->massBump( $olx_id );
                if ( ! $resp['error'] ) {
                    update_post_meta( $post_id, '_olx_last_sync', current_time( 'mysql' ) );
                    $bump_success++;
                } else {
                    // 404 → listing was manually deleted
                    if ( strpos( (string) $resp['message'], 'HTTP 404' ) !== false ) {
                        delete_post_meta( $post_id, '_olx_article_id' );
                        delete_post_meta( $post_id, '_olx_status' );
                        delete_post_meta( $post_id, '_olx_last_sync' );
                        delete_post_meta( $post_id, '_olx_sync_error' );
                    }
                    $bump_failed++;
                }
            } else {
                $bump_failed++;
            }
        }

        return add_query_arg( [
            'olx_bumped'      => $bump_success,
            'olx_bump_failed' => $bump_failed,
        ], $redirect_to );
    }

    private function handleBulkImageSync( string $redirect_to, array $post_ids ): string {
        $valid_pids = [];
        foreach ( $post_ids as $pid ) {
            if ( get_post_meta( $pid, '_olx_article_id', true ) ) {
                $valid_pids[] = $pid;
            }
        }

        if ( ! empty( $valid_pids ) ) {
            set_transient( 'drtechno_olx_mass_image_queue', $valid_pids, 12 * HOUR_IN_SECONDS );
            return admin_url( 'admin.php?page=drtechno_olx_sync&tab=sync_mass&auto_start_images=' . count( $valid_pids ) );
        }

        return add_query_arg( 'olx_image_sync_failed', '1', $redirect_to );
    }

    /* ------------------------------------------------------------------ */
    /*  Admin notices                                                     */
    /* ------------------------------------------------------------------ */

    public function adminNotices(): void {
        if ( ! empty( $_REQUEST['olx_queued'] ) ) {
            printf(
                '<div id="message" class="updated notice is-dismissible"><p><strong>✓ %d artikala</strong> je uspješno dodano u OLX red čekanja.</p></div>',
                intval( $_REQUEST['olx_queued'] )
            );
        }

        if ( isset( $_REQUEST['olx_bumped'] ) ) {
            $success = intval( $_REQUEST['olx_bumped'] );
            $failed  = intval( $_REQUEST['olx_bump_failed'] ?? 0 );
            $msg     = sprintf( '<strong>✓ %d artikala</strong> je uspješno osvježeno (BUMP) na OLX-u.', $success );
            if ( $failed > 0 ) {
                $msg .= sprintf( ' <span style="color:#d63638;">(%d preskočeno).</span>', $failed );
            }
            echo '<div id="message" class="updated notice is-dismissible" style="border-left-color: #46b450;"><p>' . $msg . '</p></div>';
        }

        if ( isset( $_REQUEST['olx_image_sync_failed'] ) ) {
            echo '<div id="message" class="error notice is-dismissible"><p>Nijedan od odabranih artikala nije povezan sa OLX-om. Slike se ne mogu ažurirati.</p></div>';
        }
    }
}
