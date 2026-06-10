<?php

namespace EbitOlx\Sync;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;

class PriceCalculator {

    /**
     * Calculate the final OLX price for a product.
     *
     * Logic:
     * 1. VIP price overrides everything
     * 2. Otherwise: sale price > regular price > base price
     * 3. Apply matching price rule (first match wins)
     * 4. Round: floor(price / 10) * 10 + 9
     *
     * @param \WC_Product $product
     * @param int         $postId
     * @return float Final price for OLX
     */
    public function calculate( \WC_Product $product, int $postId ): float {
        // VIP price takes absolute priority
        $vip_price = get_post_meta( $postId, '_olx_special_price', true );
        if ( $vip_price !== '' && floatval( $vip_price ) > 0 ) {
            return floatval( $vip_price );
        }

        // Base price: sale > regular > price
        $price = $product->get_sale_price() ?: $product->get_regular_price();
        if ( empty( $price ) ) {
            $price = $product->get_price();
        }
        $price = floatval( $price );

        if ( $price <= 0 ) {
            return $price;
        }

        // Apply price rules
        $price = $this->applyRules( $price, $postId );

        return $price;
    }

    /**
     * Check if this product has a VIP price set.
     */
    public function isVip( int $postId ): bool {
        $vip = get_post_meta( $postId, '_olx_special_price', true );
        return $vip !== '' && floatval( $vip ) > 0;
    }

    /**
     * Apply price rules. First matching rule wins.
     *
     * Rules match on: category, brand, supplier (all optional filters).
     * Operations: + or - with % (percentage) or absolute value.
     * Final rounding: floor(price / 10) * 10 + 9 (e.g. 123 -> 129, 456 -> 459)
     */
    private function applyRules( float $price, int $postId ): float {
        $rules = OptionsCache::get( 'drtechno_olx_price_rules', [] );
        if ( empty( $rules ) ) {
            return $price;
        }

        $p_cats      = wp_get_post_terms( $postId, 'product_cat', [ 'fields' => 'ids' ] );
        $p_brands    = taxonomy_exists( 'product_brand' )
            ? wp_get_post_terms( $postId, 'product_brand', [ 'fields' => 'ids' ] )
            : [];
        $p_suppliers = taxonomy_exists( 'ebit_supplier' )
            ? wp_get_post_terms( $postId, 'ebit_supplier', [ 'fields' => 'ids' ] )
            : [];

        foreach ( $rules as $rule ) {
            $match_cat      = empty( $rule['cat'] ) || in_array( (int) $rule['cat'], $p_cats );
            $match_brand    = empty( $rule['brand'] ) || in_array( (int) $rule['brand'], $p_brands );
            $match_supplier = empty( $rule['supplier'] ) || in_array( (int) $rule['supplier'], $p_suppliers );

            if ( $match_cat && $match_brand && $match_supplier ) {
                $calc_price = $price;

                if ( $rule['type'] === '%' ) {
                    $mod        = $price * ( $rule['val'] / 100 );
                    $calc_price = ( $rule['op'] === '+' ) ? ( $price + $mod ) : ( $price - $mod );
                } else {
                    $calc_price = ( $rule['op'] === '+' ) ? ( $price + $rule['val'] ) : ( $price - $rule['val'] );
                }

                if ( $calc_price < 1 ) {
                    $calc_price = 1;
                }

                // Round to nearest-10 + 9 (e.g. 123 -> 129)
                $price = floor( $calc_price / 10 ) * 10 + 9;
                break; // First match wins
            }
        }

        return $price;
    }
}
