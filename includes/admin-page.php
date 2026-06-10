<?php
/**
 * Admin Page HTML - Svi tabovi admin interfejsa
 * Uključuje: Postavke (licenca + OLX login + opis + zalihe + lokacija), Crawler, Mapiranje, Meta Podaci, Mass Sync
 */
if (!defined('ABSPATH'))
    exit;
if (!current_user_can('manage_options'))
    return;
global $wpdb;

// --- Form handling (server URL, licenca, login, mapping, bulk attrs...) ---
if (isset($_POST['olx_server_url_submit']) && check_admin_referer('olx_server_url_action', 'olx_server_url_nonce')) {
    $new_url = esc_url_raw($_POST['drtechno_olx_server_url']);
    update_option('drtechno_olx_server_url', $new_url);
    // Ping test
    if (!empty($new_url)) {
        $ping = wp_remote_post($new_url, [
            'body' => wp_json_encode(['action' => 'ping']),
            'headers' => ['Content-Type' => 'application/json'],
            'timeout' => 10,
            'sslverify' => true, // Eksplicitno — TLS verifikacija se nikad ne smije gasiti.
        ]);
        if (!is_wp_error($ping)) {
            $ping_data = json_decode(wp_remote_retrieve_body($ping), true);
            if (!empty($ping_data) && empty($ping_data['error'])) {
                update_option('drtechno_olx_server_status', 'online');
                update_option('drtechno_olx_server_time', $ping_data['data']['time'] ?? '');
                echo '<div class="notice notice-success is-dismissible"><p>✓ Server URL sačuvan! Server je <strong>dostupan</strong>.</p></div>';
            }
            else {
                update_option('drtechno_olx_server_status', 'error');
                echo '<div class="notice notice-warning is-dismissible"><p>⚠ Server URL sačuvan, ali server je vratio grešku.</p></div>';
            }
        }
        else {
            update_option('drtechno_olx_server_status', 'offline');
            echo '<div class="notice notice-error is-dismissible"><p>✗ Server URL sačuvan, ali server <strong>nije dostupan</strong>: ' . esc_html($ping->get_error_message()) . '</p></div>';
        }
    }
    else {
        echo '<div class="notice notice-success is-dismissible"><p>✓ Server URL sačuvan!</p></div>';
    }
}
if (isset($_POST['olx_license_submit']) && check_admin_referer('olx_license_action', 'olx_license_nonce')) {
    $new_key = sanitize_text_field($_POST['drtechno_olx_license_key'] ?? '');
    if ( class_exists('\EbitOlx\Plugin') ) {
        $lc = \EbitOlx\Plugin::getInstance()->getLicenseClient();
        $lc->saveCredentials( $new_key, get_option('drtechno_olx_server_url', '') );
        $validation = \EbitOlx\Plugin::getInstance()->getFeatureManager()->refresh();
        if ( $validation['valid'] ) {
            echo '<div class="notice notice-success is-dismissible"><p>✓ Licenca aktivna! Plan: <strong>' . esc_html( strtoupper( $validation['plan'] ) ) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $validation['reason'] ?? 'Licenca nije validna.' ) . '</p></div>';
        }
    } else {
        update_option('drtechno_olx_license_key', $new_key);
        $check = $this->server_request('license/validate');
        if (!$check['error']) {
            update_option('drtechno_olx_license_details', $check['data'] ?? []);
            if (isset($check['data']['features'])) {
                update_option('drtechno_olx_license_features', $check['data']['features']);
                // Update specific feature options for backward compatibility
                update_option('drtechno_olx_feat_mass_sync', !empty($check['data']['features']['mass_sync']) ? 1 : 0);
            }
            echo '<div class="notice notice-success is-dismissible"><p>✓ Licenca aktivna! Plan: <strong>' . esc_html( strtoupper($check['data']['plan'] ?? 'basic') ) . '</strong></p></div>';
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($check['message'] ?? 'Greška') . '</p></div>';
        }
    }
}

if (isset($_POST['olx_save_default_attrs_submit']) && check_admin_referer('olx_save_default_attrs_action', 'olx_save_default_attrs_nonce')) {
    $wc_cat_id = intval($_POST['wc_category_id'] ?? 0);
    $attrs = $_POST['olx_attr'] ?? [];
    $state = sanitize_text_field($_POST['olx_state'] ?? '');

    if ($wc_cat_id > 0) {
        $default_attrs = get_option('drtechno_olx_default_attributes', []);

        $cat_data = [];
        if ($state !== '')
            $cat_data['olx_state'] = $state;
        foreach ($attrs as $k => $v) {
            if ($v !== '') {
                $cat_data[sanitize_text_field($k)] = sanitize_text_field($v);
            }
        }

        $default_attrs[$wc_cat_id] = $cat_data;
        update_option('drtechno_olx_default_attributes', $default_attrs);
        echo '<div class="notice notice-success is-dismissible"><p>✓ Zadani atributi uspješno sačuvani!</p></div>';
    }
}

if (isset($_POST['olx_stock_settings_submit']) && check_admin_referer('olx_stock_settings_action', 'olx_stock_settings_nonce')) {
    update_option('drtechno_olx_sync_instock_only', isset($_POST['drtechno_olx_sync_instock_only']) ? 'yes' : 'no');
    echo '<div class="notice notice-success is-dismissible"><p>Postavke zaliha sačuvane!</p></div>';
}

if (isset($_POST['olx_login_submit']) && check_admin_referer('olx_auth_action', 'olx_auth_nonce')) {
    $olx_login_user = sanitize_text_field($_POST['olx_username']);
    $olx_login_pass = sanitize_text_field($_POST['olx_password'] ?? '');
    update_option('olx_username', $olx_login_user);

    // Lozinka se NIKAD ne čuva lokalno. Očisti eventualni stari enkriptovani zapis.
    delete_option('drtechno_olx_password_enc');

    $resp = $this->server_request('auth/connect', ['username' => $olx_login_user, 'password' => $olx_login_pass]);
    if (!$resp['error']) {
        update_option('olx_user_id', $resp['data']['user_id'] ?? '');
        update_option('olx_shop_username', $resp['data']['username'] ?? '');
        update_option('olx_account_type', $resp['data']['type'] ?? 'user');
        if (!empty($resp['data']['olx_token'])) {
            update_option('drtechno_olx_api_token', $resp['data']['olx_token']);
            update_option('olx_api_token', $resp['data']['olx_token']);
        }
        echo '<div class="notice notice-success is-dismissible"><p>✓ Uspješno povezano sa OLX.ba! Shop: <strong>' . esc_html($resp['data']['username'] ?? '') . '</strong></p></div>';
    }
    else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($resp['message'] ?? 'Greška pri povezivanju') . '</p></div>';
    }
}

if (isset($_POST['olx_auto_sync_submit']) && check_admin_referer('olx_auto_sync_action', 'olx_auto_sync_nonce')) {
    // Provjeri licencu prije snimanja
    if (!get_option('drtechno_olx_feat_mass_sync', 0)) {
        echo '<div class="notice notice-error is-dismissible"><p>Vaša licenca ne podržava ovu funkcionalnost. Nadogradite plan.</p></div>';
    }
    else {
        $was_enabled = get_option('drtechno_olx_enable_auto_sync') === 'yes';
        $is_now_enabled = isset($_POST['drtechno_olx_enable_auto_sync']);
        update_option('drtechno_olx_enable_auto_sync', $is_now_enabled ? 'yes' : 'no');
        update_option('drtechno_olx_cron_batch_size', max(1, intval($_POST['drtechno_olx_cron_batch_size'] ?? 10)));
        // Ako se isključi, odmah očisti cron job
        if ($was_enabled && !$is_now_enabled) {
            $ts = wp_next_scheduled('drtechno_olx_batch_worker_event');
            if ($ts)
                wp_unschedule_event($ts, 'drtechno_olx_batch_worker_event');
        }
        echo '<div class="notice notice-success is-dismissible"><p>Postavke automatizacije sačuvane!</p></div>';
    }
}

if (isset($_POST['olx_sync_features_submit']) && check_admin_referer('olx_sync_features_action', 'olx_sync_features_nonce')) {
    update_option('drtechno_olx_enable_hide_unhide', isset($_POST['drtechno_olx_enable_hide_unhide']) ? 'yes' : 'no');
    update_option('drtechno_olx_enable_duplicate_check', isset($_POST['drtechno_olx_enable_duplicate_check']) ? 'yes' : 'no');
    update_option('drtechno_olx_enable_image_update', isset($_POST['drtechno_olx_enable_image_update']) ? 'yes' : 'no');
    echo '<div class="notice notice-success is-dismissible"><p>Postavke sync funkcionalnosti sačuvane!</p></div>';
}

if (isset($_POST['olx_daily_populator_submit']) && check_admin_referer('olx_daily_populator_action', 'olx_daily_populator_nonce')) {
    if (!get_option('drtechno_olx_feat_mass_sync', 0)) {
        echo '<div class="notice notice-error is-dismissible"><p>Vaša licenca ne podržava ovu funkcionalnost. Nadogradite plan.</p></div>';
    }
    else {
        $was_enabled = get_option('drtechno_olx_enable_daily_populator') === 'yes';
        $is_now_enabled = isset($_POST['drtechno_olx_enable_daily_populator']);
        update_option('drtechno_olx_enable_daily_populator', $is_now_enabled ? 'yes' : 'no');
        update_option('drtechno_olx_daily_populator_time', sanitize_text_field($_POST['drtechno_olx_daily_populator_time'] ?? '02:00'));
        update_option('drtechno_olx_daily_populator_mode', sanitize_text_field($_POST['drtechno_olx_daily_populator_mode'] ?? 'update_only'));
        // Ako se isključi ili promijeni vrijeme, očisti stari cron i ponovo zakazi
        $ts = wp_next_scheduled('drtechno_olx_daily_populator_event');
        if ($ts)
            wp_unschedule_event($ts, 'drtechno_olx_daily_populator_event');
        // Ako se isključi, očisti i batch worker ako auto-sync također nije uključen
        if ($was_enabled && !$is_now_enabled && get_option('drtechno_olx_enable_auto_sync') !== 'yes') {
            $ts2 = wp_next_scheduled('drtechno_olx_batch_worker_event');
            if ($ts2)
                wp_unschedule_event($ts2, 'drtechno_olx_batch_worker_event');
        }
        echo '<div class="notice notice-success is-dismissible"><p>Postavke dnevnog populatora sačuvane!</p></div>';
    }
}

if (isset($_POST['olx_global_desc_submit']) && check_admin_referer('olx_global_desc_action', 'olx_global_desc_nonce')) {
    update_option('drtechno_olx_global_prefix', wp_kses_post($_POST['drtechno_olx_global_prefix']));
    update_option('drtechno_olx_global_description', wp_kses_post($_POST['drtechno_olx_global_description']));
    echo '<div class="notice notice-success is-dismissible"><p>Template tekstovi sačuvani!</p></div>';
}

if (isset($_POST['olx_login_submit']) && check_admin_referer('olx_auth_action', 'olx_auth_nonce')) {
    $olx_login_user = sanitize_text_field($_POST['olx_username']);
    $olx_login_pass = sanitize_text_field($_POST['olx_password']);
    update_option('olx_username', $olx_login_user);
    // Lozinka se NIKAD ne čuva lokalno. Očisti eventualni stari enkriptovani zapis.
    delete_option('drtechno_olx_password_enc');
    $resp = $this->server_request('auth/connect', ['username' => $olx_login_user, 'password' => $olx_login_pass]);
    if (!$resp['error']) {
        update_option('olx_user_id', $resp['data']['user_id'] ?? '');
        update_option('olx_shop_username', $resp['data']['username'] ?? '');
        update_option('olx_account_type', $resp['data']['type'] ?? 'user');
        // Sačuvaj OLX token lokalno — šalje se sa svakim zahtjevom
        if (!empty($resp['data']['olx_token'])) {
            update_option('drtechno_olx_api_token', $resp['data']['olx_token']);
        }
        echo '<div class="notice notice-success is-dismissible"><p>Uspješno povezano!</p></div>';
    }
    else {
        echo '<div class="notice notice-error is-dismissible"><p>' . esc_html($resp['message'] ?? 'Greška.') . '</p></div>';
    }
}

if (isset($_POST['olx_location_submit']) && check_admin_referer('olx_location_action', 'olx_location_nonce')) {
    update_option('olx_country_id', intval($_POST['olx_country_id']));
    update_option('olx_city_id', intval($_POST['olx_city_id']));
    echo '<div class="notice notice-success is-dismissible"><p>Lokacija sačuvana!</p></div>';
}
if (isset($_POST['olx_mapping_submit']) && check_admin_referer('olx_mapping_action', 'olx_mapping_nonce')) {
    if (isset($_POST['mapped_olx_cat']) && is_array($_POST['mapped_olx_cat'])) {
        update_option('drtechno_olx_category_mapping', array_filter(array_map('intval', $_POST['mapped_olx_cat'])));
        echo '<div class="notice notice-success is-dismissible"><p>Kategorije sačuvane!</p></div>';
    }
}
if (isset($_POST['olx_brand_mapping_submit']) && check_admin_referer('olx_brand_mapping_action', 'olx_brand_mapping_nonce')) {
    if (isset($_POST['mapped_olx_brand']) && is_array($_POST['mapped_olx_brand'])) {
        update_option('drtechno_olx_brand_mapping', array_filter(array_map('intval', $_POST['mapped_olx_brand'])));
        echo '<div class="notice notice-success is-dismissible"><p>Brendovi sačuvani!</p></div>';
    }
}
if (isset($_POST['reset_brands_submit'])) {
    update_option('drtechno_olx_available_brands', []);
    update_option('drtechno_olx_category_attributes', []);
    echo '<div class="notice notice-warning is-dismissible"><p>Keš obrisan.</p></div>';
}

// AI Titles settings (model + prompt; API ključ ide AJAX-om)
if ( isset( $_POST['olx_ai_titles_settings_submit'] ) && check_admin_referer( 'olx_ai_titles_settings_action', 'olx_ai_titles_settings_nonce' ) ) {
    update_option( 'drtechno_olx_gemini_model',  sanitize_text_field( $_POST['drtechno_olx_gemini_model'] ?? 'gemini-2.0-flash' ) );
    update_option( 'drtechno_olx_gemini_prompt', sanitize_textarea_field( $_POST['drtechno_olx_gemini_prompt'] ?? '' ) );
    echo '<div class="notice notice-success is-dismissible"><p>&#10003; Model i prompt sačuvani. Gemini API ključ se čuva posebno putem dugmeta.</p></div>';
}

// VIP CSV upload (Task 3)
if ( isset( $_POST['olx_vip_csv_upload'] ) && check_admin_referer( 'olx_vip_csv_upload_action', 'olx_vip_csv_upload_nonce' ) ) {
    if ( ! empty( $_FILES['olx_vip_csv']['tmp_name'] ) ) {
        $handle = fopen( $_FILES['olx_vip_csv']['tmp_name'], 'r' );
        fgetcsv( $handle ); // preskoči header red
        $diff = [ 'new' => [], 'updated' => [] ];
        while ( ( $row = fgetcsv( $handle ) ) !== false ) {
            if ( count( $row ) < 2 ) continue;
            $sku   = trim( $row[0] );
            $price = floatval( $row[1] );
            if ( empty( $sku ) || $price <= 0 ) continue;
            $products = get_posts( [
                'post_type'   => 'product',
                'post_status' => [ 'publish', 'draft', 'private' ],
                'posts_per_page' => 1,
                'fields'      => 'ids',
                'meta_query'  => [ [ 'key' => '_sku', 'value' => $sku ] ],
            ] );
            if ( empty( $products ) ) continue;
            $pid            = $products[0];
            $existing_price = get_post_meta( $pid, '_olx_special_price', true );
            $item           = [ 'pid' => $pid, 'sku' => $sku, 'price' => $price, 'name' => get_the_title( $pid ) ];
            if ( empty( $existing_price ) || floatval( $existing_price ) <= 0 ) {
                $diff['new'][] = $item;
            } else {
                $item['old_price'] = floatval( $existing_price );
                $diff['updated'][] = $item;
            }
        }
        fclose( $handle );
        set_transient( 'drtechno_olx_csv_diff', $diff, 5 * MINUTE_IN_SECONDS );
        echo '<div class="notice notice-info is-dismissible"><p>CSV obrađen: ' . intval( count( $diff['new'] ) ) . ' novih VIP, ' . intval( count( $diff['updated'] ) ) . ' ažuriranja. Pregledajte tabelu ispod i primijenite.</p></div>';
    }
}

if ( isset( $_POST['olx_vip_csv_apply'] ) && check_admin_referer( 'olx_vip_csv_apply_action', 'olx_vip_csv_apply_nonce' ) ) {
    $diff = get_transient( 'drtechno_olx_csv_diff' );
    if ( ! empty( $diff ) ) {
        $all_items = array_merge( $diff['new'] ?? [], $diff['updated'] ?? [] );
        global $wpdb;
        $table = $wpdb->prefix . 'drtechno_olx_prod_queue';
        $upload_dir = wp_upload_dir();
        foreach ( $all_items as $item ) {
            update_post_meta( $item['pid'], '_olx_special_price', $item['price'] );
            $cache_file = trailingslashit( $upload_dir['basedir'] ) . 'olx-frames/olx_frame_prod_' . intval( $item['pid'] ) . '.jpg';
            if ( file_exists( $cache_file ) ) {
                @unlink( $cache_file );
            }
            $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id) VALUES (%d)", $item['pid'] ) );
        }
        delete_transient( 'drtechno_olx_csv_diff' );
        echo '<div class="notice notice-success is-dismissible"><p>&#10003; Primijenjeno ' . intval( count( $all_items ) ) . ' VIP cijena. Artikli su dodani u red čekanja za sync.</p></div>';
    }
}

// --- Feature gating ---
$fm = class_exists( '\EbitOlx\Plugin' ) ? \EbitOlx\Plugin::getInstance()->getFeatureManager() : null;
$saved_features = get_option('drtechno_olx_license_features', []);

$can_desc_settings= $fm ? $fm->can( 'desc_settings' ) : !empty($saved_features['desc_settings']);
$can_mapping      = $fm ? $fm->can( 'mapping' ) : !empty($saved_features['mapping']);
$can_brands       = $fm ? $fm->can( 'brands' ) : !empty($saved_features['brands']);
$can_sync_mass    = $fm ? $fm->can( 'mass_sync' ) : !empty($saved_features['mass_sync']);
$can_default_attrs= $fm ? $fm->can( 'default_attrs' ) : !empty($saved_features['default_attrs']);

$can_price_rules  = $fm ? $fm->can( 'price_rules' ) : !empty($saved_features['price_rules']);
$can_vip          = $fm ? $fm->can( 'vip_articles' ) : !empty($saved_features['vip_articles']);
$can_sponsor      = $fm ? $fm->can( 'sponsor' ) : !empty($saved_features['sponsor']);
$can_mass_bump    = $fm ? $fm->can( 'mass_bump' ) : !empty($saved_features['mass_bump']);
$can_image_proc   = $fm ? $fm->can( 'image_processing' ) : !empty($saved_features['image_processing']);
$can_ai_titles    = $fm ? $fm->can( 'ai_titles' ) : !empty($saved_features['ai_titles']);
$locked_style     = 'opacity:0.5;';

// --- Čitanje podataka ---
$license_key = get_option('drtechno_olx_license_key', '');
$olx_connected = !empty(get_option('olx_shop_username'));
$active_tab = sanitize_text_field($_GET['tab'] ?? 'settings');
$saved_cat_mappings = get_option('drtechno_olx_category_mapping', []);
$saved_brand_mappings = get_option('drtechno_olx_brand_mapping', []);
$available_olx_brands = get_option('drtechno_olx_available_brands', []);
$countries_data = get_option('drtechno_olx_countries_data', []);
$cities_data = get_option('drtechno_olx_cities_data', []);
$saved_country_id = get_option('olx_country_id');
$saved_city_id = get_option('olx_city_id');
$instock_only = get_option('drtechno_olx_sync_instock_only') === 'yes';
$olx_shop_username = get_option('olx_shop_username');
$olx_account_type = get_option('olx_account_type');

// Kategorije sa servera (keširanje 1 sat)
$leaf_cats = $this->get_server_categories();
?>
<div class="wrap">
    <h1>EBIT OLX eXchange <small style="color:#888; font-size:12px;">(Cloud Edition)</small></h1>
    <h2 class="nav-tab-wrapper">
        <a href="?page=drtechno_olx_sync&tab=license" class="nav-tab <?php echo $active_tab == 'license' ? 'nav-tab-active' : ''; ?>" style="color:#2271b1; font-weight:bold;">&#128273; Licenca</a>
        <a href="?page=drtechno_olx_sync&tab=settings" class="nav-tab <?php echo $active_tab == 'settings' ? 'nav-tab-active' : ''; ?>">1. Postavke</a>
        <a href="?page=drtechno_olx_sync&tab=desc_settings" class="nav-tab <?php echo $active_tab == 'desc_settings' ? 'nav-tab-active' : ''; ?>" <?php echo $can_desc_settings ? '' : 'style="' . $locked_style . '"'; ?>>2. Postavke opisa<?php echo $can_desc_settings ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=mapping" class="nav-tab <?php echo $active_tab == 'mapping' ? 'nav-tab-active' : ''; ?>" <?php echo $can_mapping ? '' : 'style="' . $locked_style . '"'; ?>>3. Mapiranje kategorija<?php echo $can_mapping ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=brands" class="nav-tab <?php echo $active_tab == 'brands' ? 'nav-tab-active' : ''; ?>" <?php echo $can_brands ? '' : 'style="' . $locked_style . '"'; ?>>4. Meta Podaci<?php echo $can_brands ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=sync_mass" class="nav-tab <?php echo $active_tab == 'sync_mass' ? 'nav-tab-active' : ''; ?>" <?php echo $can_sync_mass ? '' : 'style="' . $locked_style . '"'; ?>>5. Masovni Sync<?php echo $can_sync_mass ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=default_attrs" class="nav-tab <?php echo $active_tab == 'default_attrs' ? 'nav-tab-active' : ''; ?>" <?php echo $can_default_attrs ? '' : 'style="' . $locked_style . '"'; ?>>6. Zadani Atributi<?php echo $can_default_attrs ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=price_rules" class="nav-tab <?php echo $active_tab == 'price_rules' ? 'nav-tab-active' : ''; ?>" <?php echo $can_price_rules ? '' : 'style="' . $locked_style . '"'; ?>>7. Pravila Cijena<?php echo $can_price_rules ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=special_prices" class="nav-tab <?php echo $active_tab == 'special_prices' ? 'nav-tab-active' : ''; ?>" <?php echo $can_vip ? '' : 'style="' . $locked_style . '"'; ?>>8. &#11088; VIP Artikli<?php echo $can_vip ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=sponsor" class="nav-tab <?php echo $active_tab == 'sponsor' ? 'nav-tab-active' : ''; ?>" <?php echo $can_sponsor ? '' : 'style="' . $locked_style . '"'; ?>>9. &#128640; Sponzoriranje<?php echo $can_sponsor ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=mass_bump" class="nav-tab <?php echo $active_tab == 'mass_bump' ? 'nav-tab-active' : ''; ?>" style="color:#0071a1;<?php echo $can_mass_bump ? '' : $locked_style; ?>">10. &#128640; Masovni BUMP<?php echo $can_mass_bump ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=image_settings" class="nav-tab <?php echo $active_tab == 'image_settings' ? 'nav-tab-active' : ''; ?>" <?php echo $can_image_proc ? '' : 'style="' . $locked_style . '"'; ?>>11. &#128444; Postavke Slika<?php echo $can_image_proc ? '' : ' &#128274;'; ?></a>
        <a href="?page=drtechno_olx_sync&tab=ai_titles" class="nav-tab <?php echo $active_tab == 'ai_titles' ? 'nav-tab-active' : ''; ?>" style="color:#7c3aed;<?php echo $can_ai_titles ? '' : $locked_style; ?>">12. &#10024; AI Nazivi<?php echo $can_ai_titles ? '' : ' &#128274;'; ?></a>
    </h2>

    <?php
    // ===================== TAB ROUTING =====================
    if ($active_tab == 'license'):
        // --- Licenca tab ---
        $license_data = $fm ? $fm->getLicenseData() : get_option('drtechno_olx_license_details', []);
        $server_url   = get_option('drtechno_olx_server_url', '');
        ?>
        <div class="card" style="max-width:800px; padding:20px; margin-top:20px; border-top:3px solid #2271b1;">
            <h3 style="margin-top:0; color:#2271b1;">&#128273; Licenca i Plan</h3>

            <?php if (!empty($license_data['valid'])): ?>
                <div style="padding:12px; background:#e5f9e7; border-left:4px solid #46b450; margin-bottom:15px;">
                    <strong>Status:</strong> Aktivna |
                    <strong>Plan:</strong> <?php echo esc_html(strtoupper($license_data['plan'] ?? 'N/A')); ?> |
                    <strong>Ističe:</strong> <?php echo esc_html($license_data['expires_at'] ?? 'N/A'); ?>
                </div>
                <?php
                $has_any_feature = !empty($license_data['features']) && count(array_filter($license_data['features'])) > 0;
                if (!$has_any_feature): ?>
                <div style="padding:10px; background:#fff8e1; border-left:4px solid #f0b429; margin-bottom:15px;">
                    <strong>&#9888; Upozorenje:</strong> Licenca je aktivna ali vaš plan nema dodijeljenih funkcionalnosti.
                    Tabovi 7-10 su zakljucani. Kontaktirajte administratora servera da ažurira vaš plan,
                    zatim kliknite <strong>Osvježi licencu</strong> ispod.
                </div>
                <?php endif; ?>
                <?php if (!empty($license_data['features'])): ?>
                <div style="margin-bottom:15px;">
                    <strong>Dostupne funkcionalnosti:</strong>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:8px;">
                        <?php
                        $feature_labels = ['mass_sync'=>'Masovni Sync','cleanup'=>'Čišćenje','sponsor'=>'Sponzoriranje','vip_articles'=>'VIP Artikli','mass_bump'=>'Masovni BUMP','image_processing'=>'Obrada Slika','price_rules'=>'Pravila Cijena','priority'=>'Prioritet'];
                        foreach ($feature_labels as $fkey => $flabel):
                            $has = !empty($license_data['features'][$fkey]);
                        ?>
                        <span style="padding:3px 10px; border-radius:12px; font-size:12px; background:<?php echo $has ? '#e5f9e7; color:#2a7a2a; border:1px solid #46b450' : '#f0f0f0; color:#888; border:1px solid #ccc'; ?>">
                            <?php echo $has ? '&#10003;' : '&#215;'; ?> <?php echo esc_html($flabel); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                <p style="color:#888; font-size:12px;">
                    <strong>Syncs:</strong> <?php echo intval($license_data['sync_count'] ?? 0); ?> |
                    <strong>Artikli:</strong> <?php echo intval($license_data['article_count'] ?? 0); ?> |
                    <strong>Dnevni:</strong> <?php echo intval($license_data['daily_sync_count'] ?? 0); ?>
                </p>
            <?php elseif (!empty($license_key)): ?>
                <div style="padding:12px; background:#fef1f1; border-left:4px solid #d63638; margin-bottom:15px;">
                    <strong>Licenca nije validna:</strong> <?php echo esc_html($license_data['reason'] ?? 'Provjera nije izvršena'); ?>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_attr(admin_url('admin.php?page=drtechno_olx_sync&tab=license')); ?>">
                <?php wp_nonce_field('olx_license_action', 'olx_license_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Licencni Ključ</th>
                        <td><input type="text" name="drtechno_olx_license_key" value="<?php echo esc_attr($license_key); ?>" class="regular-text" placeholder="EBITOLX-XXXX-XXXX-XXXX"></td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="olx_license_submit" class="button button-primary" value="Aktiviraj / Ažuriraj Licencu">
                </p>
            </form>

            <?php if ($fm && $fm->isLicenseValid() || !$fm): ?>
            <div style="margin-top:12px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                <button id="olx-refresh-license" class="button button-secondary">
                    &#8635; Osvježi licencu sa servera
                </button>
                <span id="olx-refresh-msg" style="display:none; font-size:13px;"></span>
            </div>
            <p style="margin-top:6px; font-size:11px; color:#888;">
                &#8505; Licenca se automatski keš-ira 1 sat. Koristite dugme iznad ako ste upravo ažurirali plan na serveru.
            </p>
            <script>
            document.getElementById('olx-refresh-license').addEventListener('click', function(e) {
                e.preventDefault();
                var btn = this;
                var msg = document.getElementById('olx-refresh-msg');
                btn.disabled = true;
                msg.style.display = 'inline';
                msg.style.color = '#555';
                msg.textContent = 'Osvježavam...';
                var body = new URLSearchParams();
                <?php if ($fm): ?>
                body.append('action', 'drtechno_validate_license');
                body.append('nonce', '<?php echo esc_js(wp_create_nonce('olx_sync_nonce')); ?>');
                <?php else: ?>
                body.append('action', 'drtechno_refresh_license_monolithic');
                body.append('nonce', '<?php echo esc_js(wp_create_nonce('olx_sync_nonce')); ?>');
                <?php endif; ?>
                fetch(ajaxurl, { method: 'POST', body: body })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d.success) {
                            msg.style.color = '#2a7a2a';
                            msg.textContent = '&#10003; Osvježeno! Ponovo se učitava...';
                            setTimeout(function() { location.reload(); }, 1000);
                        } else {
                            msg.style.color = '#d63638';
                            msg.textContent = '&#10007; ' + (d.data || 'Greška pri osvježavanju.');
                            btn.disabled = false;
                        }
                    })
                    .catch(function() {
                        msg.style.color = '#d63638';
                        msg.textContent = '&#10007; Greška mreže.';
                        btn.disabled = false;
                    });
            });
            </script>
            <?php endif; ?>
        </div>

    <?php elseif ($active_tab == 'price_rules'):
        if (!$can_price_rules): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Pravila Cijena - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje pravila cijena. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-price-rules.php'; endif;
    elseif ($active_tab == 'special_prices'):
        if (!$can_vip): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; VIP Artikli - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje VIP artikle. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-vip-articles.php'; endif;
    elseif ($active_tab == 'sponsor'):
        if (!$can_sponsor): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Sponzoriranje - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje sponzoriranje. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-sponsor.php'; endif;
    elseif ($active_tab == 'mass_bump'):
        if (!$can_mass_bump): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Masovni BUMP - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje masovni BUMP. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-mass-bump.php'; endif;
    elseif ($active_tab == 'image_settings'):
        if (!$can_image_proc): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Postavke Slika - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje postavke slika. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-image-settings.php'; endif;
    elseif ($active_tab == 'ai_titles'):
        if (!$can_ai_titles): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; AI Nazivi - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje AI generisanje naslova. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-ai-titles.php'; endif;
    elseif ($active_tab == 'default_attrs'):
        if (!$can_default_attrs): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Zadani Atributi - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje ovu funkcionalnost. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-default-attrs.php'; endif;

    elseif ($active_tab == 'settings'):
        include __DIR__ . '/tabs/tab-settings.php';

    elseif ($active_tab == 'desc_settings'):
        if (!$can_desc_settings): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Postavke Opisa - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje ovu funkcionalnost. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-desc-settings.php'; endif;

    elseif ($active_tab == 'mapping'):
        if (!$can_mapping): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Mapiranje Kategorija - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje ovu funkcionalnost. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-mapping.php'; endif;

    elseif ($active_tab == 'brands'):
        if (!$can_brands): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Meta Podaci - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje ovu funkcionalnost. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-brands.php'; endif;

    elseif ($active_tab == 'sync_mass'):
        if (!$can_sync_mass): ?>
            <div class="card" style="max-width:600px; padding:20px; margin-top:20px; border-top:3px solid #d63638;">
                <h3 style="margin-top:0; color:#d63638;">&#128274; Masovni Sync - Nadogradite Plan</h3>
                <p>Vaš trenutni plan ne uključuje Masovni Sync. <a href="?page=drtechno_olx_sync&tab=license">Nadogradite plan</a> za pristup.</p>
            </div>
        <?php else: include __DIR__ . '/tabs/tab-sync-mass.php'; endif;
    endif;
    ?>
    <div style="clear:both; margin-top:40px; padding-bottom:20px;"></div>
</div>
