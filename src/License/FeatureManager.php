<?php

namespace EbitOlx\License;

defined( 'ABSPATH' ) || exit;

/**
 * Provjera dostupnih funkcionalnosti na osnovu SaaS licence/plana.
 *
 * Dostupne funkcionalnosti (odgovaraju kolonama u plans tabeli na serveru):
 *   desc_settings, mapping, brands, default_attrs, mass_sync, cleanup, sponsor, 
 *   vip_articles, mass_bump, image_processing, price_rules, priority
 */
class FeatureManager {

    /**
     * Lista svih feature ključeva koji se mapiraju na drtechno_olx_feat_* opcije.
     * Mora odgovarati `plans.features` JSON kolonama na serveru.
     */
    private const FEATURE_KEYS = [
        'desc_settings', 'mapping', 'brands', 'default_attrs',
        'mass_sync', 'cleanup', 'sponsor', 'vip_articles',
        'mass_bump', 'image_processing', 'price_rules', 'priority',
    ];

    private LicenseClient $license;

    /** @var array|null Keširane funkcionalnosti za ovaj request */
    private ?array $features = null;

    /** @var array|null Keširani podaci o licenci za ovaj request */
    private ?array $licenseData = null;

    /** @var bool Označava da li su granularne wp_options već sinhronizovane u ovom requestu */
    private bool $optionsSynced = false;

    public function __construct( LicenseClient $license ) {
        $this->license = $license;
    }

    /**
     * Provjeri da li je određena funkcionalnost dostupna za trenutnu licencu.
     *
     * @param string $feature Jedna od: desc_settings, mapping, brands, default_attrs,
     *                        mass_sync, cleanup, sponsor, vip_articles,
     *                        mass_bump, image_processing, price_rules, priority, ai_titles
     */
    public function can( string $feature ): bool {
        $features = $this->getFeatures();
        return ! empty( $features[ $feature ] );
    }

    /**
     * Provjeri da li je licenca validna.
     */
    public function isLicenseValid(): bool {
        return $this->getLicenseData()['valid'] ?? false;
    }

    /**
     * Dohvati naziv trenutnog plana.
     */
    public function getPlan(): string {
        return $this->getLicenseData()['plan'] ?? 'none';
    }

    /**
     * Dohvati sve funkcionalnosti kao asocijativni niz.
     *
     * @return array<string, bool>
     */
    public function getFeatures(): array {
        if ( $this->features === null ) {
            $data = $this->getLicenseData();
            $this->features = ( $data['valid'] ?? false ) ? ( $data['features'] ?? [] ) : [];
            $this->syncGranularOptions();
        }
        return $this->features;
    }

    /**
     * Dohvati informacije o kvoti.
     *
     * @return array{max_products: int, sync_count: int, article_count: int, daily_sync_count: int}
     */
    public function getQuota(): array {
        $data = $this->getLicenseData();
        return [
            'max_products'     => $data['max_products'] ?? 0,
            'sync_count'       => $data['sync_count'] ?? 0,
            'article_count'    => $data['article_count'] ?? 0,
            'daily_sync_count' => $data['daily_sync_count'] ?? 0,
        ];
    }

    /**
     * Dohvati pune podatke o licenci (keširano po requestu).
     */
    public function getLicenseData(): array {
        if ( $this->licenseData === null ) {
            $this->licenseData = $this->license->validate();
        }
        return $this->licenseData;
    }

    /**
     * Reaktivno čišćenje feature flagova kad ServerClient detektuje license-invalid
     * grešku na bilo kojem zahtjevu. Resetuje in-memory + granularne wp_options
     * tako da svi tabovi odmah prikažu zaključano stanje.
     */
    public function onLicenseInvalidated( string $reason = '' ): void {
        $this->features      = [];
        $this->licenseData   = [ 'valid' => false, 'reason' => $reason ];
        $this->optionsSynced = false;

        foreach ( self::FEATURE_KEYS as $key ) {
            update_option( 'drtechno_olx_feat_' . $key, 0 );
        }
        update_option( 'drtechno_olx_license_features', [] );
        update_option( 'drtechno_olx_license_details',  $this->licenseData );

        $this->optionsSynced = true; // ne treba ponovni sync u istom request-u
    }

    /**
     * Forsiraj osvježavanje sa servera (briše sve keševe).
     */
    public function refresh(): array {
        $this->features      = null;
        $this->licenseData   = null;
        $this->optionsSynced = false;
        $this->licenseData   = $this->license->validate( true );
        $this->features      = ( $this->licenseData['valid'] ?? false ) ? ( $this->licenseData['features'] ?? [] ) : [];
        $this->syncGranularOptions();
        return $this->licenseData;
    }

    /**
     * Sinkroniziraj pojedinačne wp_options sa trenutnim feature stanjem.
     * Tabovi (tab-sync-mass.php, tab-sponsor.php, tab-vip-articles.php, ...) i
     * legacy AJAX provjere (BulkActions, admin-page.php) čitaju direktno te
     * pojedinačne opcije; bez ovog koraka one postoje samo dok ih neko ručno
     * ne upiše. Pokreće se najviše jednom po requestu.
     */
    private function syncGranularOptions(): void {
        if ( $this->optionsSynced ) return;
        $this->optionsSynced = true;

        $features = $this->features ?? [];

        // Optimizacija: ako je `license_features` već identičan trenutnom features array-u,
        // pretpostavi da su i feat_* opcije svježe i preskoči 13 update_option poziva.
        $existing = get_option( 'drtechno_olx_license_features', null );
        if ( is_array( $existing ) && $existing == $features ) {
            return;
        }

        foreach ( self::FEATURE_KEYS as $key ) {
            update_option( 'drtechno_olx_feat_' . $key, ! empty( $features[ $key ] ) ? 1 : 0 );
        }
        update_option( 'drtechno_olx_license_features', $features );

        // Legacy: license_details koje gledaju tab-settings.php i admin-page.php fallback
        $details = $this->licenseData ?? [];
        unset( $details['features'] );
        update_option( 'drtechno_olx_license_details', $details );
    }

    /**
     * Provjeri funkcionalnost i pošalji JSON grešku ako nije dostupna.
     * Koristiti u AJAX handlerima.
     */
    public function requireFeature( string $feature ): void {
        if ( ! $this->isLicenseValid() ) {
            wp_send_json_error( 'Licenca nije validna. Molimo provjerite vaš licencni ključ.' );
        }
        if ( ! $this->can( $feature ) ) {
            $label = self::featureLabel( $feature );
            wp_send_json_error( "Vaš plan ne uključuje funkciju: {$label}. Nadogradite plan za pristup." );
        }
    }

    /**
     * Dohvati čitljiv naziv funkcionalnosti (bosanski).
     */
    public static function featureLabel( string $feature ): string {
        $labels = [
            'desc_settings'    => 'Postavke opisa',
            'mapping'          => 'Mapiranje',
            'brands'           => 'Brendovi',
            'default_attrs'    => 'Zadani atributi',
            'mass_sync'        => 'Masovni Sync',
            'cleanup'          => 'Čišćenje',
            'sponsor'          => 'Sponzoriranje',
            'vip_articles'     => 'VIP Artikli',
            'mass_bump'        => 'Masovni BUMP',
            'image_processing' => 'Obrada Slika',
            'price_rules'      => 'Pravila Cijena',
            'priority'         => 'Prioritet',
            'ai_titles'        => 'AI Nazivi',
        ];
        return $labels[ $feature ] ?? $feature;
    }
}
