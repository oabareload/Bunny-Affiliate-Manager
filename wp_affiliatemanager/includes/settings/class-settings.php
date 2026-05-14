<?php
/**
 * Módulo de Settings — WordPress Settings API.
 *
 * Registra secciones, campos y opciones del plugin usando la API nativa de WordPress.
 *
 * @package WP_AffiliateManager\Settings
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Settings;

// Prevenir acceso directo.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Implementa el sistema de configuración del plugin usando WordPress Settings API.
 * En FASE 1: secciones y campos base preparados.
 * En FASE 2: ampliar con más opciones por módulo.
 *
 * @since 1.0.0
 */
class Settings {

	/**
	 * Grupo de opciones para settings_fields().
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPTION_GROUP = 'wpam_settings_group';

	/**
	 * Nombre de la opción en la DB.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const OPTION_NAME = WPAM_OPTION_KEY;

	/**
	 * Slug de la página de settings.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const PAGE_SLUG = 'wpam-settings';

	/**
	 * Registra settings, secciones y campos en WordPress.
	 * Se ejecuta en 'admin_init'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function register_settings(): void {
		// Registrar la opción principal con su callback de sanitización.
		register_setting(
			self::OPTION_GROUP,
			self::OPTION_NAME,
			array(
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->get_defaults(),
			)
		);

		// ---------------------------------------------------------------------------
		// Sección: General
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_general',
			__( 'Configuración General', 'wp-affiliatemanager' ),
			array( $this, 'render_section_general' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_display_mode',
			__( 'Modo de visualización', 'wp-affiliatemanager' ),
			array( $this, 'render_field_display_mode' ),
			self::PAGE_SLUG,
			'wpam_section_general'
		);

		add_settings_field(
			'wpam_field_link_target',
			__( 'Apertura de enlaces', 'wp-affiliatemanager' ),
			array( $this, 'render_field_link_target' ),
			self::PAGE_SLUG,
			'wpam_section_general'
		);

		add_settings_field(
			'wpam_field_nofollow',
			__( 'Atributo nofollow', 'wp-affiliatemanager' ),
			array( $this, 'render_field_nofollow' ),
			self::PAGE_SLUG,
			'wpam_section_general'
		);

		// ---------------------------------------------------------------------------
		// Sección: Apariencia
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_appearance',
			__( 'Apariencia', 'wp-affiliatemanager' ),
			array( $this, 'render_section_appearance' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_button_style',
			__( 'Estilo de botón', 'wp-affiliatemanager' ),
			array( $this, 'render_field_button_style' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);
	}

	// ---------------------------------------------------------------------------
	// Callbacks de secciones
	// ---------------------------------------------------------------------------

	/**
	 * Renderiza la descripción de la sección General.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_section_general(): void {
		echo '<p>' . esc_html__( 'Ajusta el comportamiento global de los enlaces de afiliados en tu sitio.', 'wp-affiliatemanager' ) . '</p>';
	}

	/**
	 * Renderiza la descripción de la sección Apariencia.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_section_appearance(): void {
		echo '<p>' . esc_html__( 'Personaliza la apariencia de los bloques de afiliados en el frontend.', 'wp-affiliatemanager' ) . '</p>';
	}

	// ---------------------------------------------------------------------------
	// Callbacks de campos
	// ---------------------------------------------------------------------------

	/**
	 * Renderiza el campo 'display_mode'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_field_display_mode(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['general']['display_mode'] ?? 'automatic';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[general][display_mode]' ); ?>">
			<option value="automatic" <?php selected( $value, 'automatic' ); ?>>
				<?php esc_html_e( 'Automático (al final del contenido)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="manual" <?php selected( $value, 'manual' ); ?>>
				<?php esc_html_e( 'Manual (shortcode)', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Define cómo se muestran los bloques de afiliados en los posts.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'link_target'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_field_link_target(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['general']['link_target'] ?? '_blank';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[general][link_target]' ); ?>">
			<option value="_blank" <?php selected( $value, '_blank' ); ?>>
				<?php esc_html_e( 'Nueva pestaña (_blank)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="_self" <?php selected( $value, '_self' ); ?>>
				<?php esc_html_e( 'Misma pestaña (_self)', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<?php
	}

	/**
	 * Renderiza el campo 'nofollow'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_field_nofollow(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['general']['nofollow'] ?? true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[general][nofollow]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Añadir rel="nofollow" a todos los enlaces de afiliados', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Recomendado para cumplir con las guías de Google para enlaces de afiliados.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'button_style'.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	public function render_field_button_style(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['button_style'] ?? 'minimal';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][button_style]' ); ?>">
			<option value="minimal" <?php selected( $value, 'minimal' ); ?>>
				<?php esc_html_e( 'Minimal', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="card" <?php selected( $value, 'card' ); ?>>
				<?php esc_html_e( 'Card', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="banner" <?php selected( $value, 'banner' ); ?>>
				<?php esc_html_e( 'Banner', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Estilo visual del bloque de afiliado en el frontend.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	// ---------------------------------------------------------------------------
	// Sanitización
	// ---------------------------------------------------------------------------

	/**
	 * Sanitiza todas las opciones antes de guardarlas en la DB.
	 *
	 * @since  1.0.0
	 * @param  mixed $input Datos enviados por el formulario.
	 * @return array Datos sanitizados.
	 */
	public function sanitize_options( mixed $input ): array {
		$defaults  = $this->get_defaults();
		$sanitized = $defaults;

		if ( ! is_array( $input ) ) {
			return $defaults;
		}

		// General.
		if ( isset( $input['general']['display_mode'] ) ) {
			$sanitized['general']['display_mode'] = in_array(
				$input['general']['display_mode'],
				array( 'automatic', 'manual' ),
				true
			) ? $input['general']['display_mode'] : 'automatic';
		}

		if ( isset( $input['general']['link_target'] ) ) {
			$sanitized['general']['link_target'] = in_array(
				$input['general']['link_target'],
				array( '_blank', '_self' ),
				true
			) ? $input['general']['link_target'] : '_blank';
		}

		$sanitized['general']['nofollow'] = ! empty( $input['general']['nofollow'] );

		// Appearance.
		if ( isset( $input['appearance']['button_style'] ) ) {
			$sanitized['appearance']['button_style'] = in_array(
				$input['appearance']['button_style'],
				array( 'minimal', 'card', 'banner' ),
				true
			) ? $input['appearance']['button_style'] : 'minimal';
		}

		return $sanitized;
	}

	/**
	 * Retorna las opciones por defecto del plugin.
	 *
	 * @since  1.0.0
	 * @return array
	 */
	private function get_defaults(): array {
		return array(
			'general' => array(
				'display_mode' => 'automatic',
				'link_target'  => '_blank',
				'nofollow'     => true,
				'track_clicks' => false,
			),
			'appearance' => array(
				'template'     => 'default',
				'button_style' => 'minimal',
			),
		);
	}
}
