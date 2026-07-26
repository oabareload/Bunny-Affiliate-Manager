<?php
/**
 * Bunny Score — Manager
 *
 * Orquesta el cálculo del Bunny Score: reúne posts, obtiene scores históricos
 * mediante `Score_Query`, aplica factores y devuelve el resultado.
 *
 * @package WP_AffiliateManager\Bunny_Score
 */

namespace WP_AffiliateManager\Bunny_Score;

use WP_AffiliateManager\Analytics\Score_Query;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Manager {

    /**
     * Calcula el Bunny Score en tiempo real.
     *
     * @param array $selected_terms_by_group Estructura: [ 'serie' => [ [ 'term_id'=>int, 'taxonomy'=>string ], ... ], ... ]
     * @param array $factors_values         Mapa factor_id => value (según tipo)
     * @param array $options                Opciones (min_posts_per_tag => int, range => 'total'|...)
     * @return array                        Resultado estructurado (no persiste)
     */
    public static function calculate( array $selected_terms_by_group, array $factors_values = array(), array $options = array() ): array {
        $min_posts = isset( $options['min_posts_per_tag'] ) ? (int) $options['min_posts_per_tag'] : 1;
        $range = isset( $options['range'] ) ? (string) $options['range'] : 'total';

        $all_post_ids = array();
        $per_term = array();

        foreach ( $selected_terms_by_group as $group => $terms ) {
            if ( empty( $terms ) || ! is_array( $terms ) ) {
                continue;
            }

            foreach ( $terms as $t ) {
                $term_id = is_array( $t ) && isset( $t['term_id'] ) ? (int) $t['term_id'] : (int) $t;
                $taxonomy = is_array( $t ) && isset( $t['taxonomy'] ) ? (string) $t['taxonomy'] : (string) $group;
                if ( ! taxonomy_exists( $taxonomy ) ) {
                    $taxonomy = 'post_tag';
                }

                $args = array(
                    'post_type'      => 'post',
                    'post_status'    => 'publish',
                    'fields'         => 'ids',
                    'no_found_rows'  => true,
                    'posts_per_page' => -1,
                    'tax_query'      => array(
                        array(
                            'taxonomy' => $taxonomy,
                            'field'    => 'term_id',
                            'terms'    => $term_id,
                            'operator' => 'IN',
                        ),
                    ),
                );

                $ids = get_posts( $args );

                $count = is_array( $ids ) ? count( $ids ) : 0;

                if ( $count < $min_posts ) {
                    // Omitir término con pocas publicaciones.
                    $per_term[] = array(
                        'group' => $group,
                        'term_id' => $term_id,
                        'taxonomy' => $taxonomy,
                        'post_ids' => $ids,
                        'count' => $count,
                        'avg_score' => null,
                        'valid' => false,
                    );
                    continue;
                }

                // Añadir a la colección general.
                foreach ( $ids as $pid ) {
                    $all_post_ids[ (int) $pid ] = (int) $pid;
                }

                $per_term[] = array(
                    'group' => $group,
                    'term_id' => $term_id,
                    'taxonomy' => $taxonomy,
                    'post_ids' => $ids,
                    'count' => $count,
                    'avg_score' => null,
                    'valid' => true,
                );
            }
        }

        $all_post_ids = array_values( $all_post_ids );

        if ( empty( $all_post_ids ) ) {
            return array(
                'historical' => array(
                    'global_avg' => null,
                    'total_posts' => 0,
                    'per_term' => $per_term,
                ),
                'factors' => array(
                    'per_factor' => array(),
                    'total_percent_add' => 0.0,
                ),
                'final' => array(
                    'bunny_score' => null,
                ),
                'meta' => array( 'warning' => 'no_posts' ),
            );
        }

        // Obtener scores históricos usando la fuente de verdad (Score_Query).
        $score_map = Score_Query::get_scores_for_post_ids( $all_post_ids, $range );

        if ( empty( $score_map ) ) {
            return array(
                'historical' => array(
                    'global_avg' => null,
                    'total_posts' => 0,
                    'per_term' => $per_term,
                ),
                'factors' => array(
                    'per_factor' => array(),
                    'total_percent_add' => 0.0,
                ),
                'final' => array(
                    'bunny_score' => null,
                ),
                'meta' => array( 'warning' => 'no_scores' ),
            );
        }

        // Calcular promedio histórico global (promedio de todos los posts válidos).
        $scores = array_values( $score_map );
        $historical_global_avg = array_sum( $scores ) / count( $scores );

        // Calcular promedio por término (informativo).
        foreach ( $per_term as &$term ) {
            if ( empty( $term['post_ids'] ) || ! $term['valid'] ) {
                continue;
            }

            $ids = array_map( 'intval', $term['post_ids'] );
            $valid_scores = array();
            foreach ( $ids as $pid ) {
                if ( isset( $score_map[ $pid ] ) ) {
                    $valid_scores[] = $score_map[ $pid ];
                }
            }

            if ( ! empty( $valid_scores ) ) {
                $term['avg_score'] = array_sum( $valid_scores ) / count( $valid_scores );
            }
        }
        unset( $term );

        // Aplicar factores.
        $per_factor = array();
        $total_percent_add = 0.0;

        // La configuración de factores se espera en $options['factors_config']
        $factors_config = isset( $options['factors_config'] ) && is_array( $options['factors_config'] ) ? $options['factors_config'] : array();

        foreach ( $factors_config as $factor_id => $cfg ) {
            $input_value = isset( $factors_values[ $factor_id ] ) ? $factors_values[ $factor_id ] : null;
            $percent = Bunny_Score_Factors::compute_percent( $cfg, $input_value );

            if ( null === $percent ) {
                $per_factor[ $factor_id ] = array(
                    'config' => $cfg,
                    'value' => $input_value,
                    'percent' => null,
                );
                continue;
            }

            $per_factor[ $factor_id ] = array(
                'config' => $cfg,
                'value' => $input_value,
                'percent' => (float) $percent,
            );

            $total_percent_add += (float) $percent;
        }

        // Calcular resultado final según especificación: Bunny = historical + sum(each factor percent * historical / 100)
        $final_bunny = $historical_global_avg + ( $historical_global_avg * $total_percent_add / 100 );

        return array(
            'historical' => array(
                'global_avg' => $historical_global_avg,
                'total_posts' => count( $scores ),
                'per_term' => $per_term,
            ),
            'factors' => array(
                'per_factor' => $per_factor,
                'total_percent_add' => $total_percent_add,
            ),
            'final' => array(
                'bunny_score' => $final_bunny,
            ),
            'meta' => array(
                'scored_posts_count' => count( $score_map ),
            ),
        );
    }
}
