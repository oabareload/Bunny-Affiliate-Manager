<?php
/**
 * Bunny Score — Factor Type: label.
 *
 * Lógica movida verbatim desde la antigua `Bunny_Score_Factors::compute_percent()`
 * (case 'label'). Nota de compatibilidad: el mapa `labels` histórico
 * almacenaba porcentajes ABSOLUTOS (no "% del máximo"). Para no romper
 * factores label ya configurados, `value_to_percent_of_max()` reexpresa esos
 * valores absolutos como % del `max_percent_positive` configurado (con
 * fallback a 100 si el máximo es 0, evitando división por cero) — el
 * resultado final tras pasar por el dispatcher común es idéntico al de antes.
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Factor_Type_Label implements Factor_Type_Interface {

    public function get_id(): string {
        return 'label';
    }

    public function get_label(): string {
        return __( 'Label', 'wp-affiliatemanager' );
    }

    public function value_to_percent_of_max( array $config, $value ): float {
        $labels = isset( $config['labels'] ) && is_array( $config['labels'] ) ? $config['labels'] : array();
        $key = (string) $value;

        if ( ! isset( $labels[ $key ] ) ) {
            return 0.0;
        }

        $absolute_percent = (float) $labels[ $key ];
        $max = isset( $config['max_percent_positive'] )
            ? (float) $config['max_percent_positive']
            : (float) ( $config['max_percent'] ?? 100.0 );

        if ( $max <= 0.0 ) {
            return 0.0;
        }

        return ( $absolute_percent / $max ) * 100;
    }

    public function render_value_input( array $config ): void {
        $factor_id = esc_attr( $config['id'] ?? '' );
        $labels = isset( $config['labels'] ) && is_array( $config['labels'] ) ? $config['labels'] : array();
        ?>
        <?php if ( ! empty( $labels ) ) : ?>
            <select name="factors[<?php echo $factor_id; ?>][value]">
                <option value=""><?php esc_html_e( 'Selecciona una opción', 'wp-affiliatemanager' ); ?></option>
                <?php foreach ( $labels as $option_value => $label_text ) : ?>
                    <option value="<?php echo esc_attr( $option_value ); ?>"><?php echo esc_html( $label_text ); ?></option>
                <?php endforeach; ?>
            </select>
        <?php else : ?>
            <input type="text" name="factors[<?php echo $factor_id; ?>][value]" value="" class="regular-text" />
        <?php endif; ?>
        <?php
    }

    public function sanitize_config( array $raw ): array {
        $labels_json = (string) ( $raw['labels_json'] ?? '' );
        $labels = array();

        if ( '' !== trim( $labels_json ) ) {
            $decoded = json_decode( $labels_json, true );
            if ( is_array( $decoded ) ) {
                foreach ( $decoded as $key => $val ) {
                    $labels[ sanitize_text_field( (string) $key ) ] = (float) $val;
                }
            }
        }

        return array(
            'labels_json' => $labels_json,
            'labels'      => $labels,
        );
    }
}
