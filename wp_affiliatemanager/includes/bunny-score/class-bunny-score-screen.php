<?php
/**
 * Bunny Score — Admin screen renderer.
 *
 * Renderizado embebido dentro del wrapper administrativo común del plugin
 * (`Admin_Menu::render_admin_header()` / `render_admin_footer()`). El estilo
 * homologa con el resto de pantallas modernas del plugin (Dashboard,
 * Analytics) reutilizando las clases realmente definidas en admin.css:
 * `.wpam-analytics-card` + `.wpam-analytics-card-title` — no las clases
 * inventadas en una revisión anterior (`wpam-section-heading`,
 * `wpam-settings-card`), que no tienen ninguna regla CSS asociada.
 *
 * La selección de etiquetas usa el selector nativo de WordPress (`tagsdiv`
 * + `tagBox`/`tags-suggest`, el mismo mecanismo que el editor de posts) en
 * vez de un `<select multiple>` fijo.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.6.3
 * @version 1.7.0
 */

namespace WP_AffiliateManager\Bunny_Score;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Screen {

    public static function render(): void {
        $options = get_option( WPAM_OPTION_KEY, array() );
        $bunny_score = isset( $options['bunny_score'] ) && is_array( $options['bunny_score'] ) ? $options['bunny_score'] : array();
        $factors = $bunny_score['factors'] ?? array();
        ?>
        <div class="bunny-page-content">

            <form id="wpam-bunny-score-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
                <?php wp_nonce_field( 'wpam_bunny_score_calc' ); ?>
                <input type="hidden" name="action" value="wpam_calculate_bunny_score" />
                <input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'wpam_admin_nonce' ) ); ?>" />

                <div class="wpam-analytics-card wpam-analytics-card--full">
                    <h3 class="wpam-analytics-card-title">
                        <?php esc_html_e( '1. Tags', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <p class="description"><?php esc_html_e( 'Agrega los tags que quieres usar para calcular el Bunny Score. Escribe para buscar (autocompletado) o pulsa Enter/coma para añadir — igual que en el editor de entradas. Si un tag no tiene suficientes publicaciones, se ignora automáticamente.', 'wp-affiliatemanager' ); ?></p>

                    <div class="tagsdiv" id="tagsdiv-wpam-bunny-score-tags">
                        <div class="jaxtag">
                            <div class="nojs-tags hide-if-js">
                                <p><?php esc_html_e( 'Add or remove tags', 'wp-affiliatemanager' ); ?></p>
                                <textarea
                                    name="selected_term_names"
                                    rows="3"
                                    cols="20"
                                    class="the-tags"
                                    id="tax-input-wpam-bunny-score-tags"
                                    aria-describedby="new-tag-wpam-bunny-score-tags-desc"
                                ></textarea>
                            </div>
                            <div class="ajaxtag hide-if-no-js">
                                <label class="screen-reader-text" for="new-tag-wpam-bunny-score-tags">
                                    <?php esc_html_e( 'Tags', 'wp-affiliatemanager' ); ?>
                                </label>
                                <p>
                                    <input
                                        type="text"
                                        id="new-tag-wpam-bunny-score-tags"
                                        name="newtag[wpam-bunny-score-tags]"
                                        class="newtag form-input-tip"
                                        size="16"
                                        autocomplete="off"
                                        aria-describedby="new-tag-wpam-bunny-score-tags-desc"
                                        data-wp-taxonomy="post_tag"
                                    />
                                    <input type="button" class="button tagadd" value="<?php esc_attr_e( 'Add', 'wp-affiliatemanager' ); ?>" />
                                </p>
                            </div>
                            <p class="howto" id="new-tag-wpam-bunny-score-tags-desc">
                                <?php esc_html_e( 'Separate with commas or the Enter key.', 'wp-affiliatemanager' ); ?>
                            </p>
                        </div>
                        <ul class="tagchecklist" role="list"></ul>
                    </div>
                </div>

                <div class="wpam-analytics-card wpam-analytics-card--full">
                    <h3 class="wpam-analytics-card-title">
                        <?php esc_html_e( '2. Factores manuales', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <p class="description"><?php esc_html_e( 'Si existen, completa los valores para los factores configurados más abajo.', 'wp-affiliatemanager' ); ?></p>

                    <?php if ( empty( $factors ) ) : ?>
                        <p class="wpam-analytics-empty"><?php esc_html_e( 'No hay factores configurados. Ve a "Manage Bunny Score Settings" para añadirlos.', 'wp-affiliatemanager' ); ?></p>
                    <?php else : ?>
                        <table class="form-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Factor', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'Tipo', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'Valor', 'wp-affiliatemanager' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $factors as $factor ) : ?>
                                    <?php if ( empty( $factor['enabled'] ) || empty( $factor['id'] ) ) { continue; } ?>
                                    <tr>
                                        <td><?php echo esc_html( $factor['label'] ?? $factor['id'] ); ?></td>
                                        <td><?php echo esc_html( ucfirst( $factor['type'] ?? 'boolean' ) ); ?></td>
                                        <td>
                                            <?php
                                            $factor_id = esc_attr( $factor['id'] );
                                            switch ( $factor['type'] ?? 'boolean' ) :
                                                case 'numeric':
                                                    ?>
                                                    <input
                                                        type="number"
                                                        name="factors[<?php echo $factor_id; ?>]"
                                                        value=""
                                                        min="0"
                                                        step="0.01"
                                                        style="width:120px;"
                                                    />
                                                    <?php
                                                    break;
                                                case 'label':
                                                    if ( ! empty( $factor['labels'] ) && is_array( $factor['labels'] ) ) :
                                                        ?>
                                                        <select name="factors[<?php echo $factor_id; ?>]">
                                                            <option value=""><?php esc_html_e( 'Selecciona una opción', 'wp-affiliatemanager' ); ?></option>
                                                            <?php foreach ( $factor['labels'] as $option_value => $label_text ) : ?>
                                                                <option value="<?php echo esc_attr( $option_value ); ?>"><?php echo esc_html( $label_text ); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <?php
                                                    else :
                                                        ?>
                                                        <input
                                                            type="text"
                                                            name="factors[<?php echo $factor_id; ?>]"
                                                            value=""
                                                            class="regular-text"
                                                        />
                                                        <?php
                                                    endif;
                                                    break;
                                                default:
                                                    ?>
                                                    <label>
                                                        <input
                                                            type="checkbox"
                                                            name="factors[<?php echo $factor_id; ?>]"
                                                            value="1"
                                                        />
                                                        <?php esc_html_e( 'Activar', 'wp-affiliatemanager' ); ?>
                                                    </label>
                                                    <?php
                                                    break;
                                            endswitch;
                                            ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>

                <div class="wpam-analytics-card wpam-analytics-card--full">
                    <h3 class="wpam-analytics-card-title">
                        <?php esc_html_e( '3. Rango histórico', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <p class="description"><?php esc_html_e( 'Elige qué periodo usar para el score histórico.', 'wp-affiliatemanager' ); ?></p>
                    <select name="range">
                        <option value="today"><?php esc_html_e( 'Hoy', 'wp-affiliatemanager' ); ?></option>
                        <option value="week"><?php esc_html_e( 'Últimos 7 días', 'wp-affiliatemanager' ); ?></option>
                        <option value="month"><?php esc_html_e( 'Últimos 30 días', 'wp-affiliatemanager' ); ?></option>
                        <option value="total" selected><?php esc_html_e( 'Total', 'wp-affiliatemanager' ); ?></option>
                    </select>

                    <p>
                        <button class="button wpam-btn-primary" type="submit"><?php esc_html_e( 'Calcular Bunny Score', 'wp-affiliatemanager' ); ?></button>
                    </p>
                </div>

                <div class="wpam-analytics-card wpam-analytics-card--full">
                    <h3 class="wpam-analytics-card-title">
                        <?php esc_html_e( '4. Resultado', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <div id="wpam-bunny-score-result">
                        <p class="wpam-analytics-empty"><?php esc_html_e( 'Resultados aparecerán aquí.', 'wp-affiliatemanager' ); ?></p>
                    </div>
                    <div id="wpam-bunny-score-error" class="wpam-bunny-score-error"></div>
                </div>
            </form>

            <div class="wpam-analytics-card wpam-analytics-card--full">
                <h3 class="wpam-analytics-card-title">
                    <?php esc_html_e( 'Manage Bunny Score Settings', 'wp-affiliatemanager' ); ?>
                </h3>
                <p class="description"><?php esc_html_e( 'Configure minimum posts per tag and factors for Bunny Score. These settings are stored in the plugin options.', 'wp-affiliatemanager' ); ?></p>

                <form method="post" action="options.php">
                    <?php
                    // Use the Settings API group so the same sanitization callbacks are used.
                    settings_fields( \WP_AffiliateManager\Settings\Settings::OPTION_GROUP );
                    // Instantiate Settings helper to reuse renderers.
                    $settings = new \WP_AffiliateManager\Settings\Settings();
                    $settings->render_field_bunny_score_min_posts();
                    $settings->render_field_bunny_score_factors();
                    submit_button( __( 'Save Bunny Score Settings', 'wp-affiliatemanager' ) );
                    ?>
                </form>
            </div>

        </div>
        <?php
    }
}
