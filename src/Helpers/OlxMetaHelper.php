<?php

namespace EbitOlx\Helpers;

defined( 'ABSPATH' ) || exit;

class OlxMetaHelper {

    // Core OLX meta keys
    private const META_ARTICLE_ID  = '_olx_article_id';
    private const META_STATUS      = '_olx_status';
    private const META_LAST_SYNC   = '_olx_last_sync';
    private const META_SYNC_ERROR  = '_olx_sync_error';
    private const META_EXCLUDE     = '_olx_exclude_sync';
    private const META_SPECIAL     = '_olx_special_price';
    private const META_STATE       = '_olx_state';
    private const META_IMAGE_HASH  = '_olx_image_hash';

    // Sponsor meta keys
    private const META_SPONSOR_STATUS = '_olx_sponsor_status';
    private const META_SPONSOR_PARAMS = '_olx_sponsor_params';
    private const META_SPONSOR_TIME   = '_olx_sponsor_time';
    private const META_SPONSOR_ERROR  = '_olx_sponsor_error';

    /**
     * Clear all OLX-related meta for a product.
     * Replaces the 5 duplicated delete_post_meta patterns.
     */
    public static function clearAllMeta( int $postId ): void {
        delete_post_meta( $postId, self::META_ARTICLE_ID );
        delete_post_meta( $postId, self::META_STATUS );
        delete_post_meta( $postId, self::META_LAST_SYNC );
        delete_post_meta( $postId, self::META_SYNC_ERROR );
    }

    // --- Article ID ---

    public static function getOlxId( int $postId ): ?string {
        $id = get_post_meta( $postId, self::META_ARTICLE_ID, true );
        return ! empty( $id ) ? (string) $id : null;
    }

    public static function setOlxId( int $postId, string $id ): void {
        update_post_meta( $postId, self::META_ARTICLE_ID, $id );
    }

    // --- Status ---

    public static function getStatus( int $postId ): ?string {
        $status = get_post_meta( $postId, self::META_STATUS, true );
        return ! empty( $status ) ? $status : null;
    }

    public static function setStatus( int $postId, string $status ): void {
        update_post_meta( $postId, self::META_STATUS, $status );
    }

    // --- Sync Error ---

    public static function getSyncError( int $postId ): ?string {
        $error = get_post_meta( $postId, self::META_SYNC_ERROR, true );
        return ! empty( $error ) ? $error : null;
    }

    public static function setSyncError( int $postId, string $error ): void {
        update_post_meta( $postId, self::META_SYNC_ERROR, $error );
    }

    public static function clearSyncError( int $postId ): void {
        delete_post_meta( $postId, self::META_SYNC_ERROR );
    }

    // --- Last Sync ---

    public static function getLastSync( int $postId ): ?string {
        $sync = get_post_meta( $postId, self::META_LAST_SYNC, true );
        return ! empty( $sync ) ? $sync : null;
    }

    public static function setLastSync( int $postId ): void {
        update_post_meta( $postId, self::META_LAST_SYNC, current_time( 'mysql' ) );
    }

    // --- Exclude from Sync ---

    public static function isExcluded( int $postId ): bool {
        return (bool) get_post_meta( $postId, self::META_EXCLUDE, true );
    }

    public static function setExcluded( int $postId, bool $excluded ): void {
        update_post_meta( $postId, self::META_EXCLUDE, $excluded ? '1' : '' );
    }

    // --- Special Price ---

    public static function getSpecialPrice( int $postId ): ?float {
        $price = get_post_meta( $postId, self::META_SPECIAL, true );
        return ! empty( $price ) ? (float) $price : null;
    }

    // --- Product State ---

    public static function getState( int $postId ): string {
        $state = get_post_meta( $postId, self::META_STATE, true );
        return ! empty( $state ) ? $state : 'new';
    }

    // --- Image Hash ---

    public static function getImageHash( int $postId ): ?string {
        $hash = get_post_meta( $postId, self::META_IMAGE_HASH, true );
        return ! empty( $hash ) ? $hash : null;
    }

    public static function setImageHash( int $postId, string $hash ): void {
        update_post_meta( $postId, self::META_IMAGE_HASH, $hash );
    }

    // --- OLX Attributes (dynamic prefix) ---

    public static function getAttribute( int $postId, string $attrKey ): ?string {
        $val = get_post_meta( $postId, '_olx_attr_' . $attrKey, true );
        return ! empty( $val ) ? $val : null;
    }

    public static function setAttribute( int $postId, string $attrKey, string $value ): void {
        update_post_meta( $postId, '_olx_attr_' . $attrKey, $value );
    }

    /**
     * Mark a product as successfully synced.
     */
    public static function markSynced( int $postId, string $olxId, string $status = 'active' ): void {
        self::setOlxId( $postId, $olxId );
        self::setStatus( $postId, $status );
        self::setLastSync( $postId );
        self::clearSyncError( $postId );
    }

    /**
     * Mark a product sync as failed.
     */
    public static function markFailed( int $postId, string $error ): void {
        self::setSyncError( $postId, $error );
    }

    // --- Sponsor Meta ---

    public static function getSponsorStatus( int $postId ): ?string {
        $status = get_post_meta( $postId, self::META_SPONSOR_STATUS, true );
        return ! empty( $status ) ? $status : null;
    }

    public static function setSponsorStatus( int $postId, string $status ): void {
        update_post_meta( $postId, self::META_SPONSOR_STATUS, $status );
    }

    public static function getSponsorParams( int $postId ) {
        $params = get_post_meta( $postId, self::META_SPONSOR_PARAMS, true );
        return ! empty( $params ) ? $params : null;
    }

    public static function setSponsorMeta( int $postId, array $params, int $time ): void {
        update_post_meta( $postId, self::META_SPONSOR_PARAMS, $params );
        update_post_meta( $postId, self::META_SPONSOR_TIME, $time );
        update_post_meta( $postId, self::META_SPONSOR_STATUS, 'scheduled' );
    }

    public static function setSponsorError( int $postId, string $error ): void {
        update_post_meta( $postId, self::META_SPONSOR_ERROR, $error );
        update_post_meta( $postId, self::META_SPONSOR_STATUS, 'error' );
    }

    public static function setSponsorActive( int $postId ): void {
        update_post_meta( $postId, self::META_SPONSOR_STATUS, 'active' );
        delete_post_meta( $postId, self::META_SPONSOR_ERROR );
    }

    public static function clearSponsorMeta( int $postId ): void {
        delete_post_meta( $postId, self::META_SPONSOR_STATUS );
        delete_post_meta( $postId, self::META_SPONSOR_PARAMS );
        delete_post_meta( $postId, self::META_SPONSOR_TIME );
        delete_post_meta( $postId, self::META_SPONSOR_ERROR );
    }
}
