<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\License\FeatureManager;
use EbitOlx\Sync\ProductSyncService;
use EbitOlx\Sync\ImageSyncService;
use EbitOlx\Image\ImageProcessor;
use EbitOlx\Logging\Logger;

/**
 * Handles single-product AJAX operations:
 *  - Publish / Update to OLX
 *  - Delete from OLX
 *  - Refresh (BUMP) on OLX
 *  - Sync images
 *  - Preview image locally
 *  - Search products (Select2)
 */
class ProductAjax extends AjaxHandler {

    private ServerClient $api;
    private FeatureManager $featureManager;

    public function __construct( ServerClient $api, FeatureManager $featureManager ) {
        $this->api            = $api;
        $this->featureManager = $featureManager;
    }

    public function register(): void {
        $this->action( 'drtechno_publish_to_olx',   'publish' );
        $this->action( 'drtechno_delete_from_olx',   'delete' );
        $this->action( 'drtechno_refresh_olx',        'refresh' );
        $this->action( 'drtechno_sync_images',         'syncImages' );
        $this->action( 'drtechno_preview_image',       'previewImage' );
        $this->action( 'drtechno_search_products',     'searchProducts' );
        $this->action( 'drtechno_preview_description', 'previewDescription' );
        $this->action( 'drtechno_get_product_prices',  'getProductPrices' );
    }

    /* ------------------------------------------------------------------ */
    /*  Publish / Update                                                  */
    /* ------------------------------------------------------------------ */
    public function publish(): void {
        $this->verify();

        $service = new ProductSyncService( $this->api, new Logger() );
        $res     = $service->syncProduct( intval( $_POST['post_id'] ?? 0 ) );

        $res['success']
            ? wp_send_json_success( $res['message'] )
            : wp_send_json_error( $res );
    }

    /* ------------------------------------------------------------------ */
    /*  Delete                                                            */
    /* ------------------------------------------------------------------ */
    public function delete(): void {
        $this->verify();

        $post_id = intval( $_POST['post_id'] );
        $existing_id = get_post_meta( $post_id, '_olx_article_id', true );

        if ( $existing_id ) {
            $this->api->delete( $existing_id );
            delete_post_meta( $post_id, '_olx_article_id' );
            delete_post_meta( $post_id, '_olx_status' );
            delete_post_meta( $post_id, '_olx_last_sync' );
            delete_post_meta( $post_id, '_olx_sync_error' );
            wp_send_json_success( 'Obrisano.' );
        }

        wp_send_json_error( 'Artikal nije na OLX-u.' );
    }

    /* ------------------------------------------------------------------ */
    /*  Refresh (BUMP)                                                    */
    /* ------------------------------------------------------------------ */
    public function refresh(): void {
        $this->verify();

        $post_id     = intval( $_POST['post_id'] );
        $existing_id = get_post_meta( $post_id, '_olx_article_id', true );

        if ( ! $existing_id ) {
            wp_send_json_error( 'Artikal nije na OLX-u.' );
        }

        $resp = $this->api->refresh( $existing_id );

        if ( ! $resp['error'] ) {
            update_post_meta( $post_id, '_olx_last_sync', current_time( 'mysql' ) );
            wp_send_json_success( 'BUMP Uspješan!' );
        }

        // 404 → listing was manually deleted on OLX
        if ( strpos( (string) ( $resp['message'] ?? '' ), '404' ) !== false ) {
            delete_post_meta( $post_id, '_olx_article_id' );
            delete_post_meta( $post_id, '_olx_status' );
            delete_post_meta( $post_id, '_olx_last_sync' );
            delete_post_meta( $post_id, '_olx_sync_error' );
            wp_send_json_error( 'Oglas je ručno obrisan na OLX-u! Veza je prekinuta. Možete ga kreirati ponovo.' );
        }

        $err_msg = $resp['message'] ?? 'Nepoznata greška';

        wp_send_json_error( 'Greška OLX-a: ' . $err_msg );
    }

    /* ------------------------------------------------------------------ */
    /*  Sync Images                                                       */
    /* ------------------------------------------------------------------ */
    public function syncImages(): void {
        $this->verify();

        $post_id = intval( $_POST['post_id'] );
        $olx_id  = get_post_meta( $post_id, '_olx_article_id', true );

        if ( ! $olx_id ) {
            wp_send_json_error( 'Artikal nije na OLX-u.' );
        }

        $force   = ! empty( $_POST['force_regen'] );
        $service = new ImageSyncService( $this->api, $this->featureManager );
        $res     = $service->syncImages( $post_id, intval( $olx_id ), false, $force );

        $res['success']
            ? wp_send_json_success( $res['message'] )
            : wp_send_json_error( $res['message'] );
    }

    /* ------------------------------------------------------------------ */
    /*  Preview Image (generate locally, don't upload)                    */
    /* ------------------------------------------------------------------ */
    public function previewImage(): void {
        $this->verify();

        $post_id  = intval( $_POST['post_id'] );
        $product  = wc_get_product( $post_id );

        if ( ! $product ) {
            wp_send_json_error( 'Proizvod ne postoji.' );
        }

        $image_id = $product->get_image_id();
        if ( ! $image_id ) {
            wp_send_json_error( 'Proizvod nema slike.' );
        }

        $processor = new ImageProcessor();
        $img_url   = $processor->process( $post_id, $image_id, true );

        wp_send_json_success( $img_url );
    }

    /* ------------------------------------------------------------------ */
    /*  Search Products (for Select2)                                     */
    /* ------------------------------------------------------------------ */
    public function searchProducts(): void {
        $this->verify();

        $term    = isset( $_GET['q'] ) ? sanitize_text_field( $_GET['q'] ) : '';
        $args    = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $term,
            'posts_per_page' => 20,
        ];
        $query   = new \WP_Query( $args );
        $results = [];

        foreach ( $query->posts as $p ) {
            $product = wc_get_product( $p->ID );
            if ( ! $product ) {
                continue;
            }
            $sku       = $product->get_sku() ? ' (SKU: ' . $product->get_sku() . ')' : '';
            $results[] = [ 'id' => $p->ID, 'text' => $p->post_title . $sku ];
        }

        wp_send_json( [ 'results' => $results ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Preview Description (renderuje template bez API poziva)           */
    /* ------------------------------------------------------------------ */
    public function previewDescription(): void {
        $this->verify();

        if ( ! $this->featureManager->can( 'desc_settings' ) ) {
            wp_send_json_error( 'Vaš plan ne uključuje funkciju postavki opisa.' );
        }

        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'Nevažeći ID artikla.' );
        }

        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( 'Artikal nije pronađen.' );
        }

        $builder = new \EbitOlx\Sync\DescriptionBuilder( $this->featureManager );
        $html    = $builder->build( $product, (float) $product->get_price(), 0 );

        wp_send_json_success( [ 'html' => $html ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Get Product Prices (za visual badge editor)                       */
    /* ------------------------------------------------------------------ */
    public function getProductPrices(): void {
        $this->verify();

        $post_id = intval( $_POST['post_id'] ?? 0 );
        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( 'Artikal nije pronađen.' );
        }

        $regular = $product->get_regular_price();
        $sale    = $product->get_sale_price();
        $vip     = get_post_meta( $post_id, '_olx_special_price', true );

        wp_send_json_success( [
            'regular' => $regular !== '' ? wc_format_localized_price( $regular ) : '0',
            'sale'    => $sale !== '' ? wc_format_localized_price( $sale ) : ( $regular !== '' ? wc_format_localized_price( $regular ) : '0' ),
            'vip'     => $vip !== '' ? wc_format_localized_price( $vip ) : ( $regular !== '' ? wc_format_localized_price( $regular ) : '0' ),
        ] );
    }
}
