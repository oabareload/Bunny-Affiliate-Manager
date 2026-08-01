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
        $bunny_score = Bunny_Score_Settings::get();
        $factors = $bunny_score['factors'] ?? array();
        $stats = Bunny_Score_Stats_Generator::get_stats();

        if ( isset( $_GET['wpam_stats_regenerated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Estadísticas históricas regeneradas.', 'wp-affiliatemanager' ) . '</p></div>';
        }
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
                        <?php esc_html_e( '2. Factores externos', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <p class="description"><?php esc_html_e( 'Introduce manualmente el valor de cada factor (la columna "Fuente" en Settings te recuerda dónde buscarlo — el plugin no hace ninguna llamada externa). Para cada factor elige un estado: "Tiene valor" (usa el dato de abajo), "No aplica" (no modifica el Bunny Score — solo disponible si el factor lo admite), o "Sin datos" (debería existir información pero no se encontró: aplica la penalización configurada). Si el factor corresponde a un TAG existente del sitio (ej. Fabricante → "Claynel"), búscalo y selécciónalo en "TAG asociado" — mismo autocomplete nativo que el editor de WordPress — y ese TAG se calculará UNA sola vez con el ajuste ya aplicado, en vez de contarse por separado si también lo agregaste en la lista de Tags.', 'wp-affiliatemanager' ); ?></p>

                    <?php if ( empty( $factors ) ) : ?>
                        <p class="wpam-analytics-empty"><?php esc_html_e( 'No hay factores configurados. Ve a "Manage Bunny Score Settings" para añadirlos.', 'wp-affiliatemanager' ); ?></p>
                    <?php else : ?>
                        <table class="form-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Factor', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'Fuente', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'Estado', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'Valor', 'wp-affiliatemanager' ); ?></th>
                                    <th><?php esc_html_e( 'TAG asociado (opcional)', 'wp-affiliatemanager' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ( $factors as $factor ) : ?>
                                    <?php if ( empty( $factor['enabled'] ) || empty( $factor['id'] ) ) { continue; } ?>
                                    <?php
                                    $factor_id = esc_attr( $factor['id'] );
                                    $type_obj = \WP_AffiliateManager\Bunny_Score\Factor_Types\Factor_Type_Registry::get( $factor['type'] ?? 'boolean' );
                                    $supports_na = ! empty( $factor['supports_not_applicable'] );
                                    ?>
                                    <tr class="wpam-bunny-factor-value-row">
                                        <td><?php echo esc_html( $factor['label'] ?? $factor['id'] ); ?></td>
                                        <td><?php echo esc_html( $factor['source_label'] ?? '' ); ?></td>
                                        <td>
                                            <select class="wpam-factor-state" name="factors[<?php echo $factor_id; ?>][state]">
                                                <option value="has_value"><?php esc_html_e( 'Tiene valor', 'wp-affiliatemanager' ); ?></option>
                                                <?php if ( $supports_na ) : ?>
                                                    <option value="not_applicable"><?php esc_html_e( 'No aplica', 'wp-affiliatemanager' ); ?></option>
                                                <?php endif; ?>
                                                <option value="no_data"><?php esc_html_e( 'Sin datos', 'wp-affiliatemanager' ); ?></option>
                                            </select>
                                        </td>
                                        <td class="wpam-factor-value-cell">
                                            <?php $type_obj->render_value_input( $factor ); ?>
                                        </td>
                                        <td class="wpam-factor-tag-picker" data-min-posts-input="#wpam-bunny-score-min-posts-input">
                                            <input type="hidden" class="wpam-factor-tag-id" name="factors[<?php echo $factor_id; ?>][tag_id]" value="" />
                                            <div class="wpam-factor-tag-autocomplete-wrap">
                                                <input
                                                    type="text"
                                                    class="wpam-factor-tag-input newtag form-input-tip"
                                                    data-wp-taxonomy="post_tag"
                                                    autocomplete="off"
                                                    placeholder="<?php esc_attr_e( 'Buscar TAG existente…', 'wp-affiliatemanager' ); ?>"
                                                />
                                            </div>
                                            <div class="wpam-factor-tag-chip" style="display:none;">
                                                <span class="wpam-factor-tag-chip-name"></span>
                                                <button type="button" class="wpam-factor-tag-remove" aria-label="<?php esc_attr_e( 'Quitar TAG asociado', 'wp-affiliatemanager' ); ?>">✕</button>
                                            </div>
                                            <div class="wpam-factor-tag-preview"></div>
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
                    <?php esc_html_e( 'Distribución histórica del sitio', 'wp-affiliatemanager' ); ?>
                </h3>
                <p class="description">
                    <?php if ( $stats && ! empty( $stats['generated_at'] ) ) : ?>
                        <?php
                        printf(
                            /* translators: %s: fecha y hora relativa de la última generación */
                            esc_html__( 'Generada por última vez %s. Se recalcula automáticamente una vez por semana vía WP-Cron.', 'wp-affiliatemanager' ),
                            esc_html(
                                sprintf(
                                    /* translators: %s: tiempo transcurrido, ej. "3 días" */
                                    __( 'hace %s', 'wp-affiliatemanager' ),
                                    human_time_diff( (int) $stats['generated_at'], time() )
                                )
                            )
                        );
                        ?>
                        (<?php echo esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $stats['generated_at'] ) ); ?>)
                    <?php else : ?>
                        <?php esc_html_e( 'Aún no se ha generado la distribución histórica. Se generará automáticamente en el próximo ciclo semanal, o puedes forzarla ahora.', 'wp-affiliatemanager' ); ?>
                    <?php endif; ?>
                </p>

                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <?php wp_nonce_field( 'wpam_regenerate_bunny_score_stats' ); ?>
                    <input type="hidden" name="action" value="wpam_regenerate_bunny_score_stats" />
                    <button type="submit" class="button"><?php esc_html_e( 'Regenerar estadísticas ahora', 'wp-affiliatemanager' ); ?></button>
                </form>
            </div>

            <div class="wpam-analytics-card wpam-analytics-card--full">
                <h3 class="wpam-analytics-card-title">
                    <?php esc_html_e( 'Manage Bunny Score Settings', 'wp-affiliatemanager' ); ?>
                </h3>
                <p class="description"><?php esc_html_e( 'Configure minimum posts per tag for Bunny Score. This value is saved instantly by AJAX when changed.', 'wp-affiliatemanager' ); ?></p>

                <?php $min_posts = absint( $bunny_score['min_posts_per_tag'] ?? 1 ); ?>
                <div class="wpam-settings-row" style="display:grid; gap:12px;">
                    <label for="wpam-bunny-score-min-posts-input" class="screen-reader-text">
                        <?php esc_html_e( 'Minimum posts per tag', 'wp-affiliatemanager' ); ?>
                    </label>
                    <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                        <input
                            id="wpam-bunny-score-min-posts-input"
                            type="number"
                            min="1"
                            step="1"
                            value="<?php echo esc_attr( $min_posts ); ?>"
                            style="width:100px;"
                        />
                        <span id="wpam-bunny-score-min-posts-status" class="wpam-saving-indicator" style="display:inline-block; margin-left:0; font-size:13px; color:var(--wpam-gray-500);"></span>
                    </div>
                    <p class="description" style="margin:0;">
                        <?php esc_html_e( 'Enter the minimum number of posts required per tag for Bunny Score calculations.', 'wp-affiliatemanager' ); ?>
                    </p>
                </div>
            </div>

            <?php
            // v1.7.5: Factores Externos — tabla resumen + modal, deliberadamente
            // FUERA del <form action="options.php"> de arriba: cada factor se
            // guarda de forma individual vía AJAX (Bunny_Score_Admin::ajax_save_factor()),
            // nunca a través del envío global de Settings. Si este bloque
            // viviera dentro de ese <form>, guardar "min. posts por tag" con la
            // tabla ya sin inputs habría mandado bunny_score.factors vacío en
            // cada submit, borrando todos los factores — exactamente el tipo de
            // bug de escritura compartida que ya se corrigió una vez en esta
            // opción.
            self::render_factors_card();
            self::render_factor_modal();
            ?>

        </div>
        <?php
    }

    /**
     * Card "Factores Externos": tabla resumen (sin inputs, sin editor de
     * rangos anidado) + botón "Agregar factor" fuera de la tabla. Toda la
     * edición vive en el modal (`render_factor_modal()`).
     *
     * @since 1.7.5
     * @return void
     */
    private static function render_factors_card(): void {
        ?>
        <div class="wpam-analytics-card wpam-analytics-card--full">
            <div class="wpam-screen-header">
                <div class="wpam-screen-header-info">
                    <h3 class="wpam-analytics-card-title" style="margin:0;">
                        <?php esc_html_e( 'Factores Externos', 'wp-affiliatemanager' ); ?>
                    </h3>
                    <p class="description" style="margin:4px 0 0;"><?php esc_html_e( 'Cada factor se guarda de inmediato al crearlo/editarlo/eliminarlo — no depende de "Guardar cambios" de Settings.', 'wp-affiliatemanager' ); ?></p>
                </div>
                <button type="button" class="button button-primary wpam-btn-primary" id="wpam-add-factor-btn">
                    + <?php esc_html_e( 'Agregar factor', 'wp-affiliatemanager' ); ?>
                </button>
            </div>

            <div id="wpam-factor-ajax-notice" class="wpam-ajax-notice" style="display:none;"></div>

            <div class="wpam-table-wrap" id="wpam-bunny-factors-table-wrap">
                <?php echo \WP_AffiliateManager\Bunny_Score\Bunny_Score_Admin::get_factors_table_html(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
            </div>
        </div>
        <?php
    }

    /**
     * Modal único, reutilizado tanto para crear como para editar (punto 3 del
     * encargo: "no quiero duplicar el formulario de crear y editar"). Se
     * imprime UNA sola vez por carga de página; el JS lo repuebla cada vez
     * que se abre — no hay riesgo de estado compartido entre factores
     * `range_table` distintos porque solo existe una instancia del modal, y
     * su editor de rangos se reconstruye por completo en cada apertura.
     *
     * @since 1.7.5
     * @return void
     */
    private static function render_factor_modal(): void {
        $type_options = \WP_AffiliateManager\Bunny_Score\Factor_Types\Factor_Type_Registry::get_types();
        ?>
        <div class="wpam-modal-overlay" id="wpam-factor-modal-overlay" style="display:none;">
            <div class="wpam-modal" id="wpam-factor-modal" role="dialog" aria-modal="true" aria-labelledby="wpam-factor-modal-title">
                <div class="wpam-modal-header">
                    <h3 id="wpam-factor-modal-title"><?php esc_html_e( 'Agregar factor', 'wp-affiliatemanager' ); ?></h3>
                    <button type="button" class="wpam-modal-close" id="wpam-factor-modal-close" aria-label="<?php esc_attr_e( 'Cerrar', 'wp-affiliatemanager' ); ?>">&times;</button>
                </div>
                <div class="wpam-modal-body">
                    <input type="hidden" id="wpam-factor-original-id" value="" />

                    <div class="wpam-modal-grid">
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-label"><?php esc_html_e( 'Nombre / Etiqueta *', 'wp-affiliatemanager' ); ?></label>
                            <input type="text" id="wpam-factor-label" class="wpam-input" />
                        </div>
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-type"><?php esc_html_e( 'Tipo', 'wp-affiliatemanager' ); ?></label>
                            <select id="wpam-factor-type" class="wpam-input">
                                <?php foreach ( $type_options as $type_id => $type_obj ) : ?>
                                    <option value="<?php echo esc_attr( $type_id ); ?>"><?php echo esc_html( $type_obj->get_label() ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-source"><?php esc_html_e( 'Fuente (informativa)', 'wp-affiliatemanager' ); ?></label>
                            <input type="text" id="wpam-factor-source" class="wpam-input" placeholder="<?php esc_attr_e( 'ej. MyFigureCollection', 'wp-affiliatemanager' ); ?>" />
                        </div>
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-max-positive"><?php esc_html_e( 'Máximo % positivo', 'wp-affiliatemanager' ); ?></label>
                            <input type="number" id="wpam-factor-max-positive" class="wpam-input" step="0.1" min="0" value="0" />
                        </div>
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-max-negative"><?php esc_html_e( 'Máximo % negativo', 'wp-affiliatemanager' ); ?></label>
                            <input type="number" id="wpam-factor-max-negative" class="wpam-input" step="0.1" min="0" value="0" />
                        </div>
                        <div class="wpam-modal-field">
                            <label for="wpam-factor-no-data-penalty"><?php esc_html_e( '% penalización "Sin datos"', 'wp-affiliatemanager' ); ?></label>
                            <input type="number" id="wpam-factor-no-data-penalty" class="wpam-input" step="1" min="0" max="100" value="0" />
                        </div>
                        <div class="wpam-modal-field wpam-modal-field--checkbox">
                            <label><input type="checkbox" id="wpam-factor-supports-na" /> <?php esc_html_e( 'Permitir "No aplica"', 'wp-affiliatemanager' ); ?></label>
                        </div>
                        <div class="wpam-modal-field wpam-modal-field--checkbox">
                            <label><input type="checkbox" id="wpam-factor-enabled" checked /> <?php esc_html_e( 'Activo', 'wp-affiliatemanager' ); ?></label>
                        </div>
                        <div class="wpam-modal-field wpam-modal-field--checkbox">
                            <label><input type="checkbox" id="wpam-factor-optional" /> <?php esc_html_e( 'Optional (compat)', 'wp-affiliatemanager' ); ?></label>
                        </div>
                    </div>

                    <div id="wpam-factor-type-fields"></div>
                </div>
                <div class="wpam-modal-footer">
                    <span class="wpam-modal-error" id="wpam-factor-modal-error" style="display:none;"></span>
                    <button type="button" class="button" id="wpam-factor-modal-cancel"><?php esc_html_e( 'Cancelar', 'wp-affiliatemanager' ); ?></button>
                    <button type="button" class="button button-primary wpam-btn-primary" id="wpam-factor-modal-save"><?php esc_html_e( 'Guardar factor', 'wp-affiliatemanager' ); ?></button>
                    <span class="wpam-saving-indicator" id="wpam-factor-modal-saving" style="display:none;"><?php esc_html_e( 'Guardando…', 'wp-affiliatemanager' ); ?></span>
                </div>
            </div>
        </div>
        <?php
    }
}
