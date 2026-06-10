<?php
/**
 * Tab: Pravila Cijena
 * Feature gate: price_rules
 */
if (!defined('ABSPATH'))
    exit;

$features = get_option('drtechno_olx_license_features', []);
if (empty($features['price_rules'])):
?>
    <div class="card" style="max-width:800px;padding:20px;margin-top:20px;background:#fcf0f1;border-left:4px solid #d63638;opacity:0.85;">
        <h3 style="margin-top:0;color:#d63638;">Pravila Cijena (Zaključano)</h3>
        <p>Vaš plan ne uključuje ovu funkcionalnost. <strong>Nadogradite plan.</strong></p>
    </div>
<?php
    return;
endif;

// Pomoćna funkcija za markiranje artikala pogođenih pravilom
if (!function_exists('drtechno_olx_queue_rule_products')) {
    function drtechno_olx_queue_rule_products($rule) {
        global $wpdb;
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => []
        ];
        
        if (!empty($rule['cat'])) {
            $args['tax_query'][] = ['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $rule['cat']];
        }
        if (!empty($rule['brand']) && taxonomy_exists('product_brand')) {
            $args['tax_query'][] = ['taxonomy' => 'product_brand', 'field' => 'term_id', 'terms' => $rule['brand']];
        }
        if (!empty($rule['supplier']) && taxonomy_exists('ebit_supplier')) {
            $args['tax_query'][] = ['taxonomy' => 'ebit_supplier', 'field' => 'term_id', 'terms' => $rule['supplier']];
        }
        
        $query = new WP_Query($args);
        $upload_dir = wp_upload_dir();
        $frame_dir = trailingslashit($upload_dir['basedir']) . 'olx-frames/';
        
        foreach ($query->posts as $pid) {
            // Ubaci u queue
            $wpdb->query($wpdb->prepare(
                "INSERT IGNORE INTO {$wpdb->prefix}drtechno_olx_prod_queue (post_id) VALUES (%d)",
                $pid
            ));
            
            // Obriši keširanu sliku
            $cache_file = $frame_dir . 'olx_frame_prod_' . $pid . '.jpg';
            if (file_exists($cache_file)) {
                unlink($cache_file);
            }
        }
    }
}

// Form handling — dodavanje pravila
if (isset($_POST['olx_save_price_rule_submit']) && check_admin_referer('olx_price_rule_action', 'olx_price_rule_nonce')) {
    $rules = get_option('drtechno_olx_price_rules', []);
    $new_rule = [
        'cat'      => intval($_POST['rule_cat']),
        'brand'    => intval($_POST['rule_brand']),
        'supplier' => intval($_POST['rule_supplier']),
        'op'       => sanitize_text_field($_POST['rule_op']),
        'type'     => sanitize_text_field($_POST['rule_type']),
        'val'      => floatval($_POST['rule_val']),
    ];
    $rules[] = $new_rule;
    update_option('drtechno_olx_price_rules', $rules);
    
    // Markiraj artikle za brisanje bedževa i sync
    drtechno_olx_queue_rule_products($new_rule);
    
    echo '<div class="notice notice-success is-dismissible"><p>Pravilo za cijene uspješno dodato! Zahvaćeni artikli će se uskoro automatski ažurirati na OLX-u.</p></div>';
}

// Brisanje pravila
if (isset($_GET['delete_price_rule']) && isset($_GET['tab']) && $_GET['tab'] === 'price_rules' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $del_id = intval($_GET['delete_price_rule']);
    $rules = get_option('drtechno_olx_price_rules', []);
    if (isset($rules[$del_id])) {
        $deleted_rule = $rules[$del_id];
        unset($rules[$del_id]);
        update_option('drtechno_olx_price_rules', array_values($rules));
        
        // Markiraj artikle za brisanje bedževa i sync
        drtechno_olx_queue_rule_products($deleted_rule);
        
        echo '<div class="notice notice-success is-dismissible"><p>Pravilo za cijene uspješno obrisano! Zahvaćeni artikli će se uskoro automatski ažurirati na OLX-u.</p></div>';
    }
}

$wc_categories = taxonomy_exists('product_cat') ? get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]) : [];
$wc_brands = taxonomy_exists('product_brand') ? get_terms(['taxonomy' => 'product_brand', 'hide_empty' => false]) : [];
$wc_suppliers = taxonomy_exists('ebit_supplier') ? get_terms(['taxonomy' => 'ebit_supplier', 'hide_empty' => false]) : [];
$rules = get_option('drtechno_olx_price_rules', []);

$get_term_name = function ($id, $terms) {
    if (!$id) return '<b>SVE</b>';
    if (!is_wp_error($terms)) {
        foreach ($terms as $t) {
            if ($t->term_id == $id) return esc_html($t->name);
        }
    }
    return 'Nepoznato';
};
?>

        <div style="display:flex;gap:20px;flex-wrap:wrap;margin-top:20px;">

            <!-- Forma za novo pravilo -->
            <div class="card" style="flex:1;min-width:350px;padding:20px;">
                <h3 style="margin-top:0;">Kreiraj Novo Pravilo</h3>
                <form method="post" action="?page=drtechno_olx_sync&tab=price_rules">
                    <?php wp_nonce_field('olx_price_rule_action', 'olx_price_rule_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row">Kategorija</th>
                            <td>
                                <select name="rule_cat" class="olx-select2" style="width:100%;">
                                    <option value="">-- SVE KATEGORIJE --</option>
                                    <?php if (!is_wp_error($wc_categories)): foreach ($wc_categories as $c): ?>
                                        <option value="<?php echo esc_attr($c->term_id); ?>"><?php echo esc_html($c->name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Brend</th>
                            <td>
                                <select name="rule_brand" class="olx-select2" style="width:100%;">
                                    <option value="">-- SVI BRENDOVI --</option>
                                    <?php if (!is_wp_error($wc_brands)): foreach ($wc_brands as $b): ?>
                                        <option value="<?php echo esc_attr($b->term_id); ?>"><?php echo esc_html($b->name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </td>
                        </tr>
                        <?php if (taxonomy_exists('ebit_supplier')): ?>
                        <tr>
                            <th scope="row">Dobavljač</th>
                            <td>
                                <select name="rule_supplier" class="olx-select2" style="width:100%;">
                                    <option value="">-- SVI DOBAVLJAČI --</option>
                                    <?php if (!is_wp_error($wc_suppliers)): foreach ($wc_suppliers as $s): ?>
                                        <option value="<?php echo esc_attr($s->term_id); ?>"><?php echo esc_html($s->name); ?></option>
                                    <?php endforeach; endif; ?>
                                </select>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th scope="row">Akcija</th>
                            <td>
                                <div style="display:flex;gap:10px;">
                                    <select name="rule_op" style="width:100px;">
                                        <option value="-">Smanji (-)</option>
                                        <option value="+">Povećaj (+)</option>
                                    </select>
                                    <input type="number" name="rule_val" step="0.01" required style="width:100px;" placeholder="Iznos" />
                                    <select name="rule_type" style="width:100px;">
                                        <option value="%">% (Postotak)</option>
                                        <option value="KM">KM (Fiksno)</option>
                                    </select>
                                </div>
                            </td>
                        </tr>
                    </table>
                    <p class="submit" style="margin-bottom:0;">
                        <input type="submit" name="olx_save_price_rule_submit" class="button button-primary" value="Dodaj Pravilo" />
                    </p>
                </form>
            </div>

            <!-- Lista aktivnih pravila -->
            <div class="card" style="flex:2;min-width:400px;padding:20px;background:#f9f9f9;">
                <h3 style="margin-top:0;">Aktivna Pravila</h3>
                <?php if (empty($rules)): ?>
                    <p style="color:#888;">Trenutno nemate aktivnih pravila.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr><th>Uslov (Kat + Brend + Dobavlj)</th><th>Akcija</th><th></th></tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rules as $index => $r):
                                $cond = $get_term_name($r['cat'], $wc_categories) . ' + ' . $get_term_name($r['brand'], $wc_brands);
                                if (taxonomy_exists('ebit_supplier'))
                                    $cond .= ' + ' . $get_term_name($r['supplier'] ?? 0, $wc_suppliers);
                                $action_text = ($r['op'] === '+' ? '<span style="color:green;font-weight:bold;">Povećaj za</span> ' : '<span style="color:red;font-weight:bold;">Smanji za</span> ') . esc_html($r['val']) . esc_html($r['type']);
                            ?>
                            <tr>
                                <td><?php echo $cond; ?></td>
                                <td><?php echo $action_text; ?></td>
                                <td style="text-align:right;">
                                    <a href="?page=drtechno_olx_sync&tab=price_rules&delete_price_rule=<?php echo $index; ?>" class="button button-small" style="color:#d63638;border-color:#d63638;" onclick="return confirm('Obrisati pravilo?')">Obriši</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

        </div>

        <script>jQuery(document).ready(function($){ $('.olx-select2').select2({ width: '100%' }); });</script>
