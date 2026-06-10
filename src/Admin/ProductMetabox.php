<?php

namespace EbitOlx\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Renders and saves the OLX product metabox on individual product edit pages.
 */
class ProductMetabox {

    public function register(): void {
        add_action( 'add_meta_boxes',      [ $this, 'addMetabox' ] );
        add_action( 'save_post_product',   [ $this, 'save' ] );
    }

    /* ------------------------------------------------------------------ */
    /*  Add metabox                                                       */
    /* ------------------------------------------------------------------ */

    public function addMetabox(): void {
        add_meta_box(
            'drtechno_olx_metabox_main',
            'OLX.ba Podaci za Sinhronizaciju',
            [ $this, 'render' ],
            'product',
            'normal',
            'high'
        );
    }

    /* ------------------------------------------------------------------ */
    /*  Save                                                              */
    /* ------------------------------------------------------------------ */

    /**
     * @param int $post_id
     */
    public function save( int $post_id ): void {
        if ( $this->isAutomatedContext() ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! is_admin() || empty( $_POST ) ) return;

        if ( ! isset( $_POST['olx_product_nonce'] ) || ! wp_verify_nonce( $_POST['olx_product_nonce'], 'olx_save_product' ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        // Exclude checkbox
        if ( isset( $_POST['olx_exclude_sync'] ) ) {
            update_post_meta( $post_id, '_olx_exclude_sync', 'yes' );
        } else {
            delete_post_meta( $post_id, '_olx_exclude_sync' );
        }

        // Custom OLX title (_olx_title)
        if ( isset( $_POST['olx_custom_title'] ) ) {
            $raw = sanitize_text_field( wp_unslash( $_POST['olx_custom_title'] ) );
            $raw = mb_substr( $raw, 0, 65, 'UTF-8' );
            if ( $raw === '' ) {
                delete_post_meta( $post_id, '_olx_title' );
            } else {
                update_post_meta( $post_id, '_olx_title', $raw );
            }
        }

        // VIP price
        if ( isset( $_POST['olx_special_price'] ) ) {
            if ( $_POST['olx_special_price'] === '' ) {
                delete_post_meta( $post_id, '_olx_special_price' );
            } else {
                update_post_meta( $post_id, '_olx_special_price', floatval( $_POST['olx_special_price'] ) );
            }
        }

        // State
        if ( isset( $_POST['olx_state'] ) ) {
            if ( $_POST['olx_state'] === '' ) {
                delete_post_meta( $post_id, '_olx_state' );
            } else {
                update_post_meta( $post_id, '_olx_state', sanitize_text_field( $_POST['olx_state'] ) );
            }
        }

        // Attributes
        if ( isset( $_POST['olx_attr'] ) && is_array( $_POST['olx_attr'] ) ) {
            foreach ( $_POST['olx_attr'] as $key => $val ) {
                $meta_key = '_olx_attr_' . sanitize_text_field( $key );
                if ( empty( $val ) && $val !== '0' ) {
                    delete_post_meta( $post_id, $meta_key );
                } else {
                    update_post_meta( $post_id, $meta_key, sanitize_text_field( $val ) );
                }
            }
        }

        delete_post_meta( $post_id, '_olx_sync_error' );
    }

    /* ------------------------------------------------------------------ */
    /*  Render                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * @param \WP_Post $post
     */
    public function render( $post ): void {
        wp_nonce_field( 'olx_save_product', 'olx_product_nonce' );

        $is_excluded = get_post_meta( $post->ID, '_olx_exclude_sync', true ) === 'yes';
        $vip_price   = get_post_meta( $post->ID, '_olx_special_price', true );

        $mapped_cats  = get_option( 'drtechno_olx_category_mapping', [] );
        $product_cats = wp_get_post_terms( $post->ID, 'product_cat', [ 'fields' => 'ids' ] );

        $olx_cat_id       = false;
        $primary_wc_cat_id = false;

        foreach ( $product_cats as $cat_id ) {
            if ( isset( $mapped_cats[ $cat_id ] ) && ! empty( $mapped_cats[ $cat_id ] ) ) {
                $olx_cat_id        = $mapped_cats[ $cat_id ];
                $primary_wc_cat_id = $cat_id;
                break;
            }
        }

        if ( ! $olx_cat_id ) {
            echo '<div style="padding:10px; background:#fcf0f1; border-left:4px solid #d63638;">';
            echo '<p>Ovaj proizvod se nalazi u WooCommerce kategoriji koja <strong>nije mapirana</strong> sa OLX-om. ';
            echo 'Molimo idite u OLX Sync -> Tab 3 i mapirajte kategoriju.</p></div>';
            return;
        }

        $olx_article_id = get_post_meta( $post->ID, '_olx_article_id', true );
        $olx_status     = get_post_meta( $post->ID, '_olx_status', true );
        $olx_last_sync  = get_post_meta( $post->ID, '_olx_last_sync', true );
        $error          = get_post_meta( $post->ID, '_olx_sync_error', true );

        $default_attrs_options = get_option( 'drtechno_olx_default_attributes', [] );
        // Tab 6 (Zadani atributi) snima u flat strukturu: olx_state je
        // rezerviran ključ, ostali ključevi su attribute-name → value parovi.
        $cat_defaults = ( $primary_wc_cat_id && isset( $default_attrs_options[ $primary_wc_cat_id ] ) )
            ? $default_attrs_options[ $primary_wc_cat_id ]
            : [];

        // Exclude checkbox
        echo '<div style="margin-bottom: 15px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-left: 4px solid #888;">';
        echo '<label><input type="checkbox" name="olx_exclude_sync" value="yes" ' . checked( $is_excluded, true, false ) . '>';
        echo ' <strong>Nemoj slati ovaj artikal na OLX</strong></label></div>';

        // Status bar
        echo '<div style="background:#fff; border:1px solid #ccc; padding:15px; margin-bottom:20px; border-radius:4px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap;"><div style="flex:1; min-width:300px;">';

        if ( $olx_article_id ) {
            $this->renderLinkedStatus( $olx_article_id, $olx_status, $olx_last_sync, $error );
        } else {
            $this->renderUnlinkedStatus( $post->ID, $olx_cat_id, $cat_defaults, $error );
        }

        echo '</div><div style="margin-top:10px;">';
        $this->renderButtons( $post->ID, $olx_article_id );
        echo '</div></div>';

        echo '<div id="publish-response" style="margin-bottom:15px;"></div>';
        echo '<div id="local-preview-box" style="display:none; margin-bottom:15px; padding:15px; background:#fff; border:1px solid #ccc; text-align:center;">'
            . '<h4 style="margin-top:0;">Lokalni Preview Slike</h4>'
            . '<img id="local-preview-img" src="" style="max-width:100%; max-height:600px; border:1px solid #ddd; box-shadow:0 4px 10px rgba(0,0,0,0.1);" /></div>';

        // Attribute fields table
        echo '<table class="form-table"><tbody>';

        // OLX custom title (_olx_title)
        $olx_custom_title = get_post_meta( $post->ID, '_olx_title', true );
        $title_len        = mb_strlen( $olx_custom_title, 'UTF-8' );
        $len_color        = $title_len > 55 ? '#d63638' : 'inherit';
        $features_check   = get_option( 'drtechno_olx_license_features', [] );
        echo '<tr><th scope="row"><label for="olx_custom_title" style="font-weight:bold;">OLX Naziv (prilagođeni)</label></th><td>';
        echo '<div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">';
        echo '<input type="text" id="olx_custom_title" name="olx_custom_title" value="' . esc_attr( $olx_custom_title ) . '" class="regular-text" maxlength="65" style="flex:1; min-width:250px;" placeholder="Ostavite prazno da koristite naziv proizvoda">';
        if ( ! empty( $features_check['ai_titles'] ) ) {
            echo '<button type="button" class="button" id="btn-generate-ai-title" data-post-id="' . esc_attr( $post->ID ) . '">&#10024; Generiši AI Naziv</button>';
            echo '<span class="spinner" id="ai-title-spinner" style="float:none;"></span>';
        }
        echo '</div>';
        echo '<p class="description"><span id="olx-title-char-count" style="color:' . $len_color . ';">' . $title_len . '</span> / 65 znakova</p>';
        echo '</td></tr>';

        // VIP price field - check feature access
        $features = $features_check;
        if ( ! empty( $features['vip_articles'] ) ) {
            echo '<tr style="background:#fff3cd;"><th scope="row"><label style="font-weight:bold; color:#d63638;">VIP OLX Cijena</label></th>';
            echo '<td><input type="number" step="0.01" name="olx_special_price" value="' . esc_attr( $vip_price ) . '" class="regular-text" placeholder="Ostavite prazno za redovnu cijenu"></td></tr>';
        }

        $this->renderStateField( $post->ID, $cat_defaults );
        $this->renderAttributeFields( $post->ID, $olx_cat_id, $cat_defaults );

        echo '</tbody></table>';

        $this->renderMetaboxScript();
    }

    /* ================================================================== */
    /*  Private render helpers                                            */
    /* ================================================================== */

    private function renderLinkedStatus( string $olx_article_id, string $olx_status, string $olx_last_sync, string $error ): void {
        if ( $error ) {
            echo '<div style="color:#d63638; margin-bottom:10px;"><strong>Posljednja Greska:</strong> ' . esc_html( $error ) . '</div>';
        }

        $status_color = ( $olx_status == 'active' ) ? 'green' : ( ( $olx_status == 'hidden' ) ? '#777' : '#ffb900' );
        $status_text  = ( $olx_status == 'active' ) ? 'Aktivno' : ( ( $olx_status == 'hidden' ) ? 'Sakriveno (Nedostupno)' : 'Draft / Na cekanju' );
        $sync_text    = $olx_last_sync ? date( 'd.m.Y H:i', strtotime( $olx_last_sync ) ) : 'Nepoznato';

        echo '<h4 style="margin:0; color:' . $status_color . ';">Artikal je povezan sa OLX-om</h4>';
        echo '<p style="margin:5px 0 0 0;">OLX ID: <a href="https://olx.ba/artikal/' . esc_attr( $olx_article_id ) . '" target="_blank"><strong>' . esc_html( $olx_article_id ) . '</strong></a>';
        echo ' | Status: <strong>' . $status_text . '</strong>';
        echo ' | Zadnji Sync: <strong>' . $sync_text . '</strong></p>';
    }

    private function renderUnlinkedStatus( int $post_id, $olx_cat_id, array $cat_defaults, string $error ): void {
        $is_ready = true;
        $all_category_attrs = get_option( 'drtechno_olx_category_attributes', [] );

        if ( isset( $all_category_attrs[ $olx_cat_id ] ) ) {
            foreach ( $all_category_attrs[ $olx_cat_id ] as $attr ) {
                $saved_val = get_post_meta( $post_id, '_olx_attr_' . $attr['name'], true );
                if ( $saved_val === '' && $attr['name'] !== 'olx_state' && isset( $cat_defaults[ $attr['name'] ] ) ) {
                    $saved_val = $cat_defaults[ $attr['name'] ];
                }
                if ( $attr['required'] && $saved_val === '' ) {
                    $is_ready = false;
                    break;
                }
            }
        }

        if ( $error ) {
            echo '<div style="color:#d63638; margin-bottom:10px;"><strong>OLX API Greska:</strong> ' . esc_html( $error ) . '</div>';
            echo '<h4 style="margin:0; color:#d63638;">Nije poslano</h4>';
        } elseif ( $is_ready ) {
            echo '<h4 style="margin:0; color:green;">Spreman za slanje</h4>';
        } else {
            echo '<h4 style="margin:0; color:#ffb900;">Fale obavezni atributi</h4>';
        }
    }

    private function renderButtons( int $post_id, string $olx_article_id ): void {
        if ( $olx_article_id ) {
            echo '<button type="button" class="button" style="color:#0071a1; border-color:#0071a1; font-weight:bold; margin-right:5px;" id="btn-refresh-olx" data-post-id="' . $post_id . '">OSVJEZI (BUMP)</button> ';
            echo '<button type="button" class="button button-primary" id="btn-publish-olx" data-post-id="' . $post_id . '">AZURIRAJ</button> ';
            echo '<button type="button" class="button" style="color:#ffb900; border-color:#ffb900; margin-left:5px;" id="btn-sync-images" data-post-id="' . $post_id . '">AZURIRAJ SLIKE</button> ';
            echo '<button type="button" class="button" style="color:#d63638; border-color:#d63638; margin-left:5px;" id="btn-delete-olx" data-post-id="' . $post_id . '">OBRISI OGLAS</button>';
        } else {
            echo '<button type="button" class="button button-primary button-large" id="btn-publish-olx" data-post-id="' . $post_id . '">KREIRAJ NA OLX-u</button>';
        }

        echo '<button type="button" class="button" style="color:#46b450; border-color:#46b450; margin-left:5px;" id="btn-preview-image" data-post-id="' . $post_id . '">PREVIEW SLIKE</button> ';
        echo '<span class="spinner" id="publish-spinner" style="float:none; margin-left:10px;"></span>';
    }

    private function renderStateField( int $post_id, array $cat_defaults ): void {
        $saved_state = get_post_meta( $post_id, '_olx_state', true );
        $state_note  = ! empty( $cat_defaults['olx_state'] )
            ? ' (Default: ' . ( $cat_defaults['olx_state'] == 'new' ? 'Novo' : 'Koristeno' ) . ')'
            : '';

        echo '<tr><th scope="row"><label style="font-weight:bold;">Stanje artikla <span style="color:red" title="Obavezno polje">*</span></label></th><td>';
        echo '<select name="olx_state" style="max-width:300px;">';
        echo '<option value="">-- Povuci sa kategorije --</option>';
        echo '<option value="new" ' . selected( $saved_state, 'new', false ) . '>Novo</option>';
        echo '<option value="used" ' . selected( $saved_state, 'used', false ) . '>Koristeno</option>';
        echo '</select>';
        echo '<span style="color:#888; font-size:12px; margin-left:10px;">' . $state_note . '</span></td></tr>';
    }

    private function renderAttributeFields( int $post_id, $olx_cat_id, array $cat_defaults ): void {
        $all_category_attrs = get_option( 'drtechno_olx_category_attributes', [] );

        if ( ! isset( $all_category_attrs[ $olx_cat_id ] ) || empty( $all_category_attrs[ $olx_cat_id ] ) ) {
            echo '<tr><td colspan="2"><p style="color:#d63638;">Atributi nisu preuzeti.</p>';
            echo '<button type="button" class="button button-secondary" id="btn-fetch-product-attrs" data-cat="' . esc_attr( $olx_cat_id ) . '">Preuzmi atribute</button>';
            echo '<span class="spinner" id="attr-spinner" style="float:none;"></span></td></tr>';
            echo '<script>jQuery(document).ready(function($) { '
                . '$("#btn-fetch-product-attrs").click(function(e) { e.preventDefault(); $(this).prop("disabled", true); '
                . '$("#attr-spinner").addClass("is-active"); $.post(olx_sync_vars.ajaxurl, { action: "drtechno_fetch_attributes", '
                . 'nonce: olx_sync_vars.nonce, cat_id: $(this).data("cat") }, function() { location.reload(); }); }); });</script>';
            return;
        }

        foreach ( $all_category_attrs[ $olx_cat_id ] as $attr ) {
            $slug       = $attr['name'];
            $meta_key   = '_olx_attr_' . $slug;
            $saved_value = get_post_meta( $post_id, $meta_key, true );
            $req        = $attr['required'] ? ' <span style="color:red" title="Obavezno polje">*</span>' : '';

            $def_val = ( $slug !== 'olx_state' && isset( $cat_defaults[ $slug ] ) ) ? $cat_defaults[ $slug ] : '';
            $note    = $def_val !== '' ? ' <span style="color:#888; font-size:12px; margin-left:10px;">(Default: ' . esc_html( $def_val ) . ')</span>' : '';

            echo '<tr><th scope="row"><label>' . esc_html( $attr['display_name'] ) . $req . '</label></th><td>';

            if ( $attr['input_type'] === 'select' && ! empty( $attr['options'] ) ) {
                echo '<select name="olx_attr[' . esc_attr( $slug ) . ']" style="width:100%; max-width:300px;"><option value="">-- Povuci sa kategorije --</option>';
                foreach ( $attr['options'] as $opt ) {
                    echo '<option value="' . esc_attr( $opt ) . '" ' . selected( $saved_value, $opt, false ) . '>' . esc_html( $opt ) . '</option>';
                }
                echo '</select>' . $note;
            } elseif ( $attr['input_type'] === 'checkbox' ) {
                echo '<input type="checkbox" name="olx_attr[' . esc_attr( $slug ) . ']" value="1" ' . checked( $saved_value, '1', false ) . '> Da' . $note;
            } else {
                $type        = ( $attr['type'] == 'number' ) ? 'number' : 'text';
                $placeholder = $def_val !== '' ? ' placeholder="Default: ' . esc_attr( $def_val ) . '"' : '';
                echo '<input type="' . $type . '" name="olx_attr[' . esc_attr( $slug ) . ']" value="' . esc_attr( $saved_value ) . '" class="regular-text" ' . $placeholder . '>';
            }

            echo '</td></tr>';
        }
    }

    private function renderMetaboxScript(): void {
        ?>
        <script>
        jQuery(document).ready(function($){
            function disableButtons() { $('#btn-publish-olx, #btn-delete-olx, #btn-refresh-olx, #btn-sync-images, #btn-preview-image').prop('disabled', true); $('#publish-spinner').addClass('is-active'); $('#publish-response').html(''); }
            function enableButtons() { $('#btn-publish-olx, #btn-delete-olx, #btn-refresh-olx, #btn-sync-images, #btn-preview-image').prop('disabled', false); $('#publish-spinner').removeClass('is-active'); }

            // OLX title char counter
            $('#olx_custom_title').on('input', function() {
                var len = $(this).val().length;
                $('#olx-title-char-count').text(len).css('color', len > 55 ? '#d63638' : 'inherit');
            });

            // AI title generation
            $('#btn-generate-ai-title').on('click', function(e) {
                e.preventDefault();
                var btn = $(this);
                btn.prop('disabled', true).text('Generisanje...');
                $('#ai-title-spinner').addClass('is-active');
                $.post(olx_sync_vars.ajaxurl, {
                    action: 'drtechno_generate_olx_title',
                    nonce: olx_sync_vars.nonce,
                    post_id: btn.data('post-id')
                }, function(res) {
                    btn.prop('disabled', false).text('✨ Generiši AI Naziv');
                    $('#ai-title-spinner').removeClass('is-active');
                    if (res.success) {
                        $('#olx_custom_title').val(res.data.title).trigger('input');
                    } else {
                        alert('Greška: ' + (res.data || 'Nepoznata greška'));
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('✨ Generiši AI Naziv');
                    $('#ai-title-spinner').removeClass('is-active');
                });
            });
            function showError(response) { var errorMsg = response.data ? (response.data.message || response.data) : 'Unknown Error'; $('#publish-response').html('<div style="color:#d63638; font-weight:bold; padding:10px; background:#fcf0f1; border-left:4px solid #d63638;">Greska: ' + errorMsg + '</div>'); }

            $('#btn-publish-olx').click(function(e){ e.preventDefault(); disableButtons(); $.post(olx_sync_vars.ajaxurl, {action:'drtechno_publish_to_olx', nonce:olx_sync_vars.nonce, post_id:$(this).data('post-id')}, function(res){ if(res.success){ $('#publish-response').html('<div style="color:green; padding:10px; background:#e5f9e7;">' + res.data + '</div>'); setTimeout(function(){location.reload();}, 2000); } else { showError(res); enableButtons(); } }).fail(function(xhr){ enableButtons(); $('#publish-response').html('<div style="color:red;">Error ' + xhr.status + '</div>'); }); });
            $('#btn-refresh-olx').click(function(e){ e.preventDefault(); disableButtons(); $.post(olx_sync_vars.ajaxurl, {action:'drtechno_refresh_olx', nonce:olx_sync_vars.nonce, post_id:$(this).data('post-id')}, function(res){ if(res.success){ $('#publish-response').html('<div style="color:green; padding:10px; background:#e5f9e7;">' + res.data + '</div>'); setTimeout(function(){location.reload();}, 2000); } else { showError(res); enableButtons(); } }).fail(function(xhr){ enableButtons(); $('#publish-response').html('<div style="color:red;">Error ' + xhr.status + '</div>'); }); });
            $('#btn-delete-olx').click(function(e){ e.preventDefault(); if(confirm('Obrisati?')) { disableButtons(); $.post(olx_sync_vars.ajaxurl, {action:'drtechno_delete_from_olx', nonce:olx_sync_vars.nonce, post_id:$(this).data('post-id')}, function(res){ if(res.success){ $('#publish-response').html('<div style="color:green; padding:10px; background:#e5f9e7;">' + res.data + '</div>'); setTimeout(function(){location.reload();}, 2000); } else { showError(res); enableButtons(); } }).fail(function(xhr){ enableButtons(); $('#publish-response').html('<div style="color:red;">Error ' + xhr.status + '</div>'); }); } });
            $('#btn-sync-images').click(function(e){ e.preventDefault(); if(confirm('Ovo ce azurirati slike na OLX-u. Nastaviti?')) { disableButtons(); $.post(olx_sync_vars.ajaxurl, {action:'drtechno_sync_images', nonce:olx_sync_vars.nonce, post_id:$(this).data('post-id'), force_regen: 1}, function(res){ if(res.success){ $('#publish-response').html('<div style="color:green; padding:10px; background:#e5f9e7;">' + res.data + '</div>'); setTimeout(function(){location.reload();}, 4000); } else { showError(res); enableButtons(); } }).fail(function(xhr){ enableButtons(); $('#publish-response').html('<div style="color:red;">Error ' + xhr.status + '</div>'); }); } });
            $('#btn-preview-image').click(function(e){
                e.preventDefault();
                disableButtons();
                $('#local-preview-box').hide();
                $.post(olx_sync_vars.ajaxurl, {action:'drtechno_preview_image', nonce:olx_sync_vars.nonce, post_id:$(this).data('post-id')}, function(res){
                    if(res.success){
                        $('#local-preview-img').attr('src', res.data);
                        $('#local-preview-box').slideDown();
                        enableButtons();
                    } else {
                        showError(res);
                        enableButtons();
                    }
                }).fail(function(xhr){
                    enableButtons();
                    $('#publish-response').html('<div style="color:red;">Error ' + xhr.status + '</div>');
                });
            });
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                           */
    /* ------------------------------------------------------------------ */

    private function isAutomatedContext(): bool {
        return ( defined( 'DOING_CRON' ) && DOING_CRON )
            || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING )
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( 'WP_CLI' ) && WP_CLI );
    }
}
