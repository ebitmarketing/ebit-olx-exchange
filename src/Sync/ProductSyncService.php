<?php

namespace EbitOlx\Sync;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Contracts\LoggerInterface;
use EbitOlx\Contracts\SyncServiceInterface;
use EbitOlx\Helpers\OlxDailyLimit;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\Helpers\OptionsCache;
use EbitOlx\License\LicenseClient;
use EbitOlx\License\FeatureManager;

class ProductSyncService implements SyncServiceInterface {

    private ServerClient $api;
    private PriceCalculator $priceCalc;
    private DescriptionBuilder $descBuilder;
    private PayloadBuilder $payloadBuilder;
    private ImageSyncService $imageSyncService;
    private FeatureManager $featureManager;
    private ?LoggerInterface $logger;

    public function __construct(
        ServerClient $api,
        ?LoggerInterface $logger = null
    ) {
        $this->api              = $api;
        $this->logger           = $logger;
        $this->featureManager   = new FeatureManager( new LicenseClient( $api ) );
        $this->priceCalc        = new PriceCalculator();
        $this->descBuilder      = new DescriptionBuilder( $this->featureManager );
        $this->payloadBuilder   = new PayloadBuilder( $this->featureManager );
        $this->imageSyncService = new ImageSyncService( $api, $this->featureManager );
    }

    /**
     * Synchronize a WooCommerce product to OLX.
     *
     * @return array{success: bool, message: string, action: string}
     */
    public function syncProduct( int $postId, bool $isMassSync = false ): array {
        // 1. Check exclusion
        if ( OlxMetaHelper::isExcluded( $postId ) ) {
            return [ 'success' => false, 'message' => 'Proizvod je isključen iz sinhronizacije.', 'action' => 'skip' ];
        }

        // 2. Load WC product
        $product = wc_get_product( $postId );
        if ( ! $product ) {
            return [ 'success' => false, 'message' => 'Proizvod ne postoji.', 'action' => 'skip' ];
        }

        $existing_id = OlxMetaHelper::getOlxId( $postId );
        $is_in_stock = $product->is_in_stock();
        $is_vip      = $this->priceCalc->isVip( $postId );

        // 3. Handle out-of-stock
        $oos_result = $this->handleOutOfStock( $postId, $product, $existing_id, $is_in_stock, $is_vip );
        if ( $oos_result !== null ) {
            return $oos_result;
        }

        // 4. Verify location
        $country_id = OptionsCache::get( 'olx_country_id' );
        $city_id    = OptionsCache::get( 'olx_city_id' );
        if ( ! $country_id || ! $city_id ) {
            OlxMetaHelper::setSyncError( $postId, 'Nije podešena lokacija shopa u postavkama.' );
            return [ 'success' => false, 'message' => 'Lokacija Shopa nije podešena.', 'action' => 'error' ];
        }

        // 5. Resolve category mapping
        if ( ! $this->featureManager->can('mapping') ) {
            OlxMetaHelper::setSyncError( $postId, 'Funkcija mapiranja kategorija nije dozvoljena u vašem planu.' );
            return [ 'success' => false, 'message' => 'Mapiranje nije dozvoljeno.', 'action' => 'error' ];
        }

        $cat_mapping = $this->payloadBuilder->resolveCategoryMapping( $postId );
        if ( ! $cat_mapping['olx_cat_id'] ) {
            OlxMetaHelper::setSyncError( $postId, 'WooCommerce kategorija nije mapirana na OLX.' );
            return [ 'success' => false, 'message' => 'Kategorija nije mapirana.', 'action' => 'error' ];
        }

        $olx_cat_id       = $cat_mapping['olx_cat_id'];
        $primary_wc_cat_id = $cat_mapping['primary_wc_cat_id'];

        // 6. Resolve attributes
        $attr_result = $this->payloadBuilder->resolveAttributes( $postId, $olx_cat_id, $primary_wc_cat_id );
        if ( $attr_result['error'] ) {
            OlxMetaHelper::setSyncError( $postId, $attr_result['error'] );
            return [ 'success' => false, 'message' => $attr_result['error'], 'action' => 'error' ];
        }

        // 7. Calculate price
        $price = $this->priceCalc->calculate( $product, $postId );

        // 8. Build description
        $description = $this->descBuilder->build( $product, $price, $olx_cat_id );

        // 9. Resolve brand and state
        $brand_id = $this->payloadBuilder->resolveBrandId( $postId );
        $state    = $this->payloadBuilder->resolveState( $postId, $primary_wc_cat_id );

        // 10. Build payload
        $is_update = ! empty( $existing_id );
        $payload   = $this->payloadBuilder->build(
            $product, $postId, $price, $olx_cat_id,
            $state, $description, $brand_id,
            $attr_result['attributes'], $is_update
        );

        // 11. Send to server API
        $response = $this->sendToApi( $postId, $existing_id, $payload, $olx_cat_id, $is_update, $isMassSync );
        if ( $response['error'] ) {
            return $response;
        }

        $listing_id = $response['listing_id'];
        $was_healed = ! empty( $response['was_404_healed'] );
        $is_new     = ! $is_update || $was_healed;

        // 12. Always check images on every sync — the internal hash will decide
        //     whether the price badge / image actually changed and needs re-upload.
        //     Force regen u 404-healed slučaju (novi OLX listing — slike sigurno fale).
        $this->imageSyncService->syncImages( $postId, (int) $listing_id, $is_new, $was_healed );

        // 13. Handle publish/hide/unhide — server handles publish internally during sync
        $this->handleListingVisibility( $postId, $listing_id, $is_in_stock );

        // 14. Update meta
        update_post_meta( $postId, '_olx_last_sync', current_time( 'mysql' ) );

        $this->logger?->info( 'Product synced', [
            'post_id' => $postId,
            'olx_id'  => $listing_id,
            'action'  => $was_healed ? '404_healed' : ( $is_new ? 'new' : 'update' ),
        ] );

        $msg_prefix = $was_healed
            ? 'Stari OLX oglas obrisan — kreiran novi! ID: '
            : 'OK. ID: ';

        return [
            'success' => true,
            'message' => $msg_prefix . $listing_id . ( ! $is_in_stock ? ' (Sakriveno)' : '' ),
            'action'  => $is_new ? 'new' : 'update',
        ];
    }

    /**
     * Delegate to ImageSyncService.
     */
    public function syncImages( int $postId, int $olxId, bool $isNew = false, bool $forceRegen = false ): array {
        return $this->imageSyncService->syncImages( $postId, $olxId, $isNew, $forceRegen );
    }

    /**
     * Handle out-of-stock products: hide or delete from OLX.
     */
    private function handleOutOfStock( int $postId, \WC_Product $product, ?string $existingId, bool $isInStock, bool $isVip ): ?array {
        $instock_only = OptionsCache::get( 'drtechno_olx_sync_instock_only' ) === 'yes';

        if ( ! $instock_only || $isInStock ) {
            return null; // Not handled here
        }

        if ( ! $existingId ) {
            return [ 'success' => false, 'message' => 'Preskočeno (nema na stanju).', 'action' => 'skip' ];
        }

        $enable_hide = OptionsCache::get( 'drtechno_olx_enable_hide_unhide' ) === 'yes';

        if ( $isVip || $enable_hide ) {
            $hide_resp = $this->api->hide( $existingId );
            // License quota guard — server može odbiti hide ako je dnevna kvota dostignuta
            if ( $hide_resp['error'] && self::isLicenseQuotaMessage( $hide_resp['message'] ?? '', $hide_resp['raw_data'] ?? null ) ) {
                OlxMetaHelper::setSyncError( $postId, 'Dnevna licencna kvota dostignuta — hide pauziran.' );
                return [ 'success' => false, 'message' => 'Dnevna kvota plana dostignuta.', 'action' => 'quota' ];
            }
            OlxMetaHelper::setStatus( $postId, 'hidden' );
            return [ 'success' => true, 'message' => 'Sakriveno (Nema na stanju).', 'action' => 'hidden' ];
        }

        // Delete
        $del_resp = $this->api->delete( $existingId );
        if ( $del_resp['error'] && self::isLicenseQuotaMessage( $del_resp['message'] ?? '', $del_resp['raw_data'] ?? null ) ) {
            OlxMetaHelper::setSyncError( $postId, 'Dnevna licencna kvota dostignuta — delete pauziran.' );
            return [ 'success' => false, 'message' => 'Dnevna kvota plana dostignuta.', 'action' => 'quota' ];
        }
        OlxMetaHelper::clearAllMeta( $postId );
        return [ 'success' => true, 'message' => 'Obrisano (Nema na stanju).', 'action' => 'deleted_outofstock' ];
    }

    /**
     * Send payload to server API via sync action.
     *
     * 404 auto-healing: ako postoji `existingId` (update) i OLX vrati 404
     * (oglas obrisan ručno na OLX-u), brišemo lokalne meta i ponovo
     * šaljemo ISTI payload bez `olx_article_id` — server tada pravi
     * NOVI oglas. Postavlja `was_404_healed=true` u return tako da
     * caller (ProductSyncService::syncProduct, MassSyncAjax,
     * CronManager::batchWorker) zna da je ovo de-facto 'new' akcija
     * (treba re-upload slika, prikaz "NOVI" status, itd.).
     */
    private function sendToApi( int $postId, ?string $existingId, array $payload, int $olxCatId, bool $isUpdate, bool $isMassSync ): array {
        // OLX daily limit pre-check: ako je flag set, preskoči CREATE bez API poziva.
        // UPDATE prolazi normalno (OLX ne računa update u limit).
        if ( ! $existingId && OlxDailyLimit::isReached() ) {
            $reachedAt = OlxDailyLimit::reachedAt() ?? '?';
            $msg       = sprintf( 'Dnevni OLX limit (350 novih) dostignut u %s — čeka oslobađanje.', $reachedAt );
            OlxMetaHelper::setSyncError( $postId, $msg );
            return [
                'error'   => true,
                'success' => false,
                'message' => 'Dnevni OLX limit dostignut — preskočeno (CREATE pauziran).',
                'action'  => 'limit',
            ];
        }

        if ( $existingId ) {
            $payload['olx_article_id'] = $existingId;
        }

        if ( $isMassSync ) {
            $payload['is_mass_sync'] = true;
        }

        $response       = $this->api->sync( $payload );
        $was_404_healed = false;

        // OLX daily limit detected on response — postavi flag i preskoči ostatak retry logike.
        // Bitno: ova provjera ide PRIJE 404-healing-a da limit error ne bi bio
        // pogrešno interpretiran kao "obrisan oglas".
        if ( $response['error'] && OlxDailyLimit::isLimitMessage( $response['message'] ?? '', $response['raw_data'] ?? null ) ) {
            OlxDailyLimit::markReached();
            $reachedAt = OlxDailyLimit::reachedAt() ?? current_time( 'mysql' );
            $this->logger?->warning( 'OLX daily limit reached', [
                'post_id'    => $postId,
                'reached_at' => $reachedAt,
                'is_create'  => ! $existingId,
            ] );
            $msg = sprintf( 'Dnevni OLX limit (350 novih) dostignut u %s — čeka oslobađanje.', $reachedAt );
            OlxMetaHelper::setSyncError( $postId, $msg );
            return [
                'error'   => true,
                'success' => false,
                'message' => 'Dnevni OLX limit dostignut — preskočeno (CREATE pauziran).',
                'action'  => 'limit',
            ];
        }

        // License daily quota (server-side) — server odbija akciju kad je
        // licencni dnevni limit (max_daily_syncs) dostignut. Primjenjuje se
        // na sve license-tied akcije (sync, hide, delete, refresh, ...).
        // Reset je u ponoć Sarajevo (server logika u LicenseManager::validate).
        if ( $response['error'] && self::isLicenseQuotaMessage( $response['message'] ?? '', $response['raw_data'] ?? null ) ) {
            $this->logger?->warning( 'License daily quota reached', [
                'post_id'      => $postId,
                'is_create'    => ! $existingId,
                'server_error' => $response['message'] ?? '',
            ] );
            OlxMetaHelper::setSyncError( $postId, 'Dnevna licencna kvota plana dostignuta — pauzirano do reseta u ponoć.' );
            return [
                'error'   => true,
                'success' => false,
                'message' => 'Dnevna kvota plana dostignuta — sinhronizacija pauzirana.',
                'action'  => 'quota',
            ];
        }

        // Auto-heal: oglas je obrisan na OLX-u — recreate kao novi.
        if ( $response['error'] && $existingId && self::isNotFoundError( $response ) ) {
            $this->logger?->info( 'OLX 404 detected — auto-healing as create', [
                'post_id'      => $postId,
                'old_olx_id'   => $existingId,
                'server_error' => $response['message'] ?? '',
            ] );

            // Očisti samo "live" sync state — `_olx_first_published` i
            // `_olx_image_hash` ostaju (history / cache).
            OlxMetaHelper::clearAllMeta( $postId );

            // Skini stari ID iz payloada → server tretira kao create
            unset( $payload['olx_article_id'] );

            $response       = $this->api->sync( $payload );
            $was_404_healed = true;
        }

        if ( $response['error'] ) {
            $err_msg = $response['message'] ?? '';
            if ( isset( $response['raw_data']['errors'] ) ) {
                $err_msg .= ' | ' . json_encode( $response['raw_data']['errors'], JSON_UNESCAPED_UNICODE );
            }
            if ( empty( $err_msg ) ) {
                $err_msg = json_encode( $response['raw_data'], JSON_UNESCAPED_UNICODE );
            }

            OlxMetaHelper::setSyncError( $postId, 'OLX API: ' . $err_msg );
            return [ 'error' => true, 'success' => false, 'message' => $err_msg, 'action' => 'error' ];
        }

        $listing_id = $response['data']['olx_id'] ?? $response['data']['id'] ?? null;
        if ( ! $listing_id && ! $was_404_healed ) {
            // U normalnom update slučaju, server može ne vratiti ID — koristi postojeći.
            $listing_id = $existingId;
        }
        if ( ! $listing_id ) {
            OlxMetaHelper::setSyncError( $postId, 'Artikal kreiran, ali API nije vratio ID.' );
            return [ 'error' => true, 'success' => false, 'message' => 'API nije vratio ID.', 'action' => 'error' ];
        }

        OlxMetaHelper::clearSyncError( $postId );
        OlxMetaHelper::setOlxId( $postId, (string) $listing_id );

        // Označi prvu objavu (ako nije već postavljeno) — koristi se za history/recovery
        if ( $was_404_healed || ! $existingId ) {
            if ( ! get_post_meta( $postId, '_olx_first_published', true ) ) {
                update_post_meta( $postId, '_olx_first_published', current_time( 'mysql' ) );
            }
        }

        return [ 'error' => false, 'listing_id' => $listing_id, 'was_404_healed' => $was_404_healed ];
    }

    /**
     * Detektuje da li je server odgovor 404/not-found za OLX resurs.
     * Pokriva sve formate koje server može proslijediti iz OLX API-ja:
     *   - "404"
     *   - "not_found" / "resource_not_found"
     *   - Bosanski: "ne postoji" / "nije pronađen"
     */
    private static function isNotFoundError( array $response ): bool {
        $msg = strtolower( (string) ( $response['message'] ?? '' ) );
        $raw = strtolower( (string) wp_json_encode( $response['raw_data'] ?? [] ) );
        $haystack = $msg . ' ' . $raw;

        return strpos( $haystack, '404' ) !== false
            || strpos( $haystack, 'not_found' ) !== false
            || strpos( $haystack, 'resource_not_found' ) !== false
            || strpos( $haystack, 'ne postoji' ) !== false
            || strpos( $haystack, 'nije pronađen' ) !== false
            || strpos( $haystack, 'nije pronaden' ) !== false;
    }

    /**
     * Detektuje da li server poruka signalizira license daily/monthly quota.
     * Server (api/v1/index.php) vraća dva tipa:
     *   - "DNEVNI LIMIT: Iskoristili ste X od Y..." (max_daily_syncs)
     *   - "KVOTA PREKORAČENA: ... ovog mjeseca." (max_products)
     */
    public static function isLicenseQuotaMessage( string $msg, $rawData = null ): bool {
        $hay = strtolower( $msg . ' ' . (string) wp_json_encode( $rawData ) );

        if ( strpos( $hay, 'dnevni limit' ) !== false )       return true;
        if ( strpos( $hay, 'kvota prekoračena' ) !== false )  return true;
        if ( strpos( $hay, 'kvota prekoracena' ) !== false )  return true;
        if ( strpos( $hay, 'iskoristili ste' ) !== false &&
             ( strpos( $hay, 'dozvoljeni' ) !== false || strpos( $hay, 'sinhronizacija' ) !== false ) ) return true;

        return false;
    }

    /**
     * Handle listing visibility: hide or unhide via server.
     * The server handles publish internally during sync, so we only need
     * to manage hide/unhide for stock-based visibility.
     */
    private function handleListingVisibility( int $postId, string $listingId, bool $isInStock ): void {
        if ( ! $isInStock ) {
            $this->api->hide( $listingId );
            OlxMetaHelper::setStatus( $postId, 'hidden' );
            return;
        }

        $old_status = OlxMetaHelper::getStatus( $postId );
        if ( $old_status === 'hidden' ) {
            $this->api->unhide( $listingId );
        }

        // Server handles publish internally during sync
        OlxMetaHelper::setStatus( $postId, 'active' );
    }
}
