<?php
/**
 * Fired when the plugin is uninstalled.
 *
 * Čisti sve podatke koje je plugin kreirao:
 * - WP Options (sve drtechno_olx_ i olx_ opcije)
 * - Transients
 * - Custom tabele
 * - WP-Cron scheduled events
 * - (Opcionalno) Product meta data
 *
 * @package EBIT_OLX_Exchange
 */

// If uninstall not called from WordPress, then exit.
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// =========================================
// 1. DELETE ALL PLUGIN OPTIONS
// =========================================
$options_to_delete = [
    // Tab 1: Postavke
    'drtechno_olx_server_url',
    'drtechno_olx_license_key',
    'drtechno_olx_api_token',
    'drtechno_olx_password_enc',
    'drtechno_olx_password_cleaned',
    'drtechno_olx_server_status',
    'drtechno_olx_server_time',
    'drtechno_olx_license_details',
    'drtechno_olx_license_features',
    'drtechno_olx_license_cache_last_valid',
    'olx_username',
    'olx_password',
    'olx_api_token',
    'olx_user_id',
    'olx_shop_username',
    'olx_account_type',
    'olx_country_id',
    'olx_city_id',

    // Tab 2: Postavke opisa
    'drtechno_olx_global_prefix',
    'drtechno_olx_global_description',

    // Tab 3: Mapiranje kategorija
    'drtechno_olx_category_mapping',

    // Tab 4: Brendovi / Meta podaci
    'drtechno_olx_brand_mapping',
    'drtechno_olx_available_brands',
    'drtechno_olx_attribute_mapping',
    'drtechno_olx_available_attributes',
    'drtechno_olx_category_attributes',

    // Lokacije
    'drtechno_olx_countries_data',
    'drtechno_olx_cities_data',

    // Tab 5: Sync / Automatizacija
    'drtechno_olx_sync_instock_only',
    'drtechno_olx_enable_auto_sync',
    'drtechno_olx_cron_batch_size',
    'drtechno_olx_enable_hide_unhide',
    'drtechno_olx_enable_duplicate_check',
    'drtechno_olx_enable_image_update',
    'drtechno_olx_enable_daily_populator',
    'drtechno_olx_daily_populator_time',
    'drtechno_olx_daily_populator_mode',
    // Legacy nazivi (stari instalovi prije Phase 6 fix-a)
    'drtechno_olx_enable_daily_cron',
    'drtechno_olx_daily_cron_time',

    // Tab 6: Zadani atributi
    'drtechno_olx_default_attributes',

    // Feature flags (svih 12 — odgovaraju plans.features na serveru)
    'drtechno_olx_feat_desc_settings',
    'drtechno_olx_feat_mapping',
    'drtechno_olx_feat_brands',
    'drtechno_olx_feat_default_attrs',
    'drtechno_olx_feat_mass_sync',
    'drtechno_olx_feat_cleanup',
    'drtechno_olx_feat_sponsor',
    'drtechno_olx_feat_vip_articles',
    'drtechno_olx_feat_mass_bump',
    'drtechno_olx_feat_image_processing',
    'drtechno_olx_feat_price_rules',
    'drtechno_olx_feat_priority',

    // Interno
    'drtechno_olx_db_version',
];

foreach ($options_to_delete as $option) {
    delete_option($option);
}

// =========================================
// 2. DELETE TRANSIENTS
// =========================================
$transients_to_delete = [
    'drtechno_olx_server_cats',
    'olx_cleanup_active_ids',
    'drtechno_olx_license_cache',
    'drtechno_olx_dashboard_live',
    'drtechno_olx_active_listings_cache',
    'drtechno_olx_mass_image_queue',
    'drtechno_olx_manual_bump_queue',
    'drtechno_olx_special_bump_queue',
    'drtechno_olx_filtered_bump_queue',
    'drtechno_olx_ai_title_queue',
    'drtechno_olx_csv_diff',
];

foreach ($transients_to_delete as $transient) {
    delete_transient($transient);
}

// =========================================
// 3. UNSCHEDULE WP-CRON EVENTS
// =========================================
$cron_events = [
    'drtechno_olx_batch_worker_event',
    'drtechno_olx_daily_populator_event',
    'drtechno_olx_sponsor_worker_event',
];

foreach ($cron_events as $event) {
    $timestamp = wp_next_scheduled($event);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $event);
    }
    // Cleanup svih hook instanci (ne samo prve), za slučaj duplikata
    wp_clear_scheduled_hook($event);
}

// =========================================
// 4. DROP CUSTOM TABLES
// =========================================
global $wpdb;
$table_prod_queue = $wpdb->prefix . 'drtechno_olx_prod_queue';
$wpdb->query("DROP TABLE IF EXISTS {$table_prod_queue}");

// Legacy tabele (ako postoje iz starijih verzija)
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}drtechno_olx_categories");
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}drtechno_olx_queue");

// =========================================
// 5. (OPCIONALNO) PRODUCT META DATA
// =========================================
// Otkomentarišite ovaj blok za KOMPLETNO brisanje svih OLX podataka sa proizvoda.
// UPOZORENJE: Ovo briše sve linkove ka OLX oglasima — nema povratka!
/*
$meta_keys = [
    // Osnovni sync state
    '_olx_article_id',
    '_olx_status',
    '_olx_last_sync',
    '_olx_sync_error',
    '_olx_state',
    '_olx_exclude_sync',
    '_olx_first_published',

    // Smart Image Cache
    '_olx_image_hash',

    // VIP artikli
    '_olx_special_price',

    // Sponzoriranje
    '_olx_sponsor_status',
    '_olx_sponsor_params',
    '_olx_sponsor_time',
    '_olx_sponsor_error',
];
foreach ($meta_keys as $key) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $key));
}
// Dinamički atribut meta keyevi (_olx_attr_*)
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_olx_attr_%'");
*/
