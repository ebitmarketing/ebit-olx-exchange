<?php

namespace EbitOlx\Sync;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Helpers\OptionsCache;
use EbitOlx\License\FeatureManager;

class PayloadBuilder {

    private FeatureManager $featureManager;

    public function __construct( FeatureManager $featureManager ) {
        $this->featureManager = $featureManager;
    }

    /**
     * Build the OLX API payload for creating or updating a listing.
     *
     * @param \WC_Product $product
     * @param int         $postId
     * @param float       $price       Calculated OLX price
     * @param int         $olxCatId    Mapped OLX category ID
     * @param string      $state       Product state (new/used)
     * @param string      $description Built description HTML
     * @param int|null    $brandId     Mapped OLX brand ID
     * @param array       $attributes  OLX attribute array
     * @param bool        $isUpdate    Whether this is an update (not a new listing)
     * @return array API payload
     */
    public function build(
        \WC_Product $product,
        int $postId,
        float $price,
        int $olxCatId,
        string $state,
        string $description,
        ?int $brandId,
        array $attributes,
        bool $isUpdate = false
    ): array {
        $country_id = OptionsCache::get( 'olx_country_id' );
        $city_id    = OptionsCache::get( 'olx_city_id' );

        $raw_title = get_post_meta( $product->get_id(), '_olx_title', true ) ?: $product->get_name();

        $payload = [
            'title'              => self::sanitizeOlxTitle( $raw_title ),
            'price'              => $price,
            'olx_category_id'    => intval( $olxCatId ),
            'state'              => $state,
            'country_id'         => intval( $country_id ),
            'city_id'            => intval( $city_id ),
            'is_in_stock'        => $product->is_in_stock(),
            'description'        => $description,
            'instock_only'           => OptionsCache::get( 'drtechno_olx_sync_instock_only' ) === 'yes',
            'enable_hide_unhide'     => OptionsCache::get( 'drtechno_olx_enable_hide_unhide' ) === 'yes',
            'enable_duplicate_check' => OptionsCache::get( 'drtechno_olx_enable_duplicate_check' ) === 'yes',
        ];

        if ( $product->get_sku() ) {
            $payload['sku'] = $product->get_sku();
        }

        if ( $brandId && $this->featureManager->can('brands') ) {
            $payload['olx_brand_id'] = intval( $brandId );
        }

        if ( ! empty( $attributes ) && $this->featureManager->can('default_attrs') ) {
            $payload['attributes'] = $attributes;
        }

        return $payload;
    }

    /**
     * Resolve OLX attributes for a product based on its mapped category.
     *
     * @param int $postId
     * @param int $olxCatId
     * @param int|false $primaryWcCatId
     * @return array{attributes: array, error: string|null}
     */
    public function resolveAttributes( int $postId, int $olxCatId, $primaryWcCatId ): array {
        if ( ! $this->featureManager->can('default_attrs') ) {
            return [ 'attributes' => [], 'error' => null ];
        }

        $all_category_attrs  = OptionsCache::get( 'drtechno_olx_category_attributes', [] );
        $default_attrs_opts  = OptionsCache::get( 'drtechno_olx_default_attributes', [] );
        // Tab 6 (Zadani atributi) snima u flat strukturu:
        //   [ 'olx_state' => 'new', 'condition' => 'A', 'color' => 'red', ... ]
        // Sve ostalo osim 'olx_state' su attribute name → value parovi.
        $cat_defaults        = ( $primaryWcCatId && isset( $default_attrs_opts[ $primaryWcCatId ] ) )
            ? $default_attrs_opts[ $primaryWcCatId ]
            : [];

        $olx_attributes = [];

        if ( isset( $all_category_attrs[ $olxCatId ] ) ) {
            foreach ( $all_category_attrs[ $olxCatId ] as $attr ) {
                $saved_val = get_post_meta( $postId, '_olx_attr_' . $attr['name'], true );

                // Fall back na zadane atribute kategorije (flat format).
                // Ne čita 'olx_state' kao atribut — to je rezervirani ključ za state.
                if ( $saved_val === '' && $attr['name'] !== 'olx_state' && isset( $cat_defaults[ $attr['name'] ] ) ) {
                    $saved_val = $cat_defaults[ $attr['name'] ];
                }

                if ( $saved_val !== '' ) {
                    $value = ( $attr['input_type'] === 'checkbox' && $saved_val == '1' ) ? 'Da' : strval( $saved_val );
                    $olx_attributes[] = [
                        'id'    => intval( $attr['id'] ),
                        'value' => $value,
                    ];
                } elseif ( $attr['required'] ) {
                    return [
                        'attributes' => [],
                        'error'      => 'Fali obavezan atribut: ' . $attr['display_name'],
                    ];
                }
            }
        }

        return [
            'attributes' => $olx_attributes,
            'error'      => null,
        ];
    }

    /**
     * Resolve the product state (new/used) with category default fallback.
     */
    public function resolveState( int $postId, $primaryWcCatId ): string {
        $state = get_post_meta( $postId, '_olx_state', true );

        if ( empty( $state ) ) {
            $defaults     = OptionsCache::get( 'drtechno_olx_default_attributes', [] );
            $cat_defaults = ( $primaryWcCatId && isset( $defaults[ $primaryWcCatId ] ) )
                ? $defaults[ $primaryWcCatId ]
                : [ 'olx_state' => '' ];
            $state = ! empty( $cat_defaults['olx_state'] ) ? $cat_defaults['olx_state'] : 'new';
        }

        return $state;
    }

    /**
     * Resolve the OLX brand ID for a product.
     */
    public function resolveBrandId( int $postId ): ?int {
        $brand_mapping = OptionsCache::get( 'drtechno_olx_brand_mapping', [] );
        $product_brands = wp_get_post_terms( $postId, 'product_brand', [ 'fields' => 'ids' ] );

        if ( ! empty( $product_brands ) && isset( $brand_mapping[ $product_brands[0] ] ) ) {
            return intval( $brand_mapping[ $product_brands[0] ] );
        }

        return null;
    }

    /**
     * Resolve the OLX category mapping for a product.
     *
     * @param int $postId
     * @return array{olx_cat_id: int|false, primary_wc_cat_id: int|false}
     */
    public function resolveCategoryMapping( int $postId ): array {
        $mapped_cats  = OptionsCache::get( 'drtechno_olx_category_mapping', [] );
        $product_cats = wp_get_post_terms( $postId, 'product_cat', [ 'fields' => 'ids' ] );

        $olx_cat_id       = false;
        $primary_wc_cat_id = false;

        foreach ( $product_cats as $cat_id ) {
            if ( isset( $mapped_cats[ $cat_id ] ) && ! empty( $mapped_cats[ $cat_id ] ) ) {
                $olx_cat_id        = $mapped_cats[ $cat_id ];
                $primary_wc_cat_id = $cat_id;
                break;
            }
        }

        return [
            'olx_cat_id'       => $olx_cat_id,
            'primary_wc_cat_id' => $primary_wc_cat_id,
        ];
    }

    /**
     * Očisti OLX naslov da bude prihvatljiv API-ju.
     *
     * OLX baca 422 validation_failed sa porukom:
     *   "Naziv artikla može biti unesen na latiničnom pismu uz dozvolu unosa afrikata"
     * čim u naslovu pojavi:
     *   - emoji (🔥, 📱, ⭐, ...)
     *   - simboli ™ ® © ° €
     *   - smart quotes („" ‚' …)
     *   - em/en dash (—, –)
     *   - ćirilica
     *   - grčka slova (μ, Ω, π) ili druga ne-latinična pisma
     *   - non-breaking space ili zero-width chars
     *
     * Whitelist (sve ostalo se briše):
     *   a-z A-Z 0-9 đĐćĆčČšŠžŽ razmaci - . , ( ) / ! ? : + % & ' "
     *
     * Pipeline:
     *   1. Standardni replacement-i smart-quote/ligatura → ASCII ekvivalent
     *   2. Cyrillic → Latin transliteracija (BS/SR script konverzija)
     *   3. Brisanje svega van OLX whitelist regex-a
     *   4. Kolapsiranje višestrukih razmaka, trim
     *   5. Truncate na 65 znakova (OLX hard limit)
     */
    private static function sanitizeOlxTitle( string $title ): string {
        // 1. Smart-quote / ligature / simboli → ASCII
        $replacements = [
            "\xE2\x80\x9C" => '"',  "\xE2\x80\x9D" => '"',  // " " left/right double quote
            "\xE2\x80\x9E" => '"',  "\xE2\x80\x9F" => '"',  // „ ‟ low/high reversed
            "\xE2\x80\x98" => "'",  "\xE2\x80\x99" => "'",  // ' ' left/right single quote
            "\xE2\x80\x9A" => "'",  "\xE2\x80\x9B" => "'",  // ‚ ‛ low/high reversed
            "\xE2\x80\xA6" => '...',                         // …
            "\xE2\x80\x93" => '-',  "\xE2\x80\x94" => '-',   // – —
            "\xC2\xA0"     => ' ',                           // non-breaking space
            "\xE2\x80\x8B" => '',   "\xE2\x80\x8C" => '',    // zero-width space, ZWNJ
            "\xE2\x80\x8D" => '',   "\xEF\xBB\xBF" => '',    // ZWJ, BOM
            'À'=>'A','Á'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','Å'=>'A','Æ'=>'AE',
            'È'=>'E','É'=>'E','Ê'=>'E','Ë'=>'E',
            'Ì'=>'I','Í'=>'I','Î'=>'I','Ï'=>'I',
            'Ò'=>'O','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ø'=>'O',
            'Ù'=>'U','Ú'=>'U','Û'=>'U','Ü'=>'U',
            'Ý'=>'Y','Ÿ'=>'Y','Ñ'=>'N','Ç'=>'C',
            'à'=>'a','á'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','æ'=>'ae',
            'è'=>'e','é'=>'e','ê'=>'e','ë'=>'e',
            'ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
            'ò'=>'o','ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ø'=>'o',
            'ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u',
            'ý'=>'y','ÿ'=>'y','ñ'=>'n','ç'=>'c','ß'=>'ss',
            '×'=>'x','÷'=>'/',
            '™'=>'','®'=>'','©'=>'','°'=>'','§'=>'','¶'=>'',
            '€'=>'EUR','£'=>'GBP','¥'=>'JPY','¢'=>'',
            '¼'=>'1/4','½'=>'1/2','¾'=>'3/4',
        ];

        // 2. Cyrillic (Bosanski/Srpski) → Latin (preserves đćčšž)
        $cyrillic = [
            'А'=>'A','Б'=>'B','В'=>'V','Г'=>'G','Д'=>'D','Ђ'=>'Đ','Е'=>'E','Ж'=>'Ž','З'=>'Z',
            'И'=>'I','Ј'=>'J','К'=>'K','Л'=>'L','Љ'=>'Lj','М'=>'M','Н'=>'N','Њ'=>'Nj','О'=>'O',
            'П'=>'P','Р'=>'R','С'=>'S','Т'=>'T','Ћ'=>'Ć','У'=>'U','Ф'=>'F','Х'=>'H','Ц'=>'C',
            'Ч'=>'Č','Џ'=>'Dž','Ш'=>'Š',
            'а'=>'a','б'=>'b','в'=>'v','г'=>'g','д'=>'d','ђ'=>'đ','е'=>'e','ж'=>'ž','з'=>'z',
            'и'=>'i','ј'=>'j','к'=>'k','л'=>'l','љ'=>'lj','м'=>'m','н'=>'n','њ'=>'nj','о'=>'o',
            'п'=>'p','р'=>'r','с'=>'s','т'=>'t','ћ'=>'ć','у'=>'u','ф'=>'f','х'=>'h','ц'=>'c',
            'ч'=>'č','џ'=>'dž','ш'=>'š',
        ];

        $title = strtr( $title, $replacements );
        $title = strtr( $title, $cyrillic );

        // 3. Whitelist filter: zadrži samo OLX-dozvoljene znakove
        // Dozvoljeno: a-z A-Z 0-9 đĐćĆčČšŠžŽ razmak - . , ( ) / ! ? : ; + % & ' "
        $title = preg_replace( '/[^a-zA-Z0-9đĐćĆčČšŠžŽ\s\-.,()\/!?:;+%&\'"]+/u', '', $title );

        // 4. Kolapsiraj razmake, trim
        $title = preg_replace( '/\s+/', ' ', $title );
        $title = trim( $title );

        // 5. Truncate na OLX limit
        return mb_substr( $title, 0, 65, 'UTF-8' );
    }
}
