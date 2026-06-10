<?php

namespace EbitOlx\Image;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;

class BadgeRenderer {

    /**
     * Render a price badge onto a canvas.
     *
     * The badge shows the old price with a strikethrough line and the new sale price.
     *
     * @param \GdImage    $canvas   The canvas to draw on
     * @param \WC_Product $product  WooCommerce product (for prices)
     * @param int         $canvasW  Canvas width in pixels
     * @param float|null  $calcRegPrice Optional calculated regular price
     * @param float|null  $calcSalePrice Optional calculated sale price
     * @return \GdImage Modified canvas
     */
    public function render( \GdImage $canvas, \WC_Product $product, int $canvasW, int $canvasH, bool $is_vip = false, float $calcRegPrice = null, float $calcSalePrice = null ): \GdImage {
        $badge_path = $is_vip ? ( DRTECHNO_OLX_PATH . 'assets/badge_vip_template.png' ) : ( DRTECHNO_OLX_PATH . 'assets/badge_template.png' );
        $font_black = DRTECHNO_OLX_PATH . 'assets/Montserrat-Black.ttf';
        $font_semi  = DRTECHNO_OLX_PATH . 'assets/Montserrat-SemiBold.ttf';

        if ( ! file_exists( $badge_path ) || ! file_exists( $font_black ) || ! file_exists( $font_semi ) ) {
            return $canvas;
        }

        $badge_img = imagecreatefrompng( $badge_path );
        if ( ! $badge_img ) {
            return $canvas;
        }

        imagealphablending( $badge_img, true );
        imagesavealpha( $badge_img, true );

        $bw = imagesx( $badge_img );
        $bh = imagesy( $badge_img );

        // Colors
        $color_black = imagecolorallocate( $badge_img, 20, 20, 20 );
        $color_white = imagecolorallocate( $badge_img, 255, 255, 255 );
        $color_red   = imagecolorallocate( $badge_img, 214, 54, 56 );

        // Prices
        $r_price = $calcRegPrice !== null ? $calcRegPrice : (float) $product->get_regular_price();
        $s_price = $calcSalePrice !== null ? $calcSalePrice : (float) $product->get_sale_price();

        $reg_price  = number_format( $r_price, 0, ',', '' );
        $sale_price = number_format( $s_price, 0, ',', '' );

        // Badge layout settings from options
        $old_price_size = floatval( OptionsCache::get( 'drtechno_olx_badge_old_price_size', 28 ) );
        $old_price_x    = $bw * floatval( OptionsCache::get( 'drtechno_olx_badge_old_price_x', 0.62 ) );
        $old_price_y    = $bh * floatval( OptionsCache::get( 'drtechno_olx_badge_old_price_y', 0.36 ) );

        $new_price_size = floatval( OptionsCache::get( 'drtechno_olx_badge_new_price_size', 85 ) );
        $new_price_x    = $bw * floatval( OptionsCache::get( 'drtechno_olx_badge_new_price_x', 0.40 ) );
        $new_price_y    = $bh * floatval( OptionsCache::get( 'drtechno_olx_badge_new_price_y', 0.82 ) );

        $line_thickness = intval( OptionsCache::get( 'drtechno_olx_badge_line_thickness', 8 ) );

        if ( $is_vip ) {
            $vip_price_val = $product->get_meta( '_olx_special_price', true );
            $vip_formatted = number_format( (float) $vip_price_val, 0, ',', '' );
            imagettftext( $badge_img, $new_price_size, 0, (int) $new_price_x, (int) $new_price_y, $color_black, $font_black, $vip_formatted );
        } else {
            // Draw old price text
            imagettftext( $badge_img, $old_price_size, 0, (int) $old_price_x, (int) $old_price_y, $color_white, $font_semi, $reg_price );

            // Draw strikethrough line
            $bbox       = imagettfbbox( $old_price_size, 0, $font_semi, $reg_price );
            $text_width = $bbox[2] - $bbox[0];
            $line_y     = (int) ( $old_price_y - ( $old_price_size / 2 ) + 5 );
            imagesetthickness( $badge_img, $line_thickness );
            imageline( $badge_img, (int) $old_price_x - 5, $line_y + 8, (int) $old_price_x + $text_width + 5, $line_y - 8, $color_red );

            // Draw new price text
            imagettftext( $badge_img, $new_price_size, 0, (int) $new_price_x, (int) $new_price_y, $color_black, $font_black, $sale_price );
        }

        // Scale and position badge on canvas
        $target_w = (int) ( $canvasW * floatval( OptionsCache::get( 'drtechno_olx_badge_width_pct', 0.55 ) ) );
        $target_h = (int) ( $bh * ( $target_w / $bw ) );

        $dest_x = $canvasW - $target_w - intval( OptionsCache::get( 'drtechno_olx_badge_pos_x', 20 ) );
        $dest_y = intval( OptionsCache::get( 'drtechno_olx_badge_pos_y', 20 ) );

        imagecopyresampled( $canvas, $badge_img, $dest_x, $dest_y, 0, 0, $target_w, $target_h, $bw, $bh );
        imagedestroy( $badge_img );

        return $canvas;
    }
}
