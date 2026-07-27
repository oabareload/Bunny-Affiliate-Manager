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
     * @since 1.6.3
     * @since 1.8.0 `$selected_terms` pasó de estar agrupado por "grupo de tags"
     *              (concepto eliminado) a una lista plana de términos. Se
     *              añadió el promedio global del sitio (`site`) y la
     *              diferencia contra él (`final.diff_vs_global`).
     *
     * @param array $selected_terms  Lista plana: [ [ 'term_id'=>int, 'taxonomy'=>string, 'name'=>string ], ... ]
     * @param array $factors_values  Mapa factor_id => value (según tipo)
     * @param array $options         Opciones (min_posts_per_tag => int, range => 'total'|...)
     * @return array                 Resultado estructurado (no persiste)
     */
    public static function calculate( array $selected_terms, array $factors_values = array(), array $options = array() ): array {
        $min_posts = isset( $options['min_posts_per_tag'] ) ? (int) $options['min_posts_per_tag'] : 1;
        $range = isset( $options['range'] ) ? (string) $options['range'] : 'total';

        $all_post_ids = array();
        $per_tag = array();

        foreach ( $selected_terms as $t ) {
            if ( ! is_array( $t ) || empty( $t['term_id'] ) ) {
                continue;
            }

            $term_id = (int) $t['term_id'];
            $taxonomy = ! empty( $t['taxonomy'] ) ? (string) $t['taxonomy'] : 'post_tag';
            if ( ! taxonomy_exists( $taxonomy ) ) {
                $taxonomy = 'post_tag';
            }
            $name = ! empty( $t['name'] ) ? (string) $t['name'] : '';

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
                // Tag ignorado: no tiene suficientes publicaciones.
                $per_tag[] = array(
                    'term_id' => $term_id,
                    'name' => $name,
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

            $per_tag[] = array(
                'term_id' => $term_id,
                'name' => $name,
                'taxonomy' => $taxonomy,
                'post_ids' => $ids,
                'count' => $count,
                'avg_score' => null,
                'valid' => true,
            );
        }

        $all_post_ids = array_values( $all_post_ids );

        // Promedio global del sitio: siempre se calcula (una sola query agregada),
        // independientemente de si hay tags seleccionados, para poder mostrar el
        // punto de referencia aunque el cálculo por tags no arroje resultado.
        $site_global = Score_Query::get_global_average( $range );
        $site_tag_extremes = self::get_site_tag_count_extremes( 'post_tag' );
        $site_min_count = $site_tag_extremes['min'];
        $site_max_count = $site_tag_extremes['max'];

        if ( empty( $all_post_ids ) ) {
            return array(
                'historical' => array(
                    'collection_score' => null,
                    'selected_tags_avg' => null,
                    'weighted_tag_score' => null,
                    'log_weighted_tag_score' => null,
                    'total_posts' => 0,
                    'per_tag' => $per_tag,
                ),
                'site' => $site_global,
                'factors' => array(
                    'per_factor' => array(),
                    'total_percent_add' => 0.0,
                ),
                'final' => array(
                    'bunny_score' => null,
                    'diff_vs_global' => null,
                    'diff_collection_vs_global' => null,
                    'diff_weighted_vs_global' => null,
                    'diff_log_vs_global' => null,
                ),
                'meta' => array( 'warning' => 'no_posts' ),
            );
        }

        // Obtener scores históricos usando la fuente de verdad (Score_Query).
        $score_map = Score_Query::get_scores_for_post_ids( $all_post_ids, $range );

        if ( empty( $score_map ) ) {
            return array(
                'historical' => array(
                    'collection_score' => null,
                    'selected_tags_avg' => null,
                    'weighted_tag_score' => null,
                    'log_weighted_tag_score' => null,
                    'total_posts' => 0,
                    'per_tag' => $per_tag,
                ),
                'site' => $site_global,
                'factors' => array(
                    'per_factor' => array(),
                    'total_percent_add' => 0.0,
                ),
                'final' => array(
                    'bunny_score' => null,
                    'diff_vs_global' => null,
                    'diff_collection_vs_global' => null,
                    'diff_weighted_vs_global' => null,
                    'diff_log_vs_global' => null,
                ),
                'meta' => array( 'warning' => 'no_scores' ),
            );
        }

        // Calcular promedio histórico de los tags seleccionados (promedio de todos los posts válidos).
        $scores = array_values( $score_map );
        $selected_tags_avg = array_sum( $scores ) / count( $scores );

        // Calcular promedio por tag (informativo) y preparar el cálculo ponderado.
        $tag_counts = array();
        foreach ( $per_tag as &$term ) {
            if ( empty( $term['post_ids'] ) || ! $term['valid'] ) {
                $term['weight_sqrt'] = null;
                $term['weight_log'] = null;
                $term['contribution_sqrt'] = null;
                $term['contribution_log'] = null;
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

            $tag_counts[] = $term['count'];
        }
        unset( $term );

        $selected_min_count = ! empty( $tag_counts ) ? min( $tag_counts ) : 0;
        $selected_max_count = ! empty( $tag_counts ) ? max( $tag_counts ) : 0;
        $min_count = $site_min_count > 0 ? $site_min_count : $selected_min_count;
        $max_count = $site_max_count > 0 ? $site_max_count : $selected_max_count;
        $weighted_sum = 0.0;
        $log_weighted_sum = 0.0;
        $weight_total = 0.0;
        $log_weight_total = 0.0;

        foreach ( $per_tag as &$term ) {
            if ( empty( $term['post_ids'] ) || ! $term['valid'] || null === $term['avg_score'] ) {
                continue;
            }

            $term['weight_sqrt'] = self::calculate_tag_weight( $term['count'], $min_count, $max_count, 'sqrt' );
            $term['weight_log'] = self::calculate_tag_weight( $term['count'], $min_count, $max_count, 'log' );
            $term['contribution_sqrt'] = $term['avg_score'] * $term['weight_sqrt'];
            $term['contribution_log'] = $term['avg_score'] * $term['weight_log'];

            $weighted_sum += $term['contribution_sqrt'];
            $log_weighted_sum += $term['contribution_log'];
            $weight_total += $term['weight_sqrt'];
            $log_weight_total += $term['weight_log'];
        }
        unset( $term );

        $weighted_tag_score = $weight_total > 0 ? $weighted_sum / $weight_total : null;
        $log_weighted_tag_score = $log_weight_total > 0 ? $log_weighted_sum / $log_weight_total : null;

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

        // Calcular resultado final según especificación: Bunny = selected_tags_avg + sum(each factor percent * selected_tags_avg / 100)
        $final_bunny = $selected_tags_avg + ( $selected_tags_avg * $total_percent_add / 100 );

        // Diferencia contra el promedio global del sitio (positivo = por encima del comportamiento histórico general).
        $diff_vs_global = null !== $site_global['avg'] ? ( $final_bunny - $site_global['avg'] ) : null;
        $diff_collection_vs_global = null !== $site_global['avg'] ? ( $selected_tags_avg - $site_global['avg'] ) : null;
        $diff_weighted_vs_global = null !== $site_global['avg'] && null !== $weighted_tag_score ? ( $weighted_tag_score - $site_global['avg'] ) : null;
        $diff_log_vs_global = null !== $site_global['avg'] && null !== $log_weighted_tag_score ? ( $log_weighted_tag_score - $site_global['avg'] ) : null;

        return array(
            'historical' => array(
                'collection_score' => $selected_tags_avg,
                'selected_tags_avg' => $selected_tags_avg,
                'weighted_tag_score' => $weighted_tag_score,
                'log_weighted_tag_score' => $log_weighted_tag_score,
                'total_posts' => count( $scores ),
                'per_tag' => $per_tag,
            ),
            'site' => $site_global,
            'factors' => array(
                'per_factor' => $per_factor,
                'total_percent_add' => $total_percent_add,
            ),
            'final' => array(
                'bunny_score' => $final_bunny,
                'diff_vs_global' => $diff_vs_global,
                'diff_collection_vs_global' => $diff_collection_vs_global,
                'diff_weighted_vs_global' => $diff_weighted_vs_global,
                'diff_log_vs_global' => $diff_log_vs_global,
            ),
            'meta' => array(
                'scored_posts_count' => count( $score_map ),
            ),
        );
    }

    /**
     * Calcula el peso de un tag basado en la cantidad de posts.
     *
     * @param int    $count      Número de posts válidos del tag.
     * @param int    $min_count  Mínimo count entre los tags válidos.
     * @param int    $max_count  Máximo count entre los tags válidos.
     * @param string $method     Método de cálculo: 'sqrt'|'log'.
     * @return float
     */
    private static function calculate_tag_weight( int $count, int $min_count, int $max_count, string $method ): float {
        if ( $count <= 0 ) {
            return 0.0;
        }

        switch ( $method ) {
            case 'normalized':
                if ( $min_count === $max_count ) {
                    return 1.0;
                }
                $min = max( 1, $min_count );
                $normalized = ( $count - $min ) / ( $max_count - $min );
                return 0.5 + 0.5 * $normalized;
            case 'log':
                return log($count + 1);
            case 'sqrt':
            default:
                return sqrt( $count );
        }
    }

    /**
     * Obtiene el conteo mínimo y máximo de posts entre todas las etiquetas del sitio.
     *
     * @param string $taxonomy Taxonomía a analizar.
     * @return array{min:int,max:int}
     */
    private static function get_site_tag_count_extremes( string $taxonomy ): array {
        global $wpdb;

        $taxonomy = sanitize_key( $taxonomy );
        $term_taxonomy = $wpdb->term_taxonomy;

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT MIN(count) AS min_count, MAX(count) AS max_count FROM {$term_taxonomy} WHERE taxonomy = %s",
                $taxonomy
            ),
            ARRAY_A
        );

        if ( ! is_array( $row ) || $row['min_count'] === null || $row['max_count'] === null ) {
            return array(
                'min' => 0,
                'max' => 0,
            );
        }

        return array(
            'min' => (int) $row['min_count'],
            'max' => (int) $row['max_count'],
        );
    }
}
