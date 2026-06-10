<?php
/**
 * Tab: Postavke Slika (Okvir i Dinamički Bedž)
 * Feature gate: image_processing
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['image_processing'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Postavke Slika (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

// Form handling
if (isset($_POST['olx_image_frame_submit']) && check_admin_referer('olx_image_frame_action', 'olx_image_frame_nonce')) {
    update_option('drtechno_olx_image_frame', intval($_POST['drtechno_olx_image_frame']));
    update_option('drtechno_olx_keep_old_images', isset($_POST['drtechno_olx_keep_old_images']) ? 'yes' : 'no');
    update_option('drtechno_olx_force_image_regen', isset($_POST['drtechno_olx_force_image_regen']) ? 'yes' : 'no');
    update_option('drtechno_olx_dynamic_badge', isset($_POST['drtechno_olx_dynamic_badge']) ? 'yes' : 'no');
    update_option('drtechno_olx_watermark_mode', sanitize_text_field($_POST['drtechno_olx_watermark_mode']));
    update_option('drtechno_olx_badge_old_price_size', floatval($_POST['drtechno_olx_badge_old_price_size']));
    update_option('drtechno_olx_badge_old_price_x', floatval($_POST['drtechno_olx_badge_old_price_x']));
    update_option('drtechno_olx_badge_old_price_y', floatval($_POST['drtechno_olx_badge_old_price_y']));
    update_option('drtechno_olx_badge_line_thickness', intval($_POST['drtechno_olx_badge_line_thickness']));
    update_option('drtechno_olx_badge_new_price_size', floatval($_POST['drtechno_olx_badge_new_price_size']));
    update_option('drtechno_olx_badge_new_price_x', floatval($_POST['drtechno_olx_badge_new_price_x']));
    update_option('drtechno_olx_badge_new_price_y', floatval($_POST['drtechno_olx_badge_new_price_y']));
    update_option('drtechno_olx_badge_width_pct', floatval($_POST['drtechno_olx_badge_width_pct']));
    update_option('drtechno_olx_badge_pos_x', intval($_POST['drtechno_olx_badge_pos_x']));
    update_option('drtechno_olx_badge_pos_y', intval($_POST['drtechno_olx_badge_pos_y']));
    echo '<div class="notice notice-success is-dismissible"><p>Postavke za slike su uspješno sačuvane!</p></div>';
}

$frame_id = get_option('drtechno_olx_image_frame');
$frame_url = $frame_id ? wp_get_attachment_url($frame_id) : '';
?>

        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-left:4px solid #ffb900;">
            <h3 style="margin-top:0;">Brendiranje Slika (Okvir i Dinamički Bedž)</h3>
            <form method="post">
                <?php wp_nonce_field('olx_image_frame_action', 'olx_image_frame_nonce'); ?>

                <!-- 1. Fiksni Okvir -->
                <table class="form-table">
                    <tr>
                        <th style="width:180px;padding:12px 10px 12px 0;">1. Fiksni Okvir (Watermark)</th>
                        <td>
                            <input type="hidden" name="drtechno_olx_image_frame" id="drtechno_olx_image_frame" value="<?php echo esc_attr($frame_id); ?>" />
                            <div id="olx-frame-preview" style="margin-bottom:10px;min-height:50px;">
                                <?php if ($frame_url): ?>
                                    <img src="<?php echo esc_url($frame_url); ?>" style="max-width:200px;max-height:200px;border:1px dashed #ccc;background:#f0f0f1;" />
                                <?php endif; ?>
                            </div>
                            <button type="button" class="button" id="olx-upload-frame-btn">Odaberi providni PNG okvir</button>
                            <button type="button" class="button" id="olx-remove-frame-btn" style="<?php echo !$frame_id ? 'display:none;' : ''; ?>color:#d63638;border-color:#d63638;">Ukloni okvir</button>
                        </td>
                    </tr>
                </table>

                <hr style="margin:20px 0;">

                <!-- 2. Dinamički Bedž -->
                <table class="form-table">
                    <tr>
                        <th style="width:180px;padding:12px 10px 12px 0;color:#0071a1;">2. Dinamički Bedž (Akcije)</th>
                        <td>
                            <label style="display:inline-block;margin-bottom:10px;">
                                <input type="checkbox" name="drtechno_olx_dynamic_badge" value="yes" <?php checked(get_option('drtechno_olx_dynamic_badge'), 'yes'); ?> />
                                <strong>Omogući iscrtavanje bedža sa cijenama</strong>
                            </label>

                            <div style="margin-bottom:15px;padding:10px;background:#e5f9e7;border-left:3px solid #46b450;">
                                <strong>Pravilo prikaza (Prioritet):</strong><br>
                                <select name="drtechno_olx_watermark_mode" style="margin-top:5px;width:100%;max-width:400px;">
                                    <option value="smart" <?php selected(get_option('drtechno_olx_watermark_mode', 'smart'), 'smart'); ?>>PAMETNO: Samo Bedž ako je akcija, inače samo Fiksni okvir</option>
                                    <option value="both" <?php selected(get_option('drtechno_olx_watermark_mode'), 'both'); ?>>Prikaži oboje istovremeno (Ako je na akciji)</option>
                                    <option value="frame_only" <?php selected(get_option('drtechno_olx_watermark_mode'), 'frame_only'); ?>>Uvijek prikazuj samo Fiksni Okvir (Ugasi bedž)</option>
                                    <option value="badge_only" <?php selected(get_option('drtechno_olx_watermark_mode'), 'badge_only'); ?>>Uvijek prikazuj samo Bedž (Ugasi fiksni okvir)</option>
                                </select>
                                <p style="margin-top:10px;">
                                    <button type="button" id="olx-open-badge-editor" class="button button-secondary">🎨 Otvori vizuelni editor pozicija</button>
                                    <small style="margin-left:10px;color:#666;">Drag&drop za pozicioniranje bedža na pravoj slici artikla.</small>
                                </p>
                            </div>

                            <!-- Podešavanje pozicija bedža -->
                            <div style="background:#f9f9f9;padding:15px;border:1px solid #ddd;margin-top:15px;">
                                <h4 style="margin-top:0;color:#000;">Podešavanje pozicija i veličina na bedžu</h4>

                                <strong>Stara cijena (Siva, precrtana)</strong>
                                <div style="display:flex;gap:10px;margin-bottom:10px;margin-top:5px;flex-wrap:wrap;">
                                    <div><small>Veličina (px):</small><br><input type="number" step="1" name="drtechno_olx_badge_old_price_size" value="<?php echo esc_attr(get_option('drtechno_olx_badge_old_price_size', '28')); ?>" style="width:70px;"></div>
                                    <div><small>Debljina linije (px):</small><br><input type="number" step="1" name="drtechno_olx_badge_line_thickness" value="<?php echo esc_attr(get_option('drtechno_olx_badge_line_thickness', '8')); ?>" style="width:70px;"></div>
                                    <div><small>X osa (Lijevo-Desno):</small><br><input type="number" step="0.01" name="drtechno_olx_badge_old_price_x" value="<?php echo esc_attr(get_option('drtechno_olx_badge_old_price_x', '0.62')); ?>" style="width:70px;" title="0.62 = 62%"></div>
                                    <div><small>Y osa (Gore-Dolje):</small><br><input type="number" step="0.01" name="drtechno_olx_badge_old_price_y" value="<?php echo esc_attr(get_option('drtechno_olx_badge_old_price_y', '0.36')); ?>" style="width:70px;" title="0.36 = 36%"></div>
                                </div>

                                <strong>Nova cijena (Crna)</strong>
                                <div style="display:flex;gap:10px;margin-bottom:10px;margin-top:5px;">
                                    <div><small>Veličina (px):</small><br><input type="number" step="1" name="drtechno_olx_badge_new_price_size" value="<?php echo esc_attr(get_option('drtechno_olx_badge_new_price_size', '85')); ?>" style="width:70px;"></div>
                                    <div><small>X osa (Lijevo-Desno):</small><br><input type="number" step="0.01" name="drtechno_olx_badge_new_price_x" value="<?php echo esc_attr(get_option('drtechno_olx_badge_new_price_x', '0.40')); ?>" style="width:70px;"></div>
                                    <div><small>Y osa (Gore-Dolje):</small><br><input type="number" step="0.01" name="drtechno_olx_badge_new_price_y" value="<?php echo esc_attr(get_option('drtechno_olx_badge_new_price_y', '0.82')); ?>" style="width:70px;"></div>
                                </div>

                                <hr style="margin:15px 0;border-top:1px dashed #ccc;">

                                <strong>Pozicija cijelog bedža na slici artikla</strong>
                                <div style="display:flex;gap:10px;margin-top:5px;">
                                    <div><small>Širina (%):</small><br><input type="number" step="0.01" name="drtechno_olx_badge_width_pct" value="<?php echo esc_attr(get_option('drtechno_olx_badge_width_pct', '0.55')); ?>" style="width:70px;" title="0.55 = 55% slike"></div>
                                    <div><small>Od DESNE ivice (px):</small><br><input type="number" step="1" name="drtechno_olx_badge_pos_x" value="<?php echo esc_attr(get_option('drtechno_olx_badge_pos_x', '20')); ?>" style="width:80px;"></div>
                                    <div><small>Od GORNJE ivice (px):</small><br><input type="number" step="1" name="drtechno_olx_badge_pos_y" value="<?php echo esc_attr(get_option('drtechno_olx_badge_pos_y', '20')); ?>" style="width:80px;"></div>
                                </div>
                            </div>
                        </td>
                    </tr>
                </table>

                <hr style="margin:20px 0;">

                <!-- Dodatne opcije -->
                <label style="display:block;margin-top:15px;background:#f9f9f9;padding:10px;border:1px solid #ddd;">
                    <input type="checkbox" name="drtechno_olx_keep_old_images" value="yes" <?php checked(get_option('drtechno_olx_keep_old_images'), 'yes'); ?> />
                    <strong>Zadrži postojeće slike prilikom ažuriranja</strong>
                </label>
                <label style="display:block;margin-top:15px;background:#e5f9e7;padding:10px;border:1px solid #46b450;">
                    <input type="checkbox" name="drtechno_olx_force_image_regen" value="yes" <?php checked(get_option('drtechno_olx_force_image_regen'), 'yes'); ?> />
                    <strong>Prisilno generiši iznova (Ignoriši keš)</strong>
                </label>

                <p class="submit" style="margin-bottom:0;margin-top:20px;">
                    <input type="submit" name="olx_image_frame_submit" class="button button-primary button-large" value="Sačuvaj postavke slika" />
                </p>
            </form>

            <!-- Vizuelni editor modal -->
            <div id="olx-badge-editor-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.82); z-index:999998; align-items:center; justify-content:center;">
                <div style="background:#fff; border-radius:4px; padding:20px; width:760px; max-width:95vw; max-height:95vh; overflow:auto; position:relative;">
                    <button type="button" id="olx-badge-editor-close" style="position:absolute;top:10px;right:14px;font-size:22px;background:none;border:none;cursor:pointer;color:#555;">&times;</button>
                    <h3 style="margin:0 0 15px;">🎨 Vizuelni editor pozicija bedža</h3>
                    <div style="display:flex; gap:10px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
                        <select id="olx-editor-product-select" style="width:260px;"></select>
                        <button type="button" id="olx-editor-load-btn" class="button">Učitaj sliku</button>
                        <label style="margin-left:10px;"><input type="radio" name="olx_editor_badge_type" value="std" checked> Standardni</label>
                        <label><input type="radio" name="olx_editor_badge_type" value="vip"> VIP</label>
                        <span id="olx-editor-spinner" class="spinner" style="float:none;vertical-align:middle;"></span>
                    </div>
                    <div id="olx-editor-canvas" style="position:relative; width:680px; height:400px; background:#eee; overflow:hidden; border:1px solid #ccc;">
                        <div id="olx-editor-placeholder" style="display:flex;align-items:center;justify-content:center;height:100%;color:#999;font-size:14px;">Odaberite artikal i kliknite "Učitaj sliku"</div>
                        <div id="olx-editor-badge" style="display:none; position:absolute; cursor:move;">
                            <img id="olx-editor-badge-img" src="" style="width:100%; height:100%; display:block; pointer-events:none;">
                            <div id="olx-editor-text-old" style="position:absolute; cursor:move; white-space:nowrap; color:#fff; font-weight:bold; text-shadow:1px 1px 2px rgba(0,0,0,0.5); user-select:none;">
                                <span>99</span>
                                <div id="olx-editor-old-line" style="position:absolute;left:0;width:100%;background:red;pointer-events:none;"></div>
                            </div>
                            <div id="olx-editor-text-new" style="position:absolute; cursor:move; white-space:nowrap; color:#141414; font-weight:900; user-select:none;">
                                <span>79</span>
                            </div>
                        </div>
                    </div>
                    <div style="display:flex; gap:20px; align-items:center; margin:10px 0; flex-wrap:wrap; background:#f9f9f9; padding:10px; border:1px solid #ddd;">
                        <div style="display:flex; align-items:center; gap:6px;">
                            <label style="font-size:12px; white-space:nowrap;">Stara cijena (px):</label>
                            <input type="number" id="olx-editor-old-size" min="6" max="200" step="1" style="width:60px;" value="<?php echo esc_attr(get_option('drtechno_olx_badge_old_price_size','28')); ?>">
                        </div>
                        <div style="display:flex; align-items:center; gap:6px;">
                            <label style="font-size:12px; white-space:nowrap;">Nova / VIP cijena (px):</label>
                            <input type="number" id="olx-editor-new-size" min="6" max="200" step="1" style="width:60px;" value="<?php echo esc_attr(get_option('drtechno_olx_badge_new_price_size','85')); ?>">
                        </div>
                    </div>
                    <p style="color:#666; font-size:12px; margin:8px 0 15px;"><strong>Drag</strong> bedž za poziciju &nbsp;·&nbsp; <strong>Resize ↔</strong> (desni plavi rub) za veličinu &nbsp;·&nbsp; <strong>Drag</strong> cijene unutar bedža za poziciju teksta</p>
                    <div style="display:flex; gap:10px;">
                        <button type="button" id="olx-editor-apply" class="button button-primary">✓ Primijeni postavke</button>
                        <button type="button" id="olx-badge-editor-close2" class="button">✕ Zatvori</button>
                    </div>
                    <p id="olx-editor-apply-msg" style="display:none; color:green; margin:8px 0 0; font-weight:bold;">✓ Postavke primijenjene u formu. Kliknite "Sačuvaj postavke slika" da sačuvate.</p>
                </div>
            </div>

            <!-- Pregled obrađene slike -->
            <div class="postbox" style="margin-top:20px;">
                <h2 class="hndle"><span>🔍 Pregled obrađene slike</span></h2>
                <div class="inside">
                    <table class="form-table">
                        <tr>
                            <th scope="row">Pretraga artikla</th>
                            <td>
                                <select id="olx-tab-preview-search" style="width:320px;"></select>
                                <input type="hidden" id="olx-tab-preview-product-id">
                                <button type="button" id="olx-tab-preview-btn" class="button button-primary" style="margin-left:8px;">🔍 PREGLED SLIKE</button>
                                <span id="olx-tab-preview-spinner" class="spinner" style="float:none; vertical-align:middle;"></span>
                                <p class="description">Ukucajte naziv artikla, odaberite iz liste i kliknite PREGLED.</p>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>

            <div id="olx-preview-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.75); z-index:999999; align-items:center; justify-content:center;">
                <div style="position:relative; background:#fff; padding:20px; border-radius:4px; max-width:90vw; max-height:90vh; overflow:auto; text-align:center;">
                    <button type="button" id="olx-preview-modal-close" style="position:absolute; top:8px; right:12px; font-size:20px; background:none; border:none; cursor:pointer; color:#555;">&times;</button>
                    <h3 id="olx-preview-modal-title" style="margin:0 0 12px;">Pregled slike</h3>
                    <img id="olx-preview-modal-img" src="" style="max-width:100%; max-height:75vh; display:block; margin:0 auto; border:1px solid #ddd;">
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Frame upload (postojeća logika)
            var image_frame;
            $('#olx-upload-frame-btn').click(function(e) {
                e.preventDefault();
                if (image_frame) { image_frame.open(); return; }
                image_frame = wp.media({
                    title: 'Odaberi prozirni PNG okvir',
                    button: { text: 'Koristi ovu sliku' },
                    multiple: false
                });
                image_frame.on('select', function() {
                    var attachment = image_frame.state().get('selection').first().toJSON();
                    $('#drtechno_olx_image_frame').val(attachment.id);
                    $('#olx-frame-preview').html('<img src="' + attachment.url + '" style="max-width:200px;max-height:200px;border:1px dashed #ccc;background:#f0f0f1;" />');
                    $('#olx-remove-frame-btn').show();
                });
                image_frame.open();
            });
            $('#olx-remove-frame-btn').click(function(e) {
                e.preventDefault();
                $('#drtechno_olx_image_frame').val('');
                $('#olx-frame-preview').html('');
                $(this).hide();
            });

            // Tab preview Select2
            if ($.fn.select2) {
                $('#olx-tab-preview-search').select2({
                    width: '320px',
                    placeholder: 'Ukucajte naziv artikla...',
                    minimumInputLength: 3,
                    language: { inputTooShort: function() { return 'Unesite najmanje 3 znaka...'; }, searching: function() { return 'Pretraga...'; }, noResults: function() { return 'Nema rezultata.'; } },
                    ajax: {
                        url: olx_sync_vars.ajaxurl,
                        dataType: 'json',
                        delay: 250,
                        data: function(params) { return { q: params.term, action: 'drtechno_search_products', nonce: olx_sync_vars.nonce }; },
                        processResults: function(data) { return { results: data.results }; }
                    }
                }).on('select2:select', function(e) {
                    $('#olx-tab-preview-product-id').val(e.params.data.id);
                });
            }

            $('#olx-tab-preview-btn').click(function(e) {
                e.preventDefault();
                var pid = parseInt($('#olx-tab-preview-product-id').val(), 10);
                if (!pid || pid <= 0) { alert('Odaberite artikal iz liste prije pregleda.'); return; }
                $('#olx-tab-preview-spinner').addClass('is-active');
                $('#olx-tab-preview-btn').prop('disabled', true);
                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_preview_image',
                    nonce:   olx_sync_vars.nonce,
                    post_id: pid
                }, function(res) {
                    $('#olx-tab-preview-spinner').removeClass('is-active');
                    $('#olx-tab-preview-btn').prop('disabled', false);
                    if (!res.success) { alert('Greška: ' + res.data); return; }
                    $('#olx-preview-modal-title').text('Pregled slike — Artikal #' + pid);
                    $('#olx-preview-modal-img').attr('src', res.data);
                    $('#olx-preview-modal').css('display', 'flex');
                });
            });
            $('#olx-preview-modal-close, #olx-preview-modal').click(function(e) {
                if (e.target === this) {
                    $('#olx-preview-modal').css('display', 'none');
                    $('#olx-preview-modal-img').attr('src', '');
                }
            });
            $(document).keydown(function(e) {
                if (e.key === 'Escape') {
                    $('#olx-preview-modal').css('display', 'none');
                    $('#olx-preview-modal-img').attr('src', '');
                    $('#olx-badge-editor-modal').css('display', 'none');
                }
            });

            // ── Vizuelni editor bedža ──────────────────────────────────────
            $('<style>'
                + '@font-face{font-family:"OlxMontserratBlack";src:url("'+olx_sync_vars.font_black_url+'") format("truetype");}'
                + '@font-face{font-family:"OlxMontserratSemi";src:url("'+olx_sync_vars.font_semi_url+'") format("truetype");}'
                + '#olx-editor-text-new{font-family:"OlxMontserratBlack",sans-serif!important;font-weight:normal!important;}'
                + '#olx-editor-text-old{font-family:"OlxMontserratSemi",sans-serif!important;font-weight:normal!important;}'
                + '#olx-editor-badge .ui-resizable-e{width:14px;right:-7px;top:0;height:100%;background:#0071a1;opacity:0.75;cursor:ew-resize;border-radius:0 4px 4px 0;z-index:10;}'
                + '.select2-dropdown{z-index:1000001!important;}.select2-container--open{z-index:1000001!important;}'
            + '</style>').appendTo('head');

            var olxEditor = { imgNaturalW:1, imgNaturalH:1, badgeNaturalW:1, badgeNaturalH:1, displayW:680, canvasH:0, badgeType:'std' };

            $('#olx-open-badge-editor').click(function() {
                $('#olx-badge-editor-modal').css('display','flex');
                $('#olx-editor-old-size').val($('[name=drtechno_olx_badge_old_price_size]').val() || 28);
                $('#olx-editor-new-size').val($('[name=drtechno_olx_badge_new_price_size]').val() || 85);
                if ($.fn.select2 && !$('#olx-editor-product-select').data('select2')) {
                    $('#olx-editor-product-select').select2({
                        width: '260px', placeholder: 'Artikal...', minimumInputLength: 3,
                        dropdownParent: $('body'),
                        ajax: { url: olx_sync_vars.ajaxurl, dataType: 'json', delay: 250,
                            data: function(p) { return { q: p.term, action: 'drtechno_search_products', nonce: olx_sync_vars.nonce }; },
                            processResults: function(d) { return { results: d.results }; } }
                    });
                }
            });

            $('#olx-editor-old-size').on('input', function() {
                $('[name=drtechno_olx_badge_old_price_size]').val($(this).val());
                if ($('#olx-editor-badge').is(':visible')) olxEditorPositionTexts();
            });
            $('#olx-editor-new-size').on('input', function() {
                $('[name=drtechno_olx_badge_new_price_size]').val($(this).val());
                if ($('#olx-editor-badge').is(':visible')) olxEditorPositionTexts();
            });
            $('#olx-badge-editor-close, #olx-badge-editor-close2').click(function() { $('#olx-badge-editor-modal').css('display','none'); });

            $('#olx-editor-load-btn').click(function() {
                var pid = $('#olx-editor-product-select').val();
                if (!pid) { alert('Odaberite artikal.'); return; }
                $('#olx-editor-spinner').addClass('is-active');
                olxEditor.badgeType = $('[name=olx_editor_badge_type]:checked').val();
                var badgeUrl = olxEditor.badgeType === 'vip' ? olx_sync_vars.badge_vip_url : olx_sync_vars.badge_std_url;
                $.when(
                    $.post(olx_sync_vars.ajaxurl, { action: 'drtechno_preview_image', nonce: olx_sync_vars.nonce, post_id: pid }),
                    $.post(olx_sync_vars.ajaxurl, { action: 'drtechno_get_product_prices', nonce: olx_sync_vars.nonce, post_id: pid })
                ).done(function(imgRes, priceRes) {
                    $('#olx-editor-spinner').removeClass('is-active');
                    if (!imgRes[0].success) { alert('Greška: ' + imgRes[0].data); return; }
                    if (priceRes[0].success) {
                        var p = priceRes[0].data;
                        olxEditor.prices = p;
                        $('#olx-editor-text-old span').text(p.regular);
                        $('#olx-editor-text-new span').text(olxEditor.badgeType === 'vip' ? p.vip : p.sale);
                    }
                    olxEditorInit(imgRes[0].data, badgeUrl);
                });
            });

            $('[name=olx_editor_badge_type]').change(function() {
                olxEditor.badgeType = $(this).val();
                var badgeUrl = olxEditor.badgeType === 'vip' ? olx_sync_vars.badge_vip_url : olx_sync_vars.badge_std_url;
                if ($('#olx-editor-badge').is(':visible')) {
                    $('#olx-editor-text-old').toggle(olxEditor.badgeType === 'std');
                    if (olxEditor.prices) {
                        $('#olx-editor-text-new span').text(olxEditor.badgeType === 'vip' ? olxEditor.prices.vip : olxEditor.prices.sale);
                    }
                    olxEditorLoadBadge(badgeUrl);
                }
            });

            function olxEditorInit(imgUrl, badgeUrl) {
                var canvas = $('#olx-editor-canvas');
                canvas.css({ 'background-image': 'url('+imgUrl+')', 'background-size': '680px auto', 'background-repeat': 'no-repeat', 'background-position': 'top left' });
                var tmpImg = new Image();
                tmpImg.onload = function() {
                    olxEditor.imgNaturalW = tmpImg.naturalWidth || 1;
                    olxEditor.imgNaturalH = tmpImg.naturalHeight || 1;
                    var scale = olxEditor.displayW / olxEditor.imgNaturalW;
                    olxEditor.canvasH = Math.round(olxEditor.imgNaturalH * scale);
                    canvas.height(olxEditor.canvasH);
                    $('#olx-editor-placeholder').hide();
                    olxEditorLoadBadge(badgeUrl);
                };
                tmpImg.src = imgUrl;
            }

            function olxEditorLoadBadge(badgeUrl) {
                var badgeImg = new Image();
                badgeImg.onload = function() {
                    olxEditor.badgeNaturalW = badgeImg.naturalWidth || 1;
                    olxEditor.badgeNaturalH = badgeImg.naturalHeight || 1;
                    olxEditorPositionBadge();
                };
                badgeImg.src = badgeUrl;
                $('#olx-editor-badge-img').attr('src', badgeUrl);
            }

            function olxEditorPositionBadge() {
                var scale = olxEditor.displayW / olxEditor.imgNaturalW;
                var widthPct = parseFloat($('[name=drtechno_olx_badge_width_pct]').val()) || 0.55;
                var posX    = parseInt($('[name=drtechno_olx_badge_pos_x]').val()) || 20;
                var posY    = parseInt($('[name=drtechno_olx_badge_pos_y]').val()) || 20;
                var bw = Math.round(olxEditor.displayW * widthPct);
                var bh = Math.round(bw * (olxEditor.badgeNaturalH / olxEditor.badgeNaturalW));
                var bl = Math.max(0, Math.round(olxEditor.displayW - bw - posX * scale));
                var bt = Math.max(0, Math.round(posY * scale));
                var badge = $('#olx-editor-badge');
                badge.css({ left: bl, top: bt, width: bw, height: bh }).show();
                if (badge.data('ui-draggable')) badge.draggable('destroy');
                badge.draggable({ containment: '#olx-editor-canvas', stop: function() { olxEditorReadBadgePos(); olxEditorPositionTexts(); } });
                if (badge.data('ui-resizable')) badge.resizable('destroy');
                badge.resizable({ handles: 'e', minWidth: 60, maxWidth: olxEditor.displayW - 10,
                    resize: function(e, ui) {
                        var ratio = olxEditor.badgeNaturalH / olxEditor.badgeNaturalW;
                        ui.size.height = Math.round(ui.size.width * ratio);
                        badge.height(ui.size.height);
                        olxEditorScaleTexts(ui.size.width);
                    },
                    stop: function() { olxEditorReadBadgePos(); olxEditorPositionTexts(); }
                });
                olxEditorPositionTexts();
            }

            function olxEditorPositionTexts() {
                var bw = $('#olx-editor-badge').width();
                var bh = $('#olx-editor-badge').height();
                var badgeScale = bw / olxEditor.badgeNaturalW;
                var isVip = olxEditor.badgeType === 'vip';
                var oldX = parseFloat($('[name=drtechno_olx_badge_old_price_x]').val()) || 0.62;
                var oldY = parseFloat($('[name=drtechno_olx_badge_old_price_y]').val()) || 0.36;
                var newX = parseFloat($('[name=drtechno_olx_badge_new_price_x]').val()) || 0.40;
                var newY = parseFloat($('[name=drtechno_olx_badge_new_price_y]').val()) || 0.82;
                var gdPtToPx = 96 / 72;
                var oldSize = Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_old_price_size]').val()) || 28) * badgeScale * gdPtToPx));
                var newSize = Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_new_price_size]').val()) || 85) * badgeScale * gdPtToPx));
                var lineH   = Math.max(1, Math.round((parseFloat($('[name=drtechno_olx_badge_line_thickness]').val()) || 8) * badgeScale));
                var oldAscent = Math.round(oldSize * 0.80);
                var newAscent = Math.round(newSize * 0.968);
                $('#olx-editor-text-old').css({ left: Math.round(oldX*bw), top: Math.round(oldY*bh - oldAscent), fontSize: oldSize+'px', display: isVip ? 'none' : 'block' });
                $('#olx-editor-old-line').css({ height: lineH+'px', top: Math.round(oldSize*0.35)+'px', transform: 'rotate(-3deg)', transformOrigin: 'left center' });
                $('#olx-editor-text-new').css({ left: Math.round(newX*bw), top: Math.round(newY*bh - newAscent), fontSize: newSize+'px' });
                ['#olx-editor-text-old','#olx-editor-text-new'].forEach(function(sel) {
                    var el = $(sel);
                    if (el.data('ui-draggable')) el.draggable('destroy');
                    el.draggable({ containment: '#olx-editor-badge', stop: olxEditorReadTextPos });
                });
            }

            function olxEditorScaleTexts(newBadgeW) {
                var s = newBadgeW / olxEditor.badgeNaturalW;
                var gdPtToPx = 96 / 72;
                $('#olx-editor-text-old').css('font-size', Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_old_price_size]').val())||28)*s*gdPtToPx))+'px');
                $('#olx-editor-text-new').css('font-size', Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_new_price_size]').val())||85)*s*gdPtToPx))+'px');
            }

            function olxEditorReadBadgePos() {
                var scale = olxEditor.displayW / olxEditor.imgNaturalW;
                var badge = $('#olx-editor-badge');
                var bl = parseInt(badge.css('left'));
                var bt = parseInt(badge.css('top'));
                var bw = badge.width();
                $('[name=drtechno_olx_badge_pos_x]').val(Math.max(0, Math.round((olxEditor.displayW - bl - bw) / scale)));
                $('[name=drtechno_olx_badge_pos_y]').val(Math.max(0, Math.round(bt / scale)));
                $('[name=drtechno_olx_badge_width_pct]').val(Math.round((bw / olxEditor.displayW) * 100) / 100);
            }

            function olxEditorReadTextPos() {
                var bw = $('#olx-editor-badge').width();
                var bh = $('#olx-editor-badge').height();
                var badgeScale = bw / olxEditor.badgeNaturalW;
                var gdPtToPx = 96 / 72;
                var oldSize = Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_old_price_size]').val())||28) * badgeScale * gdPtToPx));
                var newSize = Math.max(8, Math.round((parseFloat($('[name=drtechno_olx_badge_new_price_size]').val())||85) * badgeScale * gdPtToPx));
                $('[name=drtechno_olx_badge_old_price_x]').val(Math.round((parseInt($('#olx-editor-text-old').css('left'))  / bw) * 100) / 100);
                $('[name=drtechno_olx_badge_old_price_y]').val(Math.round(((parseInt($('#olx-editor-text-old').css('top')) + oldSize * 0.80) / bh) * 100) / 100);
                $('[name=drtechno_olx_badge_new_price_x]').val(Math.round((parseInt($('#olx-editor-text-new').css('left'))  / bw) * 100) / 100);
                $('[name=drtechno_olx_badge_new_price_y]').val(Math.round(((parseInt($('#olx-editor-text-new').css('top')) + newSize * 0.968) / bh) * 100) / 100);
            }

            $('#olx-editor-apply').click(function() {
                olxEditorReadBadgePos();
                olxEditorReadTextPos();
                $('#olx-editor-apply-msg').show();
                setTimeout(function() { $('#olx-editor-apply-msg').fadeOut(); }, 3000);
            });
        });
        </script>
