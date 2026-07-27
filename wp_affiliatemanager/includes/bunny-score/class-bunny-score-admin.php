<?php
/**
 * Bunny Score — AJAX/admin-post endpoint.
 *
 * Handles the calculation request only. Menu registration lives exclusively
 * in `Admin\Admin_Menu` (single source of truth for the plugin's admin menu,
 * consistent with every other screen) — this class no longer registers a
 * competing `admin_menu` submenu.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.6.3
 * @version 1.7.0
 */

namespace WP_AffiliateManager\Bunny_Score;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Admin {

    public static function register(): void {
        add_action( 'admin_post_wpam_calculate_bunny_score', array( __CLASS__, 'handle_calculation' ) );
        add_action( 'wp_ajax_wpam_calculate_bunny_score', array( __CLASS__, 'handle_calculation' ) );
    }

    public static function handle_calculation(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-affiliatemanager' ) );
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            check_ajax_referer( 'wpam_admin_nonce', 'nonce' );
        } else {
            check_admin_referer( 'wpam_bunny_score_calc' );
        }

        $selected = self::resolve_selected_terms();

        $raw_factors = isset( $_POST['factors'] ) ? wp_unslash( $_POST['factors'] ) : array();
        $factors = is_array( $raw_factors ) ? $raw_factors : array();

        $options = get_option( WPAM_OPTION_KEY, array() );
        $bunny_score_settings = isset( $options['bunny_score'] ) && is_array( $options['bunny_score'] ) ? $options['bunny_score'] : array();
        $factor_config = array();

        foreach ( $bunny_score_settings['factors'] ?? array() as $factor ) {
            if ( ! is_array( $factor ) || empty( $factor['enabled'] ) || empty( $factor['id'] ) ) {
                continue;
            }

            $factor_id = sanitize_key( (string) $factor['id'] );
            $type = in_array( $factor['type'] ?? 'boolean', array( 'boolean', 'numeric', 'label' ), true ) ? $factor['type'] : 'boolean';

            $factor_config[ $factor_id ] = array(
                'id'          => $factor_id,
                'label'       => sanitize_text_field( $factor['label'] ?? '' ),
                'type'        => $type,
                'enabled'     => true,
                'optional'    => ! empty( $factor['optional'] ),
                'max_percent' => max( 0.0, floatval( $factor['max_percent'] ?? 0 ) ),
                'scale_min'   => isset( $factor['scale_min'] ) ? (float) $factor['scale_min'] : 0.0,
                'scale_max'   => isset( $factor['scale_max'] ) ? (float) $factor['scale_max'] : 100.0,
                'precision'   => isset( $factor['precision'] ) ? absint( $factor['precision'] ) : 2,
                'labels'      => isset( $factor['labels'] ) && is_array( $factor['labels'] ) ? $factor['labels'] : array(),
            );
        }

        $min_posts = max( 1, absint( $bunny_score_settings['min_posts_per_tag'] ?? 1 ) );
        $range = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : 'total';
        $range = in_array( $range, array( 'today', 'week', 'month', 'total' ), true ) ? $range : 'total';

        $result = Bunny_Score_Manager::calculate(
            $selected,
            $factors,
            array(
                'factors_config'    => $factor_config,
                'min_posts_per_tag' => $min_posts,
                'range'             => $range,
            )
        );

        wp_send_json_success( $result );
    }

    /**
     * Resuelve los tags seleccionados por el selector nativo de WordPress
     * (`selected_term_names`, CSV de nombres gestionado por `tagBox`, siempre
     * sobre la taxonomía `post_tag` desde que se eliminó el concepto de
     * grupos en v1.8.0) a una lista plana de términos resueltos.
     *
     * Devuelve también el nombre de cada término (ya disponible aquí desde
     * `get_term_by()`) para que `Bunny_Score_Manager` no tenga que volver a
     * consultarlo al construir el desglose por tag.
     *
     * @since  1.7.0
     * @since  1.8.0 Aplanado: ya no hay grupos, solo `post_tag`.
     * @return array<int, array{term_id:int, taxonomy:string, name:string}>
     */
    private static function resolve_selected_terms(): array {
        $names_csv = isset( $_POST['selected_term_names'] ) ? wp_unslash( $_POST['selected_term_names'] ) : '';
        $names_csv = is_string( $names_csv ) ? $names_csv : '';

        $names = array_filter( array_map( 'trim', explode( ',', $names_csv ) ) );
        if ( empty( $names ) ) {
            return array();
        }

        $selected = array();
        foreach ( $names as $name ) {
            $term = get_term_by( 'name', sanitize_text_field( $name ), 'post_tag' );
            if ( $term && ! is_wp_error( $term ) ) {
                $selected[] = array(
                    'term_id'  => (int) $term->term_id,
                    'taxonomy' => 'post_tag',
                    'name'     => $term->name,
                );
            }
        }

        return $selected;
    }
}
