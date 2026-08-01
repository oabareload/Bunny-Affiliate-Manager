<?php
/**
 * Bunny Score — AJAX/admin-post endpoints.
 *
 * Maneja el cálculo en vivo, la regeneración de estadísticas, y (v1.7.5) el
 * CRUD completo de Factores Externos vía modal + AJAX. Menu registration
 * lives exclusively in `Admin\Admin_Menu` (single source of truth for the
 * plugin's admin menu, consistent with every other screen) — this class no
 * longer registers a competing `admin_menu` submenu.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.6.3
 * @version 1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score;

use WP_AffiliateManager\Bunny_Score\Factor_Types\Factor_Type_Registry;
use WP_AffiliateManager\Analytics\Score_Query;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Bunny_Score_Admin {

    public static function register(): void {
        add_action( 'admin_post_wpam_calculate_bunny_score', array( __CLASS__, 'handle_calculation' ) );
        add_action( 'wp_ajax_wpam_calculate_bunny_score', array( __CLASS__, 'handle_calculation' ) );
        add_action( 'admin_post_wpam_regenerate_bunny_score_stats', array( __CLASS__, 'handle_regenerate_stats' ) );

        // v1.7.5: CRUD de Factores Externos vía modal (sin depender del botón
        // global "Guardar cambios" de Settings). Mismo patrón que
        // Affiliates_Screen::ajax_save()/ajax_get_edit_row() (nonce
        // wpam_inline_crud, wp_send_json_success/error, HTML de fila devuelto
        // por el servidor para refrescar sin recargar).
        add_action( 'wp_ajax_wpam_get_bunny_score_factor', array( __CLASS__, 'ajax_get_factor' ) );
        add_action( 'wp_ajax_wpam_save_bunny_score_factor', array( __CLASS__, 'ajax_save_factor' ) );
        add_action( 'wp_ajax_wpam_delete_bunny_score_factor', array( __CLASS__, 'ajax_delete_factor' ) );

        // v1.7.5: min_posts_per_tag ya no pasa por options.php — se guarda
        // solo, mismo patrón nonce/permisos que el CRUD de factores.
        add_action( 'wp_ajax_wpam_save_bunny_score_min_posts', array( __CLASS__, 'ajax_save_min_posts' ) );

        // v1.7.6: preview del TAG asociado a un factor (posts/score histórico
        // en vivo, al seleccionar en el autocomplete nativo).
        add_action( 'wp_ajax_wpam_get_tag_preview', array( __CLASS__, 'ajax_get_tag_preview' ) );
    }

    public static function handle_calculation(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-affiliatemanager' ) );
        }

        if ( defined( 'DOING_AJAX' ) && DOING_AJAX ) {
            check_ajax_referer( 'wpam_admin_nonce', 'nonce' );
        } else {
            check_admin_referer( 'wpam_bunny_score_calc' );
        }

        $selected = self::resolve_selected_terms();

        $raw_factors = isset( $_POST['factors'] ) ? wp_unslash( $_POST['factors'] ) : array();
        $factors = is_array( $raw_factors ) ? $raw_factors : array();

        $bunny_score_settings = Bunny_Score_Settings::get();
        $factor_config = array();

        foreach ( $bunny_score_settings['factors'] ?? array() as $factor ) {
            if ( ! is_array( $factor ) || empty( $factor['enabled'] ) || empty( $factor['id'] ) ) {
                continue;
            }

            // v1.7.5 fix: se pasaba una copia manual de solo 5 campos, lo que
            // rompía en silencio cualquier factor range_table o con
            // penalización (max_percent_negative, no_data_penalty_ratio,
            // supports_not_applicable, range_table nunca llegaban a
            // Bunny_Score_Factors::compute_percent()). Ahora se pasa la
            // definición completa del factor tal cual está guardada — nada
            // queda hardcodeado aquí, cualquier campo que un Factor_Type
            // necesite ya viaja con el resto.
            $factor_id = sanitize_key( (string) $factor['id'] );
            $factor_config[ $factor_id ] = array_merge( $factor, array( 'enabled' => true ) );
        }

        $min_posts = max( 1, absint( $bunny_score_settings['min_posts_per_tag'] ?? 1 ) );
        $range = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : 'total';
        $range = in_array( $range, array( 'today', 'week', 'month', 'total' ), true ) ? $range : 'total';

        $result = Bunny_Score_Manager::calculate(
            $selected,
            $factors,
            array(
                'factors_config'    => $factor_config,
                'min_posts_per_tag' => $min_posts,
                'range'             => $range,
            )
        );

        // v1.7.6: Posición histórica — v2 tiene un único modelo (Bunny Score),
        // ya no hay 3 modelos que ubicar por separado. Lee la distribución ya
        // generada por el cron semanal (una sola get_option(), cero queries).
        // No recalcula el histórico, no toca Bunny_Score_Manager.
        $result['position'] = Bunny_Score_Stats_Generator::build_position_report(
            array(
                'bunny_score' => $result['final']['bunny_score'] ?? null,
            )
        );

        wp_send_json_success( $result );
    }

    /**
     * Regenera manualmente la distribución histórica (mismo trabajo que hace
     * el cron semanal). Vive en Bunny Score, no en Maintenance, porque es una
     * acción exclusiva de esta feature — el botón y la fecha de última
     * generación se muestran directamente en `Bunny_Score_Screen`.
     *
     * @since 1.7.5
     * @return void
     */
    public static function handle_regenerate_stats(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( __( 'Unauthorized', 'wp-affiliatemanager' ) );
        }

        check_admin_referer( 'wpam_regenerate_bunny_score_stats' );

        Bunny_Score_Stats_Generator::generate();

        $redirect = wp_get_referer();
        if ( ! $redirect ) {
            $redirect = admin_url( 'admin.php?page=wpam-bunny-score' );
        }

        wp_safe_redirect( add_query_arg( 'wpam_stats_regenerated', '1', remove_query_arg( 'wpam_stats_regenerated', $redirect ) ) );
        exit;
    }

    /**
     * Resuelve los tags seleccionados por el selector nativo de WordPress
     * (`selected_term_names`, CSV de nombres gestionado por `tagBox`, siempre
     * sobre la taxonomía `post_tag` desde que se eliminó el concepto de
     * grupos en v1.7.5) a una lista plana de términos resueltos.
     *
     * Devuelve también el nombre de cada término (ya disponible aquí desde
     * `get_term_by()`) para que `Bunny_Score_Manager` no tenga que volver a
     * consultarlo al construir el desglose por tag.
     *
     * @since  1.7.0
     * @since  1.7.5 Aplanado: ya no hay grupos, solo `post_tag`.
     * @return array<int, array{term_id:int, taxonomy:string, name:string}>
     */
    private static function resolve_selected_terms(): array {
        $names_csv = isset( $_POST['selected_term_names'] ) ? wp_unslash( $_POST['selected_term_names'] ) : '';
        $names_csv = is_string( $names_csv ) ? $names_csv : '';

        $names = array_filter( array_map( 'trim', explode( ',', $names_csv ) ) );
        if ( empty( $names ) ) {
            return array();
        }

        $selected = array();
        foreach ( $names as $name ) {
            $term = get_term_by( 'name', sanitize_text_field( $name ), 'post_tag' );
            if ( $term && ! is_wp_error( $term ) ) {
                $selected[] = array(
                    'term_id'  => (int) $term->term_id,
                    'taxonomy' => 'post_tag',
                    'name'     => $term->name,
                );
            }
        }

        return $selected;
    }

    // -------------------------------------------------------------------------
    // v1.7.5 — CRUD de Factores Externos (modal + AJAX)
    // -------------------------------------------------------------------------

    /**
     * AJAX: devuelve la configuración completa de un factor, para precargar
     * el modal de edición (incluye `range_table` completo si aplica).
     * action: wpam_get_bunny_score_factor
     *
     * @since 1.7.5
     * @return void
     */
    public static function ajax_get_factor(): void {
        check_ajax_referer( 'wpam_inline_crud', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
        }

        $id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
        if ( '' === $id ) {
            wp_send_json_error( __( 'Missing factor id.', 'wp-affiliatemanager' ) );
        }

        [ , $factors ] = self::read_factors();
        $factor = self::find_factor( $factors, $id );

        if ( null === $factor ) {
            wp_send_json_error( __( 'Factor not found.', 'wp-affiliatemanager' ) );
        }

        wp_send_json_success( array( 'factor' => $factor ) );
    }

    /**
     * AJAX: crea o actualiza UN factor. Guardado inmediato, no depende del
     * botón global "Guardar cambios" de Settings.
     * action: wpam_save_bunny_score_factor
     *
     * Seguridad (punto 7 del encargo): nonce + manage_options ya verificados
     * arriba; el tipo NUNCA se confía tal cual llega de JS —
     * `Bunny_Score_Settings::sanitize_factors()` (reutilizada, sin duplicar
     * lógica) valida el tipo contra `Factor_Type_Registry::get_ids()` y
     * delega la validación de la estructura específica (incl. `range_table`)
     * al `Factor_Type` correspondiente vía `sanitize_config()`.
     *
     * @since 1.7.5
     * @return void
     */
    public static function ajax_save_factor(): void {
        check_ajax_referer( 'wpam_inline_crud', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
        }

        $label = sanitize_text_field( wp_unslash( $_POST['label'] ?? '' ) );
        if ( '' === $label ) {
            wp_send_json_error( __( 'El nombre del factor es obligatorio.', 'wp-affiliatemanager' ) );
        }

        $original_id = sanitize_key( wp_unslash( $_POST['original_id'] ?? '' ) );
        $is_new = ( '' === $original_id );

        // Reconstruye un "factor crudo" con la MISMA forma que ya produce el
        // formulario tradicional (name="...[bunny_score][factors][N][campo]"),
        // para que pase intacto por Bunny_Score_Settings::sanitize_factors()
        // sin necesidad de ningún parseo/validación paralelo.
        $raw_factor = array(
            'id'                       => $original_id, // vacío si es nuevo: el sanitizador ya sabe autogenerarlo desde el label.
            'label'                    => $label,
            'type'                     => sanitize_key( wp_unslash( $_POST['type'] ?? 'boolean' ) ),
            'enabled'                  => ! empty( $_POST['enabled'] ),
            'optional'                 => ! empty( $_POST['optional'] ),
            'max_percent'              => wp_unslash( $_POST['max_percent'] ?? 0 ),
            'max_percent_negative'     => wp_unslash( $_POST['max_percent_negative'] ?? 0 ),
            'supports_not_applicable'  => ! empty( $_POST['supports_not_applicable'] ),
            'no_data_penalty_ratio'    => wp_unslash( $_POST['no_data_penalty_ratio'] ?? 0 ),
            'source_label'             => wp_unslash( $_POST['source_label'] ?? '' ),
            'precision'                => wp_unslash( $_POST['precision'] ?? 2 ),
            // Específico de 'numeric'.
            'scale_min'                => wp_unslash( $_POST['scale_min'] ?? '' ),
            'scale_max'                => wp_unslash( $_POST['scale_max'] ?? '' ),
            // Específico de 'label'.
            'labels_json'              => wp_unslash( $_POST['labels_json'] ?? '' ),
            // Específico de 'range_table'. NO se confía el tipo enviado para
            // decidir qué validar — Factor_Type_Range_Table::sanitize_config()
            // simplemente ignora este campo si el tipo resuelto no es el suyo.
            'range_table'              => self::parse_range_table_from_post(),
        );

        [ $options, $factors ] = self::read_factors();

        $replaced = false;
        if ( ! $is_new ) {
            foreach ( $factors as $index => $existing ) {
                if ( is_array( $existing ) && ( $existing['id'] ?? '' ) === $original_id ) {
                    $factors[ $index ] = $raw_factor;
                    $replaced = true;
                    break;
                }
            }
        }
        if ( ! $replaced ) {
            $factors[] = $raw_factor;
        }

        $saved_factors = self::write_factors( $options, $factors );

        // Encontrar el factor recién guardado por posición relativa (label +
        // ausencia previa) es frágil; en su lugar, comparamos contra el
        // conjunto de ids que YA existían antes de sanitizar para aislar el
        // id nuevo/reemplazado de forma inequívoca.
        $saved_factor = $is_new
            ? self::find_new_factor( $factors, $saved_factors, $original_id )
            : self::find_factor( $saved_factors, $original_id ) ?? self::find_factor_by_label( $saved_factors, $label );

        if ( null === $saved_factor ) {
            wp_send_json_error( __( 'No se pudo guardar el factor.', 'wp-affiliatemanager' ) );
        }

        wp_send_json_success(
            array(
                'factor'   => $saved_factor,
                'row_html' => self::get_factor_row_html( $saved_factor ),
                'is_new'   => $is_new,
            )
        );
    }

    /**
     * AJAX: elimina UN factor por id.
     * action: wpam_delete_bunny_score_factor
     *
     * @since 1.7.5
     * @return void
     */
    public static function ajax_delete_factor(): void {
        check_ajax_referer( 'wpam_inline_crud', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
        }

        $id = sanitize_key( wp_unslash( $_POST['id'] ?? '' ) );
        if ( '' === $id ) {
            wp_send_json_error( __( 'Missing factor id.', 'wp-affiliatemanager' ) );
        }

        [ $options, $factors ] = self::read_factors();

        $filtered = array_values(
            array_filter(
                $factors,
                static fn( $f ) => is_array( $f ) && ( $f['id'] ?? '' ) !== $id
            )
        );

        if ( count( $filtered ) === count( $factors ) ) {
            wp_send_json_error( __( 'Factor not found.', 'wp-affiliatemanager' ) );
        }

        self::write_factors( $options, $filtered );

        wp_send_json_success( array( 'id' => $id ) );
    }

    /**
     * AJAX: guarda únicamente `bunny_score.min_posts_per_tag`, reemplazando
     * por completo el antiguo `<form action="options.php">` de "Manage Bunny
     * Score Settings". Mismo patrón de nonce/permisos/lectura-modificación-
     * escritura que el CRUD de factores: se lee la opción COMPLETA, se toca
     * únicamente esta clave, y se reescribe entera — el resto de
     * `wpam_settings` (incluido `bunny_score.factors`) viaja intacto.
     * action: wpam_save_bunny_score_min_posts
     *
     * @since 1.7.5
     * @return void
     */
    public static function ajax_save_min_posts(): void {
        check_ajax_referer( 'wpam_inline_crud', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
        }

        $value = Bunny_Score_Settings::sanitize_min_posts( wp_unslash( $_POST['value'] ?? 1 ) );

        $settings = Bunny_Score_Settings::get();
        $settings['min_posts_per_tag'] = $value;

        Bunny_Score_Settings::update( $settings );

        wp_send_json_success( array( 'value' => $value ) );
    }

    /**
     * AJAX: preview en vivo de un TAG asociado a un factor externo (v1.7.6).
     * Se llama desde el autocomplete nativo de WP al seleccionar un TAG:
     * resuelve el `term_id` (por id o por nombre, según lo que la selección
     * ya tenga a mano) y devuelve exactamente la información que
     * `Bunny_Score_Manager::calculate()` usaría para ese TAG — misma query
     * (`Bunny_Score_Manager::get_post_ids_for_term()`), mismo criterio de
     * "existe" (cumplir `min_posts_per_tag`), para que el preview NUNCA
     * diverja de lo que el cálculo real va a hacer.
     * action: wpam_get_tag_preview
     *
     * @since 1.7.6
     * @return void
     */
    public static function ajax_get_tag_preview(): void {
        check_ajax_referer( 'wpam_inline_crud', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
        }

        $term_id = absint( wp_unslash( $_POST['term_id'] ?? 0 ) );
        $name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $min_posts = max( 1, absint( wp_unslash( $_POST['min_posts'] ?? 1 ) ) );

        $term = null;
        if ( $term_id ) {
            $term = get_term( $term_id, 'post_tag' );
        } elseif ( '' !== $name ) {
            $term = get_term_by( 'name', $name, 'post_tag' );
        }

        if ( ! $term || is_wp_error( $term ) ) {
            wp_send_json_error( __( 'TAG no encontrado.', 'wp-affiliatemanager' ) );
        }

        $resolved_id = (int) $term->term_id;
        $post_ids = Bunny_Score_Manager::get_post_ids_for_term( $resolved_id, 'post_tag' );
        $count = count( $post_ids );

        $historical = null;
        if ( $count >= $min_posts ) {
            $scores = Score_Query::get_scores_for_post_ids( $post_ids, 'total' );
            if ( ! empty( $scores ) ) {
                $historical = array_sum( $scores ) / count( $scores );
            }
        }

        $site = Score_Query::get_global_average( 'total' );

        wp_send_json_success(
            array(
                'term_id'          => $resolved_id,
                'name'             => $term->name,
                'count'            => $count,
                'min_posts'        => $min_posts,
                'historical_score' => $historical,
                'site_avg'         => $site['avg'],
                'uses_global'      => ( null === $historical ),
            )
        );
    }

    // -------------------------------------------------------------------------
    // Helpers de persistencia — leen/escriben SOLO bunny_score.factors dentro
    // de la opción completa, preservando todo lo demás por construcción (se
    // parte de la opción YA guardada, no de defaults — punto 6 del encargo).
    // -------------------------------------------------------------------------

    /**
     * @since 1.7.5
     * @return array{0: array, 1: array} [opción completa, factors[] crudo actual]
     */
    private static function read_factors(): array {
        $settings = Bunny_Score_Settings::get();
        $factors = isset( $settings['factors'] ) && is_array( $settings['factors'] )
            ? $settings['factors']
            : array();
        return array( $settings, $factors );
    }

    /**
     * Sanitiza (reutilizando `Bunny_Score_Settings::sanitize_factors()`, sin
     * duplicar lógica) y guarda. Solo se modifica `bunny_score.factors`; el
     * resto de `$options` — incluido `bunny_score.min_posts_per_tag` y todas
     * las demás secciones (general, views, redirect, appearance...) — viaja
     * intacto porque parteíamos de la opción completa ya leída, no de
     * defaults.
     *
     * @since 1.7.5
     * @param array $options Opción completa (de read_factors()).
     * @param array $factors Array crudo de factores (con el cambio ya aplicado).
     * @return array Factors ya sanitizados, tal como quedaron guardados.
     */
    private static function write_factors( array $options, array $factors ): array {
        $sanitized = Bunny_Score_Settings::sanitize_factors( $factors );

        $options['factors'] = $sanitized;
        Bunny_Score_Settings::update( $options );

        return $sanitized;
    }

    /**
     * @since 1.7.5
     * @param array  $factors
     * @param string $id
     * @return array|null
     */
    private static function find_factor( array $factors, string $id ): ?array {
        foreach ( $factors as $factor ) {
            if ( is_array( $factor ) && ( $factor['id'] ?? '' ) === $id ) {
                return $factor;
            }
        }
        return null;
    }

    /**
     * @since 1.7.5
     * @param array  $factors
     * @param string $label
     * @return array|null
     */
    private static function find_factor_by_label( array $factors, string $label ): ?array {
        foreach ( $factors as $factor ) {
            if ( is_array( $factor ) && ( $factor['label'] ?? '' ) === $label ) {
                return $factor;
            }
        }
        return null;
    }

    /**
     * Para un factor NUEVO (sin id previo), el id real se autogeneró dentro
     * de `Bunny_Score_Settings::sanitize_factors()` a partir del label (o se
     * dedup-sufijó si colisionaba). Lo aislamos comparando contra los ids que
     * ya existían en el array PRE-sanitización.
     *
     * @since 1.7.5
     * @param array  $pre_sanitized_factors  Factors crudos, incluyendo el nuevo con id=''.
     * @param array  $post_sanitized_factors Factors ya sanitizados.
     * @param string $original_id            Vacío para un factor nuevo.
     * @return array|null
     */
    private static function find_new_factor( array $pre_sanitized_factors, array $post_sanitized_factors, string $original_id ): ?array {
        $pre_ids = array();
        foreach ( $pre_sanitized_factors as $f ) {
            if ( is_array( $f ) && '' !== ( $f['id'] ?? '' ) ) {
                $pre_ids[ $f['id'] ] = true;
            }
        }

        foreach ( $post_sanitized_factors as $f ) {
            if ( is_array( $f ) && ! isset( $pre_ids[ $f['id'] ?? '' ] ) ) {
                return $f;
            }
        }

        return null;
    }

    /**
     * Parsea `range_table` desde $_POST tal como lo envía el modal:
     * `range_table[N][min|max|percent_of_max]`. La sanitización numérica y
     * el clamp a [-100,100] los hace `Factor_Type_Range_Table::sanitize_config()`
     * — aquí solo se normaliza la forma del array.
     *
     * @since 1.7.5
     * @return array
     */
    private static function parse_range_table_from_post(): array {
        $raw = isset( $_POST['range_table'] ) ? wp_unslash( $_POST['range_table'] ) : array();
        if ( ! is_array( $raw ) ) {
            return array();
        }

        $rows = array();
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $rows[] = array(
                'min'            => $row['min'] ?? 0,
                'max'            => $row['max'] ?? '',
                'percent_of_max' => $row['percent_of_max'] ?? 0,
            );
        }

        return $rows;
    }

    // -------------------------------------------------------------------------
    // Renderizado — tabla resumen (sin inputs, punto 1 del encargo) y fila.
    // Un solo lugar para generar este HTML: el render inicial de la pantalla
    // (Bunny_Score_Screen) y las respuestas AJAX de save() usan el MISMO
    // método, evitando que la tabla y la fila puedan divergir.
    // -------------------------------------------------------------------------

    /**
     * @since 1.7.5
     * @return string
     */
    public static function get_factors_table_html(): string {
        [ , $factors ] = self::read_factors();

        ob_start();
        ?>
        <table class="wpam-table wpam-bunny-factors-table" id="wpam-bunny-factors-table">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Nombre', 'wp-affiliatemanager' ); ?></th>
                    <th><?php esc_html_e( 'Tipo', 'wp-affiliatemanager' ); ?></th>
                    <th><?php esc_html_e( 'Máx. % (+)', 'wp-affiliatemanager' ); ?></th>
                    <th><?php esc_html_e( 'Máx. % (−)', 'wp-affiliatemanager' ); ?></th>
                    <th><?php esc_html_e( 'Penalización "Sin datos"', 'wp-affiliatemanager' ); ?></th>
                    <th><?php esc_html_e( 'Acciones', 'wp-affiliatemanager' ); ?></th>
                </tr>
            </thead>
            <tbody id="wpam-bunny-factors-tbody">
                <?php if ( empty( $factors ) ) : ?>
                    <tr id="wpam-bunny-factors-empty-row">
                        <td colspan="6" class="wpam-table-empty"><?php esc_html_e( 'No hay factores configurados todavía.', 'wp-affiliatemanager' ); ?></td>
                    </tr>
                <?php else : ?>
                    <?php foreach ( $factors as $factor ) : ?>
                        <?php if ( ! is_array( $factor ) ) { continue; } ?>
                        <?php echo self::get_factor_row_html( $factor ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Fila de RESUMEN únicamente — sin inputs, sin editor de rangos anidado
     * (punto 1 del encargo). Toda la edición vive en el modal.
     *
     * @since 1.7.5
     * @param array $factor
     * @return string
     */
    public static function get_factor_row_html( array $factor ): string {
        $id = esc_attr( $factor['id'] ?? '' );
        $label = $factor['label'] ?? $factor['id'] ?? '';
        $type_id = $factor['type'] ?? 'boolean';
        $type_obj = Factor_Type_Registry::get( $type_id );
        $max_positive = isset( $factor['max_percent_positive'] ) ? (float) $factor['max_percent_positive'] : (float) ( $factor['max_percent'] ?? 0 );
        $max_negative = (float) ( $factor['max_percent_negative'] ?? 0 );
        $no_data_ratio = (float) ( $factor['no_data_penalty_ratio'] ?? 0 );

        ob_start();
        ?>
        <tr class="wpam-table-row" data-id="<?php echo $id; ?>" id="wpam-factor-row-<?php echo $id; ?>">
            <td><?php echo esc_html( $label ); ?></td>
            <td><?php echo esc_html( $type_obj->get_label() ); ?></td>
            <td>+<?php echo esc_html( rtrim( rtrim( number_format( $max_positive, 2 ), '0' ), '.' ) ?: '0' ); ?>%</td>
            <td>−<?php echo esc_html( rtrim( rtrim( number_format( $max_negative, 2 ), '0' ), '.' ) ?: '0' ); ?>%</td>
            <td><?php echo esc_html( rtrim( rtrim( number_format( $no_data_ratio, 1 ), '0' ), '.' ) ?: '0' ); ?>%</td>
            <td class="wpam-col-actions">
                <div class="wpam-row-actions">
                    <button type="button" class="wpam-action-btn wpam-action-btn--edit wpam-factor-edit-btn" data-id="<?php echo $id; ?>" title="<?php esc_attr_e( 'Editar', 'wp-affiliatemanager' ); ?>">✏️</button>
                    <button type="button" class="wpam-action-btn wpam-action-btn--delete wpam-factor-delete-btn" data-id="<?php echo $id; ?>" data-label="<?php echo esc_attr( $label ); ?>" title="<?php esc_attr_e( 'Eliminar', 'wp-affiliatemanager' ); ?>">🗑️</button>
                </div>
            </td>
        </tr>
        <?php
        return (string) ob_get_clean();
    }
}
