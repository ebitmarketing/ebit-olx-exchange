<?php

namespace EbitOlx\Image;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;
use EbitOlx\Sync\PriceCalculator;

class ImageProcessor {

    private FrameRenderer $frameRenderer;
    private BadgeRenderer $badgeRenderer;
    private PriceCalculator $priceCalculator;

    public function __construct() {
        $this->frameRenderer = new FrameRenderer();
        $this->badgeRenderer = new BadgeRenderer();
        $this->priceCalculator = new PriceCalculator();
    }

    /**
     * Process a product image: apply frame and/or badge overlay.
     *
     * Replaces the monolith's apply_olx_frame() method.
     *
     * @param int  $productId  WooCommerce product ID
     * @param int  $imageId    WordPress attachment ID
     * @param bool $force      Force regeneration even if cached
     * @return string URL of the processed image (or original if no processing needed)
     */
    public function process( int $productId, int $imageId, bool $force = false ): string {
        $product    = wc_get_product( $productId );
        $image_path = get_attached_file( $imageId );

        if ( ! file_exists( $image_path ) ) {
            return wp_get_attachment_url( $imageId );
        }

        // Determine what to draw
        $draw = $this->resolveDrawMode( $product );

        if ( ! $draw['frame'] && ! $draw['badge'] ) {
            return wp_get_attachment_url( $imageId );
        }

        // Check cache
        $upload_dir = wp_upload_dir();
        $frame_dir  = $upload_dir['basedir'] . '/olx-frames';
        if ( ! file_exists( $frame_dir ) ) {
            wp_mkdir_p( $frame_dir );
        }

        $out_file = $frame_dir . '/olx_frame_prod_' . $productId . '.jpg';
        $out_url  = $upload_dir['baseurl'] . '/olx-frames/olx_frame_prod_' . $productId . '.jpg';

        if ( ! $force && $this->isCacheValid( $out_file, $image_path, $draw ) ) {
            return $out_url;
        }

        // Get frame path
        $frame_path = null;
        if ( $draw['frame'] ) {
            $frame_id = OptionsCache::get( 'drtechno_olx_image_frame' );
            if ( $frame_id ) {
                $path = get_attached_file( $frame_id );
                if ( $path && file_exists( $path ) ) {
                    $frame_path = $path;
                }
            }
        }

        // Render canvas with frame
        $canvas = $this->frameRenderer->render( $image_path, $frame_path );
        if ( ! $canvas ) {
            return wp_get_attachment_url( $imageId );
        }

        // Apply badge if needed
        if ( $draw['badge'] ) {
            $calc_price = $this->priceCalculator->calculate( $product, $productId );
            $calc_reg_price = $calc_price;
            $calc_sale_price = $calc_price;
            
            // If the item is on sale natively but has rules, or just natively on sale
            if ( $product->is_on_sale() && ! $draw['is_vip'] ) {
                // If it's a simple percent reduction or just plain sale, we need a regular price
                // We'll approximate regular price by looking at original margins, or just pass raw regular
                // Actually the safest is to let BadgeRenderer handle original regular, but pass calculated as sale
                $calc_reg_price = (float) $product->get_regular_price();
                $calc_sale_price = $calc_price;
            }

            $size   = $this->frameRenderer->getCanvasSize( $image_path, $frame_path );
            $canvas = $this->badgeRenderer->render( $canvas, $product, $size['width'], $size['height'], $draw['is_vip'], $calc_reg_price, $calc_sale_price );
        }

        // Save
        imagejpeg( $canvas, $out_file, 90 );
        imagedestroy( $canvas );

        return $out_url;
    }

    /**
     * Determine whether to draw frame and/or badge based on display mode settings.
     *
     * @return array{frame: bool, badge: bool}
     */
    private function resolveDrawMode( \WC_Product $product ): array {
        $enable_badge  = OptionsCache::get( 'drtechno_olx_dynamic_badge' ) === 'yes';
        $display_mode  = OptionsCache::get( 'drtechno_olx_watermark_mode', 'smart' );
        $is_on_sale    = $product->is_on_sale();
        $frame_id      = OptionsCache::get( 'drtechno_olx_image_frame' );
        $vip_price_val = $product->get_meta( '_olx_special_price', true );

        $badge_path_vip = DRTECHNO_OLX_PATH . 'assets/badge_vip_template.png';
        $is_vip = ( $vip_price_val !== '' && floatval( $vip_price_val ) > 0 && file_exists( $badge_path_vip ) );

        $draw_frame = false;
        $draw_badge = false;

        if ( $is_vip ) {
            $draw_badge = true;
            if ( $display_mode === 'smart' || $display_mode === 'badge_only' ) {
                $draw_frame = false;
            } else {
                $draw_frame = true;
            }
        } else {
            switch ( $display_mode ) {
                case 'smart':
                    $draw_badge = $is_on_sale;
                    $draw_frame = ! $is_on_sale;
                    break;
                case 'frame_only':
                    $draw_frame = true;
                    break;
                case 'badge_only':
                    $draw_badge = $is_on_sale;
                    break;
                case 'both':
                    $draw_frame = true;
                    $draw_badge = $is_on_sale;
                    break;
            }
        }

        $badge_path = $is_vip ? $badge_path_vip : ( DRTECHNO_OLX_PATH . 'assets/badge_template.png' );
        $font_black = DRTECHNO_OLX_PATH . 'assets/Montserrat-Black.ttf';
        $font_semi  = DRTECHNO_OLX_PATH . 'assets/Montserrat-SemiBold.ttf';

        $has_frame = $draw_frame && $frame_id && get_attached_file( $frame_id ) && file_exists( get_attached_file( $frame_id ) );
        $has_badge = $draw_badge && $enable_badge && file_exists( $badge_path ) && file_exists( $font_black ) && file_exists( $font_semi );

        return [ 'frame' => $has_frame, 'badge' => $has_badge, 'is_vip' => $is_vip ];
    }

    /**
     * Check if the cached output file is still valid.
     */
    private function isCacheValid( string $outFile, string $imagePath, array $draw ): bool {
        if ( ! file_exists( $outFile ) ) {
            return false;
        }

        $cache_time = filemtime( $outFile );
        $orig_time  = filemtime( $imagePath );

        if ( $orig_time > $cache_time ) {
            return false;
        }

        if ( $draw['frame'] ) {
            $frame_id = OptionsCache::get( 'drtechno_olx_image_frame' );
            if ( $frame_id ) {
                $frame_path = get_attached_file( $frame_id );
                if ( $frame_path && file_exists( $frame_path ) && filemtime( $frame_path ) > $cache_time ) {
                    return false;
                }
            }
        }

        if ( $draw['badge'] ) {
            $badge_path = DRTECHNO_OLX_PATH . 'assets/badge_template.png';
            if ( file_exists( $badge_path ) && filemtime( $badge_path ) > $cache_time ) {
                return false;
            }
        }

        return true;
    }
}
