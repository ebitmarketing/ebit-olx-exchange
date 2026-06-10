<?php
/**
 * Tab 12: AI Nazivi — Gemini API (server-side, per-licenca API ključ)
 * Feature gate: ai_titles
 */
if ( ! defined( 'ABSPATH' ) ) exit;

$_lc_cache = get_transient( 'drtechno_olx_license_cache' );
$features = ( $_lc_cache && isset( $_lc_cache['features'] ) ) ? $_lc_cache['features'] : get_option( 'drtechno_olx_license_features', [] );
if ( empty( $features['ai_titles'] ) ):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">&#10024; AI Nazivi (Zaključano)</h3>
        <p>Vaš plan ne uključuje funkciju AI Naziva. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

$gemini_model  = get_option( 'drtechno_olx_gemini_model', 'gemini-2.0-flash' );
$gemini_prompt = get_option( 'drtechno_olx_gemini_prompt',
    'Generiši SEO-optimiziran OLX naslov za: {naziv}. Kategorija: {kategorija}. Brend: {brend}. Maksimum 65 karaktera. Vrati samo naslov bez dodatnog teksta.'
);
$nonce = wp_create_nonce( 'olx_sync_nonce' );
?>

<!-- API Ključ (čuva se na serveru, AJAX) -->
<div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #7c3aed;">
    <h3 style="margin-top:0;color:#7c3aed;">&#10024; AI Nazivi — Gemini API</h3>
    <p style="color:#666;">
        Generiši SEO-optimizirane OLX naslove koristeći Google Gemini AI.<br>
        Naslovi se čuvaju u <code>_olx_title</code> meta polju i <strong>ne mijenjaju WooCommerce naziv artikla</strong>.<br>
        <strong>Gemini API ključ se čuva šifriran na serveru</strong> — nikad u WordPress bazi.
    </p>
    <div style="background:#f0f0f1;padding:15px;border-radius:4px;margin-bottom:20px;">
        <strong>Gemini API Ključ</strong>
        <p style="color:#666;font-size:12px;margin:4px 0 10px;">Unesite API ključ i kliknite "Sačuvaj ključ". Pohranjen je šifrirano na serveru.</p>
        <div style="display:flex;gap:10px;align-items:center;">
            <input type="password" id="gemini-api-key-input" class="regular-text" placeholder="AIza..." style="max-width:350px;">
            <button type="button" id="btn-save-gemini-key" class="button button-primary">Sačuvaj ključ</button>
            <span id="gemini-key-status" style="color:#666;font-size:12px;"></span>
        </div>
        <p style="color:#888;font-size:12px;margin-top:8px;">Kreirajte ključ na <strong>aistudio.google.com</strong>. Ostavite prazno da obrišete ključ.</p>
    </div>

    <!-- Model + Prompt (WP options, PHP forma) -->
    <form method="post" action="?page=drtechno_olx_sync&tab=ai_titles">
        <?php wp_nonce_field( 'olx_ai_titles_settings_action', 'olx_ai_titles_settings_nonce' ); ?>
        <table class="form-table">
            <tr>
                <th>Model</th>
                <td>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <select name="drtechno_olx_gemini_model" id="gemini-model-select" style="max-width:350px;">
                            <option value="gemini-2.0-flash" <?php selected($gemini_model, 'gemini-2.0-flash'); ?>>gemini-2.0-flash (Preporučeno)</option>
                            <option value="gemini-1.5-flash" <?php selected($gemini_model, 'gemini-1.5-flash'); ?>>gemini-1.5-flash</option>
                            <?php if (!in_array($gemini_model, ['gemini-2.0-flash', 'gemini-1.5-flash'])): ?>
                            <option value="<?php echo esc_attr($gemini_model); ?>" selected><?php echo esc_html($gemini_model); ?></option>
                            <?php endif; ?>
                        </select>
                        <button type="button" id="btn-fetch-models" class="button button-secondary">Dohvati modele</button>
                    </div>
                </td>
            </tr>
            <tr>
                <th>Prompt Template</th>
                <td>
                    <textarea name="drtechno_olx_gemini_prompt" rows="5" class="large-text"><?php echo esc_textarea( $gemini_prompt ); ?></textarea>
                    <p class="description">Varijable: <code>{naziv}</code>, <code>{kategorija}</code>, <code>{brend}</code>, <code>{cijena}</code>, <code>{opis}</code>, <code>{sku}</code></p>
                </td>
            </tr>
        </table>
        <p class="submit"><input type="submit" name="olx_ai_titles_settings_submit" class="button button-primary" value="Sačuvaj Model i Prompt"></p>
    </form>
</div>

<!-- Generisanje za jedan artikal -->
<div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #0071a1;">
    <h3 style="margin-top:0;color:#0071a1;">Generiši Naslov za Jedan Artikal</h3>
    <table class="form-table">
        <tr>
            <th>Artikal</th>
            <td><select id="ai-title-product" class="olx-product-search" style="width:100%;max-width:400px;"></select></td>
        </tr>
    </table>
    <p>
        <button type="button" id="btn-generate-single-title" class="button button-primary">&#10024; Generiši AI Naslov</button>
    </p>
    <div id="single-title-result" style="display:none;margin-top:10px;padding:12px;background:#e5f9e7;border-left:4px solid #46b450;border-radius:4px;">
        <strong>Generisani naslov:</strong> <span id="single-title-text"></span>
        <br><small id="single-title-chars" style="color:#888;"></small>
    </div>
</div>

<!-- Masovno generisanje -->
<div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #ffb900;">
    <h3 style="margin-top:0;color:#b07a00;">Masovno AI Generisanje Naslova</h3>
    <p style="color:#666;">
        Generiše AI naslove za <strong>sve artikle koji su objavljeni na OLX-u</strong>.<br>
        Svaki artikal = jedan Gemini API poziv. Pauza: 500ms.
    </p>
    <p>
        <button type="button" id="btn-ai-bulk-start" class="button button-primary button-large">Pokreni Masovno Generisanje</button>
    </p>
    <div id="ai-bulk-progress" style="display:none;margin-top:15px;">
        <div id="ai-bulk-log" style="background:#f6f7f7;border:1px solid #ddd;padding:10px;max-height:250px;overflow-y:auto;font-family:monospace;font-size:12px;"></div>
        <p id="ai-bulk-status" style="color:#888;margin-top:8px;"></p>
    </div>
</div>

<script>
jQuery(document).ready(function($){
    var nonce = '<?php echo esc_js( $nonce ); ?>';

    if ($.fn.select2) {
        $('#ai-title-product').select2({
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

    document.getElementById('btn-save-gemini-key').addEventListener('click', function(){
        var btn = this;
        var key = document.getElementById('gemini-api-key-input').value;
        btn.disabled = true;
        document.getElementById('gemini-key-status').textContent = 'Čuvam...';
        var body = new URLSearchParams({action:'drtechno_save_gemini_key', nonce:nonce, gemini_api_key:key});
        fetch(olx_sync_vars.ajaxurl, {method:'POST', body:body})
            .then(r=>r.json())
            .then(function(d){
                btn.disabled = false;
                document.getElementById('gemini-key-status').textContent = d.success ? '✓ Ključ sačuvan na serveru.' : '✗ ' + (d.data||'Greška.');
                document.getElementById('gemini-key-status').style.color = d.success ? 'green' : '#d63638';
            })
            .catch(function(){ btn.disabled=false; document.getElementById('gemini-key-status').textContent='✗ Greška mreže.'; });
    });

    document.getElementById('btn-fetch-models').addEventListener('click', function(){
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Učitavanje...';
        var body = new URLSearchParams({action:'drtechno_fetch_gemini_models', nonce:nonce});
        fetch(olx_sync_vars.ajaxurl, {method:'POST', body:body})
            .then(r=>r.json())
            .then(function(d){
                btn.disabled = false;
                btn.textContent = 'Dohvati modele';
                if (!d.success) { alert('Greška: ' + (d.data||'')); return; }
                var sel = document.getElementById('gemini-model-select');
                var current = sel.value;
                sel.innerHTML = '';
                (d.data || []).forEach(function(m){
                    var opt = document.createElement('option');
                    opt.value = m.id;
                    opt.textContent = m.display;
                    if (m.id === current || m.id.endsWith('/'+current)) opt.selected = true;
                    sel.appendChild(opt);
                });
            })
            .catch(function(){ btn.disabled=false; btn.textContent='Dohvati modele'; });
    });

    document.getElementById('btn-generate-single-title').addEventListener('click', function(){
        var pid = document.getElementById('ai-title-product').value;
        if (!pid) { alert('Odaberite artikal.'); return; }
        var btn = this;
        btn.disabled = true;
        btn.textContent = 'Generisanje...';
        document.getElementById('single-title-result').style.display = 'none';
        var body = new URLSearchParams({action:'drtechno_generate_olx_title', post_id:pid, nonce:nonce});
        fetch(olx_sync_vars.ajaxurl, {method:'POST', body:body})
            .then(r=>r.json())
            .then(function(d){
                btn.disabled = false;
                btn.textContent = '✨ Generiši AI Naslov';
                if (!d.success) { alert('Greška: ' + (d.data||'')); return; }
                document.getElementById('single-title-text').textContent = d.data.title;
                document.getElementById('single-title-chars').textContent = d.data.title.length + '/65 karaktera';
                document.getElementById('single-title-result').style.display = 'block';
            })
            .catch(function(){ btn.disabled=false; btn.textContent='✨ Generiši AI Naslov'; });
    });

    document.getElementById('btn-ai-bulk-start').addEventListener('click', function(){
        var btn = this;
        btn.disabled = true;
        document.getElementById('ai-bulk-progress').style.display = 'block';
        document.getElementById('ai-bulk-log').innerHTML = '';
        document.getElementById('ai-bulk-status').textContent = 'Pokretanje...';
        var body = new URLSearchParams({action:'drtechno_ai_title_gen_start', nonce:nonce});
        fetch(olx_sync_vars.ajaxurl, {method:'POST', body:body})
            .then(r=>r.json())
            .then(function(d){
                if (!d.success) {
                    document.getElementById('ai-bulk-status').textContent = '✗ ' + (d.data||'Greška.');
                    btn.disabled = false;
                    return;
                }
                document.getElementById('ai-bulk-status').textContent = d.data;
                processNext();
            });
        function processNext(){
            var pb = new URLSearchParams({action:'drtechno_ai_title_gen_process', nonce:nonce});
            fetch(olx_sync_vars.ajaxurl, {method:'POST', body:pb})
                .then(r=>r.json())
                .then(function(d){
                    if (!d.success) {
                        document.getElementById('ai-bulk-status').textContent = '✗ Greška.';
                        btn.disabled = false;
                        return;
                    }
                    var log = document.getElementById('ai-bulk-log');
                    log.innerHTML += '<div>' + d.data.message + '</div>';
                    log.scrollTop = log.scrollHeight;
                    if (d.data.status === 'complete') {
                        document.getElementById('ai-bulk-status').textContent = '✓ Završeno!';
                        btn.disabled = false;
                    } else {
                        document.getElementById('ai-bulk-status').textContent = 'Ostalo: ' + d.data.left;
                        setTimeout(processNext, 500);
                    }
                });
        }
    });
});
</script>
