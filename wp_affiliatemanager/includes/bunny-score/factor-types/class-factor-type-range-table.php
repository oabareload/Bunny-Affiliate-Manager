<?php
/**
 * Bunny Score — Factor Type: range_table.
 *
 * Tabla de rangos completamente configurable desde Settings — sin
 * percentiles de sitios externos (no existen para cantidades como "figuras
 * publicadas" o "seguidores", y cambiarían constantemente si existieran).
 *
 * Cada tramo define un [min, max|null] sobre el valor crudo (una cantidad,
 * ej. número de figuras) y un `percent_of_max` (0-100) — el % DEL AJUSTE
 * MÁXIMO del factor (positivo o negativo, decidido por el dispatcher común
 * en `Bunny_Score_Factors::compute_percent()`, no aquí). `max => null`
 * representa el último tramo abierto ("500 o más").
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Factor_Type_Range_Table implements Factor_Type_Interface {

    public function get_id(): string {
        return 'range_table';
    }

    public function get_label(): string {
        return __( 'Range table', 'wp-affiliatemanager' );
    }

    /**
     * Cada tramo define un [min, max|null] sobre el valor crudo (una cantidad,
     * ej. número de figuras) y un `percent_of_max` FIRMADO, en el rango
     * -100..100 — el signo decide explícitamente el caso:
     *   - negativo → penalización: se aplica sobre `max_percent_negative`.
     *   - 0        → no modifica el Bunny Score.
     *   - positivo → bonificación: se aplica sobre `max_percent_positive`.
     * El dispatcher común (`Bunny_Score_Factors::compute_percent()`) es
     * quien multiplica este ratio por el máximo correspondiente según su
     * signo — este método solo debe devolver el ratio firmado tal cual,
     * SIN pisar el signo. `max => null` representa el último tramo abierto
     * ("500 o más").
     *
     * @since 1.7.5
     */
    public function value_to_percent_of_max( array $config, $value ): float {
        $ranges = isset( $config['range_table'] ) && is_array( $config['range_table'] ) ? $config['range_table'] : array();
        if ( empty( $ranges ) ) {
            return 0.0;
        }

        $v = (float) $value;

        foreach ( $ranges as $range ) {
            $min = isset( $range['min'] ) ? (float) $range['min'] : 0.0;
            $max = ( isset( $range['max'] ) && '' !== $range['max'] && null !== $range['max'] ) ? (float) $range['max'] : null;

            if ( $v < $min ) {
                continue;
            }
            if ( null !== $max && $v > $max ) {
                continue;
            }

            // -100..100, firmado. NO usar max(0.0, ...) aquí — eso destruiría
            // el caso de penalización (ver nota arriba y en el dispatcher).
            return max( -100.0, min( 100.0, (float) ( $range['percent_of_max'] ?? 0 ) ) );
        }

        return 0.0;
    }

    /**
     * En la pantalla de cálculo, el input de valor para range_table es
     * simplemente la cantidad cruda (ej. número de figuras) — el tramo se
     * resuelve server-side en `value_to_percent_of_max()`.
     *
     * @since 1.7.5
     */
    public function render_value_input( array $config ): void {
        $factor_id = esc_attr( $config['id'] ?? '' );
        ?>
        <input
            type="number"
            name="factors[<?php echo $factor_id; ?>][value]"
            value=""
            min="0"
            step="1"
            style="width:120px;"
            placeholder="<?php esc_attr_e( 'Cantidad', 'wp-affiliatemanager' ); ?>"
        />
        <?php
    }

    public function sanitize_config( array $raw ): array {
        $raw_ranges = isset( $raw['range_table'] ) && is_array( $raw['range_table'] ) ? $raw['range_table'] : array();
        $ranges = array();

        foreach ( $raw_ranges as $range ) {
            if ( ! is_array( $range ) ) {
                continue;
            }

            $min = absint( $range['min'] ?? 0 );
            $max_raw = $range['max'] ?? '';
            $max = ( '' === $max_raw || null === $max_raw ) ? null : absint( $max_raw );
            $pct = isset( $range['percent_of_max'] ) ? (float) $range['percent_of_max'] : 0.0;
            $pct = max( -100.0, min( 100.0, $pct ) );

            $ranges[] = array(
                'min'            => $min,
                'max'            => $max,
                'percent_of_max' => $pct,
            );
        }

        // Ordenar por 'min' ascendente — evita que un orden accidental en el
        // formulario rompa la búsqueda lineal de value_to_percent_of_max().
        usort( $ranges, static fn( $a, $b ) => $a['min'] <=> $b['min'] );

        return array(
            'range_table' => $ranges,
        );
    }
}
