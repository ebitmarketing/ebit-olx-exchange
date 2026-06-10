<?php
/**
 * Tab 6: Zadani Atributi (Default Values)
 * Dostupne varijable iz admin-page.php: $saved_cat_mappings, $leaf_cats, $this
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['default_attrs'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Zadani Atributi (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

$default_attrs = get_option('drtechno_olx_default_attributes', []);
$all_cat_attrs = get_option('drtechno_olx_category_attributes', []);

// Brisanje zadanih atributa za kategoriju
if (isset($_GET['delete_default_cat']) && check_admin_referer('olx_delete_default_action', 'olx_delete_default_nonce') && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $del_id = intval($_GET['delete_default_cat']);
    if (isset($default_attrs[$del_id])) {
        unset($default_attrs[$del_id]);
        update_option('drtechno_olx_default_attributes', $default_attrs);
        echo '<div class="notice notice-success is-dismissible"><p>Zadani atributi za kategoriju su obrisani.</p></div>';
    }
}
?>
        <div class="card" style="max-width:800px;padding:20px;margin-top:20px;">
            <h3>Zadani Atributi (Default Values)</h3>
            <p style="color:#666;">Ovdje možete definisati početne vrijednosti atributa za pojedine WooCommerce kategorije. Ako proizvod iz te kategorije nema ručno podešene OLX atribute (ili mu nedostaje odabrano polje), plugin će automatski iskoristiti ove sačuvane vrijednosti pri slanju na OLX.</p>
            
            <?php if (empty($saved_cat_mappings)): ?>
                <div class="notice notice-warning inline"><p>Prvo morate mapirati kategorije u Tabu 3, a zatim preuzeti atribute u Tabu 4.</p></div>
            <?php
else: ?>
                <div style="background:#f0f6fc;padding:15px;border:1px solid #c3c4c7;border-radius:4px;margin-bottom:20px;">
                    <h4 style="margin-top:0;">1. Odaberite Kategoriju i Podesite Atribute</h4>
                    <table class="form-table">
                        <tr>
                            <th style="width:200px;"><label>WooCommerce Kategorija</label></th>
                            <td>
                                <select id="default-attr-cat-select" class="olx-select2" style="width:100%;max-width:400px;">
                                    <option value="">-- Odaberite kategoriju --</option>
                                    <?php
    $wc_cats = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
    foreach ($wc_cats as $wc) {
        if (isset($saved_cat_mappings[$wc->term_id]) && !empty($saved_cat_mappings[$wc->term_id])) {
            echo '<option value="' . esc_attr($wc->term_id) . '" data-olx-cat="' . esc_attr($saved_cat_mappings[$wc->term_id]) . '">' . esc_html($wc->name) . '</option>';
        }
    }
?>
                                </select>
                            </td>
                        </tr>
                    </table>
                    
                    <div id="default-attr-form-container" style="display:none;margin-top:15px;border-top:1px solid #ddd;padding-top:15px;">
                        <!-- Ovdje se AJAX-om ucitava forma -->
                        <span class="spinner" id="default-attr-spinner" style="float:none;"></span>
                        <div id="default-attr-form-content"></div>
                    </div>
                </div>

                <script>
                jQuery(document).ready(function($){
                    $('.olx-select2').select2({width:'100%'});
                    
                    $('#default-attr-cat-select').on('change', function(){
                        var wc_cat_id = $(this).val();
                        var olx_cat_id = $(this).find('option:selected').data('olx-cat');
                        
                        if(!wc_cat_id) {
                            $('#default-attr-form-container').hide();
                            return;
                        }
                        
                        $('#default-attr-form-container').show();
                        $('#default-attr-spinner').addClass('is-active');
                        $('#default-attr-form-content').html('');
                        
                        // Učitaj atribute AJAX-om
                        $.post(olx_sync_vars.ajaxurl, {
                            action: 'drtechno_get_cat_default_form',
                            nonce: olx_sync_vars.nonce,
                            wc_cat_id: wc_cat_id,
                            olx_cat_id: olx_cat_id
                        }, function(response) {
                            $('#default-attr-spinner').removeClass('is-active');
                            if(response.success) {
                                $('#default-attr-form-content').html(response.data);
                            } else {
                                $('#default-attr-form-content').html('<div style="color:red;">Atributi za ovu kategoriju nisu preuzeti. Otiđite u Tab 4 (Meta Podaci) i kliknite "Preuzmi".</div>');
                            }
                        }).fail(function(){
                            $('#default-attr-spinner').removeClass('is-active');
                            $('#default-attr-form-content').html('<div style="color:red;">Greška pri učitavanju forme.</div>');
                        });
                    });
                });
                </script>

                <?php if (!empty($default_attrs)): ?>
                <div style="margin-top:30px;">
                    <h4>Podešeni Zadani Atributi</h4>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th>Kategorija</th>
                                <th>Mapirane vrijednosti</th>
                                <th style="width:100px;">Akcija</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($default_attrs as $cat_id => $vals):
            $cat = get_term($cat_id, 'product_cat');
            if (!$cat || is_wp_error($cat))
                continue;
            $display_vals = [];
            $olx_cat = $saved_cat_mappings[$cat_id] ?? '';
            $attrs_def = $all_cat_attrs[$olx_cat] ?? [];

            foreach ($vals as $k => $v) {
                if (empty($v))
                    continue;
                if ($k === 'olx_state') {
                    $v_disp = ($v === 'new') ? 'Novo' : (($v === 'used') ? 'Korišteno' : $v);
                    $display_vals[] = "<strong>Stanje:</strong> {$v_disp}";
                }
                else {
                    $attr_name = $k;
                    // Pokušaj naći lijepo ime
                    foreach ($attrs_def as $ad) {
                        if ($ad['name'] == $k) {
                            $attr_name = $ad['display_name'];
                            break;
                        }
                    }
                    $v_disp = ($v === '1') ? 'Da' : $v;
                    $display_vals[] = "<strong>" . esc_html($attr_name) . ":</strong> " . esc_html($v_disp);
                }
            }
            $delete_url = wp_nonce_url(admin_url('admin.php?page=drtechno_olx_sync&tab=default_attrs&delete_default_cat=' . $cat_id), 'olx_delete_default_action', 'olx_delete_default_nonce');
?>
                            <tr>
                                <td><strong><?php echo esc_html($cat->name); ?></strong></td>
                                <td><?php echo implode(' | ', $display_vals); ?></td>
                                <td><a href="<?php echo $delete_url; ?>" class="button button-small" onclick="return confirm('Ukloniti zadane atribute za ovu kategoriju?');">Ukloni</a></td>
                            </tr>
                            <?php
        endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php
    endif; ?>
            <?php
endif; ?>
        </div>
