<?php

namespace EbitOlx\Sync;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;
use EbitOlx\License\FeatureManager;

class DescriptionBuilder {

    private FeatureManager $featureManager;

    public function __construct( FeatureManager $featureManager ) {
        $this->featureManager = $featureManager;
    }

    /**
     * Build the OLX listing description from global template + product data.
     *
     * Supports 15 template variables:
     * [product_link], [product_name], [short_description], [long_description],
     * [sku], [gtin], [olx_category_id], [attributes], [olx_price],
     * [regular_price], [sale_price], [shipping_price]
     *
     * @param \WC_Product $product
     * @param float       $price     Calculated OLX price
     * @param int         $olxCatId  Mapped OLX category ID
     * @return string HTML description
     */
    public function build( \WC_Product $product, float $price, int $olxCatId ): string {
        $parts = [];

        if ( $this->featureManager->can('desc_settings') ) {
            $global_prefix = trim( OptionsCache::get( 'drtechno_olx_global_prefix', '' ) );
            if ( ! empty( $global_prefix ) ) {
                $parts[] = wpautop( $this->processVariables( stripslashes( $global_prefix ), $product, $price, $olxCatId ) );
            }

            $global_desc = trim( OptionsCache::get( 'drtechno_olx_global_description', '' ) );
            if ( ! empty( $global_desc ) ) {
                $parts[] = wpautop( $this->processVariables( stripslashes( $global_desc ), $product, $price, $olxCatId ) );
            }
        } else {
            // Include just short and long description natively if desc_settings flag is disabled
            $parts[] = wpautop( $product->get_short_description() );
            $parts[] = wpautop( $product->get_description() );
        }

        $description = implode( '', $parts );

        // Fallback to product name if description is empty
        if ( empty( trim( strip_tags( $description ) ) ) ) {
            $description = $product->get_name();
        }

        return $description;
    }

    /**
     * Replace template variables in text.
     */
    private function processVariables( string $text, \WC_Product $product, float $price, int $olxCatId ): string {
        $product_url = esc_url( $product->get_permalink() );

        // Fix double-protocol issues
        $text = str_replace( [ 'http://[product_link]', 'https://[product_link]' ], '[product_link]', $text );
        $text = str_replace( [ 'href="[product_link]"', "href='[product_link]'" ], 'href="' . $product_url . '"', $text );

        // Simple replacements
        $text = str_replace( '[product_link]', $product_url, $text );
        $text = str_replace( '[product_name]', $product->get_name(), $text );
        $text = str_replace( '[short_description]', wpautop( $product->get_short_description() ), $text );
        $text = str_replace( '[long_description]', wpautop( $product->get_description() ), $text );
        $text = str_replace( '[sku]', $product->get_sku(), $text );
        $text = str_replace( '[gtin]', $this->resolveGtin( $product ), $text );
        $text = str_replace( '[ean]', $this->resolveGtin( $product ), $text ); // [ean] = alias za [gtin]
        $supplier_terms = taxonomy_exists( 'ebit_supplier' )
            ? wp_get_post_terms( $product->get_id(), 'ebit_supplier', [ 'fields' => 'names' ] )
            : [];
        $text = str_replace( '[supplier]', implode( ', ', is_array( $supplier_terms ) ? $supplier_terms : [] ), $text );
        $text = str_replace( '[olx_category_id]', (string) $olxCatId, $text );
        $text = str_replace( '[attributes]', $this->buildAttributesHtml( $product ), $text );

        // Price replacements
        $currency = html_entity_decode( get_woocommerce_currency_symbol() );
        $text     = str_replace( '[olx_price]', wc_format_localized_price( $price ) . ' ' . $currency, $text );

        $reg_price  = $product->get_regular_price();
        $sale_price = $product->get_sale_price();
        $text       = str_replace( '[regular_price]', $reg_price ? wc_format_localized_price( $reg_price ) . ' ' . $currency : '', $text );
        $text       = str_replace( '[sale_price]', $sale_price ? wc_format_localized_price( $sale_price ) . ' ' . $currency : '', $text );

        // Shipping
        $text = str_replace( '[shipping_price]', $this->resolveShippingCost( $product ), $text );

        return make_clickable( $text );
    }

    /**
     * Resolve GTIN/EAN/UPC from multiple possible meta fields.
     */
    private function resolveGtin( \WC_Product $product ): string {
        $id = $product->get_id();

        $fields = [ '_gtin', '_ean', '_upc', '_wpm_gtin_code', '_barcode' ];
        foreach ( $fields as $field ) {
            $val = get_post_meta( $id, $field, true );
            if ( ! empty( $val ) ) {
                return $val;
            }
        }

        return '';
    }

    /**
     * Build HTML attributes list from WooCommerce product attributes.
     */
    private function buildAttributesHtml( \WC_Product $product ): string {
        $attributes = $product->get_attributes();
        if ( empty( $attributes ) ) {
            return '';
        }

        $html = '<strong>Karakteristike:</strong><br>';

        foreach ( $attributes as $attribute ) {
            $attr_name   = wc_attribute_label( $attribute->get_name() );
            $attr_values = [];

            if ( $attribute->is_taxonomy() ) {
                $terms = wp_get_post_terms( $product->get_id(), $attribute->get_name(), 'all' );
                foreach ( $terms as $term ) {
                    $attr_values[] = $term->name;
                }
            } else {
                $attr_values = $attribute->get_options();
            }

            if ( ! empty( $attr_values ) ) {
                $html .= '<strong>' . esc_html( $attr_name ) . ':</strong> ' . esc_html( implode( ', ', $attr_values ) ) . '<br>';
            }
        }

        return $html;
    }

    /**
     * Resolve shipping cost from WooCommerce flat rate shipping.
     */
    private function resolveShippingCost( \WC_Product $product ): string {
        if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
            return 'Besplatno / Nije definisano';
        }

        $currency          = html_entity_decode( get_woocommerce_currency_symbol() );
        $shipping_class_id = $product->get_shipping_class_id();
        $shipping_cost     = '';

        $zones       = \WC_Shipping_Zones::get_zones();
        $zones[0]    = [ 'zone_id' => 0 ];

        foreach ( $zones as $zone_arr ) {
            $zone    = new \WC_Shipping_Zone( $zone_arr['zone_id'] );
            $methods = $zone->get_shipping_methods();

            foreach ( $methods as $method ) {
                if ( $method->id === 'flat_rate' ) {
                    if ( $shipping_class_id && $method->get_option( 'class_cost_' . $shipping_class_id ) !== '' ) {
                        $shipping_cost = $method->get_option( 'class_cost_' . $shipping_class_id );
                        break 2;
                    } elseif ( $method->get_option( 'cost' ) !== '' && $shipping_cost === '' ) {
                        $shipping_cost = $method->get_option( 'cost' );
                    }
                }
            }
        }

        if ( $shipping_cost !== '' ) {
            if ( preg_match( '/([0-9]+[.,]?[0-9]*)/', $shipping_cost, $matches ) ) {
                $numeric = (float) str_replace( ',', '.', $matches[1] );
                return $numeric > 0 ? wc_format_localized_price( $numeric ) . ' ' . $currency : 'Besplatno';
            }
        }

        return 'Besplatno / Nije definisano';
    }
}
