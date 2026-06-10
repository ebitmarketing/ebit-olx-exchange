<?php

namespace EbitOlx\Ajax;

defined( 'ABSPATH' ) || exit;

use EbitOlx\Api\ServerClient;
use EbitOlx\Helpers\OlxMetaHelper;
use EbitOlx\License\FeatureManager;

/**
 * Handles sponsoring (promotion) AJAX endpoints:
 *  - Calculate sponsor price
 */
class SponsorAjax extends AjaxHandler {

    private ServerClient $api;
    private ?FeatureManager $features;

    public function __construct( ServerClient $api, ?FeatureManager $features = null ) {
        $this->api      = $api;
        $this->features = $features;
    }

    public function register(): void {
        $this->action( 'drtechno_calc_sponsor_price', 'calcPrice' );
    }

    /**
     * Calculate the price for sponsoring one or more listings.
     */
    public function calcPrice(): void {
        ob_start();
        $this->verify();

        if ( $this->features ) {
            $this->features->requireFeature( 'sponsor' );
        }

        $post_id     = intval( $_POST['post_id'] );
        $count_items = isset( $_POST['count'] ) ? intval( $_POST['count'] ) : 1;

        $olx_id = OlxMetaHelper::getOlxId( $post_id );
        if ( ! $olx_id ) {
            ob_end_clean();
            wp_send_json_error( 'Barem prvi odabrani artikal mora biti objavljen na OLX-u da bi izračunali cijenu.' );
        }

        $params = [
            'type'          => intval( $_POST['type'] ),
            'days'          => intval( $_POST['days'] ),
            'refresh_every' => intval( $_POST['refresh_every'] ),
        ];

        if ( ! empty( $_POST['locations'] ) ) {
            $params['locations'] = [ sanitize_text_field( $_POST['locations'] ) ];
        }

        $response = $this->api->sponsorPrice( $olx_id, $params );
        ob_end_clean();

        if ( ! $response['error'] && isset( $response['data']['total'] ) ) {
            $single_price = intval( $response['data']['total'] );
            $total_price  = $single_price * $count_items;
            wp_send_json_success( [ 'total' => $total_price, 'single' => $single_price ] );
        } else {
            $err = $response['message'] ?? 'Greška pri računanju cijene.';
            wp_send_json_error( $err );
        }
    }
}
