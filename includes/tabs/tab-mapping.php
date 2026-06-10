<?php
/**
 * Tab 3: Mapiranje kategorija (WC → OLX)
 * Dostupne varijable iz admin-page.php: $license_key, $leaf_cats, $saved_cat_mappings, $this
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['mapping'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Mapiranje kategorija (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

if (empty($license_key)) {
    echo '<div class="notice notice-error"><p>Unesite licencni ključ u Tab 1.</p></div>';
}
else { ?>
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;">
            <h3>📂 OLX Kategorije (Sa Servera)</h3>
            <p style="color:#666;">Kategorije se automatski keširaju svakih sat vremena sa vašeg centralnog servera.</p>
            <?php if (!empty($leaf_cats)): ?>
                <p style="color:green;font-size:16px;margin:15px 0;"><strong>✓ Spemno: <?php echo count($leaf_cats); ?> kategorija mapirano.</strong></p>
            <?php
    else: ?>
                <p style="color:#d63638;font-size:14px;margin:15px 0;"><strong>⚠ Nema kategorija. Pokrenite Crawler na vašem Server Admin panelu!</strong></p>
            <?php
    endif; ?>
            <button id="refresh-cats-btn" class="button button-secondary">🔄 Prisilno osvježi sa servera sad</button>
            <span class="spinner" id="cats-spinner" style="float:none;margin-left:10px;"></span>
            <div id="cats-result" style="margin-top:10px;"></div>
        </div>
        <script>jQuery(document).ready(function($){
            $('#refresh-cats-btn').click(function(e){
                e.preventDefault(); $(this).prop('disabled',true); $('#cats-spinner').addClass('is-active'); $('#cats-result').html('');
                $.post(olx_sync_vars.ajaxurl, {action:'drtechno_fetch_categories', nonce:olx_sync_vars.nonce}, function(r){
                    if(r.success) { $('#cats-result').html('<div style="color:green;font-weight:bold;">✓ '+r.data+'</div>'); setTimeout(function(){location.reload();},1500); }
                    else { $('#cats-result').html('<div style="color:#d63638;">'+r.data+'</div>'); $('#refresh-cats-btn').prop('disabled',false); $('#cats-spinner').removeClass('is-active'); }
                }).fail(function(){ $('#cats-result').html('<div style="color:red;">Greška povezivanja sa serverom.</div>'); $('#refresh-cats-btn').prop('disabled',false); $('#cats-spinner').removeClass('is-active'); });
            });
        });</script>
        <?php
}?>
        <hr>
        <form method="post"><?php wp_nonce_field('olx_mapping_action', 'olx_mapping_nonce'); ?>
            <p><input type="text" id="cat-search-filter" placeholder="🔍 Brza pretraga..." style="width:100%;max-width:400px;padding:8px;margin-bottom:10px;"></p>
            <table class="wp-list-table widefat fixed striped" id="mapping-table"><thead><tr><th>WooCommerce Kategorija</th><th>OLX Kategorija</th></tr></thead><tbody>
            <?php $wc_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
foreach ($wc_cats as $wc) {
    $sv = isset($saved_cat_mappings[$wc->term_id]) ? $saved_cat_mappings[$wc->term_id] : '';
    echo "<tr><td><strong>{$wc->name}</strong></td><td><select name='mapped_olx_cat[{$wc->term_id}]' class='olx-select2' style='width:100%;max-width:400px;'><option value=''>-- Ne sinhronizuj --</option>";
    if (!empty($leaf_cats))
        foreach ($leaf_cats as $cr) {
            $cid = $cr['id'] ?? '';
            $cpath = $cr['path'] ?? '';
            echo "<option value='{$cid}' " . selected($sv, $cid, false) . ">" . esc_html($cpath) . "</option>";
        }
    echo "</select></td></tr>";
}?>
            </tbody></table><p class="submit"><input type="submit" name="olx_mapping_submit" class="button button-primary" value="Sačuvaj" /></p>
        </form>
        <script>
        jQuery(document).ready(function($){
            $('.olx-select2').each(function() {
                $(this).select2({
                    width: '100%',
                    placeholder: '-- Ne sinhronizuj --',
                    dropdownParent: $(this).parent()
                });
            });
            $('#cat-search-filter').on('keyup',function(){
                var v=$(this).val().toLowerCase();
                $('#mapping-table tbody tr').filter(function(){
                    $(this).toggle($(this).text().toLowerCase().indexOf(v)>-1);
                });
            });
        });
        </script>
