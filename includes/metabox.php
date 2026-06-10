<?php
/**
 * Product Metabox - renderirano unutar WooCommerce proizvoda
 * Identičan UI kao u originalnom pluginu.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

wp_nonce_field( 'olx_save_product', 'olx_product_nonce' );
$mapped_cats = get_option( 'drtechno_olx_category_mapping', [] );
$product_cats = wp_get_post_terms( $post->ID, 'product_cat', ['fields' => 'ids'] );
$olx_cat_id = false;
foreach ( $product_cats as $cat_id ) { if ( isset($mapped_cats[$cat_id]) && !empty($mapped_cats[$cat_id]) ) { $olx_cat_id = $mapped_cats[$cat_id]; break; } }

if ( !$olx_cat_id ) { echo '<div style="padding:10px; background:#fcf0f1; border-left:4px solid #d63638;"><p>Ovaj proizvod se nalazi u WooCommerce kategoriji koja <strong>nije mapirana</strong> sa OLX-om.</p></div>'; return; }

$olx_article_id = get_post_meta( $post->ID, '_olx_article_id', true );
$olx_status = get_post_meta( $post->ID, '_olx_status', true );
$olx_last_sync = get_post_meta( $post->ID, '_olx_last_sync', true );
$error = get_post_meta( $post->ID, '_olx_sync_error', true );

echo '<div style="background:#fff; border:1px solid #ccc; padding:15px; margin-bottom:20px; border-radius:4px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;">';
echo '<div style="flex:1; min-width:300px;">';

if ( $olx_article_id ) {
    if($error) echo '<div style="color:#d63638; margin-bottom:10px;"><strong>⚠️ Greška:</strong> '.$error.'</div>';
    $sc = ($olx_status == 'active') ? 'green' : (($olx_status == 'hidden') ? '#777' : '#ffb900');
    $st = ($olx_status == 'active') ? 'Aktivno' : (($olx_status == 'hidden') ? 'Sakriveno' : 'Draft');
    echo '<h4 style="margin:0; color:'.$sc.';">✓ Artikal je na OLX-u</h4><p style="margin:5px 0 0 0;">OLX ID: <a href="https://olx.ba/artikal/'.esc_attr($olx_article_id).'" target="_blank"><strong>'.esc_html($olx_article_id).'</strong></a> | Status: <strong>'.$st.'</strong> | Sync: <strong>'.($olx_last_sync ? date('d.m.Y H:i', strtotime($olx_last_sync)) : '?').'</strong></p>';
} else {
    $is_ready = true;
    $all_category_attrs = get_option('drtechno_olx_category_attributes', []);
    if (isset($all_category_attrs[$olx_cat_id])) { foreach ($all_category_attrs[$olx_cat_id] as $attr) { if ($attr['required'] && get_post_meta($post->ID, '_olx_attr_'.$attr['name'], true) === '') { $is_ready = false; break; } } }
    if ($error) { echo '<div style="color:#d63638; margin-bottom:10px;"><strong>⚠️ Greška:</strong> '.$error.'</div><h4 style="margin:0; color:#d63638;">✗ Artikal nije na OLX-u</h4>'; }
    elseif ($is_ready) { echo '<h4 style="margin:0; color:green;">✓ SPREMAN za OLX</h4>'; }
    else { echo '<h4 style="margin:0; color:#ffb900;">⚠️ Popunite obavezne atribute</h4>'; }
}
echo '</div><div style="margin-top:10px;">';
if ($olx_article_id) { echo '<button type="button" class="button" style="color:#0071a1; border-color:#0071a1; font-weight:bold; margin-right:5px;" id="btn-refresh-olx" data-post-id="'.$post->ID.'">OSVJEŽI (BUMP)</button> <button type="button" class="button button-primary" id="btn-publish-olx" data-post-id="'.$post->ID.'">AŽURIRAJ</button> <button type="button" class="button" style="color:#d63638; border-color:#d63638; margin-left:5px;" id="btn-delete-olx" data-post-id="'.$post->ID.'">OBRIŠI</button>'; }
else { echo '<button type="button" class="button button-primary button-large" id="btn-publish-olx" data-post-id="'.$post->ID.'">KREIRAJ NA OLX-u</button>'; }
echo '<span class="spinner" id="publish-spinner" style="float:none; margin-left:10px;"></span></div></div><div id="publish-response" style="margin-bottom:15px;"></div>';

// Stanje artikla
$saved_state = get_post_meta($post->ID, '_olx_state', true) ?: 'new';
echo '<table class="form-table"><tbody><tr><th><label style="font-weight:bold;">Stanje artikla <span style="color:red">*</span></label></th><td><select name="olx_state" style="max-width:300px;"><option value="new" '.selected($saved_state,'new',false).'>Novo</option><option value="used" '.selected($saved_state,'used',false).'>Korišteno</option></select></td></tr>';

// Atributi
$all_category_attrs = get_option('drtechno_olx_category_attributes', []);
if (!isset($all_category_attrs[$olx_cat_id]) || empty($all_category_attrs[$olx_cat_id])) {
    echo '<tr><td colspan="2"><p style="color:#d63638;">Atributi nisu preuzeti za ovu kategoriju.</p><button type="button" class="button" id="btn-fetch-product-attrs" data-cat="'.$olx_cat_id.'">Preuzmi atribute</button><span class="spinner" id="attr-spinner" style="float:none;"></span></td></tr>';
    echo '<script>jQuery(document).ready(function($){$("#btn-fetch-product-attrs").click(function(e){e.preventDefault();$(this).prop("disabled",true);$("#attr-spinner").addClass("is-active");$.post(olx_sync_vars.ajaxurl,{action:"drtechno_fetch_attributes",nonce:olx_sync_vars.nonce,cat_id:$(this).data("cat")},function(){location.reload();});});});</script>';
} else {
    foreach ($all_category_attrs[$olx_cat_id] as $attr) {
        $slug = $attr['name']; $mk = '_olx_attr_'.$slug; $sv = get_post_meta($post->ID, $mk, true); $req = $attr['required'] ? '<span style="color:red">*</span>' : '';
        echo '<tr><th><label>'.esc_html($attr['display_name']).' '.$req.'</label></th><td>';
        if ($attr['input_type']==='select' && !empty($attr['options'])) { echo '<select name="olx_attr['.$slug.']" style="width:100%;max-width:300px;"><option value="">-- Odaberi --</option>'; foreach($attr['options'] as $o) echo '<option value="'.esc_attr($o).'" '.selected($sv,$o,false).'>'.esc_html($o).'</option>'; echo '</select>'; }
        elseif ($attr['input_type']==='checkbox') { echo '<input type="checkbox" name="olx_attr['.$slug.']" value="1" '.checked($sv,'1',false).'> Da'; }
        else { $t=($attr['type']=='number')?'number':'text'; echo '<input type="'.$t.'" name="olx_attr['.$slug.']" value="'.esc_attr($sv).'" class="regular-text">'; }
        echo '</td></tr>';
    }
}
echo '</tbody></table>';
?>
<script>
jQuery(document).ready(function($){
    function dis(){$('#btn-publish-olx,#btn-delete-olx,#btn-refresh-olx').prop('disabled',true);$('#publish-spinner').addClass('is-active');$('#publish-response').html('');}
    function en(){$('#btn-publish-olx,#btn-delete-olx,#btn-refresh-olx').prop('disabled',false);$('#publish-spinner').removeClass('is-active');}
    function err(r){var m=r.data.message||r.data;var d=r.data.raw?'<br><textarea style="width:100%;height:80px;margin-top:10px;font-family:monospace;">'+JSON.stringify(r.data.raw,null,2)+'</textarea>':'';$('#publish-response').html('<div style="color:#d63638;font-weight:bold;padding:10px;background:#fcf0f1;border-left:4px solid #d63638;">Greška: '+m+d+'</div>');}
    $('#btn-publish-olx').click(function(e){e.preventDefault();dis();$.post(olx_sync_vars.ajaxurl,{action:'drtechno_publish_to_olx',nonce:olx_sync_vars.nonce,post_id:$(this).data('post-id')},function(r){if(r.success){$('#publish-response').html('<div style="color:green;padding:10px;background:#e5f9e7;">'+r.data+'</div>');setTimeout(function(){location.reload();},2000);}else{err(r);en();}}).fail(function(x){en();$('#publish-response').html('<div style="color:red;">Error '+x.status+'</div>');});});
    $('#btn-refresh-olx').click(function(e){e.preventDefault();dis();$.post(olx_sync_vars.ajaxurl,{action:'drtechno_refresh_olx',nonce:olx_sync_vars.nonce,post_id:$(this).data('post-id')},function(r){if(r.success){$('#publish-response').html('<div style="color:green;padding:10px;background:#e5f9e7;">'+r.data+'</div>');setTimeout(function(){location.reload();},2000);}else{err(r);en();}}).fail(function(x){en();$('#publish-response').html('<div style="color:red;">Error '+x.status+'</div>');});});
    $('#btn-delete-olx').click(function(e){e.preventDefault();if(confirm('Obrisati?')){dis();$.post(olx_sync_vars.ajaxurl,{action:'drtechno_delete_from_olx',nonce:olx_sync_vars.nonce,post_id:$(this).data('post-id')},function(r){if(r.success){$('#publish-response').html('<div style="color:green;padding:10px;background:#e5f9e7;">'+r.data+'</div>');setTimeout(function(){location.reload();},2000);}else{err(r);en();}}).fail(function(x){en();$('#publish-response').html('<div style="color:red;">Error '+x.status+'</div>');});}});
});
</script>
