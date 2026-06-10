<?php

namespace EbitOlx\Helpers;

defined( 'ABSPATH' ) || exit;

/**
 * OLX dnevni limit (350 novih oglasa) tracker.
 *
 * OLX API ne pruža endpoint za provjeru trenutnog stanja kvote — limit
 * se otkriva tek kad request padne sa 400 i porukom:
 *   "Prekoračili ste limit objave oglasa od 350 po danu!"
 *
 * Limit je sliding 24h prozor (slot-ovi se oslobađaju gradualno kako
 * prolazi 24h od svake objave), NE midnight reset.
 *
 * Ova klasa drži transient flag sa 1h TTL-om. Plugin prije svakog CREATE
 * poziva provjeri flag — ako je set, preskače API poziv. UPDATE / BUMP /
 * HIDE / DELETE NISU pogođeni (OLX ih ne računa u limit).
 *
 * Auto-recovery: nakon 1h transient istekne, sljedeći batch worker
 * pokušava 1 CREATE. Ako prođe — queue se nastavlja. Ako ponovo 400 —
 * transient se postavi opet sa fresh timestamp-om.
 */
class OlxDailyLimit {

    private const TRANSIENT_KEY = 'drtechno_olx_daily_limit_reached';
    private const TTL           = HOUR_IN_SECONDS; // 1h auto-recovery probe

    /**
     * Detektuje li server poruka signalizira dostignut OLX dnevni limit.
     *
     * @param string $message  Tekst odgovora servera
     * @param mixed  $rawData  Original raw response (array|string|null)
     */
    public static function isLimitMessage( string $message, $rawData = null ): bool {
        $haystack = strtolower( $message );
        if ( $rawData !== null ) {
            $haystack .= ' ' . strtolower( (string) wp_json_encode( $rawData ) );
        }

        // OLX poruka može doći u par formata; pokrij sve.
        if ( strpos( $haystack, 'prekoračili ste limit' ) !== false ) return true;
        if ( strpos( $haystack, 'prekoracili ste limit' ) !== false ) return true;
        if ( strpos( $haystack, 'limit objave oglasa' ) !== false )    return true;
        if ( strpos( $haystack, 'limit objave' ) !== false && strpos( $haystack, 'po danu' ) !== false ) return true;

        return false;
    }

    /**
     * Aktivira flag — TTL 1h. Sliding: svaki novi 400 produžuje TTL.
     * Sačuva mysql timestamp prvog pogađanja u tom 1h prozoru.
     */
    public static function markReached(): void {
        set_transient( self::TRANSIENT_KEY, current_time( 'mysql' ), self::TTL );
    }

    /**
     * Da li je flag trenutno aktivan?
     */
    public static function isReached(): bool {
        return (bool) get_transient( self::TRANSIENT_KEY );
    }

    /**
     * Kada je limit prvi put detektiran u trenutnom prozoru (mysql timestamp), ili null.
     */
    public static function reachedAt(): ?string {
        $val = get_transient( self::TRANSIENT_KEY );
        return is_string( $val ) && ! empty( $val ) ? $val : null;
    }

    /**
     * Manuelni reset (debug / staging / nakon test-a).
     */
    public static function clear(): void {
        delete_transient( self::TRANSIENT_KEY );
    }
}
