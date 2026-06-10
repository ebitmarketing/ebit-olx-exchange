<?php
/**
 * Tab 2: Postavke opisa (Template za gornji/donji dio opisa)
 * Feature gate: desc_settings
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['desc_settings'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Postavke opisa (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;
?>
        <!-- Template opisa -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;">
            <h3>Template za Opis</h3>
            <div style="background:#e5f9e7;padding:15px;border-left:4px solid #46b450;margin-bottom:15px;">
                <strong>🛠️ Dostupne varijable:</strong>
                <ul style="margin-top:8px;margin-bottom:0;font-size:13px;"><li><code>[product_name]</code> - Naziv</li><li><code>[product_link]</code> - Link</li><li><code>[short_description]</code> - Kratki opis</li><li><code>[long_description]</code> - Dugi opis</li><li><code>[attributes]</code> - Karakteristike</li><li><code>[regular_price]</code> / <code>[sale_price]</code> / <code>[shipping_price]</code></li><li><code>[ean]</code> - GTIN/EAN barkod</li><li><code>[sku]</code> - SKU (šifra artikla)</li><?php if (taxonomy_exists('ebit_supplier')): ?><li><code>[supplier]</code> - Dobavljač</li><?php
endif; ?></ul>
            </div>
            <form method="post"><?php wp_nonce_field('olx_global_desc_action', 'olx_global_desc_nonce'); ?>
                <h4>Gornji dio</h4><div style="margin-bottom:25px;"><?php wp_editor(stripslashes(get_option('drtechno_olx_global_prefix')), 'drtechno_olx_global_prefix', ['textarea_rows' => 8, 'media_buttons' => false]); ?></div>
                <h4>Donji dio</h4><div style="margin-bottom:15px;"><?php wp_editor(stripslashes(get_option('drtechno_olx_global_description')), 'drtechno_olx_global_description', ['textarea_rows' => 8, 'media_buttons' => false]); ?></div>
                <p class="submit" style="margin-bottom:0;"><input type="submit" name="olx_global_desc_submit" class="button button-primary" value="Sačuvaj Template" /></p>
            </form>
        </div>

        <!-- Pregled opisa -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #46b450;">
            <h3 style="margin-top:0;">&#128065; Pregled Opisa</h3>
            <p style="color:#666;">Odaberite artikal da vidite kako će izgledati opis na OLX-u (koristi sačuvani template).</p>
            <table class="form-table">
                <tr>
                    <th>Artikal</th>
                    <td>
                        <select id="desc-preview-product" class="olx-product-search" style="width:100%;max-width:400px;"></select>
                    </td>
                </tr>
            </table>
            <button type="button" id="btn-desc-preview" class="button button-secondary">&#128065; Pregledaj Opis</button>
            <div id="desc-preview-result" style="display:none;margin-top:15px;padding:15px;border:1px solid #ddd;border-radius:4px;background:#fff;"></div>
            <script>
            jQuery(document).ready(function($){
                if ($.fn.select2) {
                    $('#desc-preview-product').select2({
                        width: '100%',
                        placeholder: 'Upišite naziv artikla...',
                        minimumInputLength: 3,
                        ajax: {
                            url: olx_sync_vars.ajaxurl,
                            dataType: 'json',
                            delay: 250,
                            data: function(params) { return { q: params.term, action: 'drtechno_search_products', nonce: olx_sync_vars.nonce }; },
                            processResults: function(data) { return { results: data.results }; }
                        }
                    });
                }
            });
            document.getElementById('btn-desc-preview').addEventListener('click', function(){
                var pid = document.getElementById('desc-preview-product').value;
                if (!pid) { alert('Odaberite artikal.'); return; }
                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Učitavanje...';
                var body = new URLSearchParams({
                    action: 'drtechno_preview_description',
                    post_id: pid,
                    nonce: olx_sync_vars.nonce
                });
                fetch(olx_sync_vars.ajaxurl, {method:'POST', body:body})
                    .then(r=>r.json())
                    .then(function(d){
                        btn.disabled = false;
                        btn.textContent = '👁 Pregledaj Opis';
                        var res = document.getElementById('desc-preview-result');
                        res.style.display = 'block';
                        res.innerHTML = d.success ? (d.data.html || '<em>Prazan opis.</em>') : '<span style="color:#d63638;">Greška: ' + (d.data||'') + '</span>';
                    })
                    .catch(function(){ btn.disabled=false; btn.textContent='👁 Pregledaj Opis'; });
            });
            </script>
        </div>
