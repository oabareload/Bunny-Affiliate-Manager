<?php
/**
 * Bunny Score — Factor Type contract.
 *
 * Cada tipo de factor (boolean, numeric, label, range_table, y cualquier
 * tipo futuro) implementa esta interfaz. El motor de cálculo nunca conoce
 * los tipos concretos — solo pasa por `Factor_Type_Registry`.
 *
 * IMPORTANTE: un Factor_Type NUNCA decide "No aplica" / "Sin datos" / signo /
 * escala máxima. Eso lo resuelve `Bunny_Score_Factors::compute_percent()`
 * (único punto de entrada, sin cambios de firma) ANTES de delegar aquí. Un
 * Factor_Type solo sabe convertir un valor YA presente en un porcentaje del
 * ajuste máximo (0-100) — nada más.
 *
 * @package WP_AffiliateManager\Bunny_Score\Factor_Types
 * @since   1.7.5
 */

namespace WP_AffiliateManager\Bunny_Score\Factor_Types;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

interface Factor_Type_Interface {

    /**
     * Identificador único, guardado en `factor['type']`.
     *
     * @return string
     */
    public function get_id(): string;

    /**
     * Nombre visible en el <select> de tipo, en Settings.
     *
     * @return string
     */
    public function get_label(): string;

    /**
     * Convierte un valor YA presente (nunca null, nunca "no aplica"/"sin
     * datos" — esos estados ni siquiera llegan aquí) en un porcentaje DEL
     * AJUSTE MÁXIMO, en el rango -100..100 (firmado). El signo decide
     * explícitamente el caso:
     *   - negativo → penalización: el dispatcher común lo aplica sobre
     *     `max_percent_negative`.
     *   - 0        → no modifica el Bunny Score.
     *   - positivo → bonificación: se aplica sobre `max_percent_positive`.
     * Tipos que nunca penalizan (boolean, numeric, label) simplemente nunca
     * devuelven valores negativos — es un subconjunto válido del contrato,
     * no una excepción. Este método NO decide qué máximo usar ni aplica
     * ningún signo por su cuenta más allá de devolver el ratio tal cual.
     *
     * @param array $config Configuración completa del factor.
     * @param mixed $value  Valor crudo suministrado por el administrador.
     * @return float -100..100
     */
    public function value_to_percent_of_max( array $config, $value ): float;

    /**
     * Imprime el control de entrada de VALOR en la pantalla "Calcular Bunny
     * Score" (sección Factores Externos) — el selector de estado
     * (Tiene valor / No aplica / Sin datos) lo pinta el llamador común;
     * este método solo pinta el input concreto para el estado "Tiene valor".
     *
     * @param array $config Configuración del factor.
     * @return void
     */
    public function render_value_input( array $config ): void;

    /**
     * Sanitiza la porción de configuración específica de este tipo (ej.
     * `scale_min`/`scale_max`, `labels`, `range_table`). Los campos comunes
     * los sanitiza `Bunny_Score_Settings::sanitize_factors()`.
     *
     * @param array $raw Datos crudos de ese factor tal como llegaron en $_POST.
     * @return array Sub-array sanitizado, para hacer array_merge() con los campos comunes.
     */
    public function sanitize_config( array $raw ): array;
}
