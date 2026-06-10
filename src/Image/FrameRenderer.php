<?php

namespace EbitOlx\Image;

defined( 'ABSPATH' ) || exit;

class FrameRenderer {

    /**
     * Create a canvas with the product image, optionally overlaid with a frame.
     *
     * @param string      $imagePath  Path to the product image
     * @param string|null $framePath  Path to the frame PNG (null = no frame)
     * @return \GdImage|null Canvas with image (and frame), or null on failure
     */
    public function render( string $imagePath, ?string $framePath ): ?\GdImage {
        $image_info = getimagesize( $imagePath );
        if ( ! $image_info ) {
            return null;
        }

        // Determine canvas size
        if ( $framePath && file_exists( $framePath ) ) {
            $frame_info = getimagesize( $framePath );
            $fw = $frame_info[0];
            $fh = $frame_info[1];
        } else {
            $fw = $image_info[0];
            $fh = $image_info[1];
            if ( $fw > 1200 ) {
                $scale = 1200 / $fw;
                $fw    = 1200;
                $fh    = (int) ( $fh * $scale );
            }
        }

        // Create canvas
        $canvas = imagecreatetruecolor( $fw, $fh );
        $white  = imagecolorallocate( $canvas, 255, 255, 255 );
        imagefilledrectangle( $canvas, 0, 0, $fw, $fh, $white );

        // Load source image
        $source_img = $this->loadImage( $imagePath, $image_info[2] );
        if ( ! $source_img ) {
            imagedestroy( $canvas );
            return null;
        }

        // Scale and center product image on canvas
        $sw    = $image_info[0];
        $sh    = $image_info[1];
        $scale = min( $fw / $sw, $fh / $sh );
        $new_w = (int) ( $sw * $scale );
        $new_h = (int) ( $sh * $scale );
        $dest_x = (int) ( ( $fw - $new_w ) / 2 );
        $dest_y = (int) ( ( $fh - $new_h ) / 2 );
        imagecopyresampled( $canvas, $source_img, $dest_x, $dest_y, 0, 0, $new_w, $new_h, $sw, $sh );
        imagedestroy( $source_img );

        // Overlay frame
        if ( $framePath && file_exists( $framePath ) ) {
            $frame_img = imagecreatefrompng( $framePath );
            if ( $frame_img ) {
                imagealphablending( $frame_img, true );
                imagesavealpha( $frame_img, true );
                imagecopy( $canvas, $frame_img, 0, 0, 0, 0, $fw, $fh );
                imagedestroy( $frame_img );
            }
        }

        return $canvas;
    }

    /**
     * Get the canvas dimensions that would be used for a given image/frame combo.
     *
     * @return array{width: int, height: int}
     */
    public function getCanvasSize( string $imagePath, ?string $framePath ): array {
        if ( $framePath && file_exists( $framePath ) ) {
            $info = getimagesize( $framePath );
            return [ 'width' => $info[0], 'height' => $info[1] ];
        }

        $info = getimagesize( $imagePath );
        $fw   = $info[0];
        $fh   = $info[1];
        if ( $fw > 1200 ) {
            $scale = 1200 / $fw;
            $fw    = 1200;
            $fh    = (int) ( $fh * $scale );
        }

        return [ 'width' => $fw, 'height' => $fh ];
    }

    /**
     * Load an image resource from a file path based on type.
     */
    private function loadImage( string $path, int $type ): ?\GdImage {
        switch ( $type ) {
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg( $path ) ?: null;
            case IMAGETYPE_PNG:
                return imagecreatefrompng( $path ) ?: null;
            case IMAGETYPE_WEBP:
                if ( function_exists( 'imagecreatefromwebp' ) ) {
                    return imagecreatefromwebp( $path ) ?: null;
                }
                return null;
            default:
                return null;
        }
    }
}
