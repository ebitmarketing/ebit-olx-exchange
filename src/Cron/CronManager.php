<?php

namespace EbitOlx\Cron;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\License\FeatureManager;
use EbitOlx\Sync\ProductSyncService;
use EbitOlx\Logging\Logger;

/**
 * Manages all cron-related logic:
 *  - Custom cron intervals
 *  - Scheduling / unscheduling events
 *  - Batch worker (processes product queue)
 *  - Daily populator (fills queue for full re-sync)
 *  - Sponsor worker (processes pending sponsor requests)
 *  - Auto-queue on product update
 *  - Auto-delete on product trash
 */
class CronManager {

    private ServerClient $api;
    private ?FeatureManager $features;

    public function __construct( ServerClient $api, ?FeatureManager $features = null ) {
        $this->api      = $api;
        $this->features = $features;
    }

    /**
     * Register all cron hooks with WordPress.
     */
    public function register(): void {
        add_filter( 'cron_schedules', [ $this, 'addIntervals' ] );
        add_action( 'init', [ $this, 'scheduleJobs' ] );

        add_action( 'drtechno_olx_batch_worker_event',     [ $this, 'batchWorker' ] );
        add_action( 'drtechno_olx_daily_populator_event',  [ $this, 'dailyPopulator' ] );
        add_action( 'drtechno_olx_sponsor_worker_event',   [ $this, 'sponsorWorker' ] );

        // Auto-queue / auto-delete hooks
        add_action( 'woocommerce_update_product',           [ $this, 'autoQueueOnUpdate' ], 10, 1 );
        add_action( 'woocommerce_product_set_stock_status', [ $this, 'autoQueueOnStockChange' ], 10, 3 );
        add_action( 'wp_trash_post',                        [ $this, 'autoDeleteOnTrash' ] );
        add_action( 'before_delete_post',                   [ $this, 'autoDeleteOnTrash' ] );
    }

    /* ================================================================== */
    /*  Cron Intervals                                                    */
    /* ================================================================== */

    /**
     * @param array $schedules
     * @return array
     */
    public function addIntervals( array $schedules ): array {
        $schedules['drtechno_2_minutes'] = [
            'interval' => 120,
            'display'  => 'Svake 2 minute (EbitOlx Worker)',
        ];
        return $schedules;
    }

    /* ================================================================== */
    /*  Scheduling                                                        */
    /* ================================================================== */

    public function scheduleJobs(): void {
        if ( ! wp_next_scheduled( 'drtechno_olx_batch_worker_event' ) ) {
            wp_schedule_event( time(), 'drtechno_2_minutes', 'drtechno_olx_batch_worker_event' );
        }

        if ( ! wp_next_scheduled( 'drtechno_olx_sponsor_worker_event' ) ) {
            wp_schedule_event( time(), 'drtechno_2_minutes', 'drtechno_olx_sponsor_worker_event' );
        }

        if ( get_option( 'drtechno_olx_enable_daily_populator' ) === 'yes' ) {
            if ( ! wp_next_scheduled( 'drtechno_olx_daily_populator_event' ) ) {
                $site_minute      = abs( crc32( get_site_url() ) ) % 60;
                $site_default     = sprintf( '02:%02d', $site_minute );
                $cron_time        = get_option( 'drtechno_olx_daily_populator_time', $site_default );
                $target_timestamp = strtotime( $cron_time );
                if ( $target_timestamp <= time() ) {
                    $target_timestamp += DAY_IN_SECONDS;
                }
                wp_schedule_event( $target_timestamp, 'daily', 'drtechno_olx_daily_populator_event' );
            }
        } else {
            $timestamp = wp_next_scheduled( 'drtechno_olx_daily_populator_event' );
            if ( $timestamp ) {
                wp_unschedule_event( $timestamp, 'drtechno_olx_daily_populator_event' );
            }
        }
    }

    /* ================================================================== */
    /*  Batch Worker                                                      */
    /* ================================================================== */

    public function batchWorker(): void {
        global $wpdb;
        $table      = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $batch_size = intval( get_option( 'drtechno_olx_cron_batch_size', 30 ) );

        if ( $batch_size <= 0 ) {
            $batch_size = 30;
        }

        $items = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC LIMIT {$batch_size}" );
        if ( empty( $items ) ) return;

        $service = new ProductSyncService( $this->api, new Logger() );

        foreach ( $items as $item ) {
            $wpdb->delete( $table, [ 'id' => $item->id ] );
            $res = $service->syncProduct( $item->post_id, true );

            // OLX daily limit ('limit') ili license daily quota ('quota'):
            // vrati item u queue (bez retry counter increment-a) tako da se
            // proba opet kad blokada nestane (OLX: 1h sliding, license: midnight reset).
            if ( ! $res['success'] && in_array( $res['action'] ?? '', [ 'limit', 'quota' ], true ) ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$table} (post_id, retries) VALUES (%d, %d)",
                    $item->post_id,
                    $item->retries
                ) );
                continue;
            }

            // Retry on transient errors (max 2), skip permanent attribute errors and fatal plan errors
            $is_fatal_plan_error = strpos( (string) $res['message'], 'Vaš plan ne uključuje funkciju' ) !== false;

            if ( $is_fatal_plan_error ) {
                $wpdb->query( "TRUNCATE TABLE {$table}" );
                break; // Stop processing the rest of the batch
            }

            if (
                ! $res['success']
                && isset( $res['action'] )
                && $res['action'] === 'error'
                && strpos( (string) $res['message'], 'Fali obavezan atribut' ) === false
            ) {
                if ( $item->retries < 2 ) {
                    $wpdb->query( $wpdb->prepare(
                        "INSERT INTO {$table} (post_id, retries) VALUES (%d, %d)",
                        $item->post_id,
                        $item->retries + 1
                    ) );
                }
            }
        }
    }

    /* ================================================================== */
    /*  Daily Populator                                                   */
    /* ================================================================== */

    public function dailyPopulator(): void {
        if ( get_option( 'drtechno_olx_enable_daily_populator' ) !== 'yes' ) return;

        global $wpdb;
        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';

        $wpdb->query( "TRUNCATE TABLE {$table}" );

        $mapped_cats = array_keys( array_filter( get_option( 'drtechno_olx_category_mapping', [] ) ) );
        if ( empty( $mapped_cats ) ) return;

        $instock_only = get_option( 'drtechno_olx_sync_instock_only' ) === 'yes';
        $cat_ids      = implode( ',', array_map( 'intval', $mapped_cats ) );

        // Single SQL: products with mapped category + OLX ID + not excluded
        $wpdb->query( "
            INSERT IGNORE INTO {$table} (post_id)
            SELECT DISTINCT p.ID
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_olx
                ON pm_olx.post_id = p.ID
                AND pm_olx.meta_key = '_olx_article_id'
                AND pm_olx.meta_value != ''
            INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
            INNER JOIN {$wpdb->term_taxonomy} tt
                ON tt.term_taxonomy_id = tr.term_taxonomy_id
                AND tt.taxonomy = 'product_cat'
                AND tt.term_id IN ({$cat_ids})
            LEFT JOIN {$wpdb->postmeta} pm_excl
                ON pm_excl.post_id = p.ID
                AND pm_excl.meta_key = '_olx_exclude_sync'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND (pm_excl.meta_value IS NULL OR pm_excl.meta_value != 'yes')
        " );

        // Filtriranje out-of-stock (osim VIP artikala sa specijalnom cijenom)
        if ( $instock_only ) {
            $wpdb->query( "
                DELETE q FROM {$table} q
                INNER JOIN {$wpdb->postmeta} pm_stock
                    ON pm_stock.post_id = q.post_id
                    AND pm_stock.meta_key = '_stock_status'
                LEFT JOIN {$wpdb->postmeta} pm_vip
                    ON pm_vip.post_id = q.post_id
                    AND pm_vip.meta_key = '_olx_special_price'
                WHERE pm_stock.meta_value = 'outofstock'
                AND (pm_vip.meta_value IS NULL OR CAST(pm_vip.meta_value AS DECIMAL(10,2)) <= 0)
            " );
        }
    }

    /* ================================================================== */
    /*  Sponsor Worker                                                    */
    /* ================================================================== */

    public function sponsorWorker(): void {
        if ( $this->features && ! $this->features->can( 'sponsor' ) ) return;

        $args = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'draft', 'private' ],
            'posts_per_page' => 15,
            'meta_query'     => [
                [ 'key' => '_olx_sponsor_status', 'value' => 'pending' ],
                [ 'key' => '_olx_sponsor_time', 'value' => current_time( 'timestamp' ), 'compare' => '<=' ],
            ],
        ];

        $posts = get_posts( $args );

        foreach ( $posts as $p ) {
            $olx_id = OlxMetaHelper::getOlxId( $p->ID );
            $params = OlxMetaHelper::getSponsorParams( $p->ID );

            if ( $olx_id && $params ) {
                $response = $this->api->sponsorActivate( $olx_id, $params );

                if ( ! $response['error'] ) {
                    OlxMetaHelper::setSponsorActive( $p->ID );
                } else {
                    $err = $response['message'] ?? json_encode( $response['raw_data'] );
                    OlxMetaHelper::setSponsorError( $p->ID, $err );
                }
            } else {
                OlxMetaHelper::setSponsorError( $p->ID, 'Artikal nije povezan sa OLX-om ili nema parametara.' );
            }
        }
    }

    /* ================================================================== */
    /*  Auto-Queue on Product Update                                      */
    /* ================================================================== */

    public function autoQueueOnUpdate( int $product_id ): void {
        if ( $this->isAutomatedContext() ) return;
        if ( ! is_admin() || empty( $_POST ) ) return;

        // Prevent recursion
        remove_action( 'woocommerce_update_product', [ $this, 'autoQueueOnUpdate' ], 10 );

        $olx_id       = get_post_meta( $product_id, '_olx_article_id', true );
        $exclude_sync = get_post_meta( $product_id, '_olx_exclude_sync', true );

        if ( ! empty( $olx_id ) && $exclude_sync !== 'yes' ) {
            global $wpdb;
            $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id, retries) VALUES (%d, 0)", $product_id ) );
        }

        add_action( 'woocommerce_update_product', [ $this, 'autoQueueOnUpdate' ], 10, 1 );
    }

    /**
     * Queue-uj proizvod na OLX update kad se promijeni stock status.
     *
     * Hook: woocommerce_product_set_stock_status
     * Args: ($product_id, $stock_status, $product)
     *
     * Relaxed context check vs autoQueueOnUpdate — bulk-update plugin-i,
     * REST API klijenti, programatic poziva sve treba da queue-uju kad
     * korisnik EXPLICIT-no mijenja stock status. Skipuje se SAMO:
     *   - DOING_CRON (ne loop kad batch worker sam piše stock)
     *   - WP_IMPORTING (početni import shop-a ne treba spamovati OLX)
     */
    public function autoQueueOnStockChange( $product_id, $stock_status, $product = null ): void {
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) return;
        if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) return;

        $olx_id  = get_post_meta( $product_id, '_olx_article_id', true );
        $exclude = get_post_meta( $product_id, '_olx_exclude_sync', true );

        if ( ! empty( $olx_id ) && $exclude !== 'yes' ) {
            global $wpdb;
            $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$table} (post_id, retries) VALUES (%d, 0)",
                $product_id
            ) );
        }
    }

    /* ================================================================== */
    /*  Auto-Delete on Trash                                              */
    /* ================================================================== */

    public function autoDeleteOnTrash( int $post_id ): void {
        if ( $this->isAutomatedContext() ) return;
        if ( ! is_admin() ) return;
        if ( get_post_type( $post_id ) !== 'product' ) return;

        $olx_id = OlxMetaHelper::getOlxId( $post_id );
        if ( $olx_id ) {
            $this->api->delete( $olx_id );
            OlxMetaHelper::clearAllMeta( $post_id );
        }
    }

    /* ================================================================== */
    /*  Helpers                                                           */
    /* ================================================================== */

    /**
     * Check if we're in a context that shouldn't trigger auto-sync
     * (cron, import, REST, WP-CLI).
     */
    private function isAutomatedContext(): bool {
        return ( defined( 'DOING_CRON' ) && DOING_CRON )
            || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( 'WP_CLI' ) && WP_CLI );
    }
}
