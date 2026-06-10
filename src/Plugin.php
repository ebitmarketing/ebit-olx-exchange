<?php

namespace EbitOlx;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Admin\AssetManager;
use EbitOlx\Admin\BulkActions;
use EbitOlx\Admin\ProductListColumns;
use EbitOlx\Admin\ProductMetabox;
use EbitOlx\Ajax\AiTitleAjax;
use EbitOlx\Ajax\BumpAjax;
use EbitOlx\Ajax\CategoryAjax;
use EbitOlx\Ajax\CleanupAjax;
use EbitOlx\Ajax\LicenseAjax;
use EbitOlx\Ajax\MassSyncAjax;
use EbitOlx\Ajax\ProductAjax;
use EbitOlx\Ajax\SponsorAjax;
use EbitOlx\Api\ServerClient;
use EbitOlx\Cron\CronManager;
use EbitOlx\Database\Migrator;
use EbitOlx\License\FeatureManager;
use EbitOlx\License\LicenseClient;
use EbitOlx\Logging\Logger;
use EbitOlx\Security\CredentialManager;

class Plugin {

    private static ?self $instance = null;
    private Logger $logger;
    private CredentialManager $credentials;
    private LicenseClient $licenseClient;
    private FeatureManager $featureManager;

    /** @var bool Track whether Phase 3 services have been booted */
    private bool $phase3Booted = false;

    public static function getInstance(): self {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->logger         = new Logger();
        $this->credentials    = new CredentialManager();
        $this->licenseClient  = new LicenseClient();
        $this->featureManager = new FeatureManager( $this->licenseClient );
    }

    /**
     * Boot the plugin — register all components.
     * Phases 1–3 run alongside the existing monolith class.
     * The monolith delegates to services when they exist (class_exists checks).
     */
    public function boot(): void {
        // Phase 1: Database migrations
        ( new Migrator() )->register();

        // Phase 1: Asset management (new CSS/JS files)
        ( new AssetManager() )->register();

        // Phase 1: One-time credential migration (plain text → encrypted)
        add_action( 'admin_init', [ $this, 'maybe_migrate_credentials' ] );

        // Sinkroniziraj granularne feat_* opcije sa stvarnim license features-ima
        // čim god uđemo u admin (prije nego što tab fajlovi pročitaju get_option).
        add_action( 'admin_init', [ $this, 'syncFeatureOptions' ], 5 );

        // Reaktivno zaključaj tabove kad server vrati license-invalid grešku
        // na bilo kojem requestu (ServerClient::request firea ovaj action).
        add_action( 'ebit_olx_license_invalidated', [ $this->featureManager, 'onLicenseInvalidated' ], 10, 1 );

        // Phase 3: AJAX, Cron, Admin UI services
        $this->bootPhase3();

        $this->logger->info( 'Plugin booted', [ 'version' => EBIT_OLX_VERSION ] );
    }

    /**
     * Phase 3: Register AJAX handlers, Cron manager, Admin UI components.
     * These are wired up with a flag check so the monolith can detect
     * them via class_exists() and skip its own implementations.
     */
    private function bootPhase3(): void {
        if ( $this->phase3Booted ) return;
        $this->phase3Booted = true;

        $api = ServerClient::getInstance();
        $fm  = $this->featureManager;

        // ── License AJAX ─────────────────────────────────────────────
        ( new LicenseAjax( $this->licenseClient, $fm, $api ) )->register();

        // ── AJAX Handlers ───────────────────────────────────────────
        ( new ProductAjax( $api, $fm ) )->register();
        ( new MassSyncAjax( $api, $fm ) )->register();
        ( new CleanupAjax( $api, $fm ) )->register();
        ( new SponsorAjax( $api, $fm ) )->register();
        ( new CategoryAjax( $api ) )->register();
        ( new BumpAjax( $api ) )->register();
        ( new AiTitleAjax( $api ) )->register();

        // ── Cron Manager ────────────────────────────────────────────
        ( new CronManager( $api, $fm ) )->register();

        // ── Admin: Product list columns + filter ────────────────────
        ( new ProductListColumns() )->register();

        // ── Admin: Product metabox (edit screen) ────────────────────
        ( new ProductMetabox() )->register();

        // ── Admin: Bulk actions on Products list ────────────────────
        ( new BulkActions( $api ) )->register();
    }

    /**
     * Triggeruje FeatureManager::getFeatures() na admin_init. To poziva
     * syncGranularOptions() koji upiše sve drtechno_olx_feat_* opcije
     * iz keširane license validacije, tako da tab fajlovi (koji čitaju
     * get_option direktno) vide svjež feature state na svakom admin loadu.
     */
    public function syncFeatureOptions(): void {
        // Cijena ovog poziva je: 1 transient lookup (svjež keš = 0 HTTP poziva)
        // + 0 update_option ako se features nisu mijenjali.
        $this->featureManager->getFeatures();
    }

    /**
     * Obriši lokalno sačuvanu OLX lozinku (bezbjednosna mjera — korisnik se mora ponovo ulogovati).
     */
    public function maybe_migrate_credentials(): void {
        if ( get_option( 'drtechno_olx_password_cleaned' ) === 'yes' ) {
            return;
        }

        $this->credentials->delete_password();
        update_option( 'drtechno_olx_password_cleaned', 'yes' );

        $this->logger->info( 'Lokalno sačuvana OLX lozinka obrisana iz sigurnosnih razloga.' );
    }

    public function getLogger(): Logger {
        return $this->logger;
    }

    public function getCredentialManager(): CredentialManager {
        return $this->credentials;
    }

    public function getLicenseClient(): LicenseClient {
        return $this->licenseClient;
    }

    public function getFeatureManager(): FeatureManager {
        return $this->featureManager;
    }

    /**
     * Check if Phase 3 services are active (used by monolith for delegation checks).
     */
    public function isPhase3Active(): bool {
        return $this->phase3Booted;
    }

    // Prevent cloning and unserialization
    private function __clone() {}
    public function __wakeup() {
        throw new \RuntimeException( 'Cannot unserialize singleton' );
    }
}
