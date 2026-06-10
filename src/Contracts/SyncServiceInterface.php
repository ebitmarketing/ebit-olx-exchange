<?php

namespace EbitOlx\Contracts;

defined( 'ABSPATH' ) || exit;

interface SyncServiceInterface {

    /**
     * Synchronize a WooCommerce product to OLX.
     *
     * @param int $postId WooCommerce product post ID
     * @return array Result with 'success' boolean and 'message' string
     */
    public function syncProduct( int $postId, bool $isMassSync = false ): array;

    /**
     * Synchronize product images to OLX.
     *
     * @param int  $postId     WooCommerce product post ID
     * @param int  $olxId      OLX listing ID
     * @param bool $isNew      Whether this is a new listing
     * @param bool $forceRegen Force image regeneration
     * @return array Array of uploaded image URLs
     */
    public function syncImages( int $postId, int $olxId, bool $isNew = false, bool $forceRegen = false ): array;
}
