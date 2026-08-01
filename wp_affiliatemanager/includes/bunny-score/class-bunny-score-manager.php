<?php
/**
 * Bunny Score — Manager
 *
 * v2: fuente única de verdad. Ya no existen múltiples modelos de cálculo
 * (Collection Score / Weighted Tag Score / Log Weighted Tag Score). Todo el
 * sistema trabaja sobre TAGs: cada TAG (venga de un factor externo asociado
 * o escrito directamente) aporta exactamente un Score Ajustado, y el Bunny
 * Score Final es el promedio de todos esos Scores Ajustados. Ningún cálculo
 * paralelo.
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
     * Calcula el Bunny Score en tiempo real (v2 — modelo único basado en TAGs).
     *
     * Reglas (ver encargo "Refactor del Bunny Score v2"):
     * 1. Un factor externo puede asociarse opcionalmente a un TAG, vía
     *    `$factors_values[id]['tag_id']` (term_id, seleccionado con el
     *    autocomplete nativo de WP — nunca texto libre). CUALQUIER factor
     *    que resuelva a un `percent` no-null (estado "Tiene valor" o "Sin
     *    datos") participa SIEMPRE en el cálculo, tenga o no tenga TAG
     *    asociado — la ausencia de TAG (o que el TAG asociado no exista/no
     *    cumpla el mínimo) nunca saca al factor del cálculo, solo cambia la
     *    base sobre la que se aplica:
     *      - con TAG asociado que existe y cumple `min_posts_per_tag` con
     *        actividad registrada → Score Histórico = el promedio propio del TAG.
     *      - sin TAG asociado, o el TAG no existe/no cumple el mínimo →
     *        Score Histórico = Score Global del sitio.
     *    Score Ajustado = Score Histórico + Score Histórico * percent / 100.
     * 2. Un TAG nunca se calcula dos veces: cualquier `term_id` consumido por
     *    un factor externo se excluye de la lista de TAGs escritos
     *    manualmente (`$selected_terms`) — comparación EXCLUSIVAMENTE por
     *    `term_id` (nunca por nombre, slug, ni texto normalizado). Siempre
     *    gana la versión del factor.
     * 3. Los TAGs sin factor asociado (la inmensa mayoría — "1/7", "NSFW",
     *    "Bunny Girl", etc.) simplemente conservan Score Ajustado = Score
     *    Histórico, sin ninguna modificación.
     * 4. Bunny Score Final = promedio de TODOS los Scores Ajustados (de
     *    factor-tags y de tags independientes válidos). Ningún otro cálculo
     *    paralelo existe.
     *
     * @since 1.6.3
     * @since 1.7.6 v2: colapsado a un único modelo. Eliminados collection_score/
     *              weighted_tag_score/log_weighted_tag_score y sus pesos
     *              sqrt/log. `per_tag` ahora expone únicamente name/count/
     *              historical_score/adjusted_score/source.
     * @since 1.7.7 Asociación factor↔TAG vía autocomplete nativo (term_id),
     *              ya no texto libre. Deduplicación por `term_id` exclusivamente.
     *              El "existe en el histórico" de un TAG de factor ahora
     *              respeta `min_posts_per_tag`, igual que los TAGs independientes
     *              (antes bastaba con >0 posts con score).
     *              Bugfix: (1) un factor sin `tag_id` ya no se excluye del
     *              cálculo — antes un `continue` lo sacaba por completo del
     *              per_tag/promedio final; ahora SIEMPRE participa, usando
     *              Score Global como base si no hay TAG asociado o no
     *              califica. (2) `historical.total_posts` ahora suma también
     *              los posts que respaldan el Score Global cuando algún
     *              factor cayó en ese fallback — antes solo contaba posts de
     *              TAGs propios válidos, mostrando "0 publicaciones" incluso
     *              cuando el cálculo sí había usado el histórico global.
     *
     * @param array $selected_terms  Lista plana: [ [ 'term_id'=>int, 'taxonomy'=>string, 'name'=>string ], ... ]
     * @param array $factors_values  Mapa factor_id => ['state'=>..,'value'=>..,'tag_id'=>int opcional] (o escalar suelto, compat)
     * @param array $options         Opciones (min_posts_per_tag => int, range => 'total'|..., factors_config => array)
     * @return array                 Resultado estructurado (no persiste)
     */
    public static function calculate( array $selected_terms, array $factors_values = array(), array $options = array() ): array {
        $min_posts = isset( $options['min_posts_per_tag'] ) ? (int) $options['min_posts_per_tag'] : 1;
        $range = isset( $options['range'] ) ? (string) $options['range'] : 'total';
        $factors_config = isset( $options['factors_config'] ) && is_array( $options['factors_config'] ) ? $options['factors_config'] : array();

        // Promedio global del sitio: siempre se calcula (una sola query agregada),
        // es la base de fallback para TAGs nuevos/sin asociar y el punto de
        // referencia del resultado final.
        $site_global = Score_Query::get_global_average( $range );

        $per_tag = array();
        $all_post_ids = array(); // unión de posts de TAGs propios válidos.
        $used_global_fallback = false; // v1.7.7: bug 1 — ver más abajo.

        // -----------------------------------------------------------------
        // Paso 1: factores externos. CUALQUIER factor cuyo `percent` resuelva
        // (no-null) participa en el cálculo, tenga o no tenga TAG asociado
        // (v1.7.7, bug 2). Si tiene TAG asociado, se marca como "consumido"
        // para el paso 2 (regla 2: un TAG nunca se calcula dos veces).
        // -----------------------------------------------------------------
        $per_factor = array();
        $total_percent_add = 0.0;
        $consumed_term_ids = array(); // term_id (int) => true

        foreach ( $factors_config as $factor_id => $cfg ) {
            $submitted = isset( $factors_values[ $factor_id ] ) ? $factors_values[ $factor_id ] : null;
            $percent = Bunny_Score_Factors::compute_percent( $cfg, $submitted );

            $per_factor[ $factor_id ] = array(
                'config'  => $cfg,
                'value'   => $submitted,
                'percent' => $percent,
            );

            if ( null === $percent ) {
                // "No aplica" (estado explícito) o factor deshabilitado/sin
                // valor: este es el ÚNICO caso que no participa en el cálculo.
                // Si trae un tag_id, tampoco se considera consumido — si ese
                // mismo term_id fue elegido como tag independiente, se procesa
                // normalmente en el paso 2.
                continue;
            }

            $total_percent_add += (float) $percent;

            // v1.7.7 fix (bug 2): YA NO hay `continue` cuando falta tag_id.
            // Un factor con valor SIEMPRE genera una fila en per_tag — con TAG
            // propio si se asoció y califica, o sobre el Score Global si no.
            $tag_id = is_array( $submitted ) ? absint( $submitted['tag_id'] ?? 0 ) : 0;

            $name = ! empty( $cfg['label'] ) ? (string) $cfg['label'] : (string) $factor_id;
            $count = 0;
            $historical = null;
            $row_used_global = false;

            if ( $tag_id ) {
                $consumed_term_ids[ $tag_id ] = true;

                $term = get_term( $tag_id, 'post_tag' );
                if ( $term && ! is_wp_error( $term ) ) {
                    $name = $term->name;
                    $post_ids = self::get_post_ids_for_term( $tag_id, 'post_tag' );
                    $count = count( $post_ids );
                    if ( $count >= $min_posts ) {
                        $scores = Score_Query::get_scores_for_post_ids( $post_ids, $range );
                        if ( ! empty( $scores ) ) {
                            $historical = array_sum( $scores ) / count( $scores );
                            foreach ( $post_ids as $pid ) {
                                $all_post_ids[ (int) $pid ] = (int) $pid;
                            }
                        }
                    }
                } else {
                    // term_id no resuelve a ningún término real (borrado, etc.).
                    $name = sprintf( '#%d', $tag_id );
                }
            }

            if ( null === $historical ) {
                // Sin TAG asociado, o el TAG no existe/no califica: se usa el
                // Score Global como base — nunca se pierde el efecto del factor.
                $historical = $site_global['avg'];
                $used_global_fallback = true;
                $row_used_global = true;
                // v1.7.7 (ajuste solicitado): cuando se usa el Global, el
                // "count" mostrado debe ser el respaldo histórico REAL del
                // Global (site_global.total_posts), no el conteo insuficiente
                // del TAG propio (ej. 1 post) — ese 1 no es lo que sustenta
                // el Score mostrado, el Global sí.
                $count = (int) ( $site_global['total_posts'] ?? 0 );
            }

            $adjusted = null !== $historical ? ( $historical + ( $historical * $percent / 100 ) ) : null;

            $per_tag[] = array(
                'name'             => $name,
                'term_id'          => $tag_id ?: null,
                'count'            => $count,
                'historical_score' => $historical,
                'adjusted_score'   => $adjusted,
                'source'           => 'factor',
                'factor_id'        => $factor_id,
                'used_global'      => $row_used_global,
            );
        }

        // -----------------------------------------------------------------
        // Paso 2: TAGs escritos manualmente, saltando cualquiera cuyo term_id
        // ya fue consumido por un factor externo en el paso 1.
        // -----------------------------------------------------------------
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

            if ( isset( $consumed_term_ids[ $term_id ] ) ) {
                // Ya aportó vía un factor externo — nunca se procesa dos veces,
                // ni aparece duplicado, ni vuelve a modificar el Bunny Score.
                continue;
            }

            $post_ids = self::get_post_ids_for_term( $term_id, $taxonomy );
            $count = count( $post_ids );

            if ( $count < $min_posts ) {
                // Tag ignorado: no tiene suficientes publicaciones.
                $per_tag[] = array(
                    'name'             => $name,
                    'term_id'          => $term_id,
                    'count'            => $count,
                    'historical_score' => null,
                    'adjusted_score'   => null,
                    'source'           => 'tag',
                );
                continue;
            }

            $scores = Score_Query::get_scores_for_post_ids( $post_ids, $range );
            if ( empty( $scores ) ) {
                $per_tag[] = array(
                    'name'             => $name,
                    'term_id'          => $term_id,
                    'count'            => $count,
                    'historical_score' => null,
                    'adjusted_score'   => null,
                    'source'           => 'tag',
                );
                continue;
            }

            foreach ( $post_ids as $pid ) {
                $all_post_ids[ (int) $pid ] = (int) $pid;
            }

            $historical = array_sum( $scores ) / count( $scores );

            $per_tag[] = array(
                'name'             => $name,
                'term_id'          => $term_id,
                'count'            => $count,
                'historical_score' => $historical,
                'adjusted_score'   => $historical, // sin factor asociado: Ajustado = Histórico.
                'source'           => 'tag',
            );
        }

        // -----------------------------------------------------------------
        // Paso 3: Bunny Score Final = promedio de TODOS los Scores Ajustados.
        // Ningún cálculo paralelo.
        // -----------------------------------------------------------------
        $adjusted_values = array();
        foreach ( $per_tag as $row ) {
            if ( null !== $row['adjusted_score'] ) {
                $adjusted_values[] = $row['adjusted_score'];
            }
        }

        $bunny_score = ! empty( $adjusted_values ) ? ( array_sum( $adjusted_values ) / count( $adjusted_values ) ) : null;
        $diff_vs_global = ( null !== $bunny_score && null !== $site_global['avg'] ) ? ( $bunny_score - $site_global['avg'] ) : null;

        // v1.7.7 fix (bug 1, ajustado): si algún factor cayó en el fallback
        // al Score Global, "publicaciones analizadas" debe representar
        // Únicamente el respaldo histórico real de ese Global — NO una suma
        // de posts parciales de TAGs insuficientes más el total del sitio.
        // Ej.: Global=229, Fabricante=1 (insuficiente), Franquicia=0 → el
        // reporte debe decir 229, no 230 ni 229+1. Si ningún factor cayó en
        // el fallback, se mantiene la unión real de posts de TAGs propios.
        $total_posts = $used_global_fallback
            ? (int) ( $site_global['total_posts'] ?? 0 )
            : count( $all_post_ids );

        return array(
            'historical' => array(
                'total_posts' => $total_posts,
                'per_tag'     => $per_tag,
            ),
            'site' => $site_global,
            'factors' => array(
                'per_factor'        => $per_factor,
                'total_percent_add' => $total_percent_add,
            ),
            'final' => array(
                'bunny_score'    => $bunny_score,
                'diff_vs_global' => $diff_vs_global,
            ),
            'meta' => array(
                'tags_used'            => count( $per_tag ),
                'used_global_fallback' => $used_global_fallback,
            ),
        );
    }

    /**
     * Devuelve los post IDs (posts publicados) que pertenecen a un término.
     * Única fuente de esta consulta — usada tanto para TAGs de factores
     * externos como para TAGs escritos manualmente, y por el preview AJAX
     * (`Bunny_Score_Admin::ajax_get_tag_preview()`). Público desde v1.7.7
     * precisamente para que ese preview reutilice esta misma query en vez
     * de duplicarla.
     *
     * @since 1.7.6
     * @since 1.7.7 Visibilidad pública (antes privada).
     * @param int    $term_id
     * @param string $taxonomy
     * @return int[]
     */
    public static function get_post_ids_for_term( int $term_id, string $taxonomy = 'post_tag' ): array {
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
        return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
    }
}
