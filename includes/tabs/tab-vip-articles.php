<?php
/**
 * Tab: VIP Artikli (Specijalne cijene)
 * Feature gate: vip_articles
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['vip_articles'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">VIP Artikli (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

global $wpdb;

// Form handling — dodavanje VIP artikla
if (isset($_POST['olx_add_special_submit']) && check_admin_referer('olx_add_special_action', 'olx_add_special_nonce')) {
    $pid = intval($_POST['special_product_id']);
    $sp = floatval($_POST['special_price_val']);
    if ($pid > 0 && $sp > 0) {
        update_post_meta($pid, '_olx_special_price', $sp);
        $wpdb->query($wpdb->prepare(
            "INSERT IGNORE INTO {$wpdb->prefix}drtechno_olx_prod_queue (post_id) VALUES (%d)",
            $pid
        ));
        echo '<div class="notice notice-success is-dismissible"><p>VIP artikal dodan!</p></div>';
    }
}

// Uklanjanje VIP artikla
if (isset($_GET['remove_special']) && isset($_GET['tab']) && $_GET['tab'] === 'special_prices' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pid = intval($_GET['remove_special']);
    delete_post_meta($pid, '_olx_special_price');
    $wpdb->query($wpdb->prepare(
        "INSERT IGNORE INTO {$wpdb->prefix}drtechno_olx_prod_queue (post_id) VALUES (%d)",
        $pid
    ));
    
    // Obriši keširanu sliku da bi se izgenerisao novi bedž
    $upload_dir = wp_upload_dir();
    $cache_file = trailingslashit($upload_dir['basedir']) . 'olx-frames/olx_frame_prod_' . $pid . '.jpg';
    if (file_exists($cache_file)) {
        unlink($cache_file);
    }
    
    echo '<div class="notice notice-success is-dismissible"><p>Artikal uklonjen iz VIP evidencije.</p></div>';
}

$special_query = new WP_Query([
    'post_type'      => 'product',
    'post_status'    => ['publish', 'draft', 'private'],
    'posts_per_page' => -1,
    'meta_query'     => [['key' => '_olx_special_price', 'compare' => 'EXISTS']],
]);
?>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">

            <!-- Forma za dodavanje -->
            <div class="card" style="flex:1;min-width:350px;padding:20px;border-top:3px solid #ffb900;">
                <h3 style="margin-top:0;">Dodaj Artikal u VIP Evidenciju</h3>
                <form method="post" action="?page=drtechno_olx_sync&tab=special_prices">
                    <?php wp_nonce_field('olx_add_special_action', 'olx_add_special_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Pronađi Artikal</th>
                            <td><select name="special_product_id" class="olx-product-search" style="width:100%;" required></select></td>
                        </tr>
                        <tr>
                            <th scope="row">Specijalna OLX Cijena</th>
                            <td><input type="number" name="special_price_val" step="0.01" required style="width:150px;" placeholder="Iznos u KM" /></td>
                        </tr>
                    </table>
                    <p class="submit" style="margin-bottom:0;">
                        <input type="submit" name="olx_add_special_submit" class="button button-primary" value="Spasi i Dodaj u Evidenciju" />
                    </p>
                </form>
            </div>

            <!-- Lista VIP artikala -->
            <div class="card" style="flex:2;min-width:400px;padding:20px;background:#fff;">
                <div style="display:flex;justify-content:space-between;align-items:center;">
                    <h3 style="margin-top:0;">Lista VIP Artikala</h3>
                    <?php if ($special_query->post_count > 0): ?>
                        <button id="btn-bump-special" class="button button-primary button-large" style="background:#0071a1;">Masovni BUMP Specijalnih</button>
                    <?php endif; ?>
                </div>

                <div id="bump-special-log" style="margin-bottom:15px;padding:10px;background:#f0f0f1;max-height:100px;overflow-y:auto;font-family:monospace;display:none;"></div>

                <?php if ($special_query->post_count == 0): ?>
                    <p style="color:#888;">Trenutno nemate VIP artikala u evidenciji.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Artikal (Ime i SKU)</th>
                                <th>WC Cijena</th>
                                <th style="color:#d63638;">OLX VIP Cijena</th>
                                <th>OLX Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($special_query->posts as $p):
                                $prod = wc_get_product($p->ID);
                                if (!$prod) continue;
                                $sp_price   = get_post_meta($p->ID, '_olx_special_price', true);
                                $olx_id     = get_post_meta($p->ID, '_olx_article_id', true);
                                $olx_status = get_post_meta($p->ID, '_olx_status', true);
                                $status_text = '-';
                                if ($olx_id) {
                                    if ($olx_status == 'active')
                                        $status_text = '<span style="color:green;">Aktivan</span>';
                                    elseif ($olx_status == 'hidden')
                                        $status_text = '<span style="color:#ffb900;">Sakriven (0 kom)</span>';
                                    else
                                        $status_text = '<span style="color:#888;">Draft</span>';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($p->post_title); ?></strong><br>
                                    <small>SKU: <?php echo esc_html($prod->get_sku()); ?></small>
                                </td>
                                <td><?php echo $prod->get_price() ? wc_price($prod->get_price()) : '-'; ?></td>
                                <td style="font-weight:bold;color:#d63638;font-size:15px;"><?php echo esc_html($sp_price); ?> KM</td>
                                <td><?php echo $status_text; ?></td>
                                <td style="text-align:right;">
                                    <a href="?page=drtechno_olx_sync&tab=special_prices&remove_special=<?php echo $p->ID; ?>" class="button button-small" onclick="return confirm('Ukloniti artikal iz VIP evidencije?')">Ukloni</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div>

        <script>
        jQuery(document).ready(function($) {
            // Select2 za pretragu proizvoda
            $('.olx-product-search').select2({
                width: '100%',
                placeholder: 'Upišite naziv artikla za pretragu...',
                minimumInputLength: 3,
                ajax: {
                    url: olx_sync_vars.ajaxurl,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return { q: params.term, action: 'drtechno_search_products', nonce: olx_sync_vars.nonce };
                    },
                    processResults: function(data) {
                        return { results: data.results };
                    }
                }
            });

            // Masovni BUMP specijalnih
            function processSpecialBump() {
                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_bump_special_process',
                    nonce: olx_sync_vars.nonce
                }, function(res) {
                    $('#bump-special-log').prepend('<div>' + res.data.message + '</div>');
                    if (res.data.status === 'processing') {
                        setTimeout(processSpecialBump, 500);
                    } else {
                        $('#btn-bump-special').prop('disabled', false).text('Završeno');
                        setTimeout(function() { location.reload(); }, 2000);
                    }
                }).fail(function() {
                    setTimeout(processSpecialBump, 5000);
                });
            }

            $('#btn-bump-special').click(function(e) {
                e.preventDefault();
                if (!confirm('Želite li lansirati sve specijalne artikle na vrh OLX pretrage?')) return;
                $(this).prop('disabled', true).text('Priprema...');
                $('#bump-special-log').show().html('Pokrećem BUMP...');
                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_bump_special_start',
                    nonce: olx_sync_vars.nonce
                }, function(res) {
                    $('#bump-special-log').prepend('<div style="color:blue;font-weight:bold;">' + res.data + '</div>');
                    processSpecialBump();
                });
            });
        });
        </script>

        <!-- VIP CSV Import -->
        <div class="card" style="max-width:900px;padding:20px;margin-top:20px;border-top:3px solid #2271b1;">
            <h3 style="margin-top:0;color:#2271b1;">&#128196; Masovni VIP Import (CSV)</h3>
            <p style="color:#666;">
                Uploadujte CSV fajl sa kolonama: <code>SKU</code>, <code>Cijena</code>.<br>
                <strong>⚠️ CSV mora imati header red</strong> (npr. <code>SKU,Cijena</code>) — prvi red se uvijek preskače!<br>
                Primjer:
                <code style="display:block;background:#fff;padding:8px;margin-top:6px;border:1px solid #ddd;">SKU,Cijena<br>SKU123,49.99<br>SKU456,99.00</code>
                <strong>Zeleno</strong> = novi VIP artikal, <strong>Žuto</strong> = ažuriranje postojeće VIP cijene.
            </p>

            <form method="post" enctype="multipart/form-data" action="?page=drtechno_olx_sync&tab=special_prices">
                <?php wp_nonce_field('olx_vip_csv_upload_action', 'olx_vip_csv_upload_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th>CSV Fajl</th>
                        <td><input type="file" name="olx_vip_csv" accept=".csv" required></td>
                    </tr>
                </table>
                <p><input type="submit" name="olx_vip_csv_upload" class="button button-secondary" value="Analiziraj CSV"></p>
            </form>

            <?php
            $diff = get_transient('drtechno_olx_csv_diff');
            if (!empty($diff['new']) || !empty($diff['updated'])):
            ?>
            <div style="margin-top:20px;">
                <h4>Pregled promjena</h4>
                <table class="wp-list-table widefat fixed striped" style="max-width:700px;">
                    <thead><tr><th>SKU</th><th>Artikal</th><th>Stara cijena</th><th>Nova cijena</th><th>Akcija</th></tr></thead>
                    <tbody>
                    <?php foreach (($diff['new'] ?? []) as $item): ?>
                        <tr style="background:#e5f9e7;">
                            <td><?php echo esc_html($item['sku']); ?></td>
                            <td><?php echo esc_html($item['name']); ?></td>
                            <td style="color:#888;">—</td>
                            <td><strong><?php echo esc_html(number_format($item['price'], 2)); ?> KM</strong></td>
                            <td><span style="color:green;font-weight:bold;">NOVI VIP</span></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php foreach (($diff['updated'] ?? []) as $item): ?>
                        <tr style="background:#fff8e1;">
                            <td><?php echo esc_html($item['sku']); ?></td>
                            <td><?php echo esc_html($item['name']); ?></td>
                            <td style="color:#888;text-decoration:line-through;"><?php echo esc_html(number_format($item['old_price'] ?? 0, 2)); ?> KM</td>
                            <td><strong><?php echo esc_html(number_format($item['price'], 2)); ?> KM</strong></td>
                            <td><span style="color:#b07a00;font-weight:bold;">AŽURIRANO</span></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <form method="post" action="?page=drtechno_olx_sync&tab=special_prices" style="margin-top:15px;">
                    <?php wp_nonce_field('olx_vip_csv_apply_action', 'olx_vip_csv_apply_nonce'); ?>
                    <input type="submit" name="olx_vip_csv_apply" class="button button-primary" value="&#10003; Primijeni sve promjene (<?php echo intval(count($diff['new']) + count($diff['updated'])); ?> artikala)">
                    <span style="color:#888;font-size:12px;margin-left:10px;">Sačuvat će se <?php echo intval(count($diff['new']) + count($diff['updated'])); ?> VIP cijena.</span>
                </form>
            </div>
            <?php endif; ?>
        </div>
