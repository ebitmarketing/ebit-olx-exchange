<?php
/**
 * Tab 1: Postavke (Server URL, Licenca, OLX Login, Lokacija)
 * Dostupne varijable iz admin-page.php: $license_key, $olx_connected, $countries_data, $cities_data,
 *   $saved_country_id, $saved_city_id, $olx_shop_username, $olx_account_type, $this
 */
if (!defined('ABSPATH'))
    exit;

$server_status = get_option('drtechno_olx_server_status', '');
$server_time = get_option('drtechno_olx_server_time', '');
$lic_details = get_option('drtechno_olx_license_details', []);
?>
        <?php if (!empty($license_key)): ?>
        <!-- Pregled / Klijent Dashboard -->
        <div class="card" style="max-width:1200px;padding:20px;margin-top:20px;border-top:3px solid #2271b1;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:15px;flex-wrap:wrap;gap:10px;">
                <h3 style="margin:0;">📊 Pregled — Klijent Dashboard</h3>
                <button type="button" id="btn-dashboard-refresh" class="button button-secondary">🔄 Osvježi</button>
            </div>
            <div id="dashboard-stats">
                <p style="color:#666;">Učitavanje pregleda… <span class="spinner is-active" style="float:none;"></span></p>
            </div>

            <template id="dashboard-tpl">
                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:15px;">
                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #2271b1;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">📜 Licenca</h4>
                        <p style="margin:4px 0;"><strong>Plan:</strong> <span data-f="plan_name"></span></p>
                        <p style="margin:4px 0;"><strong>Status:</strong> <span data-f="status_badge"></span></p>
                        <p style="margin:4px 0;"><strong>Ističe:</strong> <span data-f="expires_at"></span></p>
                        <p style="margin:4px 0;"><strong>Preostalo:</strong> <span data-f="days_left"></span> dana</p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #00a32a;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">📅 Mjesečna kvota</h4>
                        <p style="margin:4px 0;"><strong>Iskorišteno:</strong> <span data-f="monthly_used"></span> / <span data-f="monthly_max"></span></p>
                        <div style="background:#ddd;height:10px;border-radius:5px;overflow:hidden;margin-top:6px;">
                            <div data-f="monthly_bar" style="height:100%;background:#00a32a;width:0%;transition:width .3s;"></div>
                        </div>
                        <p style="margin:8px 0 4px;"><strong>Reset:</strong> <span data-f="monthly_reset"></span></p>
                        <p style="font-size:11px;color:#666;margin:0;" data-f="quota_type_note"></p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #dba617;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">⏱ Dnevna kvota</h4>
                        <p style="margin:4px 0;"><strong>Iskorišteno:</strong> <span data-f="daily_used"></span> / <span data-f="daily_max"></span></p>
                        <div style="background:#ddd;height:10px;border-radius:5px;overflow:hidden;margin-top:6px;">
                            <div data-f="daily_bar" style="height:100%;background:#dba617;width:0%;transition:width .3s;"></div>
                        </div>
                        <p style="margin:8px 0 4px;"><strong>Reset:</strong> <span data-f="daily_reset"></span></p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #d63638;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">🛒 OLX nalog</h4>
                        <p style="margin:4px 0;"><strong>Shop:</strong> <span data-f="shop"></span></p>
                        <p style="margin:4px 0;"><strong>Aktivni listinzi:</strong> <span data-f="listing_count"></span></p>
                        <p style="margin:4px 0;"><strong>OLX kredita:</strong> <span data-f="credits"></span></p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #8c8f94;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">⬆️ BUMP slotovi</h4>
                        <p style="margin:4px 0;"><strong>Besplatni preostali:</strong> <span data-f="bump_free"></span> / <span data-f="bump_free_total"></span></p>
                        <p style="margin:4px 0;"><strong>Plaćeni danas:</strong> <span data-f="bump_paid"></span></p>
                        <p style="font-size:11px;color:#666;margin:8px 0 0;">Reset: ponoć (server time)</p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #6c2eb9;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">📦 OLX limiti po kategoriji</h4>
                        <div data-f="cat_limits"></div>
                        <p style="font-size:11px;color:#666;margin:8px 0 0;">Limit 0 = bez ograničenja</p>
                    </div>

                    <div class="dash-card" style="padding:15px;background:#f6f7f7;border-left:4px solid #00a0d2;border-radius:4px;">
                        <h4 style="margin:0 0 10px;">🗄 WordPress brojači</h4>
                        <p style="margin:4px 0;"><strong>Sinhronizovano:</strong> <span data-f="local_synced"></span></p>
                        <p style="margin:4px 0;"><strong>Sa greškom:</strong> <span data-f="local_error"></span></p>
                        <p style="margin:4px 0;"><strong>Sakriveno:</strong> <span data-f="local_hidden"></span></p>
                        <p style="margin:4px 0;"><strong>U redu (queue):</strong> <span data-f="local_queue"></span></p>
                    </div>
                </div>
            </template>

            <script>
            jQuery(function($){
                function fmtDays(expires) {
                    if (!expires) return '∞';
                    var t = new Date(expires.replace(' ', 'T') + 'Z').getTime() - Date.now();
                    return Math.max(0, Math.floor(t / 86400000));
                }
                function fmtCountdownToMidnight(serverTime) {
                    var st = serverTime ? new Date(serverTime.replace(' ', 'T') + 'Z') : new Date();
                    var midnight = new Date(st);
                    midnight.setUTCHours(24, 0, 0, 0);
                    var diff = midnight - st;
                    if (diff <= 0) return '0h 0m';
                    var h = Math.floor(diff / 3600000);
                    var m = Math.floor((diff % 3600000) / 60000);
                    return h + 'h ' + m + 'm';
                }
                function fmtMonthlyReset(resetAt) {
                    if (!resetAt) return '—';
                    var d = new Date(resetAt.replace(' ', 'T') + 'Z');
                    d.setUTCMonth(d.getUTCMonth() + 1);
                    var days = Math.max(0, Math.floor((d - Date.now()) / 86400000));
                    return days + ' dana';
                }
                function statusBadge(status, daysLeft) {
                    var s = (status || '').toLowerCase();
                    if (s === 'expired')   return '<span style="color:#d63638;font-weight:bold;">⛔ Isteklo</span>';
                    if (s === 'suspended') return '<span style="color:#d63638;font-weight:bold;">🚫 Suspendovano</span>';
                    if (daysLeft <= 7)     return '<span style="color:#dba617;font-weight:bold;">⚠️ Aktivno (ističe uskoro)</span>';
                    return '<span style="color:#00a32a;font-weight:bold;">✅ Aktivno</span>';
                }
                function quotaTypeNote(qt) {
                    return qt === 'articles'
                        ? '* Broji se samo NOVI artikli'
                        : '* Broji se svaki sync poziv';
                }
                function fmtNum(n) {
                    if (n == null) return '—';
                    try { return new Intl.NumberFormat('bs-BA').format(n); } catch(e) { return String(n); }
                }

                function render(d) {
                    var lic   = d.license || {};
                    var olx   = d.olx     || {};
                    var local = d.local   || {};
                    var $tpl  = $($('#dashboard-tpl').html());

                    var daysLeft    = fmtDays(lic.expires_at);
                    var monthlyUsed = lic.quota_type === 'articles' ? (lic.article_count || 0) : (lic.sync_count || 0);
                    var monthlyMax  = lic.max_products || 0;
                    var monthlyPct  = monthlyMax > 0 ? Math.min(100, (monthlyUsed / monthlyMax) * 100) : 0;
                    var dailyUsed   = lic.daily_sync_count || 0;
                    var dailyMax    = lic.max_daily_syncs || 0;
                    var dailyPct    = dailyMax > 0 ? Math.min(100, (dailyUsed / dailyMax) * 100) : 0;

                    $tpl.find('[data-f="plan_name"]').text((lic.plan || '—').toString().toUpperCase());
                    $tpl.find('[data-f="status_badge"]').html(statusBadge(lic.status, daysLeft));
                    $tpl.find('[data-f="expires_at"]').text(lic.expires_at ? lic.expires_at.split(' ')[0] : '—');
                    $tpl.find('[data-f="days_left"]').text(daysLeft);

                    $tpl.find('[data-f="monthly_used"]').text(fmtNum(monthlyUsed));
                    $tpl.find('[data-f="monthly_max"]').text(monthlyMax > 0 ? fmtNum(monthlyMax) : '∞');
                    $tpl.find('[data-f="monthly_bar"]').css({width: monthlyPct + '%', background: monthlyPct >= 100 ? '#d63638' : '#00a32a'});
                    $tpl.find('[data-f="monthly_reset"]').text(fmtMonthlyReset(lic.sync_count_reset_at));
                    $tpl.find('[data-f="quota_type_note"]').text(quotaTypeNote(lic.quota_type));

                    $tpl.find('[data-f="daily_used"]').text(fmtNum(dailyUsed));
                    $tpl.find('[data-f="daily_max"]').text(dailyMax > 0 ? fmtNum(dailyMax) : '∞');
                    $tpl.find('[data-f="daily_bar"]').css({width: dailyPct + '%', background: dailyPct >= 100 ? '#d63638' : '#dba617'});
                    $tpl.find('[data-f="daily_reset"]').text(dailyMax > 0 ? fmtCountdownToMidnight(lic.server_time) : 'Bez limita');

                    $tpl.find('[data-f="shop"]').text(lic.shop_username || '—');
                    $tpl.find('[data-f="listing_count"]').text(olx.listing_count != null ? fmtNum(olx.listing_count) : '—');
                    $tpl.find('[data-f="credits"]').text(olx.credits != null ? fmtNum(olx.credits) : '—');

                    $tpl.find('[data-f="bump_free"]').text(Math.max(0, (olx.free_limit || 0) - (olx.free_count || 0)));
                    $tpl.find('[data-f="bump_free_total"]').text(olx.free_limit || 0);
                    $tpl.find('[data-f="bump_paid"]').text(olx.paid_count || 0);

                    var catLabels = { 'cars': '🚗 Auti', 'real-estate': '🏠 Nekretnine', 'other': '📦 Ostalo' };
                    var $cat = $tpl.find('[data-f="cat_limits"]');
                    var cats = olx.cat_limits || {};
                    if (Object.keys(cats).length === 0) {
                        $cat.html('<p style="color:#888;margin:0;">—</p>');
                    } else {
                        $cat.empty();
                        $.each(cats, function(key, row) {
                            var label = catLabels[key] || key;
                            var lim   = row.limit || 0;
                            var used  = row.listings || 0;
                            var pct   = lim > 0 ? Math.min(100, (used / lim) * 100) : 0;
                            var color = pct >= 100 ? '#d63638' : '#6c2eb9';
                            var maxTxt = lim > 0 ? fmtNum(lim) : '∞';
                            var bar = lim > 0
                                ? '<div style="background:#ddd;height:6px;border-radius:3px;overflow:hidden;margin:3px 0 6px;"><div style="height:100%;background:' + color + ';width:' + pct + '%;"></div></div>'
                                : '';
                            $cat.append(
                                '<p style="margin:4px 0;"><strong>' + label + ':</strong> ' + fmtNum(used) + ' / ' + maxTxt + '</p>' + bar
                            );
                        });
                    }

                    $tpl.find('[data-f="local_synced"]').text(fmtNum(local.synced || 0));
                    $tpl.find('[data-f="local_error"]').text(fmtNum(local.error || 0));
                    $tpl.find('[data-f="local_hidden"]').text(fmtNum(local.hidden || 0));
                    $tpl.find('[data-f="local_queue"]').text(fmtNum(local.queue || 0));

                    $('#dashboard-stats').empty().append($tpl);
                }

                function load(force) {
                    var $btn = $('#btn-dashboard-refresh');
                    $btn.prop('disabled', true);
                    $('#dashboard-stats').html('<p style="color:#666;">Učitavanje… <span class="spinner is-active" style="float:none;"></span></p>');
                    $.post(olx_sync_vars.ajaxurl, {
                        action: 'drtechno_dashboard_stats',
                        nonce:  olx_sync_vars.nonce,
                        force:  force ? 1 : 0
                    }, function(r){
                        if (r.success) render(r.data);
                        else $('#dashboard-stats').html('<p style="color:#d63638;">Greška: ' + (r.data || 'Nepoznata greška') + '</p>');
                    }).fail(function(){
                        $('#dashboard-stats').html('<p style="color:#d63638;">Greška mreže.</p>');
                    }).always(function(){
                        setTimeout(function(){ $btn.prop('disabled', false); }, 2000);
                    });
                }

                $('#btn-dashboard-refresh').on('click', function(){ load(true); });
                load(false);
            });
            </script>
        </div>
        <?php endif; ?>

        <!-- Server URL -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #00a32a;">
            <h3>🌐 Server URL</h3>
            <form method="post"><?php wp_nonce_field('olx_server_url_action', 'olx_server_url_nonce'); ?>
                <table class="form-table"><tr><th>API Endpoint</th><td><input type="url" name="drtechno_olx_server_url" value="<?php echo esc_attr(get_option('drtechno_olx_server_url', '')); ?>" class="regular-text" placeholder="https://vas-server.com/api/index.php" required style="width:100%;max-width:500px;" /></td></tr></table>
                <?php if (!empty(get_option('drtechno_olx_server_url', ''))): ?>
                <div style="margin:10px 0 5px;padding:10px 14px;border-radius:6px;<?php
    if ($server_status === 'online')
        echo 'background:#e5f9e7;border:1px solid #46b450;';
    elseif ($server_status === 'offline' || $server_status === 'error')
        echo 'background:#fcf0f1;border:1px solid #d63638;';
    else
        echo 'background:#f0f6fc;border:1px solid #c3c4c7;';
?>">
                    <?php if ($server_status === 'online'): ?>
                        <span style="color:#00a32a;font-weight:bold;">✅ Server dostupan</span>
                        <?php if ($server_time): ?><span style="color:#666;margin-left:10px;">Vrijeme servera: <?php echo esc_html($server_time); ?></span><?php
        endif; ?>
                    <?php
    elseif ($server_status === 'offline'): ?>
                        <span style="color:#d63638;font-weight:bold;">❌ Server nedostupan</span>
                    <?php
    elseif ($server_status === 'error'): ?>
                        <span style="color:#dba617;font-weight:bold;">⚠️ Server je vratio grešku</span>
                    <?php
    else: ?>
                        <span style="color:#666;">ℹ️ Kliknite "Sačuvaj" da provjerite status servera</span>
                    <?php
    endif; ?>
                </div>
                <?php
endif; ?>
                <p class="submit" style="margin-bottom:0;">
                    <input type="submit" name="olx_server_url_submit" class="button button-primary" value="Sačuvaj" />
                    <button type="button" id="btn-test-connection" class="button button-secondary" style="margin-left:8px;">Testiraj konekciju</button>
                    <span id="test-conn-result" style="margin-left:10px;"></span>
                </p>
            </form>
            <script>
            jQuery(document).ready(function($) {
                $('#btn-test-connection').click(function() {
                    var $btn = $(this);
                    var $res = $('#test-conn-result');
                    $btn.prop('disabled', true).text('...');
                    $res.text('');
                    $.post(olx_sync_vars.ajaxurl, {
                        action: 'drtechno_test_connection',
                        nonce: olx_sync_vars.nonce
                    }, function(r) {
                        if (r.success) {
                            var time = (r.data && r.data.time) ? r.data.time : '';
                            $res.html('<span style="color:#00a32a;font-weight:bold;">&#10003; Povezano' + (time ? ' (' + time + ')' : '') + '</span>');
                        } else {
                            $res.html('<span style="color:#d63638;font-weight:bold;">&#10007; ' + (r.data || 'Greška') + '</span>');
                        }
                    }).fail(function() {
                        $res.html('<span style="color:#d63638;font-weight:bold;">&#10007; Greška pri slanju zahtjeva</span>');
                    }).always(function() {
                        $btn.prop('disabled', false).text('Testiraj konekciju');
                    });
                });
            });
            </script>
        </div>



        <?php if (!empty($license_key)): ?>
        <!-- OLX Login -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;">
            <h3>Prijava na OLX.ba</h3>
            <form method="post"><?php wp_nonce_field('olx_auth_action', 'olx_auth_nonce'); ?>
                <table class="form-table">
                    <tr><th>OLX Email</th><td><input type="text" name="olx_username" value="<?php echo esc_attr(get_option('olx_username')); ?>" class="regular-text" required /></td></tr>
                    <tr><th>OLX Password</th><td>
                        <input type="password" name="olx_password" value="" class="regular-text" required autocomplete="off" placeholder="Unesite lozinku za povezivanje" />
                        <br><small style="color:#666;">Lozinka se koristi samo jednom za povezivanje i NE čuva se lokalno. Ako token istekne, ponovo se prijavite.</small>
                    </td></tr>
                </table>
                <?php if ($olx_connected): ?><div class="notice notice-success inline" style="margin:20px 0;"><p><strong>Status:</strong> Povezano! | <strong>Tip:</strong> <?php echo esc_html($olx_account_type); ?> | <strong>Shop:</strong> <?php echo esc_html($olx_shop_username); ?></p></div><?php
    endif; ?>
                <p class="submit" style="margin-bottom:0;"><input type="submit" name="olx_login_submit" class="button button-primary" value="Poveži se" /></p>
            </form>
        </div>



        <!-- Lokacija -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;">
            <h3>Lokacija Shopa</h3>
            <?php if (empty($countries_data) || empty($cities_data)): ?>
                <button id="fetch-locations-btn" class="button button-secondary">Preuzmi lokacije</button><span class="spinner" id="loc-spinner" style="float:none;margin-left:10px;"></span>
                <script>jQuery(document).ready(function($){$('#fetch-locations-btn').click(function(e){e.preventDefault();$(this).prop('disabled',true);$('#loc-spinner').addClass('is-active');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_fetch_locations',nonce:olx_sync_vars.nonce},function(){location.reload();});});});</script>
            <?php
    else: ?>
                <form method="post"><?php wp_nonce_field('olx_location_action', 'olx_location_nonce'); ?>
                    <table class="form-table">
                        <tr><th>Država</th><td><select name="olx_country_id" class="olx-select2" style="width:100%;max-width:400px;" required><option value="">-- Odaberite --</option><?php foreach ($countries_data as $c): ?><option value="<?php echo esc_attr($c['id']); ?>" <?php selected($saved_country_id, $c['id']); ?>><?php echo esc_html($c['name']); ?></option><?php
        endforeach; ?></select></td></tr>
                        <tr><th>Grad</th><td><select name="olx_city_id" class="olx-select2" style="width:100%;max-width:400px;" required><option value="">-- Odaberite --</option><?php foreach ($cities_data as $e) {
            if (isset($e['cantons'])) {
                foreach ($e['cantons'] as $cn) {
                    echo "<optgroup label='" . esc_attr($e['name'] . ' - ' . $cn['name']) . "'>";
                    if (isset($cn['cities'])) {
                        foreach ($cn['cities'] as $ct) {
                            echo "<option value='" . esc_attr($ct['id']) . "' " . selected($saved_city_id, $ct['id'], false) . ">" . esc_html($ct['name']) . "</option>";
                        }
                    }
                    echo "</optgroup>";
                }
            }
        }?></select></td></tr>
                    </table>
                    <p class="submit" style="margin-bottom:0;"><input type="submit" name="olx_location_submit" class="button button-primary" value="Sačuvaj lokaciju" /></p>
                </form>
                <script>jQuery(document).ready(function($){$('.olx-select2').select2({width:'100%'});});</script>
            <?php
    endif; ?>
        </div>
        <?php
endif; ?>
