<?php
/**
 * Bunny Score — Factors dispatcher.
 *
 * Único punto de entrada del cálculo de factores — `Bunny_Score_Manager`
 * llama exclusivamente a `compute_percent()`, con la misma firma que tenía
 * antes de v1.7.5. Internamente ahora resuelve el ESTADO del factor (Tiene
 * valor / No aplica / Sin datos) — un concepto transversal a todos los
 * tipos, resuelto UNA sola vez aquí — y solo delega en el tipo concreto
 * (`Factor_Type_Registry`) la conversión "valor crudo → % del máximo" para
 * el caso "Tiene valor".
 *
 * Estados (v1.7.5 — Factores Externos):
 * - not_applicable → NO modifica el Bunny Score. Retorna null, se excluye
 *   por completo de la suma (Bunny_Score_Manager ya ignora null).
 * - no_data        → SÍ modifica el Bunny Score: aplica una penalización
 *   fija por configuración de ese factor (`no_data_penalty_ratio` % de
 *   `max_percent_negative`). Nunca se confunde con "no aplica".
 * - has_value (o compat: escalar suelto, formato pre-v1.7.5) → delega en
 *   el Factor_Type registrado para ese `type`.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.6.3
 * @since   1.7.5 Reescrito como dispatcher de estado + Factor_Type_Registry.
 *                Misma firma pública, mismo comportamiento para factores
 *                boolean/numeric/label ya configurados (ver cada clase en
 *                factor-types/ para la nota de equivalencia verbatim).
 */

namespace WP_AffiliateManager\Bunny_Score;

use WP_AffiliateManager\Bunny_Score\Factor_Types\Factor_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Factors {

    /**
     * Calcula el porcentaje aportado por un factor según su configuración
     * y el valor/estado suministrado por el administrador.
     *
     * @param array $config    Configuración del factor (incluye 'id', 'type',
     *                         'max_percent'/'max_percent_positive',
     *                         'max_percent_negative', 'supports_not_applicable',
     *                         'no_data_penalty_ratio', y los campos propios
     *                         del tipo: scale_min/max, labels, range_table...).
     * @param mixed $submitted Lo que llegó de $_POST['factors'][id]. Puede ser:
     *                         - un escalar suelto (compat pre-v1.7.5: boolean/
     *                           numeric/label enviaban el valor directo) → se
     *                           trata como estado 'has_value'.
     *                         - un array ['state' => ..., 'value' => ...]
     *                           (formato v1.7.5, lo que pinta cada
     *                           Factor_Type::render_value_input()).
     * @return float|null Porcentaje (puede ser negativo) o null si "No aplica"
     *                     o si el factor está deshabilitado/optional sin valor.
     */
    public static function compute_percent( array $config, $submitted ): ?float {
        if ( empty( $config['enabled'] ) ) {
            return null;
        }

        [ $state, $value ] = self::extract_state( $submitted, $config );

        if ( 'not_applicable' === $state ) {
            return null;
        }

        if ( 'no_data' === $state ) {
            $ratio = isset( $config['no_data_penalty_ratio'] ) ? max( 0.0, min( 100.0, (float) $config['no_data_penalty_ratio'] ) ) : 0.0;
            $max_negative = isset( $config['max_percent_negative'] ) ? (float) $config['max_percent_negative'] : 0.0;
            return -1 * ( $ratio / 100 ) * $max_negative;
        }

        // has_value.
        if ( null === $value || '' === $value ) {
            return ! empty( $config['optional'] ) ? null : 0.0;
        }

        $type = Factor_Type_Registry::get( $config['type'] ?? Factor_Type_Registry::DEFAULT_TYPE );
        // -100..100, firmado (NO clampear a [0,100] aquí — destruiría el
        // caso de penalización de range_table antes de que el signo llegue
        // a decidir qué máximo usar, dos líneas más abajo).
        $ratio_of_max = max( -100.0, min( 100.0, $type->value_to_percent_of_max( $config, $value ) ) ) / 100;

        $max_positive = isset( $config['max_percent_positive'] )
            ? (float) $config['max_percent_positive']
            : (float) ( $config['max_percent'] ?? 0.0 );

        // El signo del ratio decide explícitamente el caso (ver contrato en
        // Factor_Type_Interface::value_to_percent_of_max()):
        //   ratio < 0 → penalización, se aplica sobre max_percent_negative.
        //   ratio = 0 → no modifica el Bunny Score.
        //   ratio > 0 → bonificación, se aplica sobre max_percent_positive.
        // Ejemplo (positivo +8%, negativo -7%): ratio -1.00 → -7%, -0.50 →
        // -3.5%, 0 → 0%, 0.50 → +4%, 1.00 → +8%.
        if ( $ratio_of_max < 0 ) {
            $max_negative = isset( $config['max_percent_negative'] ) ? (float) $config['max_percent_negative'] : 0.0;
            $percent = $ratio_of_max * $max_negative; // ratio ya es negativo.
        } else {
            $percent = $ratio_of_max * $max_positive;
        }

        $precision = isset( $config['precision'] ) ? (int) $config['precision'] : 2;
        return round( $percent, $precision );
    }

    /**
     * Normaliza `$submitted` a `[state, value]`. Retrocompatible: si llega
     * un escalar suelto (o un array sin 'state', como enviaban los factores
     * boolean/numeric/label antes de v1.7.5), se trata como 'has_value' con
     * ese valor directo — ningún factor configurado antes de esta versión
     * cambia de comportamiento.
     *
     * @since 1.7.5
     * @param mixed $submitted
     * @param array $config
     * @return array{0:string,1:mixed}
     */
    private static function extract_state( $submitted, array $config ): array {
        if ( is_array( $submitted ) && array_key_exists( 'state', $submitted ) ) {
            $state = (string) $submitted['state'];
            if ( ! in_array( $state, array( 'has_value', 'not_applicable', 'no_data' ), true ) ) {
                $state = 'has_value';
            }

            // "No aplica" solo es válido si el factor lo admite; si no lo
            // admite, se trata como "sin dato" (has_value con valor vacío),
            // que ya cae en la rama optional/0.0 más abajo.
            if ( 'not_applicable' === $state && empty( $config['supports_not_applicable'] ) ) {
                $state = 'has_value';
                $submitted = array( 'value' => null );
            }

            return array( $state, $submitted['value'] ?? null );
        }

        // Compat pre-v1.7.5: escalar suelto o array plano sin 'state'.
        return array( 'has_value', $submitted );
    }
}
