<?php
/**
 * Tab 5: Masovni Sync (Postavke sync-a, Automatizacija, Push/Cleanup)
 * Dostupne varijable iz admin-page.php: $instock_only, $this
 * Feature gate: mass_sync
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['mass_sync'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Masovni Sync (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

$allow_mass = get_option('drtechno_olx_feat_mass_sync', 0);
$allow_clean = ! empty( $features['cleanup'] );
global $wpdb;
$queue_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}drtechno_olx_prod_queue");
$next_run = wp_next_scheduled('drtechno_olx_batch_worker_event');
$pop_next = wp_next_scheduled('drtechno_olx_daily_populator_event');
?>

        <!-- ═══════════════════════════════════════════ -->
        <!-- SEKCIJA 1: POSTAVKE SYNC-a                 -->
        <!-- ═══════════════════════════════════════════ -->
        <div style="margin-top:20px;">
            <h2 style="margin:0 0 5px 0;font-size:16px;color:#1d2327;">⚙️ Postavke sinhronizacije</h2>
            <p style="color:#666;margin:0 0 12px 0;">Globalne opcije koje kontrolišu ponašanje sync-a za sve proizvode.</p>
            <div class="card" style="max-width:100%;padding:20px 24px;">
                <form method="post">
                    <?php wp_nonce_field('olx_stock_settings_action', 'olx_stock_settings_nonce'); ?>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th style="width:180px;padding:12px 10px 12px 0;">Kontrola zaliha</th>
                            <td style="padding:12px 0;">
                                <label><input type="checkbox" name="drtechno_olx_sync_instock_only" value="yes" <?php checked($instock_only); ?> />
                                Samo artikle na stanju
                                <br><small style="color:#666;">Ako je isključeno, šalju se i oni van zaliha.</small></label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="margin:0;padding:10px 0 0;"><input type="submit" name="olx_stock_settings_submit" class="button button-primary" value="Sačuvaj" /></p>
                </form>
                <hr style="margin:16px 0;border:0;border-top:1px solid #e0e0e0;">
                <form method="post">
                    <?php wp_nonce_field('olx_sync_features_action', 'olx_sync_features_nonce'); ?>
                    <table class="form-table" style="margin:0;">
                        <tr>
                            <th style="width:180px;padding:12px 10px 12px 0;">Sakri/Prikaži</th>
                            <td style="padding:12px 0;">
                                <label><input type="checkbox" name="drtechno_olx_enable_hide_unhide" value="yes" <?php checked(get_option('drtechno_olx_enable_hide_unhide'), 'yes'); ?> />
                                Sakri oglas umjesto brisanja kad nema zaliha
                                <br><small style="color:#666;">Proizvod se <strong>sakrije</strong> na OLX-u (čuva ID i poziciju). Kad se vrate zalihe — automatski se ponovo prikaže.</small></label>
                            </td>
                        </tr>
                        <tr>
                            <th style="width:180px;padding:12px 10px 12px 0;">Detekcija duplikata</th>
                            <td style="padding:12px 0;">
                                <label><input type="checkbox" name="drtechno_olx_enable_duplicate_check" value="yes" <?php checked(get_option('drtechno_olx_enable_duplicate_check'), 'yes'); ?> />
                                Provjeri da li oglas već postoji na OLX-u
                                <br><small style="color:#666;">Prije kreiranja novog, traži po naslovu. Ako postoji — ažurira umjesto duplikata.</small></label>
                            </td>
                        </tr>
                        <tr>
                            <th style="width:180px;padding:12px 10px 12px 0;">Ažuriranje slika</th>
                            <td style="padding:12px 0;">
                                <label><input type="checkbox" name="drtechno_olx_enable_image_update" value="yes" <?php checked(get_option('drtechno_olx_enable_image_update'), 'yes'); ?> />
                                Ažuriraj slike pri update-u oglasa
                                <br><small style="color:#666;">Kad se ažurira postojeći oglas, stare slike se brišu i nove se uploaduju. <strong>⚠️ Usporava sync</strong> jer zahtijeva dodatne API pozive.</small></label>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="margin:0;padding:10px 0 0;"><input type="submit" name="olx_sync_features_submit" class="button button-primary" value="Sačuvaj" /></p>
                </form>
            </div>
        </div>

        <!-- ═══════════════════════════════════════════ -->
        <!-- SEKCIJA 2: AUTOMATIZACIJA                  -->
        <!-- ═══════════════════════════════════════════ -->
        <div style="margin-top:30px;">
            <h2 style="margin:0 0 5px 0;font-size:16px;color:#1d2327;">🤖 Automatizacija</h2>
            <p style="color:#666;margin:0 0 12px 0;">Pozadinski procesi koji automatski sinhronizuju proizvode sa OLX-om.</p>

            <div style="background:#f0f6fc;padding:12px 16px;border-radius:6px;border:1px solid #c3c4c7;margin-bottom:15px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
                <span><strong>📊 Worker Queue:</strong> <strong style="color:#2271b1;"><?php echo intval($queue_count); ?></strong> proizvoda u redu</span>
                <?php if ($next_run): ?>
                    <span>⏱️ Sljedeći ciklus: <strong><?php echo date('H:i:s', $next_run + (get_option('gmt_offset') * 3600)); ?></strong></span>
                <?php
    endif; ?>
                <span style="color:#666;font-size:12px;">Batch: <?php echo esc_html(get_option('drtechno_olx_cron_batch_size', 10)); ?> art./ciklus (svake 2 min)</span>
            </div>
            <div style="display:flex;gap:20px;flex-wrap:wrap;">

                <!-- Auto-Sync -->
                <?php if ($allow_mass): ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #00a32a;margin-top:0;">
                    <h3 style="margin-top:0;">⚡ Auto-Sync pri Snimanju</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Kad sačuvate proizvod koji je već na OLX-u → automatski ide u queue → Worker ga obradi.</p>
                    <form method="post"><?php wp_nonce_field('olx_auto_sync_action', 'olx_auto_sync_nonce'); ?>
                        <label style="display:flex;align-items:start;gap:8px;margin-bottom:12px;">
                            <input type="checkbox" name="drtechno_olx_enable_auto_sync" value="yes" <?php checked(get_option('drtechno_olx_enable_auto_sync'), 'yes'); ?> style="margin-top:3px;" />
                            <span>Omogući automatski sync pri snimanju proizvoda</span>
                        </label>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <label style="font-weight:600;white-space:nowrap;">Batch:</label>
                            <input type="number" name="drtechno_olx_cron_batch_size" value="<?php echo esc_attr(get_option('drtechno_olx_cron_batch_size', 10)); ?>" min="1" max="50" style="width:70px;" />
                            <small style="color:#666;">art./ciklus</small>
                        </div>
                        <input type="submit" name="olx_auto_sync_submit" class="button button-primary" value="Sačuvaj" />
                    </form>
                </div>
                <?php
else: ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;background:#fcf0f1;border-left:4px solid #d63638;margin-top:0;opacity:0.85;">
                    <h3 style="margin-top:0;color:#d63638;">🔒 Auto-Sync (Zaključano)</h3>
                    <p>Vaša licenca ne uključuje ovu funkciju. <strong>Nadogradite plan.</strong></p>
                </div>
                <?php
endif; ?>

                <!-- Daily Populator -->
                <?php if ($allow_mass): ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #ff6b00;margin-top:0;">
                    <h3 style="margin-top:0;">📅 Dnevni Populator</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Jednom dnevno automatski ubacuje proizvode u queue. Možete birati: samo ažuriranje, samo nove, ili oboje.</p>
                    <form method="post"><?php wp_nonce_field('olx_daily_populator_action', 'olx_daily_populator_nonce'); ?>
                        <label style="display:flex;align-items:start;gap:8px;margin-bottom:12px;">
                            <input type="checkbox" name="drtechno_olx_enable_daily_populator" value="yes" <?php checked(get_option('drtechno_olx_enable_daily_populator'), 'yes'); ?> style="margin-top:3px;" />
                            <span>Omogući dnevni automatski sync</span>
                        </label>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <label style="font-weight:600;white-space:nowrap;">Način:</label>
                            <select name="drtechno_olx_daily_populator_mode" style="flex:1;padding:5px;">
                                <option value="update_only" <?php selected(get_option('drtechno_olx_daily_populator_mode', 'update_only'), 'update_only'); ?>>Samo ažuriraj postojeće</option>
                                <option value="all" <?php selected(get_option('drtechno_olx_daily_populator_mode', 'update_only'), 'all'); ?>>Ažuriraj + kreiraj nove</option>
                                <option value="new_only" <?php selected(get_option('drtechno_olx_daily_populator_mode', 'update_only'), 'new_only'); ?>>Samo kreiraj nove</option>
                            </select>
                        </div>
                        <div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
                            <label style="font-weight:600;white-space:nowrap;">Vrijeme:</label>
                            <?php
                            $pop_site_minute  = abs( crc32( get_site_url() ) ) % 60;
                            $pop_site_default = sprintf( '02:%02d', $pop_site_minute );
                            ?>
                            <input type="time" name="drtechno_olx_daily_populator_time" value="<?php echo esc_attr(get_option('drtechno_olx_daily_populator_time', $pop_site_default)); ?>" />
                            <?php if ($pop_next): ?>
                                <small style="color:#00a32a;">✓ Zakazano: <?php echo date('d.m. H:i', $pop_next + (get_option('gmt_offset') * 3600)); ?></small>
                            <?php
    elseif (get_option('drtechno_olx_enable_daily_populator') === 'yes'): ?>
                                <small style="color:#d63638;">⚠️ Nije zakazano</small>
                            <?php
    endif; ?>
                        </div>
                        <input type="submit" name="olx_daily_populator_submit" class="button button-primary" value="Sačuvaj" />
                    </form>
                </div>
                <?php
else: ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;background:#fcf0f1;border-left:4px solid #d63638;margin-top:0;opacity:0.85;">
                    <h3 style="margin-top:0;color:#d63638;">🔒 Dnevni Populator (Zaključano)</h3>
                    <p>Vaša licenca ne uključuje ovu funkciju. <strong>Nadogradite plan.</strong></p>
                </div>
                <?php
endif; ?>

            </div>
        </div>

        <!-- ═══════════════════════════════════════════ -->
        <!-- SEKCIJA 3: MASOVNE AKCIJE                  -->
        <!-- ═══════════════════════════════════════════ -->
        <div style="margin-top:30px;">
            <h2 style="margin:0 0 5px 0;font-size:16px;color:#1d2327;">🚀 Masovne akcije</h2>
            <p style="color:#666;margin:0 0 12px 0;">Ručno pokretanje masovnog slanja ili čišćenja oglasa.</p>

            <div style="display:flex;gap:20px;flex-wrap:wrap;">

                <!-- Push -->
                <?php if ($allow_mass): ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #2271b1;margin-top:0;">
                    <h3 style="margin-top:0;">➡️ Push</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Slanje svih proizvoda na OLX odjednom.</p>
                    <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
                        <label style="font-weight:600;white-space:nowrap;">Način:</label>
                        <select id="mass-push-mode" style="flex:1;padding:5px;">
                            <option value="all">Sve</option>
                            <option value="update_only">Samo ažuriraj</option>
                            <option value="new_only">Samo nove</option>
                        </select>
                    </div>
                    <button id="btn-mass-push" class="button button-primary">Pokreni Push</button>
                    <div id="mass-push-log" style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;height:120px;overflow-y:auto;font-family:monospace;font-size:12px;">Na čekanju za slanje: <strong><?php echo intval($queue_count); ?></strong> komada.</div>
                </div>
                <?php
else: ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;background:#fcf0f1;border-left:4px solid #d63638;margin-top:0;opacity:0.85;">
                    <h3 style="margin-top:0;color:#d63638;">🔒 Push (Zaključano)</h3>
                    <p>Vaša licenca ne uključuje masovnu sinhronizaciju. <strong>Nadogradite plan.</strong></p>
                </div>
                <?php
endif; ?>

                <!-- Cleanup -->
                <?php if ($allow_clean): ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #d63638;margin-top:0;">
                    <h3 style="margin-top:0;">🧹 Cleanup</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Briše OLX oglase koji nemaju par u WooCommerce-u.</p>
                    <button id="btn-mass-cleanup" class="button button-secondary">Pokreni Čistač</button>
                    <div id="mass-clean-log" style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;height:120px;overflow-y:auto;font-family:monospace;font-size:12px;">Spremno...</div>
                </div>
                <?php
else: ?>
                <div class="card" style="flex:1;min-width:340px;padding:20px;background:#fcf0f1;border-left:4px solid #d63638;margin-top:0;opacity:0.85;">
                    <h3 style="margin-top:0;color:#d63638;">🔒 Cleanup (Zaključano)</h3>
                    <p>Vaša licenca ne uključuje čišćenje. <strong>Nadogradite plan.</strong></p>
                </div>
<?php
endif; ?>
                
                <!-- Expired Cleanup -->
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #ff6b00;margin-top:0;">
                    <h3 style="margin-top:0;">🔄 Istekli Oglasi</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Pronađi OLX oglase koji su istekli i obradi ih prema odabranoj akciji.</p>
                    <div style="margin-bottom:10px;">
                        <label style="font-weight:600;display:block;margin-bottom:4px;">Akcija:</label>
                        <select id="expired-action" style="max-width:100%;padding:4px 6px;">
                            <option value="refresh">Samo Produži / Refresh (Sigurno i preporučeno)</option>
                            <option value="delete">Obriši pa kreiraj NOVO (Agresivno — ide na vrh)</option>
                        </select>
                    </div>
                    <button id="btn-expired-cleanup" class="button button-secondary">Pokreni Obnovu Isteklih</button>
                    <div id="expired-cleanup-log" style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;height:120px;overflow-y:auto;font-family:monospace;font-size:12px;">Spremno...</div>
                </div>

                <!-- Hidden Cleanup -->
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #888;margin-top:0;">
                    <h3 style="margin-top:0;">👁️ Brisanje Skrivenih</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Skriveni oglasi (bez zaliha) nakupljaju se s vremenom. Ovaj alat ih trajno briše sa OLX-a.<br><small style="color:#888;">Oglasi pod VIP zaštitom (s posebnom cijenom) su automatski izuzeti.</small></p>
                    <button id="btn-hidden-cleanup" class="button button-secondary">Obriši Skrivene Oglase</button>
                    <div id="hidden-cleanup-log" style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;height:120px;overflow-y:auto;font-family:monospace;font-size:12px;">Spremno...</div>
                </div>

                <!-- BUMP / Brisanje Aktivnih i Skrivenih po filteru -->
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #e91e63;margin-top:0;">
                    <h3 style="margin-top:0;color:#e91e63;">🔄 BUMP / Brisanje Aktivnih i Skrivenih</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Preuzima aktivne i skrivene oglase. Može se filtrirati po kategoriji i brendu. Primjenjuje odabranu akciju na sve pronađene oglase.</p>
                    <table style="width:100%;border-collapse:collapse;margin-bottom:10px;">
                        <tr>
                            <td style="padding:4px 0;width:90px;"><label style="font-weight:600;">Kategorija</label></td>
                            <td style="padding:4px 0;">
                                <select id="cab-cat" style="width:100%;padding:4px;">
                                    <option value="0">— Sve mapirane kategorije —</option>
                                    <?php
                                    $cab_mappings = get_option( 'drtechno_olx_category_mapping', [] );
                                    if ( ! empty( $cab_mappings ) ) {
                                        $cab_cats = get_terms( [ 'taxonomy' => 'product_cat', 'hide_empty' => false, 'include' => array_keys( $cab_mappings ), 'orderby' => 'name' ] );
                                        if ( ! is_wp_error( $cab_cats ) ) {
                                            foreach ( $cab_cats as $c ) {
                                                echo '<option value="' . esc_attr( $c->term_id ) . '">' . esc_html( $c->name ) . '</option>';
                                            }
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <?php if ( taxonomy_exists( 'product_brand' ) ) : ?>
                        <tr>
                            <td style="padding:4px 0;"><label style="font-weight:600;">Brend</label></td>
                            <td style="padding:4px 0;">
                                <select id="cab-brand" style="width:100%;padding:4px;">
                                    <option value="">— Svi brendovi —</option>
                                    <?php
                                    $cab_brands = get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false, 'orderby' => 'name' ] );
                                    if ( ! is_wp_error( $cab_brands ) ) {
                                        foreach ( $cab_brands as $b ) {
                                            echo '<option value="' . esc_attr( $b->slug ) . '">' . esc_html( $b->name ) . '</option>';
                                        }
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td style="padding:4px 0;"><label style="font-weight:600;">Akcija</label></td>
                            <td style="padding:4px 0;">
                                <select id="cab-action" style="width:100%;padding:4px;">
                                    <option value="refresh">Samo Produži / Refresh (Sigurno i preporučeno)</option>
                                    <option value="delete">Obriši pa kreiraj NOVO (Agresivno — ide na vrh)</option>
                                </select>
                            </td>
                        </tr>
                    </table>
                    <button id="btn-cab-start" class="button button-secondary">Pokreni Pregled i Obnovu</button>
                    <div id="cab-log" style="margin-top:12px;padding:10px;background:#f6f7f7;border:1px solid #dcdcde;border-radius:4px;height:200px;overflow-y:auto;font-family:monospace;font-size:12px;">Spremno...</div>
                    <p id="cab-status" style="color:#888;font-size:12px;margin:6px 0 0;"></p>
                </div>

                <!-- Recovery / Debug alati -->
                <div class="card" style="flex:1;min-width:340px;padding:20px;border-left:4px solid #6366f1;margin-top:0;">
                    <h3 style="margin-top:0;color:#6366f1;">🛠 Recovery alati</h3>
                    <p style="color:#666;font-size:13px;margin-bottom:12px;">Manuelni alati za debug i oporavak sync state-a.</p>

                    <p style="margin:10px 0 4px;"><strong>OLX daily-limit flag</strong></p>
                    <p style="color:#888;font-size:12px;margin:0 0 6px;">Briše plugin transient flag (1h auto-recovery prozor). Sljedeći CREATE pokušaj ide bez čekanja.</p>
                    <button id="btn-reset-daily-limit" class="button button-secondary">Resetuj limit flag</button>
                    <span id="reset-daily-limit-msg" style="margin-left:10px;font-size:12px;"></span>

                    <p style="margin:18px 0 4px;"><strong>Re-queue outofstock proizvoda</strong></p>
                    <p style="color:#888;font-size:12px;margin:0 0 6px;">Backup ako stock-change hook nije uhvatio promjenu — ubacuje sve outofstock + sa OLX ID-jem proizvode u sync queue.</p>
                    <button id="btn-requeue-oos" class="button button-secondary">Ubaci sve outofstock u queue</button>
                    <span id="requeue-oos-msg" style="margin-left:10px;font-size:12px;"></span>
                </div>

            </div>
        </div>
        <?php
        $auto_count = isset( $_GET['auto_start_images'] ) ? intval( $_GET['auto_start_images'] ) : 0;
        if ( $auto_count > 0 ): ?>
        <div class="card" style="max-width:100%;padding:20px;border-left:4px solid #0071a1;margin-top:20px;" id="auto-img-sync-card">
            <h3 style="margin-top:0;">🖼️ Masovni Sync Slika</h3>
            <p>Pokrenuto automatski za <strong><?php echo intval( $auto_count ); ?></strong> odabranih artikala.</p>
            <div id="auto-img-log" style="padding:10px;background:#f6f7f7;border:1px solid #dcdcde;height:150px;overflow-y:auto;font-family:monospace;font-size:12px;">Pokretanje...</div>
        </div>
        <script>
        jQuery(document).ready(function($){
            function runImgSync(){
                $.post(olx_sync_vars.ajaxurl,{action:'drtechno_mass_image_sync_process',nonce:olx_sync_vars.nonce},function(r){
                    if(r.success){
                        $('#auto-img-log').prepend('<div>'+r.data.message+'</div>');
                        if(r.data.status==='processing') setTimeout(runImgSync,600);
                        else $('#auto-img-log').prepend('<div style="color:green;font-weight:bold;">✓ Sve slike ažurirane!</div>');
                    } else {
                        $('#auto-img-log').prepend('<div style="color:red;">Greška: '+(r.data||'Nepoznata')+'</div>');
                    }
                }).fail(function(){ setTimeout(runImgSync,3000); });
            }
            runImgSync();
        });
        </script>
        <?php endif; ?>
        <script>jQuery(document).ready(function($){function ppq(){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_mass_sync_process',nonce:olx_sync_vars.nonce},function(r){$('#mass-push-log').prepend('<div>'+r.data.message+'</div>');if(r.data.status==='processing')setTimeout(ppq,500);else $('#btn-mass-push').prop('disabled',false).text('Završeno');}).fail(function(){setTimeout(ppq,5000);});}$('#btn-mass-push').click(function(e){e.preventDefault();if(!confirm('Pokrenuti?'))return;$(this).prop('disabled',true).text('Priprema...');$('#mass-push-log').html('');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_mass_sync_start',nonce:olx_sync_vars.nonce,sync_mode:$('#mass-push-mode').val()},function(r){$('#mass-push-log').prepend('<div style="color:green;">'+r.data+'</div>');ppq();});});function pc(p){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_cleanup_process',nonce:olx_sync_vars.nonce,page:p},function(r){$('#mass-clean-log').prepend('<div>'+r.data.message+'</div>');if(r.data.status==='processing')setTimeout(function(){pc(r.data.next_page);},1000);else $('#btn-mass-cleanup').prop('disabled',false).text('Završeno');}).fail(function(){setTimeout(function(){pc(p);},5000);});}$('#btn-mass-cleanup').click(function(e){e.preventDefault();if(!confirm('Sigurni?'))return;$(this).prop('disabled',true).text('Skeniram...');$('#mass-clean-log').html('');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_cleanup_start',nonce:olx_sync_vars.nonce},function(){pc(1);});});$('#btn-expired-cleanup').click(function(e){e.preventDefault();if(!confirm('Pokrenuti obradu isteklih oglasa?'))return;var $b=$(this).prop('disabled',true).text('Obrađujem...');var act=$('#expired-action').val();$('#expired-cleanup-log').html('');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_expired_cleanup_start',nonce:olx_sync_vars.nonce},function(){pec(0,act,$b);});});function pec(p,act,$b){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_expired_cleanup_process',nonce:olx_sync_vars.nonce,page:p,exp_action:act},function(r){if(!r.success){$('#expired-cleanup-log').prepend('<div style="color:red;">Greška: '+r.data+'</div>');$b.prop('disabled',false).text('Pokreni Obnovu Isteklih');return;}$('#expired-cleanup-log').prepend('<div>'+r.data.message+'</div>');if(r.data.status==='processing'&&r.data.next_page!=null)setTimeout(function(){pec(r.data.next_page,act,$b);},1000);else{$('#expired-cleanup-log').prepend('<div style="color:green;font-weight:bold;">✓ Završeno!</div>');$b.prop('disabled',false).text('Pokreni Obnovu Isteklih');}}).fail(function(){setTimeout(function(){pec(p,act,$b);},5000);});}$('#btn-hidden-cleanup').click(function(e){e.preventDefault();if(!confirm('Ovo će trajno obrisati sve skrivene oglase sa OLX-a. VIP artikli su zaštićeni. Nastaviti?'))return;var $b=$(this).prop('disabled',true).text('Obrađujem...');$('#hidden-cleanup-log').html('');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_hidden_cleanup_start',nonce:olx_sync_vars.nonce},function(){phc('start',$b);});});function phc(p,$b){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_hidden_cleanup_process',nonce:olx_sync_vars.nonce,page:p},function(r){if(!r.success){$('#hidden-cleanup-log').prepend('<div style="color:red;">Greška: '+(typeof r.data==='string'?r.data:JSON.stringify(r.data))+'</div>');$b.prop('disabled',false).text('Obriši Skrivene Oglase');return;}$('#hidden-cleanup-log').prepend('<div>'+(r.data&&r.data.message?r.data.message:'')+'</div>');if(r.data&&r.data.status==='processing'&&r.data.next_page!=null){setTimeout(function(){phc(r.data.next_page,$b);},1000);}else{$('#hidden-cleanup-log').prepend('<div style="color:green;font-weight:bold;">✓ Završeno!</div>');$b.prop('disabled',false).text('Obriši Skrivene Oglase');}}).fail(function(){setTimeout(function(){phc(p,$b);},5000);});}$('#btn-cab-start').click(function(e){e.preventDefault();if(!confirm('Pokrenuti pregled i obnovu aktivnih i skrivenih oglasa?'))return;var $b=$(this).prop('disabled',true).text('Obrađujem...');$('#cab-log').html('');$('#cab-status').text('Pokretanje...');var act=$('#cab-action').val();var catId=$('#cab-cat').val();var brand=$('#cab-brand').length?$('#cab-brand').val():'';$.post(olx_sync_vars.ajaxurl,{action:'drtechno_filtered_bump_start',nonce:olx_sync_vars.nonce,bump_action:act,wc_cat_id:catId,olx_status:'active_hidden',brand_name:brand},function(r){if(!r.success){$('#cab-status').text('✗ '+(r.data||'Greška.'));$b.prop('disabled',false).text('Pokreni Pregled i Obnovu');return;}$('#cab-status').text(r.data);pcab($b);});});function pcab($b){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_filtered_bump_process',nonce:olx_sync_vars.nonce},function(r){if(!r.success){$('#cab-status').text('✗ Greška.');$b.prop('disabled',false).text('Pokreni Pregled i Obnovu');return;}$('#cab-log').prepend('<div>'+r.data.message+'</div>');if(r.data.status==='complete'){$('#cab-status').text('✓ Završeno!');$b.prop('disabled',false).text('Pokreni Pregled i Obnovu');}else{$('#cab-status').text('Ostalo: '+r.data.left);setTimeout(function(){pcab($b);},300);}}).fail(function(){setTimeout(function(){pcab($b);},5000);});}
$('#btn-reset-daily-limit').click(function(e){e.preventDefault();var $b=$(this).prop('disabled',true).text('Brišem...');$('#reset-daily-limit-msg').text('').css('color','#666');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_reset_daily_limit',nonce:olx_sync_vars.nonce},function(r){var ok=r.success;$('#reset-daily-limit-msg').text((ok?'✓ ':'✗ ')+(r.data||'')).css('color',ok?'#00a32a':'#d63638');}).fail(function(){$('#reset-daily-limit-msg').text('✗ Mrežna greška').css('color','#d63638');}).always(function(){$b.prop('disabled',false).text('Resetuj limit flag');});});
$('#btn-requeue-oos').click(function(e){e.preventDefault();if(!confirm('Ubaciti sve outofstock proizvode (sa OLX ID-jem) u queue?'))return;var $b=$(this).prop('disabled',true).text('Ubacujem...');$('#requeue-oos-msg').text('').css('color','#666');$.post(olx_sync_vars.ajaxurl,{action:'drtechno_requeue_outofstock',nonce:olx_sync_vars.nonce},function(r){var ok=r.success;$('#requeue-oos-msg').text((ok?'✓ ':'✗ ')+(r.data||'')).css('color',ok?'#00a32a':'#d63638');}).fail(function(){$('#requeue-oos-msg').text('✗ Mrežna greška').css('color','#d63638');}).always(function(){$b.prop('disabled',false).text('Ubaci sve outofstock u queue');});});});</script>
