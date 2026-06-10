<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\License\FeatureManager;

/**
 * Handles cleanup AJAX operations:
 *  - Orphan cleanup (OLX listings without WC product) — via server
 *  - Expired listing recovery — via server
 *  - Hidden listing deletion — via server
 */
class CleanupAjax extends AjaxHandler {

    private ServerClient $api;
    private ?FeatureManager $features;

    public function __construct( ServerClient $api, ?FeatureManager $features = null ) {
        $this->api      = $api;
        $this->features = $features;
    }

    public function register(): void {
        $this->action( 'drtechno_cleanup_start',           'cleanupStart' );
        $this->action( 'drtechno_cleanup_process',          'cleanupProcess' );
        $this->action( 'drtechno_expired_cleanup_start',    'expiredStart' );
        $this->action( 'drtechno_expired_cleanup_process',  'expiredProcess' );
        $this->action( 'drtechno_hidden_cleanup_start',     'hiddenStart' );
        $this->action( 'drtechno_hidden_cleanup_process',   'hiddenProcess' );
    }

    /* ================================================================== */
    /*  Orphan Cleanup — delegated to server                              */
    /* ================================================================== */

    public function cleanupStart(): void {
        $this->verify();
        if ( $this->features ) {
            $this->features->requireFeature( 'cleanup' );
        }
        wp_send_json_success( 'Čišćenje pokrenuto...' );
    }

    public function cleanupProcess(): void {
        $this->verify();

        $page_param = isset( $_POST['page'] ) ? sanitize_text_field( $_POST['page'] ) : '1';
        $page       = ( $page_param === 'start' ) ? 1 : intval( $page_param );

        // Collect all OLX IDs that WC knows about
        $wc_olx_ids = $this->getWcOlxIds();
        if ( empty( $wc_olx_ids ) ) {
            wp_send_json_error( 'Nema WooCommerce artikala sa OLX ID-jem.' );
        }

        $response = $this->api->cleanup( $page, $wc_olx_ids );

        if ( ! $response['error'] && isset( $response['data']['status'] ) ) {
            if ( $response['data']['status'] === 'complete' ) {
                wp_send_json_success( [
                    'status'  => 'complete',
                    'message' => $response['data']['message'] ?? 'Završeno!',
                ] );
            }

            wp_send_json_success( [
                'status'    => 'processing',
                'message'   => $response['data']['message'] ?? "Stranica {$page} skenirana.",
                'next_page' => $response['data']['next_page'] ?? $page + 1,
            ] );
        }

        wp_send_json_error( $response['message'] ?? 'Greška pri čišćenju.' );
    }

    /* ================================================================== */
    /*  Expired Listings — delegated to server                            */
    /* ================================================================== */

    public function expiredStart(): void {
        $this->verify();
        wp_send_json_success( 'Proces obnove isteklih oglasa pokrenut...' );
    }

    public function expiredProcess(): void {
        $this->verify();
        global $wpdb;

        $page_param = isset( $_POST['page'] ) ? sanitize_text_field( $_POST['page'] ) : '1';
        $page       = ( $page_param === 'start' ) ? 0 : intval( $page_param );
        $exp_action = in_array( $_POST['exp_action'] ?? '', [ 'refresh', 'delete' ], true )
            ? sanitize_text_field( $_POST['exp_action'] )
            : 'delete';

        $wc_olx_ids = $this->getWcOlxIds();

        $response = $this->api->expiredRecovery( $page, $wc_olx_ids, $exp_action );

        if ( ! $response['error'] && isset( $response['data']['status'] ) ) {
            if ( ! empty( $response['data']['expired_ids'] ) ) {
                $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
                foreach ( $response['data']['expired_ids'] as $olx_id ) {
                    $wc_products = get_posts( [
                        'post_type'   => 'product',
                        'post_status' => [ 'publish', 'draft', 'private' ],
                        'meta_key'    => '_olx_article_id',
                        'meta_value'  => $olx_id,
                        'fields'      => 'ids',
                    ] );

                    foreach ( $wc_products as $pid ) {
                        if ( $exp_action === 'delete' ) {
                            OlxMetaHelper::clearAllMeta( $pid );
                            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $pid ) );
                        } else {
                            // refresh — samo stavi u queue za BUMP, ne briši meta
                            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $pid ) );
                        }
                    }
                }
            }

            if ( $response['data']['status'] === 'complete' ) {
                wp_send_json_success( [
                    'status'  => 'complete',
                    'message' => $response['data']['message'] ?? 'Završeno!',
                ] );
            }

            wp_send_json_success( [
                'status'    => 'processing',
                'message'   => $response['data']['message'] ?? "Stranica {$page} obrađena.",
                'next_page' => $response['data']['next_page'] ?? null,
            ] );
        }

        wp_send_json_error( $response['message'] ?? 'Greška pri obradi isteklih oglasa.' );
    }

    /* ================================================================== */
    /*  Hidden Listings — delete via server                               */
    /* ================================================================== */

    public function hiddenStart(): void {
        $this->verify();
        wp_send_json_success( 'Proces brisanja skrivenih oglasa pokrenut...' );
    }

    public function hiddenProcess(): void {
        $this->verify();

        // page='start' za prvi poziv — server tada čita meta.last_page i kreće
        // iterirati UNAZAD (zbog OLX shifting bug-a kad se brišu listings).
        // Naredni pozivi šalju integer next_page koji server vraća.
        $page_param = isset( $_POST['page'] ) ? sanitize_text_field( $_POST['page'] ) : 'start';

        // VIP-zaštićeni ID-jevi se NE smiju brisati
        $vip_protected = $this->getVipProtectedOlxIds();

        // wc_olx_ids služi samo za clearing lokalnih meta nakon brisanja —
        // čak i prazna lista znači da možda postoje skriveni OLX oglasi (orphan)
        // koji treba da se brišu, pa nema early-return-a kao za orphan cleanup.
        $wc_olx_ids = $this->getWcOlxIds();

        $response = $this->api->request( 'cleanup', [
            'page'        => $page_param,
            'wc_olx_ids'  => $wc_olx_ids,
            'filter'      => 'hidden',
            'exclude_ids' => $vip_protected,
        ], 60 );

        if ( ! $response['error'] && isset( $response['data']['status'] ) ) {
            // Clear WC meta for deleted listings
            if ( ! empty( $response['data']['deleted_ids'] ) ) {
                foreach ( $response['data']['deleted_ids'] as $olx_id ) {
                    $wc_products = get_posts( [
                        'post_type'   => 'product',
                        'post_status' => [ 'publish', 'draft', 'private' ],
                        'meta_key'    => '_olx_article_id',
                        'meta_value'  => $olx_id,
                        'fields'      => 'ids',
                    ] );

                    foreach ( $wc_products as $pid ) {
                        OlxMetaHelper::clearAllMeta( $pid );
                    }
                }
            }

            // Server već vraća informativnu poruku "Stranica X: obrisano N..."
            // u $response['data']['message']. Koristi je direktno umjesto da
            // građimo lokalnu sa nedefinisanom $page varijablom (sad je
            // page_param string 'start' ili int — server zna pravu stranicu).
            $server_msg = $response['data']['message'] ?? '';

            if ( $response['data']['status'] === 'complete' ) {
                wp_send_json_success( [
                    'status'  => 'complete',
                    'message' => $server_msg !== ''
                        ? $server_msg
                        : 'Završeno! Svi skriveni oglasi (osim onih pod VIP zaštitom) su trajno obrisani.',
                ] );
            }

            wp_send_json_success( [
                'status'    => 'processing',
                'message'   => $server_msg !== '' ? $server_msg : 'Stranica obrađena.',
                'next_page' => $response['data']['next_page'] ?? null,
            ] );
        }

        // Server-level greška: koristi message s vrha response-a (ServerClient stavlja
        // pravu OLX/license grešku tamo). Ako je iz nekog razloga prazno → generic fallback.
        $err_msg = ( ! empty( $response['message'] ) && $response['message'] !== 'OK' )
            ? $response['message']
            : 'Greška pri brisanju skrivenih oglasa.';
        wp_send_json_error( $err_msg );
    }

    /* ================================================================== */
    /*  Helpers                                                           */
    /* ================================================================== */

    /**
     * Get all OLX article IDs stored in WooCommerce product meta.
     * Single JOIN query — replaces N+1 get_posts + get_post_meta.
     */
    private function getWcOlxIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_olx_article_id'
            AND pm.meta_value != ''
            AND p.post_type = 'product'
            AND p.post_status IN ('publish', 'draft', 'private')
        " );
        return array_values( array_filter( array_map( 'strval', $ids ) ) );
    }

    /**
     * Get OLX IDs of VIP-protected products.
     * Single JOIN query — replaces N+1.
     */
    private function getVipProtectedOlxIds(): array {
        global $wpdb;
        $ids = $wpdb->get_col( "
            SELECT pm.meta_value
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            INNER JOIN {$wpdb->postmeta} pm_vip
                ON pm_vip.post_id = p.ID
                AND pm_vip.meta_key = '_olx_special_price'
                AND CAST(pm_vip.meta_value AS DECIMAL(10,2)) > 0
            WHERE pm.meta_key = '_olx_article_id'
            AND pm.meta_value != ''
            AND p.post_type = 'product'
            AND p.post_status IN ('publish', 'draft', 'private')
        " );
        return array_values( array_filter( array_map( 'strval', $ids ) ) );
    }
}
