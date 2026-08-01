<?php
/**
 * Bunny Score — Factor Type: boolean.
 *
 * Lógica movida verbatim desde la antigua `Bunny_Score_Factors::compute_percent()`
 * (case 'boolean') — cero cambio de comportamiento para factores existentes.
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Factor_Type_Boolean implements Factor_Type_Interface {

    public function get_id(): string {
        return 'boolean';
    }

    public function get_label(): string {
        return __( 'Boolean', 'wp-affiliatemanager' );
    }

    public function value_to_percent_of_max( array $config, $value ): float {
        $truthy = filter_var( $value, FILTER_VALIDATE_BOOLEAN );
        return $truthy ? 100.0 : 0.0;
    }

    public function render_value_input( array $config ): void {
        $factor_id = esc_attr( $config['id'] ?? '' );
        ?>
        <label>
            <input type="checkbox" name="factors[<?php echo $factor_id; ?>][value]" value="1" />
            <?php esc_html_e( 'Activar', 'wp-affiliatemanager' ); ?>
        </label>
        <?php
    }

    public function sanitize_config( array $raw ): array {
        return array();
    }
}
