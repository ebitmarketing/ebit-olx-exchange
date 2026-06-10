<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OptionsCache;

/**
 * Thin proxy for AI title generation — all Gemini logic runs on server-sync backend.
 * Gemini API key is stored encrypted per-license on server, never in WP options.
 */
class AiTitleAjax extends AjaxHandler {

    private ServerClient $api;

    public function __construct( ServerClient $api ) {
        $this->api = $api;
    }

    public function register(): void {
        $this->action( 'drtechno_save_gemini_key',       'saveGeminiKey' );
        $this->action( 'drtechno_fetch_gemini_models',   'fetchModels' );
        $this->action( 'drtechno_generate_olx_title',    'generateSingle' );
        $this->action( 'drtechno_ai_title_gen_start',    'bulkStart' );
        $this->action( 'drtechno_ai_title_gen_process',  'bulkProcess' );
    }

    public function saveGeminiKey(): void {
        $this->verify();
        $key = sanitize_text_field( $_POST['gemini_api_key'] ?? '' );
        $res = $this->api->request( 'ai_title/settings', [ 'sub' => 'save', 'gemini_api_key' => $key ] );
        if ( $res['error'] ) {
            wp_send_json_error( $res['message'] ?? 'Greška pri čuvanju.' );
        }
        wp_send_json_success( 'API ključ sačuvan na serveru.' );
    }

    public function fetchModels(): void {
        $this->verify();
        $res = $this->api->request( 'ai_title/models' );
        if ( $res['error'] ) {
            wp_send_json_error( $res['message'] ?? 'Greška.' );
        }
        wp_send_json_success( $res['data'] ?? [] );
    }

    public function generateSingle(): void {
        $this->verify();
        $post_id = intval( $_POST['post_id'] ?? 0 );
        if ( ! $post_id ) {
            wp_send_json_error( 'Nevažeći ID artikla.' );
        }
        $product = wc_get_product( $post_id );
        if ( ! $product ) {
            wp_send_json_error( 'Artikal nije pronađen.' );
        }
        $res = $this->api->request( 'ai_title/generate', $this->buildProductData( $product ) );
        if ( $res['error'] ) {
            wp_send_json_error( $res['message'] ?? 'Greška.' );
        }
        $title = $res['data']['title'] ?? '';
        update_post_meta( $post_id, '_olx_title', $title );
        wp_send_json_success( [ 'title' => $title ] );
    }

    public function bulkStart(): void {
        $this->verify();
        $pids = get_posts( [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'meta_query'     => [ [ 'key' => '_olx_article_id', 'compare' => 'EXISTS' ] ],
        ] );
        set_transient( 'drtechno_olx_ai_title_queue', $pids, 2 * HOUR_IN_SECONDS );
        wp_send_json_success( 'Pripremljeno ' . count( $pids ) . ' artikala za AI generisanje naslova.' );
    }

    public function bulkProcess(): void {
        $this->verify();
        $pids = get_transient( 'drtechno_olx_ai_title_queue' );
        if ( empty( $pids ) ) {
            wp_send_json_success( [ 'status' => 'complete', 'message' => 'AI generisanje završeno!' ] );
        }
        $pid = array_shift( $pids );
        set_transient( 'drtechno_olx_ai_title_queue', $pids, 2 * HOUR_IN_SECONDS );
        $product = wc_get_product( $pid );
        if ( ! $product ) {
            wp_send_json_success( [
                'status'  => 'processing',
                'message' => '<span style="color:#888;">Preskočeno — ID ' . intval( $pid ) . ' nije validan artikal.</span>',
                'left'    => count( $pids ),
            ] );
        }
        $res = $this->api->request( 'ai_title/generate', $this->buildProductData( $product ) );
        if ( $res['error'] ) {
            $msg = '<span style="color:#d63638;">&#10007; ID ' . intval( $pid ) . ': ' . esc_html( $res['message'] ?? 'Greška' ) . '</span>';
        } else {
            $title = $res['data']['title'] ?? '';
            update_post_meta( $pid, '_olx_title', $title );
            $preview = mb_strimwidth( $title, 0, 55, '...', 'UTF-8' );
            $msg = '<span style="color:green;">&#10003; ID ' . intval( $pid ) . ': ' . esc_html( $preview ) . '</span>';
        }
        wp_send_json_success( [ 'status' => 'processing', 'message' => $msg, 'left' => count( $pids ) ] );
    }

    private function buildProductData( \WC_Product $product ): array {
        $pid    = $product->get_id();
        $cats   = wp_get_post_terms( $pid, 'product_cat', [ 'fields' => 'names' ] );
        $brands = wp_get_post_terms( $pid, 'product_brand', [ 'fields' => 'names' ] );
        return [
            'name'        => $product->get_name(),
            'category'    => implode( ', ', is_array( $cats ) ? $cats : [] ),
            'brand'       => implode( ', ', is_array( $brands ) ? $brands : [] ),
            'price'       => $product->get_price(),
            'description' => wp_strip_all_tags( $product->get_short_description() ),
            'sku'         => $product->get_sku(),
            'model'       => OptionsCache::get( 'drtechno_olx_gemini_model', 'gemini-2.0-flash' ),
            'prompt'      => OptionsCache::get( 'drtechno_olx_gemini_prompt', '' ),
        ];
    }
}
