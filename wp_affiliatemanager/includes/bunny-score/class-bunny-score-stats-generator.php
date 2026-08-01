<?php
/**
 * Bunny Score — Stats Generator
 *
 * Dueño único de la distribución histórica de scores del sitio: la genera
 * (proceso semanal vía WP-Cron, o manualmente desde la pantalla Bunny Score),
 * la almacena en una opción de caché propia (NO dentro de `wpam_settings`,
 * para no repetir el bug de escritura compartida ya corregido en esa opción),
 * y expone la matemática de "dónde cae un score dentro de esa distribución"
 * (percentil exacto, z-score, semáforo) como simple lectura + aritmética en
 * memoria — sin recorrer posts ni lanzar queries en cada cálculo de Bunny
 * Score.
 *
 * No calcula Bunny Score. No toca factores. No genera información por
 * figura/tag — únicamente estadísticas agregadas de todo el sitio.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score;

use WP_AffiliateManager\Analytics\Score_Query;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Stats_Generator {

    /**
     * Puntos de percentil que se almacenan explícitamente (además del array
     * completo de scores, que permite calcular CUALQUIER percentil exacto).
     *
     * @since 1.7.5
     */
    private const PERCENTILE_POINTS = array( 1, 5, 10, 20, 25, 30, 40, 50, 60, 70, 75, 80, 90, 95, 99 );

    /**
     * Umbrales del semáforo: NO son números fijos arbitrarios (30/60/80).
     * Son los cuartiles reales de la distribución (P25/P50/P75) aplicados
     * al percentil ya calculado del score frente al histórico real del
     * sitio — se adaptan automáticamente a cómo se comporta BunnyChase,
     * no a una convención arbitraria.
     *
     * @since 1.7.5
     */
    private const SEMAPHORE_P25 = 25;
    private const SEMAPHORE_P50 = 50;
    private const SEMAPHORE_P75 = 75;

    /** Límites de cordura para el número de bins del histograma. */
    private const MIN_BINS = 5;
    private const MAX_BINS = 60;

    /**
     * Versión del esquema de la estructura almacenada en caché. Si el
     * algoritmo de cálculo de la distribución cambia alguna vez (nueva
     * fórmula de percentiles, nuevos campos, cambio de método estadístico),
     * basta con incrementar este número: `get_stats()` invalida
     * automáticamente cualquier caché generada con un schema_version
     * distinto, sin necesidad de una migración de opciones ni de borrar
     * manualmente `wpam_bunny_score_cache`.
     *
     * @since 1.7.5
     */
    private const SCHEMA_VERSION = 1;

    // -------------------------------------------------------------------------
    // Generación (cron semanal / botón manual)
    // -------------------------------------------------------------------------

    /**
     * Recorre TODOS los posts con actividad (una sola query agregada, ver
     * `Score_Query::get_all_scores()`) y genera la distribución histórica
     * completa: total, promedio, mediana, desviación estándar, mín, máx,
     * percentiles P1..P99, e histograma real (bins calculados automáticamente
     * con la regla de Freedman–Diaconis, con fallback a Sturges).
     *
     * Se guarda también el array completo de scores ordenado — es lo que
     * permite calcular el percentil EXACTO de cualquier score en
     * `get_position()`, sin aproximar a partir de los 15 puntos de percentil
     * guardados.
     *
     * @since 1.7.5
     * @return void
     */
    public static function generate(): void {
        $scores = Score_Query::get_all_scores( 'total' );
        sort( $scores, SORT_NUMERIC );

        $n = count( $scores );

        if ( 0 === $n ) {
            update_option(
                WPAM_BUNNY_SCORE_CACHE_KEY,
                array(
                    'schema_version' => self::SCHEMA_VERSION,
                    'generated_at' => time(),
                    'total_posts'  => 0,
                    'avg'          => null,
                    'median'       => null,
                    'stddev'       => null,
                    'min'          => null,
                    'max'          => null,
                    'percentiles'  => array(),
                    'distribution' => array(),
                    'scores'       => array(),
                ),
                false // no autoload: solo se lee bajo demanda desde la pantalla Bunny Score.
            );
            return;
        }

        $avg = array_sum( $scores ) / $n;

        $variance = 0.0;
        foreach ( $scores as $s ) {
            $variance += ( $s - $avg ) ** 2;
        }
        // Desviación estándar poblacional: tenemos el 100% de los posts, no una muestra.
        $stddev = sqrt( $variance / $n );

        $percentiles = array();
        foreach ( self::PERCENTILE_POINTS as $p ) {
            $percentiles[ 'p' . $p ] = self::percentile_value( $scores, $p );
        }

        update_option(
            WPAM_BUNNY_SCORE_CACHE_KEY,
            array(
                'schema_version' => self::SCHEMA_VERSION,
                'generated_at' => time(),
                'total_posts'  => $n,
                'avg'          => $avg,
                'median'       => self::percentile_value( $scores, 50 ),
                'stddev'       => $stddev,
                'min'          => $scores[0],
                'max'          => $scores[ $n - 1 ],
                'percentiles'  => $percentiles,
                'distribution' => self::build_distribution( $scores ),
                'scores'       => $scores,
            ),
            false
        );
    }

    // -------------------------------------------------------------------------
    // Lectura
    // -------------------------------------------------------------------------

    /**
     * Lee la estadística ya generada. Una sola llamada a `get_option()`,
     * cero queries. Devuelve `null` si nunca se ha generado, o si la caché
     * existente quedó obsoleta por un cambio de `SCHEMA_VERSION` — en
     * ambos casos el llamador debe tratarlo como "aún no hay estadísticas",
     * exactamente el mismo estado que antes de la primera generación.
     *
     * @since 1.7.5
     * @return array|null
     */
    public static function get_stats(): ?array {
        $stats = get_option( WPAM_BUNNY_SCORE_CACHE_KEY, null );
        if ( ! is_array( $stats ) ) {
            return null;
        }
        if ( ( $stats['schema_version'] ?? 0 ) !== self::SCHEMA_VERSION ) {
            return null;
        }
        return $stats;
    }

    /**
     * Versión de `$stats` segura para enviar al cliente vía JSON: sin el
     * array completo de scores (puede tener miles de elementos y solo se
     * necesita server-side para el cálculo exacto de percentil).
     *
     * @since 1.7.5
     * @param array $stats
     * @return array
     */
    private static function public_stats_summary( array $stats ): array {
        unset( $stats['scores'] );
        return $stats;
    }

    /**
     * Calcula dónde cae un score dentro de la distribución histórica ya
     * generada: percentil EXACTO (rank real sobre el array de scores
     * almacenado, sin interpolar), z-score, diferencia contra el promedio
     * global, y semáforo.
     *
     * @since 1.7.5
     * @param float|null $score
     * @param array|null $stats Resultado de get_stats() (con 'scores').
     * @return array|null
     */
    public static function get_position( ?float $score, ?array $stats ): ?array {
        if ( null === $score || empty( $stats ) || empty( $stats['scores'] ) || empty( $stats['total_posts'] ) ) {
            return null;
        }

        $percentile = self::rank_percentile( $stats['scores'], $score );
        $stddev = isset( $stats['stddev'] ) ? (float) $stats['stddev'] : 0.0;
        $avg = isset( $stats['avg'] ) ? (float) $stats['avg'] : null;

        return array(
            'score'          => $score,
            'percentile'     => $percentile,
            'z_score'        => ( $stddev > 0 && null !== $avg ) ? ( $score - $avg ) / $stddev : null,
            'diff_vs_global' => null !== $avg ? $score - $avg : null,
            'semaphore'      => self::get_semaphore( $percentile ),
        );
    }

    /**
     * Semáforo basado únicamente en el percentil (ya calculado sobre la
     * distribución real) frente a los cuartiles reales P25/P50/P75. Sin IA,
     * sin heurísticas: un lookup de rango.
     *
     * @since 1.7.5
     * @param float $percentile
     * @return array{icon:string,label:string}
     */
    public static function get_semaphore( float $percentile ): array {
        if ( $percentile < self::SEMAPHORE_P25 ) {
            return array( 'icon' => '🔴', 'label' => __( 'Bajo', 'wp-affiliatemanager' ) );
        }
        if ( $percentile < self::SEMAPHORE_P50 ) {
            return array( 'icon' => '🟡', 'label' => __( 'Medio', 'wp-affiliatemanager' ) );
        }
        if ( $percentile < self::SEMAPHORE_P75 ) {
            return array( 'icon' => '🟢', 'label' => __( 'Bueno', 'wp-affiliatemanager' ) );
        }
        return array( 'icon' => '⭐', 'label' => __( 'Excelente', 'wp-affiliatemanager' ) );
    }

    /**
     * Arma el reporte de "Posición histórica" para los N modelos que
     * `Bunny_Score_Manager::calculate()` ya devolvió — no recalcula nada
     * del histórico, solo lee la opción de caché una vez y hace aritmética.
     *
     * @since 1.7.5
     * @param array<string,float|null> $model_scores Ej: ['collection_score'=>.., 'weighted_tag_score'=>.., 'log_weighted_tag_score'=>..]
     * @return array{stats: array|null, models: array}
     */
    public static function build_position_report( array $model_scores ): array {
        $stats = self::get_stats();

        $models = array();
        foreach ( $model_scores as $key => $score ) {
            $models[ $key ] = self::get_position( null !== $score ? (float) $score : null, $stats );
        }

        return array(
            'stats'  => $stats ? self::public_stats_summary( $stats ) : null,
            'models' => $models,
        );
    }

    // -------------------------------------------------------------------------
    // Matemática interna
    // -------------------------------------------------------------------------

    /**
     * Valor correspondiente a un percentil dado, por interpolación lineal
     * sobre un array YA ordenado (método estándar, el mismo que usan
     * herramientas como numpy con su modo 'linear').
     *
     * @since 1.7.5
     * @param float[] $sorted
     * @param float   $p 0-100
     * @return float
     */
    private static function percentile_value( array $sorted, float $p ): float {
        $n = count( $sorted );
        if ( 0 === $n ) {
            return 0.0;
        }
        if ( 1 === $n ) {
            return (float) $sorted[0];
        }

        $rank = ( $p / 100 ) * ( $n - 1 );
        $lower = (int) floor( $rank );
        $upper = (int) ceil( $rank );

        if ( $lower === $upper ) {
            return (float) $sorted[ $lower ];
        }

        $weight = $rank - $lower;
        return $sorted[ $lower ] + $weight * ( $sorted[ $upper ] - $sorted[ $lower ] );
    }

    /**
     * Percentil EXACTO de un score dado, contra el array completo de scores
     * ya ordenado (búsqueda binaria: posición de inserción / total). No es
     * una aproximación por interpolación sobre percentiles guardados — es el
     * rank real dentro de los datos completos.
     *
     * @since 1.7.5
     * @param float[] $sorted
     * @param float   $score
     * @return float 0-100
     */
    private static function rank_percentile( array $sorted, float $score ): float {
        $n = count( $sorted );
        if ( 0 === $n ) {
            return 0.0;
        }

        $lo = 0;
        $hi = $n;
        while ( $lo < $hi ) {
            $mid = intdiv( $lo + $hi, 2 );
            if ( $sorted[ $mid ] <= $score ) {
                $lo = $mid + 1;
            } else {
                $hi = $mid;
            }
        }

        return ( $lo / $n ) * 100;
    }

    /**
     * Histograma real (no una campana teórica): número de bins calculado
     * automáticamente con la regla de Freedman–Diaconis (robusta ante datos
     * sesgados, usa el rango intercuartílico en vez de asumir normalidad).
     * Si el IQR es 0 (muchos valores idénticos, la FD se degenera), cae a la
     * regla de Sturges. Resultado acotado a [MIN_BINS, MAX_BINS] por cordura
     * de renderizado.
     *
     * @since 1.7.5
     * @param float[] $sorted
     * @return array<int, array{from:float, to:float, count:int}>
     */
    private static function build_distribution( array $sorted ): array {
        $n = count( $sorted );
        $min = $sorted[0];
        $max = $sorted[ $n - 1 ];
        $range = $max - $min;

        if ( $range <= 0 || $n < 2 ) {
            return array(
                array(
                    'from'  => $min,
                    'to'    => $max,
                    'count' => $n,
                ),
            );
        }

        $bins = self::calculate_bin_count( $sorted, $range );
        $width = $range / $bins;

        $buckets = array_fill( 0, $bins, 0 );
        foreach ( $sorted as $s ) {
            $idx = (int) floor( ( $s - $min ) / $width );
            if ( $idx >= $bins ) {
                $idx = $bins - 1;
            } elseif ( $idx < 0 ) {
                $idx = 0;
            }
            ++$buckets[ $idx ];
        }

        $distribution = array();
        for ( $i = 0; $i < $bins; $i++ ) {
            $distribution[] = array(
                'from'  => $min + $i * $width,
                'to'    => $min + ( $i + 1 ) * $width,
                'count' => $buckets[ $i ],
            );
        }

        return $distribution;
    }

    /**
     * Regla de Freedman–Diaconis: ancho de bin = 2 * IQR * n^(-1/3).
     * Fallback a Sturges (ceil(log2(n) + 1)) si el IQR es 0.
     *
     * @since 1.7.5
     * @param float[] $sorted
     * @param float   $range
     * @return int
     */
    private static function calculate_bin_count( array $sorted, float $range ): int {
        $n = count( $sorted );
        $q1 = self::percentile_value( $sorted, 25 );
        $q3 = self::percentile_value( $sorted, 75 );
        $iqr = $q3 - $q1;

        $bins = 0;
        if ( $iqr > 0 ) {
            $bin_width = 2 * $iqr * ( $n ** ( -1 / 3 ) );
            if ( $bin_width > 0 ) {
                $bins = (int) ceil( $range / $bin_width );
            }
        }

        if ( $bins <= 0 ) {
            $bins = (int) ceil( log( $n, 2 ) + 1 ); // Sturges.
        }

        return max( self::MIN_BINS, min( self::MAX_BINS, $bins ) );
    }
}
