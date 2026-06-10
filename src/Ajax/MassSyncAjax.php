<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxDailyLimit;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\License\FeatureManager;
use EbitOlx\Sync\ProductSyncService;
use EbitOlx\Sync\ImageSyncService;
use EbitOlx\Logging\Logger;

/**
 * Handles mass-operation AJAX endpoints:
 *  - Mass sync (start + process)
 *  - Mass image sync (start + process)
 *  - Mass manual bump (start + process)
 *  - VIP bump (start + process)
 */
class MassSyncAjax extends AjaxHandler {

    private ServerClient $api;
    private ?FeatureManager $features;

    public function __construct( ServerClient $api, ?FeatureManager $features = null ) {
        $this->api      = $api;
        $this->features = $features;
    }

    public function register(): void {
        // Mass sync
        $this->action( 'drtechno_mass_sync_start',   'massSyncStart' );
        $this->action( 'drtechno_mass_sync_process',  'massSyncProcess' );

        // Mass image sync
        $this->action( 'drtechno_mass_image_sync_start',   'massImageSyncStart' );
        $this->action( 'drtechno_mass_image_sync_process',  'massImageSyncProcess' );

        // Manual mass bump
        $this->action( 'drtechno_mass_manual_bump_start',   'manualBumpStart' );
        $this->action( 'drtechno_mass_manual_bump_process',  'manualBumpProcess' );

        // VIP bump
        $this->action( 'drtechno_bump_special_start',   'specialBumpStart' );
        $this->action( 'drtechno_bump_special_process',  'specialBumpProcess' );

        // Info
        $this->action( 'drtechno_get_bump_info', 'getBumpInfo' );

        // Recovery / debug tools
        $this->action( 'drtechno_reset_daily_limit',   'resetDailyLimit' );
        $this->action( 'drtechno_requeue_outofstock', 'requeueOutOfStock' );
    }

    public function getBumpInfo(): void {
        $this->verify();
        
        $credits_resp = $this->api->request('credits/balance');
        $limits_resp  = $this->api->request('refresh_limits');
        
        $data = [
            'credits' => 0,
            'free_limit' => 0,
            'free_count' => 0,
            'paid_count' => 0,
            'listing_count' => 0
        ];
        
        if ( ! $credits_resp['error'] && isset($credits_resp['data']['credits']) ) {
            $data['credits'] = intval( $credits_resp['data']['credits'] );
        }
        
        if ( ! $limits_resp['error'] && isset($limits_resp['data']['free_limit']) ) {
            $data['free_limit']    = intval( $limits_resp['data']['free_limit'] );
            $data['free_count']    = intval( $limits_resp['data']['free_count'] );
            $data['paid_count']    = intval( $limits_resp['data']['paid_count'] );
            $data['listing_count'] = intval( $limits_resp['data']['listing_count'] );
        }
        
        wp_send_json_success( $data );
    }

    /* ================================================================== */
    /*  Mass Sync                                                         */
    /* ================================================================== */

    public function massSyncStart(): void {
        $this->verify();
        if ( $this->features ) {
            $this->features->requireFeature( 'mass_sync' );
        }
        global $wpdb;

        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';

        delete_transient( 'drtechno_olx_active_listings_cache' );
        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $mapped_cats = array_keys( array_filter( get_option( 'drtechno_olx_category_mapping', [] ) ) );
        if ( empty( $mapped_cats ) ) {
            wp_send_json_error( 'Nema mapiranih kategorija.' );
        }

        $instock_only = get_option( 'drtechno_olx_sync_instock_only' ) === 'yes';
        $sync_mode    = isset( $_POST['sync_mode'] ) ? sanitize_text_field( $_POST['sync_mode'] ) : 'all';

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'tax_query'      => [ [ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $mapped_cats ] ],
            'meta_query'     => [
                'relation' => 'OR',
                [ 'key' => '_olx_exclude_sync', 'compare' => 'NOT EXISTS' ],
                [ 'key' => '_olx_exclude_sync', 'value' => 'yes', 'compare' => '!=' ],
            ],
        ];

        $products     = get_posts( $args );
        $queued_count = 0;

        foreach ( $products as $pid ) {
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;

            $existing_id = get_post_meta( $pid, '_olx_article_id', true );
            $is_in_stock = $product->is_in_stock();

            if ( $sync_mode === 'new_only' && ! empty( $existing_id ) ) continue;
            if ( $sync_mode === 'update_only' && empty( $existing_id ) ) continue;

            if ( $instock_only && ! $is_in_stock ) {
                $vip = get_post_meta( $pid, '_olx_special_price', true );
                if ( empty( $vip ) || floatval( $vip ) <= 0 ) {
                    if ( empty( $existing_id ) ) continue;
                }
            }

            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $pid ) );
            $queued_count++;
        }

        wp_send_json_success( 'Ubačeno ' . $queued_count . ' proizvoda u red čekanja na osnovu vaših filtera.' );
    }

    public function massSyncProcess(): void {
        $this->verify();
        global $wpdb;

        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $item  = $wpdb->get_row( "SELECT * FROM {$table} ORDER BY id ASC LIMIT 1" );

        if ( ! $item ) {
            wp_send_json_success( [ 'status' => 'complete', 'message' => 'Završeno!' ] );
        }

        $wpdb->delete( $table, [ 'id' => $item->id ] );

        $product_name = get_the_title( $item->post_id );
        if ( empty( $product_name ) ) {
            $product_name = 'Nepoznat artikal';
        }

        $service = new ProductSyncService( $this->api, new Logger() );
        $res     = $service->syncProduct( $item->post_id, true );

        $action_status = $this->formatActionStatus( $res );

        // OLX daily limit: vrati item u queue (bez retry counter increment-a)
        // tako da ga cron batch worker pokupi nakon što transient istekne (1h).
        // Bez ovog, mass sync bi tiho pojeo sve preostale CREATE itemse iz reda.
        if ( ! $res['success'] && in_array( $res['action'] ?? '', [ 'limit', 'quota' ], true ) ) {
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (post_id, retries) VALUES (%d, %d)",
                $item->post_id,
                $item->retries
            ) );
        }

        // Retry logic (max 2 retries, skip permanent attribute errors and fatal plan errors)
        $is_fatal_plan_error = strpos( (string) $res['message'], 'Vaš plan ne uključuje funkciju' ) !== false;

        if (
            ! $res['success']
            && isset( $res['action'] )
            && $res['action'] === 'error'
            && strpos( (string) $res['message'], 'Fali obavezan atribut' ) === false
            && ! $is_fatal_plan_error
        ) {
            if ( $item->retries < 2 ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$table} (post_id, retries) VALUES (%d, %d)",
                    $item->post_id,
                    $item->retries + 1
                ) );
            }
        }

        $left        = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $log_message = sprintf(
            "ID: %d | %s | Status: <strong>%s</strong> | Ostalo: %d",
            $item->post_id,
            esc_html( mb_strimwidth( $product_name, 0, 35, '...' ) ),
            $action_status,
            $left
        );

        if ( $is_fatal_plan_error ) {
            $wpdb->query( "TRUNCATE TABLE {$table}" ); // Abort entirely
            wp_send_json_success( [ 'status' => 'complete', 'message' => $log_message ] );
        }

        wp_send_json_success( [ 'status' => 'processing', 'message' => $log_message ] );
    }

    /* ================================================================== */
    /*  Mass Image Sync                                                   */
    /* ================================================================== */

    public function massImageSyncStart(): void {
        $this->verify();

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_olx_article_id', 'compare' => 'EXISTS' ] ],
        ];

        $pids = get_posts( $args );
        set_transient( 'drtechno_olx_mass_image_queue', $pids, 12 * HOUR_IN_SECONDS );
        wp_send_json_success( 'Pripremljeno ' . count( $pids ) . ' povezanih artikala za proces ažuriranja slika.' );
    }

    public function massImageSyncProcess(): void {
        $this->verify();

        $pids = get_transient( 'drtechno_olx_mass_image_queue' );
        if ( empty( $pids ) ) {
            wp_send_json_success( [ 'status' => 'complete', 'message' => 'Sve slike su uspješno ažurirane!' ] );
        }

        $pid = array_shift( $pids );
        set_transient( 'drtechno_olx_mass_image_queue', $pids, 12 * HOUR_IN_SECONDS );

        $olx_id = OlxMetaHelper::getOlxId( $pid );

        if ( $olx_id ) {
            $service = new ImageSyncService( $this->api );
            $res     = $service->syncImages( $pid, intval( $olx_id ), false );
            $msg     = '<span style="color:green;">&#10003; ID ' . $pid . ': ' . $res['message'] . '</span>';
        } else {
            $msg = '<span style="color:#888;">Preskočeno (Nije na OLX-u)</span>';
        }

        wp_send_json_success( [ 'status' => 'processing', 'message' => $msg, 'left' => count( $pids ) ] );
    }

    /* ================================================================== */
    /*  Manual Mass Bump                                                  */
    /* ================================================================== */

    public function manualBumpStart(): void {
        $this->verify();
        if ( $this->features ) {
            $this->features->requireFeature( 'mass_bump' );
        }

        $raw_pids = isset( $_POST['pids'] ) ? (array) $_POST['pids'] : [];
        $pids     = array_values( array_filter( array_map( 'absint', $raw_pids ) ) );
        if ( empty( $pids ) ) {
            wp_send_json_error( 'Nema artikala.' );
        }

        set_transient( 'drtechno_olx_manual_bump_queue', $pids, HOUR_IN_SECONDS );
        wp_send_json_success( 'Pripremljeno ' . count( $pids ) . ' artikala za osvježavanje.' );
    }

    public function manualBumpProcess(): void {
        $this->verify();

        $pids = get_transient( 'drtechno_olx_manual_bump_queue' );
        if ( empty( $pids ) ) {
            wp_send_json_success( [
                'status'  => 'complete',
                'message' => '<span style="color:green; font-weight:bold;">Svi odabrani artikli su obrađeni!</span>',
            ] );
        }

        $pid = array_shift( $pids );
        set_transient( 'drtechno_olx_manual_bump_queue', $pids, HOUR_IN_SECONDS );

        $olx_id = OlxMetaHelper::getOlxId( $pid );

        if ( $olx_id ) {
            $resp = $this->api->massBump( $olx_id );
            if ( ! $resp['error'] ) {
                OlxMetaHelper::setLastSync( $pid );
                $msg = '<span style="color:green;">&#10003; BUMP uspješan (Proizvod ID: ' . intval( $pid ) . ')</span>';
            } else {
                $err_msg = $resp['message'] ?? wp_json_encode( $resp['raw_data'] );
                $msg = '<span style="color:#d63638;">&#10007; Greška ili Limit (ID: ' . intval( $pid ) . ') - ' . esc_html( $err_msg ) . '</span>';
            }
        } else {
            $msg = '<span style="color:#888;">Preskočeno - Nije na OLX-u (ID: ' . intval( $pid ) . ')</span>';
        }

        wp_send_json_success( [ 'status' => 'processing', 'message' => $msg, 'left' => count( $pids ) ] );
    }

    /* ================================================================== */
    /*  VIP (Special) Bump                                                */
    /* ================================================================== */

    public function specialBumpStart(): void {
        $this->verify();
        if ( $this->features ) {
            $this->features->requireFeature( 'vip_articles' );
        }

        $args = [
            'post_type'      => 'product',
            'meta_key'       => '_olx_special_price',
            'posts_per_page' => -1,
            'fields'         => 'ids',
        ];

        $pids = get_posts( $args );
        set_transient( 'drtechno_olx_special_bump_queue', $pids, HOUR_IN_SECONDS );
        wp_send_json_success( 'Pripremljeno ' . count( $pids ) . ' VIP artikala za BUMP.' );
    }

    public function specialBumpProcess(): void {
        $this->verify();

        $pids = get_transient( 'drtechno_olx_special_bump_queue' );
        if ( empty( $pids ) ) {
            wp_send_json_success( [ 'status' => 'complete', 'message' => 'Svi VIP artikli su obrađeni!' ] );
        }

        $pid = array_shift( $pids );
        set_transient( 'drtechno_olx_special_bump_queue', $pids, HOUR_IN_SECONDS );

        $olx_id = OlxMetaHelper::getOlxId( $pid );

        if ( $olx_id ) {
            $resp = $this->api->refresh( $olx_id );
            if ( ! $resp['error'] ) {
                OlxMetaHelper::setLastSync( $pid );
                $msg = '<span style="color:green;">&#10003; BUMP uspješan (Proizvod ID: ' . intval( $pid ) . ')</span>';
            } else {
                $msg = '<span style="color:#d63638;">&#10007; BUMP greška (ID: ' . intval( $pid ) . ') - Možda je dostignut limit.</span>';
            }
        } else {
            $msg = '<span style="color:#888;">Nije na OLX-u (ID: ' . intval( $pid ) . ')</span>';
        }

        wp_send_json_success( [ 'status' => 'processing', 'message' => $msg, 'left' => count( $pids ) ] );
    }

    /* ================================================================== */
    /*  Helpers                                                           */
    /* ================================================================== */

    private function formatActionStatus( array $res ): string {
        if ( ! isset( $res['action'] ) ) {
            return '<span style="color:#d63638;">GREŠKA</span>';
        }

        $has_hidden = strpos( $res['message'] ?? '', 'Sakriveno' ) !== false;

        switch ( $res['action'] ) {
            case 'new':
                return '<span style="color:green;">NOVI' . ( $has_hidden ? ' (Sakriven)' : '' ) . '</span>';
            case 'update':
                return '<span style="color:#ffb900;">UPDATE' . ( $has_hidden ? ' (Sakriven)' : '' ) . '</span>';
            case 'hidden_vip':
            case 'hidden':
                return '<span style="color:#ffb900; font-weight:bold;">SAKRIVENO (OOS)</span>';
            case 'deleted_outofstock':
                return '<span style="color:#0071a1;">OBRISANO (OOS)</span>';
            case 'skip':
                return '<span style="color:#888;">PRESKOČENO</span>';
            case 'limit':
                return '<span style="color:#dba617;font-weight:bold;" title="OLX dnevni limit od 350 novih oglasa dostignut. Plugin pauzira CREATE 1h. UPDATE postojećih nastavlja normalno.">⏸ LIMIT — čeka oslobađanje</span>';
            case 'quota':
                return '<span style="color:#dba617;font-weight:bold;" title="Dnevna licencna kvota plana iskorištena. Server pauzira sve license-tied operacije do reseta u ponoć (Sarajevo).">⏸ KVOTA — čeka reset (ponoć)</span>';
            default:
                return '<span style="color:#d63638;" title="' . esc_attr( $res['message'] ) . '">GREŠKA (' . esc_html( mb_strimwidth( $res['message'], 0, 50, '...' ) ) . ')</span>';
        }
    }

    /* ================================================================== */
    /*  Recovery / Debug Tools                                            */
    /* ================================================================== */

    /**
     * Manuelno briše OLX daily-limit transient flag (PR #4 feature).
     * Korisno ako korisnik zna da je 24h prozor istekao i ne želi čekati
     * 1h auto-recovery probe period.
     */
    public function resetDailyLimit(): void {
        $this->verify();

        $was_set = OlxDailyLimit::isReached();
        OlxDailyLimit::clear();

        wp_send_json_success( $was_set
            ? 'OLX daily-limit flag obrisan. Sljedeći CREATE pokušaj ide bez čekanja.'
            : 'Daily-limit flag nije bio aktivan — ništa nije promijenjeno.' );
    }

    /**
     * Bulk insert svih outofstock proizvoda sa _olx_article_id u sync queue.
     * Backup mehanizam ako stock-change hook nije uhvatio promjenu (direktan SQL,
     * plugin koji bypasuje WC API, itd.).
     */
    public function requeueOutOfStock(): void {
        $this->verify();
        global $wpdb;
        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';

        $sql = "
            INSERT IGNORE INTO {$table} (post_id, retries)
            SELECT DISTINCT p.ID, 0
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_olx
                ON pm_olx.post_id = p.ID
                AND pm_olx.meta_key = '_olx_article_id'
                AND pm_olx.meta_value != ''
            INNER JOIN {$wpdb->postmeta} pm_stock
                ON pm_stock.post_id = p.ID
                AND pm_stock.meta_key = '_stock_status'
                AND pm_stock.meta_value = 'outofstock'
            LEFT JOIN {$wpdb->postmeta} pm_excl
                ON pm_excl.post_id = p.ID
                AND pm_excl.meta_key = '_olx_exclude_sync'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND (pm_excl.meta_value IS NULL OR pm_excl.meta_value != 'yes')
        ";
        $count = $wpdb->query( $sql );

        wp_send_json_success( sprintf( 'Ubačeno %d outofstock proizvoda u queue.', (int) $count ) );
    }
}
