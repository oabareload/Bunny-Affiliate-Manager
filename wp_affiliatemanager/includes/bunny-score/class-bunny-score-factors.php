<?php
/**
 * Bunny Score — Factors model
 *
 * Representa factores configurables (boolean, numeric, label) y su lógica
 * de normalización / cálculo de porcentaje.
 *
 * @package WP_AffiliateManager\Bunny_Score
 */

namespace WP_AffiliateManager\Bunny_Score;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Factors {

    /**
     * Calcula el porcentaje aportado por un factor según su configuración.
     *
     * @param array $config  Configuración del factor.
     * @param mixed $value   Valor suministrado por el administrador (puede ser null).
     * @return float|null    Porcentaje (>= 0) o null si el factor es optional y no aplica.
     */
    public static function compute_percent( array $config, $value ): ?float {
        if ( empty( $config['enabled'] ) ) {
            return null;
        }

        $type = $config['type'] ?? 'boolean';

        // Normalizar parámetros comunes.
        $max_percent = isset( $config['max_percent'] ) ? (float) $config['max_percent'] : 0.0;
        $optional    = ! empty( $config['optional'] );

        switch ( $type ) {
            case 'boolean':
                if ( null === $value ) {
                    return $optional ? null : 0.0;
                }
                $truthy = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
                return $truthy ? $max_percent : 0.0;

            case 'numeric':
                if ( null === $value || $value === '' ) {
                    return $optional ? null : 0.0;
                }

                $v = (float) $value;
                $min = isset( $config['scale_min'] ) ? (float) $config['scale_min'] : 0.0;
                $max = isset( $config['scale_max'] ) ? (float) $config['scale_max'] : 100.0;

                if ( $max <= $min ) {
                    // Degenerate scale: si el valor es >= min se le da max_percent.
                    return $v >= $min ? $max_percent : 0.0;
                }

                // Clamp
                if ( $v <= $min ) {
                    $percent = 0.0;
                } elseif ( $v >= $max ) {
                    $percent = $max_percent;
                } else {
                    $ratio = ( $v - $min ) / ( $max - $min );
                    $percent = $ratio * $max_percent;
                }

                $precision = isset( $config['precision'] ) ? (int) $config['precision'] : 2;
                return round( max( 0.0, $percent ), $precision );

            case 'label':
                if ( null === $value || $value === '' ) {
                    return $optional ? null : 0.0;
                }

                $labels = isset( $config['labels'] ) && is_array( $config['labels'] ) ? $config['labels'] : array();
                $key = (string) $value;
                if ( isset( $labels[ $key ] ) ) {
                    return (float) $labels[ $key ];
                }

                return 0.0;

            default:
                return null;
        }
    }
}
