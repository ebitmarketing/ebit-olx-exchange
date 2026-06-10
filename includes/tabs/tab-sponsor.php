<?php
/**
 * Tab: Sponzoriranje
 * Feature gate: sponsor
 * Dostupne varijable iz admin-page.php: $this
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['sponsor'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Sponzoriranje (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

// Form handling — zakazivanje sponzorstva
if (isset($_POST['olx_add_sponsor_submit']) && check_admin_referer('olx_add_sponsor_action', 'olx_add_sponsor_nonce')) {
    $pids = isset($_POST['sponsor_product_id']) ? (array) $_POST['sponsor_product_id'] : [];
    $success_count = 0;
    $failed_count = 0;
    $schedule_time = strtotime($_POST['sponsor_time']);
    if (!$schedule_time) $schedule_time = current_time('timestamp');

    $sponsor_params = [
        'type'          => intval($_POST['sponsor_type']),
        'days'          => intval($_POST['sponsor_days']),
        'refresh_every' => intval($_POST['sponsor_refresh']),
    ];
    if (!empty($_POST['sponsor_location'])) {
        $sponsor_params['locations'] = [sanitize_text_field($_POST['sponsor_location'])];
    }

    foreach ($pids as $pid) {
        $pid = intval($pid);
        $olx_id = get_post_meta($pid, '_olx_article_id', true);
        if ($pid > 0 && $olx_id) {
            update_post_meta($pid, '_olx_sponsor_params', $sponsor_params);
            update_post_meta($pid, '_olx_sponsor_time', $schedule_time);
            update_post_meta($pid, '_olx_sponsor_status', 'pending');
            delete_post_meta($pid, '_olx_sponsor_error');
            $success_count++;
        } else {
            $failed_count++;
        }
    }
    if ($success_count > 0) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Sponzorstvo uspješno zakazano za ' . $success_count . ' artikala!</strong>';
        if ($failed_count > 0) echo ' (Preskočeno ' . $failed_count . ' jer nisu na OLX-u)';
        echo '</p></div>';
    }
}

// Uklanjanje sponzorstva
if (isset($_GET['remove_sponsor']) && isset($_GET['tab']) && $_GET['tab'] === 'sponsor' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $pid = intval($_GET['remove_sponsor']);
    delete_post_meta($pid, '_olx_sponsor_params');
    delete_post_meta($pid, '_olx_sponsor_time');
    delete_post_meta($pid, '_olx_sponsor_status');
    delete_post_meta($pid, '_olx_sponsor_error');
    echo '<div class="notice notice-success is-dismissible"><p>Zakazano sponzorstvo je otkazano.</p></div>';
}

// Dohvati kredite putem servera
$available_credits = 0;
$credits_resp = $this->server_request('credits/balance');
if (!$credits_resp['error'] && isset($credits_resp['data']['credits'])) {
    $available_credits = intval($credits_resp['data']['credits']);
}

// Dohvati aktivna/pending sponzorstva
$pending_query = new WP_Query([
    'post_type'      => 'product',
    'post_status'    => ['publish', 'draft', 'private'],
    'posts_per_page' => -1,
    'meta_query'     => [['key' => '_olx_sponsor_status', 'compare' => 'EXISTS']],
]);
?>

        <!-- Kredit info -->
        <div style="margin-top:20px;padding:15px;background:#e5f9e7;border-left:4px solid #46b450;">
            <h3 style="margin:0;">Raspoloživo OLX Kredita: <strong><?php echo number_format($available_credits, 0, ',', '.'); ?></strong></h3>
        </div>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">

            <!-- Forma za zakazivanje -->
            <div class="card" style="flex:1;min-width:350px;padding:20px;border-top:3px solid #ffb900;">
                <h3 style="margin-top:0;">Zakaži Masovno Sponzorisanje</h3>
                <form method="post" action="?page=drtechno_olx_sync&tab=sponsor">
                    <?php wp_nonce_field('olx_add_sponsor_action', 'olx_add_sponsor_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">1. Odaberi Artikle<br><small>(Max 50)</small></th>
                            <td>
                                <select name="sponsor_product_id[]" id="sponsor_product_id" class="olx-product-search-multi" multiple="multiple" style="width:100%;" required></select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">2. Vrsta sponzorisanja</th>
                            <td>
                                <select name="sponsor_type" id="sponsor_type" class="sponsor-calc" style="width:100%;">
                                    <option value="1">1 - Normalno sponzorisanje</option>
                                    <option value="2">2 - Premium sponzorisanje</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">3. Broj dana</th>
                            <td>
                                <select name="sponsor_days" id="sponsor_days" class="sponsor-calc" style="width:100%;">
                                    <option value="1">1 dan</option>
                                    <option value="2">2 dana</option>
                                    <option value="3">3 dana</option>
                                    <option value="5">5 dana</option>
                                    <option value="7">7 dana</option>
                                    <option value="14">14 dana</option>
                                    <option value="21">21 dan</option>
                                    <option value="30">30 dana</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">4. Auto-Obnova (OLX)</th>
                            <td>
                                <select name="sponsor_refresh" id="sponsor_refresh" class="sponsor-calc" style="width:100%;">
                                    <option value="0">Bez automatske obnove</option>
                                    <option value="24">Svaka 24 sata</option>
                                    <option value="8">Svakih 8 sati</option>
                                    <option value="6">Svakih 6 sati</option>
                                    <option value="3">Svaka 3 sata</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">5. Lokacija prikaza</th>
                            <td>
                                <select name="sponsor_location" id="sponsor_location" class="sponsor-calc" style="width:100%;">
                                    <option value="">Standardna lokacija</option>
                                    <option value="homepage">Naslovnica (Homepage)</option>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">6. Vrijeme aktivacije</th>
                            <td>
                                <input type="datetime-local" name="sponsor_time" id="sponsor_time" style="width:100%;" required value="<?php echo date('Y-m-d\TH:i'); ?>" />
                            </td>
                        </tr>
                    </table>

                    <!-- Kalkulacija cijene -->
                    <div style="background:#f0f0f1;padding:15px;margin-top:15px;text-align:center;border-radius:5px;">
                        Cijena ove promocije: <strong style="font-size:20px;color:#d63638;" id="sponsor_live_price">-</strong>
                        <div id="sponsor_details" style="font-size:12px;color:#666;margin-top:5px;">Odaberite artikle za kalkulaciju.</div>
                        <div id="sponsor_credit_warning" style="color:#d63638;display:none;margin-top:5px;font-weight:bold;">Nemate dovoljno kredita!</div>
                    </div>

                    <p class="submit" style="margin-bottom:0;">
                        <input type="submit" id="btn_save_sponsor" name="olx_add_sponsor_submit" class="button button-primary button-large" value="Zakaži Promociju" disabled />
                    </p>
                </form>
            </div>

            <!-- Lista sponzorisanja -->
            <div class="card" style="flex:2;min-width:400px;padding:20px;background:#fff;">
                <h3 style="margin-top:0;">Lista Sponzorisanja</h3>
                <?php if ($pending_query->post_count == 0): ?>
                    <p style="color:#888;">Nema zakazanih ni aktivnih promocija.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Artikal (OLX ID)</th><th>Postavke</th><th>Vrijeme</th><th>Status</th><th>Akcija</th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pending_query->posts as $p):
                                $olx_id = get_post_meta($p->ID, '_olx_article_id', true) ?: '-';
                                $status = get_post_meta($p->ID, '_olx_sponsor_status', true);
                                $time   = get_post_meta($p->ID, '_olx_sponsor_time', true);
                                $params = get_post_meta($p->ID, '_olx_sponsor_params', true);
                                $err    = get_post_meta($p->ID, '_olx_sponsor_error', true);

                                $status_html = '';
                                if ($status == 'pending')
                                    $status_html = '<span style="color:#ffb900;font-weight:bold;">Na čekanju</span>';
                                elseif ($status == 'active')
                                    $status_html = '<span style="color:green;font-weight:bold;">Aktivirano</span>';
                                else
                                    $status_html = '<span style="color:#d63638;font-weight:bold;" title="' . esc_attr($err) . '">Greška (Hover)</span>';

                                $params_text = '';
                                if (is_array($params)) {
                                    $type_text = ($params['type'] == 2) ? 'Premium' : 'Normal';
                                    $params_text = $type_text . ' | ' . $params['days'] . ' dana';
                                    if ($params['refresh_every'] > 0)
                                        $params_text .= ' | Auto: ' . $params['refresh_every'] . 'h';
                                    if (isset($params['locations']) && in_array('homepage', $params['locations']))
                                        $params_text .= ' | Naslovnica';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><a href="<?php echo admin_url('post.php?post=' . $p->ID . '&action=edit'); ?>"><?php echo esc_html($p->post_title); ?></a></strong><br>
                                    <small>OLX ID: <?php echo esc_html($olx_id); ?></small>
                                </td>
                                <td><small><?php echo esc_html($params_text); ?></small></td>
                                <td><?php echo $time ? date('d.m.Y H:i', $time) : '-'; ?></td>
                                <td><?php echo $status_html; ?></td>
                                <td style="text-align:right;">
                                    <a href="?page=drtechno_olx_sync&tab=sponsor&remove_sponsor=<?php echo $p->ID; ?>" class="button button-small" onclick="return confirm('Obriši zapis?')">Obriši</a>
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
            var availableCredits = <?php echo intval($available_credits); ?>;

            // Multi-select za proizvode
            $('.olx-product-search-multi').select2({
                multiple: true,
                maximumSelectionLength: 50,
                width: '100%',
                placeholder: 'Pronađite jedan ili više artikala...',
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

            // Kalkulacija cijene sponzorstva via server
            function calculateSponsorPrice() {
                var pids = $('#sponsor_product_id').val();
                if (!pids || pids.length === 0) {
                    $('#sponsor_live_price').text('-');
                    $('#sponsor_details').text('Odaberite artikle za kalkulaciju.');
                    $('#btn_save_sponsor').prop('disabled', true);
                    $('#sponsor_credit_warning').hide();
                    return;
                }

                $('#sponsor_live_price').text('Računam...');
                $('#sponsor_details').text('Računam na osnovu ' + pids.length + ' odabranih artikala...');
                $('#btn_save_sponsor').prop('disabled', true);
                $('#sponsor_credit_warning').hide();

                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_calc_sponsor_price',
                    nonce: olx_sync_vars.nonce,
                    post_id: pids[0],
                    count: pids.length,
                    type: $('#sponsor_type').val(),
                    days: $('#sponsor_days').val(),
                    refresh_every: $('#sponsor_refresh').val(),
                    locations: $('#sponsor_location').val()
                }, function(res) {
                    if (res.success) {
                        var totalCost = parseInt(res.data.total);
                        $('#sponsor_live_price').text(totalCost + ' kredita');
                        $('#sponsor_details').text('Plaćate OLX-u: ' + pids.length + ' art. x ' + res.data.single + ' kredita');

                        if (totalCost > availableCredits) {
                            $('#sponsor_credit_warning').show();
                            $('#btn_save_sponsor').prop('disabled', true);
                        } else {
                            $('#btn_save_sponsor').prop('disabled', false);
                        }
                    } else {
                        $('#sponsor_live_price').html('<span style="font-size:14px;color:red;">Greška API-ja</span>');
                    }
                }).fail(function() {
                    $('#sponsor_live_price').html('<span style="font-size:14px;color:red;">Greška mreže</span>');
                });
            }

            $('.sponsor-calc').on('change', function() { calculateSponsorPrice(); });
            $('#sponsor_product_id').on('change', function() { calculateSponsorPrice(); });
        });
        </script>
