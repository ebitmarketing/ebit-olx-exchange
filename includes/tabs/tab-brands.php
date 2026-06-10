<?php
/**
 * Tab 4: Meta Podaci / Brendovi
 * Dostupne varijable iz admin-page.php: $saved_cat_mappings, $available_olx_brands, $saved_brand_mappings, $this
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['brands'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Meta Podaci / Brendovi (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

if (empty($saved_cat_mappings)) {
    echo '<div class="notice notice-warning"><p>Mapirajte kategorije u Tab 3.</p></div>';
}
else { ?>
        <div class="card" style="max-width:100%;padding:20px;margin-top:20px;">
            <h3>1. Preuzmi Podatke</h3>
            <button id="fetch-meta-btn" class="button button-secondary">Preuzmi za mapirane kategorije</button>
            <form method="post" style="display:inline-block;margin-left:10px;"><input type="submit" name="reset_brands_submit" class="button" value="Očisti keš" onclick="return confirm('Sigurni?');" /></form>
            <div id="meta-progress-container" style="display:none;margin-top:15px;"><span class="spinner is-active" style="float:none;"></span><span id="meta-status-text" style="font-weight:bold;margin-left:10px;">Obrađujem...</span></div>
        </div>
        <script>jQuery(document).ready(function($){$('#fetch-meta-btn').click(function(e){e.preventDefault();$(this).prop('disabled',true);$('#meta-progress-container').show();var mc=<?php echo json_encode(array_values(array_unique($saved_cat_mappings))); ?>;var ci=0;function fn(){if(ci>=mc.length){$('#meta-status-text').text('Završeno!').css('color','green');setTimeout(function(){location.reload();},1500);return;}$('#meta-status-text').text('ID: '+mc[ci]);$.post(olx_sync_vars.ajaxurl,{action:'drtechno_fetch_brands',nonce:olx_sync_vars.nonce,cat_id:mc[ci]},function(){$.post(olx_sync_vars.ajaxurl,{action:'drtechno_fetch_attributes',nonce:olx_sync_vars.nonce,cat_id:mc[ci]},function(){ci++;setTimeout(fn,1000);});});}if(mc.length>0)fn();});});</script>

        <?php if (!empty($available_olx_brands)): ?>
        <hr><h3>2. Mapiranje Brendova</h3>
        <form method="post"><?php wp_nonce_field('olx_brand_mapping_action', 'olx_brand_mapping_nonce'); ?>
            <table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:40%;">Vaš Brend</th><th>OLX Brend</th></tr></thead><tbody>
            <?php $wc_brands = get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]);
        foreach ($wc_brands as $wb) {
            $sv = isset($saved_brand_mappings[$wb->term_id]) ? $saved_brand_mappings[$wb->term_id] : '';
            echo "<tr><td><strong>{$wb->name}</strong></td><td><select name='mapped_olx_brand[{$wb->term_id}]' class='olx-select2' style='width:100%;max-width:400px;'><option value=''>-- Ostalo --</option>";
            foreach ($available_olx_brands as $oid => $on)
                echo "<option value='{$oid}' " . selected($sv, $oid, false) . ">" . esc_html($on) . "</option>";
            echo "</select></td></tr>";
        }?>
            </tbody></table><p class="submit"><input type="submit" name="olx_brand_mapping_submit" class="button button-primary" value="Sačuvaj" /></p>
        </form>
        <script>jQuery(document).ready(function($){$('.olx-select2').select2({width:'100%'});});</script>
        <?php
    endif;
}?>
