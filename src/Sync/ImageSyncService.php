<?php

namespace EbitOlx\Sync;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\Helpers\OptionsCache;
use EbitOlx\Image\ImageProcessor;
use EbitOlx\License\FeatureManager;

class ImageSyncService {

    private ServerClient $api;
    private ImageProcessor $imageProcessor;
    private FeatureManager $featureManager;

    public function __construct( ServerClient $api, FeatureManager $featureManager ) {
        $this->api            = $api;
        $this->imageProcessor = new ImageProcessor();
        $this->featureManager = $featureManager;
    }

    /**
     * Synchronize product images to OLX via server-sync backend.
     *
     * Flow (matches reference plugin drtechno-olx-sync.php::sync_product_images):
     *   1. Process main image (apply badge/frame via GD)
     *   2. Upload main image — OLX returns ALL current images (old + new uploaded)
     *   3. Extract new_main_id (last in list) and old_image_ids (all others)
     *   4. Set the new uploaded image as main
     *   5. Delete old images (identified from upload response)
     *      Fallback: if upload returned no list, call getListing and delete all found images
     *   6. Upload gallery images (up to 5 total)
     *
     * @return array{success: bool, message: string}
     */
    public function syncImages( int $postId, int $olxId, bool $isNew = false, bool $forceRegen = false ): array {
        $product = wc_get_product( $postId );
        if ( ! $product ) {
            return [ 'success' => false, 'message' => 'Proizvod ne postoji.' ];
        }

        if ( OptionsCache::get( 'drtechno_olx_force_image_regen' ) === 'yes' ) {
            $forceRegen = true;
        }

        $main_img_id = $product->get_image_id();
        $gallery_ids = $product->get_gallery_image_ids() ?: [];

        // Change detection via hash
        $current_hash = $this->computeHash( $postId, $product, $main_img_id, $gallery_ids );
        $saved_hash   = OlxMetaHelper::getImageHash( $postId );

        if ( ! $isNew && ! $forceRegen && $current_hash === $saved_hash ) {
            return [
                'success' => true,
                'message' => '<span style="color:#888;">Preskočeno (Slike i cijene su već najnovije)</span>',
            ];
        }

        $keep_old   = OptionsCache::get( 'drtechno_olx_keep_old_images' ) === 'yes';
        $olx_id_str = (string) $olxId;

        $new_main_id    = null;
        $uploaded_count = 0;
        $old_image_ids  = [];
        $deleted_ids    = [];

        // Step 1+2: Process and upload main (badge/frame) image.
        $main_img_url = null;
        if ( $main_img_id ) {
            if ( $this->featureManager->can( 'image_processing' ) ) {
                $main_img_url = $this->imageProcessor->process( $postId, $main_img_id, true );
            } else {
                $main_img_url = wp_get_attachment_url( $main_img_id );
            }
        }

        if ( $main_img_url ) {
            $upload_resp = $this->api->imageUpload( $olx_id_str, $main_img_url );
            if ( ! $upload_resp['error'] ) {
                $uploaded_count++;
                // OLX returns ALL images on the listing after upload (old + new).
                // The last entry is the one we just uploaded.
                $img_data = is_array( $upload_resp['data'] ) ? $upload_resp['data'] : [];
                if ( ! empty( $img_data ) ) {
                    $last = end( $img_data );
                    if ( isset( $last['id'] ) ) {
                        $new_main_id = (string) $last['id'];
                    }
                    // All other images in the list are old ones to delete.
                    if ( ! $keep_old && ! $isNew ) {
                        foreach ( $img_data as $img ) {
                            if ( isset( $img['id'] ) && (string) $img['id'] !== $new_main_id ) {
                                $old_image_ids[] = (string) $img['id'];
                            }
                        }
                    }
                }
            }
        }

        // Step 3: Set the new uploaded image as main.
        $main_set = false;
        if ( $new_main_id ) {
            $main_resp = $this->api->imageSetMain( $olx_id_str, $new_main_id );
            $main_set  = ! $main_resp['error'];
        }

        // Step 4: Delete old images.
        // Do this BEFORE gallery upload so we don't hit OLX's 5-image limit.
        if ( ! $keep_old && ! $isNew ) {
            if ( ! empty( $old_image_ids ) ) {
                // Old images identified from upload response — precise list.
                foreach ( $old_image_ids as $img_id ) {
                    $res = $this->api->imageDelete( $olx_id_str, $img_id );
                    if ( ! $res['error'] ) {
                        $deleted_ids[] = $img_id;
                    }
                }
            } elseif ( ! $main_img_url ) {
                // No main image uploaded (product has no featured image) —
                // fall back to getListing to find and delete existing images.
                $listing = $this->api->getListing( $olx_id_str );
                if ( ! $listing['error'] ) {
                    $raw  = $listing['data'];
                    $imgs = $raw['images'] ?? $raw['data']['images'] ?? [];
                    foreach ( $imgs as $img ) {
                        if ( isset( $img['id'] ) ) {
                            $res = $this->api->imageDelete( $olx_id_str, (string) $img['id'] );
                            if ( ! $res['error'] ) {
                                $deleted_ids[] = (string) $img['id'];
                            }
                        }
                    }
                }
            }
            // If main_img_url was set but upload response had no images list,
            // old images can't be identified — skip deletion to avoid data loss.
        }

        // Step 5: Upload gallery images (up to 5 total including main).
        $uploaded_count += $this->uploadGallery( $olx_id_str, $gallery_ids, $uploaded_count );

        // Step 6: Fallback — if upload response had no image ID, fetch listing
        // and set the first image as main.
        if ( $main_img_url && ! $new_main_id ) {
            $listing_after = $this->api->getListing( $olx_id_str );
            if ( ! $listing_after['error'] ) {
                $raw  = $listing_after['data'];
                $imgs = $raw['images'] ?? $raw['data']['images'] ?? [];
                if ( ! empty( $imgs ) ) {
                    $first = reset( $imgs );
                    if ( isset( $first['id'] ) ) {
                        $new_main_id = (string) $first['id'];
                        $main_resp   = $this->api->imageSetMain( $olx_id_str, $new_main_id );
                        $main_set    = ! $main_resp['error'];
                    }
                }
            }
        }

        OlxMetaHelper::setImageHash( $postId, $current_hash );

        $msg = "Obrisano starih: <strong>" . count( $deleted_ids ) . "</strong> | Dodato novih: <strong>{$uploaded_count}</strong>.<br>";
        if ( $new_main_id ) {
            $msg .= "Glavna slika (ID: {$new_main_id}) " . ( $main_set
                ? "<span style='color:green;'>uspješno postavljena.</span>"
                : "<span style='color:red;'>NEUSPJEŠNO postavljena.</span>" );
        } elseif ( $main_img_url ) {
            $msg .= "<span style='color:#ffb900;'>Nova slika nije dobila ID od API-ja.</span>";
        }

        return [ 'success' => true, 'message' => $msg ];
    }

    /**
     * Compute a hash representing the current state of product images + settings.
     */
    private function computeHash( int $postId, \WC_Product $product, $mainImgId, array $galleryIds ): string {
        $upload_dir  = wp_upload_dir();
        $cached_file = $upload_dir['basedir'] . '/olx-frames/olx_frame_prod_' . $postId . '.jpg';
        $cache_time  = file_exists( $cached_file ) ? filemtime( $cached_file ) : 0;

        $frame_id   = OptionsCache::get( 'drtechno_olx_image_frame' );
        $frame_time = 0;
        if ( $frame_id ) {
            $frame_file = get_attached_file( $frame_id );
            $frame_time = $frame_file && file_exists( $frame_file ) ? filemtime( $frame_file ) : 0;
        }

        $badge_path     = EBIT_OLX_PATH . 'assets/badge_template.png';
        $badge_time     = file_exists( $badge_path ) ? filemtime( $badge_path ) : 0;
        $badge_vip_path = EBIT_OLX_PATH . 'assets/badge_vip_template.png';
        $badge_vip_time = file_exists( $badge_vip_path ) ? filemtime( $badge_vip_path ) : 0;

        $badge_enabled = OptionsCache::get( 'drtechno_olx_dynamic_badge' ) === 'yes';
        $display_mode  = OptionsCache::get( 'drtechno_olx_watermark_mode', 'smart' );
        $price_data    = $badge_enabled
            ? ( $product->get_regular_price() . '_' . $product->get_sale_price() . '_' . $product->get_meta( '_olx_special_price', true ) )
            : 'no_badge';

        return md5(
            $mainImgId . '_' .
            implode( ',', $galleryIds ) . '_' .
            $cache_time . '_' .
            $frame_time . '_' .
            $badge_time . '_' .
            $badge_vip_time . '_' .
            $price_data . '_' .
            $display_mode
        );
    }

    /**
     * Upload gallery images via server (up to 5 total including main).
     */
    private function uploadGallery( string $olxId, array $galleryIds, int $alreadyUploaded ): int {
        $count = 0;
        foreach ( $galleryIds as $img_id ) {
            if ( ( $alreadyUploaded + $count ) >= 5 ) {
                break;
            }
            $url = wp_get_attachment_url( $img_id );
            if ( $url ) {
                $this->api->imageUpload( $olxId, $url );
                $count++;
            }
        }
        return $count;
    }
}
