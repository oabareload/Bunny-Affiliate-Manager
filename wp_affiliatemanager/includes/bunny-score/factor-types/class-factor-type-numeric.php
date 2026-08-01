<?php
/**
 * Bunny Score — Factor Type: numeric.
 *
 * Lógica de conversión movida verbatim desde la antigua
 * `Bunny_Score_Factors::compute_percent()` (case 'numeric') — la escala
 * lineal `scale_min`/`scale_max` es idéntica, solo reexpresada como
 * porcentaje del máximo (0-100) en vez de un porcentaje absoluto, para que
 * el dispatcher común pueda aplicar el signo/máximo real.
 *
 * Cubre "Popularidad de la franquicia" (score normalizado 0-100 de
 * MyAnimeList/Steam/Google Play/App Store/IMDb) usando scale_min=0,
 * scale_max=100 — sin necesidad de una tabla de rangos, porque ya viene
 * normalizado por la fuente externa.
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Factor_Type_Numeric implements Factor_Type_Interface {

    public function get_id(): string {
        return 'numeric';
    }

    public function get_label(): string {
        return __( 'Numeric', 'wp-affiliatemanager' );
    }

    public function value_to_percent_of_max( array $config, $value ): float {
        $v = (float) $value;
        $min = isset( $config['scale_min'] ) ? (float) $config['scale_min'] : 0.0;
        $max = isset( $config['scale_max'] ) ? (float) $config['scale_max'] : 100.0;

        if ( $max <= $min ) {
            // Escala degenerada: si el valor es >= min, 100% del máximo.
            return $v >= $min ? 100.0 : 0.0;
        }

        if ( $v <= $min ) {
            return 0.0;
        }
        if ( $v >= $max ) {
            return 100.0;
        }

        return ( ( $v - $min ) / ( $max - $min ) ) * 100;
    }

    public function render_value_input( array $config ): void {
        $factor_id = esc_attr( $config['id'] ?? '' );
        ?>
        <input
            type="number"
            name="factors[<?php echo $factor_id; ?>][value]"
            value=""
            min="<?php echo esc_attr( $config['scale_min'] ?? 0 ); ?>"
            max="<?php echo esc_attr( $config['scale_max'] ?? 100 ); ?>"
            step="0.01"
            style="width:120px;"
        />
        <?php
    }

    public function sanitize_config( array $raw ): array {
        return array(
            'scale_min' => isset( $raw['scale_min'] ) ? (float) $raw['scale_min'] : 0.0,
            'scale_max' => isset( $raw['scale_max'] ) ? (float) $raw['scale_max'] : 100.0,
        );
    }
}
