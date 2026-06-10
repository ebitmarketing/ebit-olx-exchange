<?php
/**
 * Tab: Masovni BUMP (Ručno osvježavanje)
 * Feature gate: mass_bump
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['mass_bump'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Masovni BUMP (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;
?>

        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #0071a1;">
            <h3 style="margin-top:0;color:#0071a1;">Masovno Ručno Osvježavanje (BUMP)</h3>
            <p style="color:#666;">
                Odaberite do 50 artikala koje želite <strong>odmah</strong> osvježiti na OLX-u (podići na vrh pretrage).<br>
                Ova akcija će momentalno potrošiti vaše besplatne bumpove ili OLX kredite. <strong>Nema automatskog ponavljanja.</strong>
            </p>

            <div id="bump-user-stats" style="margin-bottom:20px;display:flex;gap:15px;align-items:center;">
                <div style="flex:1;background:#f6f7f7;border:1px solid #dcdcde;padding:15px;border-radius:4px;text-align:center;">
                    <span style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:5px;">Preostale besplatne obnove</span>
                    <strong id="bump-limit-val" style="font-size:24px;color:#2271b1;">Učitavanje...</strong>
                </div>
                <div style="flex:1;background:#f6f7f7;border:1px solid #dcdcde;padding:15px;border-radius:4px;text-align:center;">
                    <span style="font-size:12px;color:#666;text-transform:uppercase;letter-spacing:1px;display:block;margin-bottom:5px;">Trenutni OLX Krediti</span>
                    <strong id="bump-credits-val" style="font-size:24px;color:#008a20;">Učitavanje...</strong>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row">Odaberi Artikle</th>
                    <td>
                        <select id="manual_bump_product_ids" class="olx-product-search-multi" multiple="multiple" style="width:100%;"></select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="button" id="btn-mass-manual-bump" class="button button-primary button-large">Osvježi odabrane artikle ODMAH</button>
            </p>

            <div id="mass-manual-bump-log" style="display:none;margin-top:15px;padding:10px;background:#f0f0f1;max-height:250px;overflow-y:auto;font-family:monospace;border:1px solid #ddd;"></div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            
            // Fetch stats
            $.post(olx_sync_vars.ajaxurl, {
                action: 'drtechno_get_bump_info',
                nonce: olx_sync_vars.nonce
            }, function(res) {
                if(res.success && res.data) {
                    $('#bump-credits-val').text(res.data.credits + ' OLX');
                    var ostalo = res.data.free_limit - res.data.free_count;
                    if(ostalo < 0) ostalo = 0;
                    
                    $('#bump-limit-val').html(ostalo + ' <span style="font-size:12px;color:#888;">/ '+res.data.free_limit+'</span>');
                    
                    if(ostalo === 0 && res.data.credits < 10) {
                        $('#btn-mass-manual-bump').prop('disabled', true).text('Nemate dovoljno Kredita / Obnova');
                    }
                } else {
                    $('#bump-limit-val').text('Greška');
                    $('#bump-credits-val').text('Greška');
                }
            }).fail(function() {
                $('#bump-limit-val').text('N/A');
                $('#bump-credits-val').text('N/A');
            });
            // Multi-select za proizvode (max 50)
            $('.olx-product-search-multi').select2({
                multiple: true,
                maximumSelectionLength: 50,
                width: '100%',
                placeholder: 'Upišite naziv artikla...',
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

            // Progresivna AJAX obrada bump-ova
            function processManualBump() {
                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_mass_manual_bump_process',
                    nonce: olx_sync_vars.nonce
                }, function(res) {
                    $('#mass-manual-bump-log').prepend('<div>' + res.data.message + '</div>');
                    if (res.data.status === 'processing') {
                        setTimeout(processManualBump, 1000);
                    } else {
                        $('#btn-mass-manual-bump').prop('disabled', false).text('Završeno');
                    }
                }).fail(function() {
                    $('#mass-manual-bump-log').prepend('<div style="color:red;">Greška mreže. Pokušavam ponovo...</div>');
                    setTimeout(processManualBump, 5000);
                });
            }

            $('#btn-mass-manual-bump').click(function(e) {
                e.preventDefault();
                var pids = $('#manual_bump_product_ids').val();
                if (!pids || pids.length === 0) {
                    alert('Molimo odaberite barem jedan artikal!');
                    return;
                }
                if (!confirm('Ovo će potrošiti kredite/besplatne bumpove za ' + pids.length + ' artikala. Da li ste sigurni?')) return;

                $(this).prop('disabled', true).text('Osvježavam...');
                $('#mass-manual-bump-log').show().html('<div style="color:blue;font-weight:bold;">Priprema ' + pids.length + ' artikala...</div>');

                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_mass_manual_bump_start',
                    nonce: olx_sync_vars.nonce,
                    pids: pids
                }, function(res) {
                    if (res.success) {
                        processManualBump();
                    }
                });
            });
        });
        </script>

        <!-- Filtered BUMP -->
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;border-top:3px solid #8b4513;">
            <h3 style="margin-top:0;color:#8b4513;">Filtered BUMP — Filtrirano Osvježavanje</h3>
            <p style="color:#666;">
                Osvježi ili obriši+ponovo objavi artikle filtrirane po WC kategoriji, brendu i OLX statusu.<br>
                <strong>Osvježi</strong> — BUMP listing (podigne na vrh). <strong>Obriši + Ponovo objavi</strong> — briše s OLX-a i vraća u red čekanja za novu objavu.
            </p>

            <table class="form-table">
                <tr>
                    <th>WC Kategorija</th>
                    <td><?php wp_dropdown_categories([
                        'taxonomy'         => 'product_cat',
                        'id'               => 'filtered_bump_wc_cat',
                        'name'             => 'filtered_bump_wc_cat',
                        'show_option_all'  => 'Sve kategorije',
                        'value_field'      => 'term_id',
                        'hide_empty'       => false,
                    ]); ?></td>
                </tr>
                <tr>
                    <th>Brend (slug)</th>
                    <td>
                        <?php if ( taxonomy_exists( 'product_brand' ) ): ?>
                            <select id="filtered_bump_brand" style="min-width:240px;">
                                <option value="">Svi brendovi</option>
                                <?php foreach ( get_terms( [ 'taxonomy' => 'product_brand', 'hide_empty' => false ] ) as $b ): ?>
                                    <option value="<?php echo esc_attr( $b->slug ); ?>"><?php echo esc_html( $b->name ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        <?php else: ?>
                            <input type="text" id="filtered_bump_brand" placeholder="brand-slug (opcionalno)" style="min-width:240px;">
                            <p class="description" style="margin:4px 0 0;color:#888;">Taksonomija <code>product_brand</code> ne postoji — unesite slug ručno ili ostavite prazno.</p>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th>OLX Status</th>
                    <td>
                        <select id="filtered_bump_olx_status">
                            <option value="active">Aktivni</option>
                            <option value="hidden">Skriveni</option>
                            <option value="all">Svi</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Akcija</th>
                    <td>
                        <select id="filtered_bump_action">
                            <option value="refresh">Osvježi (BUMP)</option>
                            <option value="delete">Obriši + Ponovo objavi</option>
                        </select>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="button" id="btn-filtered-bump-start" class="button button-primary">Pokreni Filtered BUMP</button>
            </p>

            <div id="filtered-bump-progress" style="display:none;margin-top:15px;">
                <div style="background:#f6f7f7;padding:10px;border:1px solid #ddd;max-height:200px;overflow-y:auto;" id="filtered-bump-log"></div>
                <p id="filtered-bump-status" style="color:#888;margin-top:8px;"></p>
            </div>

            <script>
            (function(){
                var nonce = '<?php echo esc_js(wp_create_nonce('olx_sync_nonce')); ?>';
                document.getElementById('btn-filtered-bump-start').addEventListener('click', function(){
                    var btn = this;
                    btn.disabled = true;
                    document.getElementById('filtered-bump-progress').style.display = 'block';
                    document.getElementById('filtered-bump-log').innerHTML = '';
                    document.getElementById('filtered-bump-status').textContent = 'Pokretanje...';

                    var body = new URLSearchParams({
                        action: 'drtechno_filtered_bump_start',
                        nonce: nonce,
                        bump_action: document.getElementById('filtered_bump_action').value,
                        wc_cat_id: document.getElementById('filtered_bump_wc_cat').value,
                        olx_status: document.getElementById('filtered_bump_olx_status').value,
                        brand_name: (document.getElementById('filtered_bump_brand') || {value:''}).value,
                    });

                    fetch(ajaxurl, {method:'POST', body:body})
                        .then(r=>r.json())
                        .then(function(d){
                            if (!d.success) {
                                document.getElementById('filtered-bump-status').textContent = '✗ ' + (d.data||'Greška.');
                                btn.disabled = false;
                                return;
                            }
                            document.getElementById('filtered-bump-status').textContent = d.data;
                            processNext();
                        });

                    function processNext(){
                        var pb = new URLSearchParams({action:'drtechno_filtered_bump_process', nonce:nonce});
                        fetch(ajaxurl, {method:'POST', body:pb})
                            .then(r=>r.json())
                            .then(function(d){
                                if (!d.success) { document.getElementById('filtered-bump-status').textContent='✗ Greška.'; btn.disabled=false; return; }
                                var log = document.getElementById('filtered-bump-log');
                                log.innerHTML += '<div>' + d.data.message + '</div>';
                                log.scrollTop = log.scrollHeight;
                                if (d.data.status === 'complete') {
                                    document.getElementById('filtered-bump-status').textContent = '✓ Završeno!';
                                    btn.disabled = false;
                                } else {
                                    document.getElementById('filtered-bump-status').textContent = 'Ostalo: ' + d.data.left;
                                    setTimeout(processNext, 300);
                                }
                            });
                    }
                });
            })();
            </script>
        </div>
