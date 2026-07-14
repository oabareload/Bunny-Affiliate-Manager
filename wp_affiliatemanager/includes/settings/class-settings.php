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
			'wpam_field_render_mode',
			__( 'Modo de renderizado', 'wp-affiliatemanager' ),
			array( $this, 'render_field_render_mode' ),
			self::PAGE_SLUG,
			'wpam_section_general'
		);

		add_settings_field(
			'wpam_field_display_mode',
			__( 'Modo de visualización (legacy)', 'wp-affiliatemanager' ),
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

		add_settings_field(
			'wpam_field_exclude_admins_from_analytics',
			__( 'Exclude Administrators From Analytics', 'wp-affiliatemanager' ),
			array( $this, 'render_field_exclude_admins_from_analytics' ),
			self::PAGE_SLUG,
			'wpam_section_general'
		);

		// ---------------------------------------------------------------------------
		// Sección: Views Tracking — v1.2.0
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_views',
			__( 'Views Tracking', 'wp-affiliatemanager' ),
			array( $this, 'render_section_views' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_count_admin_views',
			__( 'Count Administrator Views', 'wp-affiliatemanager' ),
			array( $this, 'render_field_count_admin_views' ),
			self::PAGE_SLUG,
			'wpam_section_views'
		);

		add_settings_field(
			'wpam_field_count_logged_in_users',
			__( 'Count Logged-in Users', 'wp-affiliatemanager' ),
			array( $this, 'render_field_count_logged_in_users' ),
			self::PAGE_SLUG,
			'wpam_section_views'
		);

		add_settings_field(
			'wpam_field_count_bot_traffic',
			__( 'Count Bot Traffic', 'wp-affiliatemanager' ),
			array( $this, 'render_field_count_bot_traffic' ),
			self::PAGE_SLUG,
			'wpam_section_views'
		);

		// ---------------------------------------------------------------------------
		// Sección: Recently Viewed Posts — v1.3.0
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_recently_viewed',
			__( 'Recently Viewed Posts', 'wp-affiliatemanager' ),
			array( $this, 'render_section_recently_viewed' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_rv_enabled',
			__( 'Enable Recently Viewed', 'wp-affiliatemanager' ),
			array( $this, 'render_field_rv_enabled' ),
			self::PAGE_SLUG,
			'wpam_section_recently_viewed'
		);

		add_settings_field(
			'wpam_field_rv_auto_insert',
			__( 'Auto-insert After Content', 'wp-affiliatemanager' ),
			array( $this, 'render_field_rv_auto_insert' ),
			self::PAGE_SLUG,
			'wpam_section_recently_viewed'
		);

		add_settings_field(
			'wpam_field_rv_count',
			__( 'Number of Posts', 'wp-affiliatemanager' ),
			array( $this, 'render_field_rv_count' ),
			self::PAGE_SLUG,
			'wpam_section_recently_viewed'
		);

		add_settings_field(
			'wpam_field_rv_title',
			__( 'Block Title', 'wp-affiliatemanager' ),
			array( $this, 'render_field_rv_title' ),
			self::PAGE_SLUG,
			'wpam_section_recently_viewed'
		);

		// ---------------------------------------------------------------------------
		// Sección: Redirect / Interstitial — v0.2.0-alpha2
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_redirect',
			__( 'Redirect / Interstitial', 'wp-affiliatemanager' ),
			array( $this, 'render_section_redirect' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_enable_interstitial',
			__( 'Habilitar página interstitial', 'wp-affiliatemanager' ),
			array( $this, 'render_field_enable_interstitial' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_redirect_delay',
			__( 'Delay de redirect (segundos)', 'wp-affiliatemanager' ),
			array( $this, 'render_field_redirect_delay' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_disclaimer_text',
			__( 'Texto de disclaimer', 'wp-affiliatemanager' ),
			array( $this, 'render_field_disclaimer_text' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_interstitial_title',
			__( 'Título del interstitial', 'wp-affiliatemanager' ),
			array( $this, 'render_field_interstitial_title' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_interstitial_countdown_text',
			__( 'Texto del countdown', 'wp-affiliatemanager' ),
			array( $this, 'render_field_interstitial_countdown_text' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_interstitial_button_text',
			__( 'Texto del botón continuar', 'wp-affiliatemanager' ),
			array( $this, 'render_field_interstitial_button_text' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_show_related_post_excerpt',
			__( 'Show Related Post Excerpt', 'wp-affiliatemanager' ),
			array( $this, 'render_field_show_related_post_excerpt' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		add_settings_field(
			'wpam_field_interstitial_width',
			__( 'Interstitial Width', 'wp-affiliatemanager' ),
			array( $this, 'render_field_interstitial_width' ),
			self::PAGE_SLUG,
			'wpam_section_redirect'
		);

		// ---------------------------------------------------------------------------
		// Sección: Interstitial — Content Slots
		// ---------------------------------------------------------------------------
		add_settings_section(
			'wpam_section_content_slots',
			__( 'Interstitial — Content Slot', 'wp-affiliatemanager' ),
			array( $this, 'render_section_content_slots' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'wpam_field_slot0_type',
			__( 'Slot Type', 'wp-affiliatemanager' ),
			array( $this, 'render_field_slot0_type' ),
			self::PAGE_SLUG,
			'wpam_section_content_slots'
		);

		add_settings_field(
			'wpam_field_slot0_position',
			__( 'Slot Position', 'wp-affiliatemanager' ),
			array( $this, 'render_field_slot0_position' ),
			self::PAGE_SLUG,
			'wpam_section_content_slots'
		);

		add_settings_field(
			'wpam_field_slot0_html',
			__( 'Custom HTML', 'wp-affiliatemanager' ),
			array( $this, 'render_field_slot0_html' ),
			self::PAGE_SLUG,
			'wpam_section_content_slots'
		);

		add_settings_field(
			'wpam_field_slot0_image',
			__( 'Image + Link', 'wp-affiliatemanager' ),
			array( $this, 'render_field_slot0_image' ),
			self::PAGE_SLUG,
			'wpam_section_content_slots'
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
			'wpam_field_link_style',
			__( 'Estilo de template', 'wp-affiliatemanager' ),
			array( $this, 'render_field_link_style' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);

		add_settings_field(
			'wpam_field_button_style',
			__( 'Estilo de botón', 'wp-affiliatemanager' ),
			array( $this, 'render_field_button_style' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);

		add_settings_field(
			'wpam_field_display_content',
			__( 'Contenido de la card', 'wp-affiliatemanager' ),
			array( $this, 'render_field_display_content' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);

		add_settings_field(
			'wpam_field_cta_text',
			__( 'Texto del botón CTA', 'wp-affiliatemanager' ),
			array( $this, 'render_field_cta_text' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);

		add_settings_field(
			'wpam_field_cta_hidden',
			__( 'Ocultar botón CTA', 'wp-affiliatemanager' ),
			array( $this, 'render_field_cta_hidden' ),
			self::PAGE_SLUG,
			'wpam_section_appearance'
		);

		add_settings_field(
			'wpam_field_frontend_order',
			__( 'Orden en frontend', 'wp-affiliatemanager' ),
			array( $this, 'render_field_frontend_order' ),
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
	 * Renderiza la descripción de la sección Redirect.
	 *
	 * @since  0.2.0-alpha2
	 * @return void
	 */
	public function render_section_redirect(): void {
		echo '<p>' . esc_html__( 'Configura la página interstitial que aparece antes de redirigir al sitio externo.', 'wp-affiliatemanager' ) . '</p>';
	}

	/**
	 * Renderiza la descripción de la sección Views Tracking.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function render_section_views(): void {
		echo '<p>' . esc_html__( 'Controla qué tipo de visitantes cuentan para las estadísticas de vistas de posts.', 'wp-affiliatemanager' ) . '</p>';
	}

	/**
	 * Renderiza la descripción de la sección Recently Viewed Posts.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function render_section_recently_viewed(): void {
		echo '<p>' . esc_html__( 'Muestra un bloque con los últimos posts vistos por cada visitante, basado en cookie (sin base de datos).', 'wp-affiliatemanager' ) . '</p>';
	}

	/**
	 * Renderiza el campo enable_interstitial.
	 *
	 * @since  0.2.0-alpha2
	 * @return void
	 */
	public function render_field_enable_interstitial(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['enable_interstitial'] ?? true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][enable_interstitial]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Mostrar página interstitial antes de redirigir', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Si está desactivado, el redirect ocurre instantáneamente sin mostrar ninguna página.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo redirect_delay como select con opciones fijas.
	 *
	 * Opciones: 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60 segundos.
	 * Máximo: 60 s. Valor 0 = bypass instantáneo del interstitial.
	 *
	 * @since  0.2.0-alpha2
	 * @since  0.2.0-alpha3.1 Cambiado de number input a select con opciones fijas.
	 * @return void
	 */
	public function render_field_redirect_delay(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = absint( $options['redirect']['redirect_delay'] ?? 3 );

		$allowed = array( 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60 );

		// Si el valor guardado no está en la lista, usar el más cercano.
		if ( ! in_array( $value, $allowed, true ) ) {
			$value = 5; // fallback
		}
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][redirect_delay]' ); ?>">
			<?php foreach ( $allowed as $seconds ) : ?>
				<option value="<?php echo esc_attr( (string) $seconds ); ?>" <?php selected( $value, $seconds ); ?>>
					<?php
					if ( 0 === $seconds ) {
						esc_html_e( '0s — Redirect instantáneo', 'wp-affiliatemanager' );
					} else {
						/* translators: %d: número de segundos */
						printf( esc_html__( '%ds', 'wp-affiliatemanager' ), $seconds );
					}
					?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Tiempo antes de redirigir. 0s = sin countdown, redirect instantáneo aunque el interstitial esté activado.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo disclaimer_text.
	 *
	 * @since  0.2.0-alpha2
	 * @return void
	 */
	public function render_field_disclaimer_text(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$default = __( 'Los precios, disponibilidad y contenido son responsabilidad del sitio externo.', 'wp-affiliatemanager' );
		$value   = $options['redirect']['disclaimer_text'] ?? $default;
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][disclaimer_text]' ); ?>"
			rows="3"
			class="large-text"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'Texto visible bajo el botón de continuar. Acepta HTML básico.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo interstitial_title.
	 *
	 * @since  0.2.0-alpha3
	 * @return void
	 */
	public function render_field_interstitial_title(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['interstitial_title'] ?? __( 'Estás saliendo de BunnyChase', 'wp-affiliatemanager' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][interstitial_title]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="large-text"
			placeholder="<?php esc_attr_e( 'Estás saliendo de BunnyChase', 'wp-affiliatemanager' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Título principal que aparece en la página de salida.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo interstitial_countdown_text.
	 *
	 * @since  0.2.0-alpha3
	 * @return void
	 */
	public function render_field_interstitial_countdown_text(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['interstitial_countdown_text'] ?? __( 'Redirigiendo en {seconds}s', 'wp-affiliatemanager' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][interstitial_countdown_text]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="large-text"
			placeholder="<?php esc_attr_e( 'Redirigiendo en {seconds}s', 'wp-affiliatemanager' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Usa {seconds} como placeholder dinámico. Ejemplo: "Redirigiendo en {seconds}s"', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo interstitial_button_text.
	 *
	 * @since  0.2.0-alpha3.2
	 * @return void
	 */
	public function render_field_interstitial_button_text(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['interstitial_button_text'] ?? __( 'Continuar', 'wp-affiliatemanager' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][interstitial_button_text]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Continuar', 'wp-affiliatemanager' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Texto del botón principal de la página interstitial.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza la descripción de la sección Apariencia.
	 *
	 * @since  1.0.0
	 * @return void
	 */
	/**
	 * Renderiza el campo show_related_post_excerpt.
	 *
	 * @since  0.2.5
	 * @return void
	 */
	public function render_field_show_related_post_excerpt(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['show_related_post_excerpt'] ?? false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][show_related_post_excerpt]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Show the manual excerpt from the related post.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Only post_excerpt is used. Automatic excerpts and post content are never used.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo interstitial_width.
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_field_interstitial_width(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['redirect']['interstitial_width'] ?? '460';
		$widths  = array(
			'460'  => '460px',
			'600'  => '600px',
			'800'  => '800px',
			'1000' => '1000px',
			'full' => __( 'Full Width', 'wp-affiliatemanager' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[redirect][interstitial_width]' ); ?>">
			<?php foreach ( $widths as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Ancho máximo de la card interstitial. Full Width ocupa todo el viewport.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza la descripción de la sección Content Slots.
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_section_content_slots(): void {
		echo '<p>' . esc_html__( 'Añade un bloque de contenido personalizado dentro de la página interstitial. Ideal para publicidad, banners o promociones temporales.', 'wp-affiliatemanager' ) . '</p>';
	}

	/**
	 * Renderiza el campo tipo de slot (slot 0).
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_field_slot0_type(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['content_slots'][0]['type'] ?? 'none';
		?>
		<select
			name="<?php echo esc_attr( self::OPTION_NAME . '[content_slots][0][type]' ); ?>"
			id="wpam-slot0-type"
		>
			<option value="none"       <?php selected( $value, 'none' ); ?>><?php esc_html_e( 'None (disabled)', 'wp-affiliatemanager' ); ?></option>
			<option value="custom_html" <?php selected( $value, 'custom_html' ); ?>><?php esc_html_e( 'Custom HTML', 'wp-affiliatemanager' ); ?></option>
			<option value="image_link" <?php selected( $value, 'image_link' ); ?>><?php esc_html_e( 'Image + Link', 'wp-affiliatemanager' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Tipo de contenido a mostrar en el slot. Selecciona None para desactivarlo.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo de posición del slot (slot 0).
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_field_slot0_position(): void {
		$options   = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value     = $options['content_slots'][0]['position'] ?? 'after_disclaimer';
		$positions = array(
			'before_disclaimer' => __( 'Before Disclaimer', 'wp-affiliatemanager' ),
			'after_disclaimer'  => __( 'After Disclaimer', 'wp-affiliatemanager' ),
			'before_related'    => __( 'Before Related Post', 'wp-affiliatemanager' ),
			'after_related'     => __( 'After Related Post', 'wp-affiliatemanager' ),
		);
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[content_slots][0][position]' ); ?>">
			<?php foreach ( $positions as $key => $label ) : ?>
				<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $value, $key ); ?>>
					<?php echo esc_html( $label ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<p class="description"><?php esc_html_e( 'Dónde aparece el slot dentro de la card interstitial.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo Custom HTML del slot (slot 0).
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_field_slot0_html(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['content_slots'][0]['html'] ?? '';
		?>
		<textarea
			name="<?php echo esc_attr( self::OPTION_NAME . '[content_slots][0][html]' ); ?>"
			rows="6"
			class="large-text code"
			placeholder="&lt;!-- Adsense, banner, promoción temporal... --&gt;"
		><?php echo esc_textarea( $value ); ?></textarea>
		<p class="description"><?php esc_html_e( 'HTML libre. Se renderiza solo cuando el tipo de slot es Custom HTML. Acepta scripts de redes publicitarias.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza los campos Image + Link del slot (slot 0).
	 *
	 * @since  0.2.6
	 * @return void
	 */
	public function render_field_slot0_image(): void {
		$options   = get_option( self::OPTION_NAME, $this->get_defaults() );
		$slot      = $options['content_slots'][0] ?? array();
		$image_url = $slot['image_url'] ?? '';
		$dest_url  = $slot['dest_url']  ?? '';
		$alt_text  = $slot['alt_text']  ?? '';
		$field     = self::OPTION_NAME . '[content_slots][0]';
		?>
		<table class="form-table" style="margin:0;">
			<tr>
				<th style="padding-left:0;width:140px;"><?php esc_html_e( 'Image URL', 'wp-affiliatemanager' ); ?></th>
				<td>
					<input
						type="url"
						name="<?php echo esc_attr( $field . '[image_url]' ); ?>"
						value="<?php echo esc_attr( $image_url ); ?>"
						class="large-text"
						placeholder="https://example.com/banner.jpg"
					/>
				</td>
			</tr>
			<tr>
				<th style="padding-left:0;"><?php esc_html_e( 'Destination URL', 'wp-affiliatemanager' ); ?></th>
				<td>
					<input
						type="url"
						name="<?php echo esc_attr( $field . '[dest_url]' ); ?>"
						value="<?php echo esc_attr( $dest_url ); ?>"
						class="large-text"
						placeholder="https://example.com/oferta"
					/>
				</td>
			</tr>
			<tr>
				<th style="padding-left:0;"><?php esc_html_e( 'Alt Text', 'wp-affiliatemanager' ); ?></th>
				<td>
					<input
						type="text"
						name="<?php echo esc_attr( $field . '[alt_text]' ); ?>"
						value="<?php echo esc_attr( $alt_text ); ?>"
						class="regular-text"
						placeholder="<?php esc_attr_e( 'Descripción de la imagen', 'wp-affiliatemanager' ); ?>"
					/>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Se renderiza solo cuando el tipo de slot es Image + Link. El bloque completo es clicable.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	public function render_section_appearance(): void {
		echo '<p>' . esc_html__( 'Personaliza la apariencia de los bloques de afiliados en el frontend.', 'wp-affiliatemanager' ) . '</p>';
	}

	// ---------------------------------------------------------------------------
	// Callbacks de campos
	// ---------------------------------------------------------------------------

	/**
	 * Renderiza el campo 'render_mode'.
	 *
	 * Controla cómo se inyectan los links en el frontend:
	 * - disabled:      no se renderiza nada automáticamente.
	 * - after_content: se añade al final del contenido del post.
	 * - before_content: se añade al principio del contenido.
	 * - shortcode_only: solo se muestra si se usa [wpam_links].
	 *
	 * @since  4.0.0
	 * @return void
	 */
	public function render_field_render_mode(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['general']['render_mode'] ?? 'after_content';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[general][render_mode]' ); ?>">
			<option value="disabled" <?php selected( $value, 'disabled' ); ?>>
				<?php esc_html_e( 'Desactivado (no renderizar)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="after_content" <?php selected( $value, 'after_content' ); ?>>
				<?php esc_html_e( 'Después del contenido (automático)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="before_content" <?php selected( $value, 'before_content' ); ?>>
				<?php esc_html_e( 'Antes del contenido (automático)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="shortcode_only" <?php selected( $value, 'shortcode_only' ); ?>>
				<?php esc_html_e( 'Solo shortcode [wpam_links]', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Define dónde y cómo se muestran los bloques de afiliados en el frontend.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

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
	 * Renderiza el campo 'exclude_admins_from_analytics'.
	 *
	 * @since  0.2.5
	 * @return void
	 */
	public function render_field_exclude_admins_from_analytics(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['general']['exclude_admins_from_analytics'] ?? true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[general][exclude_admins_from_analytics]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Do not record analytics clicks for administrators.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Skips click tracking when the current user can manage options.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'count_admin_views'.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function render_field_count_admin_views(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['views']['count_admin_views'] ?? false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[views][count_admin_views]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Count page views made by administrators.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When disabled, visits from users who can manage options are not recorded in wpam_views.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'count_logged_in_users'.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function render_field_count_logged_in_users(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['views']['count_logged_in_users'] ?? true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[views][count_logged_in_users]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Count page views made by logged-in (non-administrator) users.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Administrators are governed separately by "Count Administrator Views" above.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'count_bot_traffic'.
	 *
	 * @since  1.2.0
	 * @return void
	 */
	public function render_field_count_bot_traffic(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['views']['count_bot_traffic'] ?? false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[views][count_bot_traffic]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Count requests identified as bots (simple user-agent heuristic).', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Recommended off. Even when disabled, most bots never trigger the beacon since they do not execute JavaScript.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'recently_viewed.enabled'.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function render_field_rv_enabled(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['recently_viewed']['enabled'] ?? false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[recently_viewed][enabled]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Enable the Recently Viewed Posts feature.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When disabled, no history cookie is set for any visitor.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'recently_viewed.auto_insert'.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function render_field_rv_auto_insert(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['recently_viewed']['auto_insert'] ?? true;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[recently_viewed][auto_insert]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Automatically insert the block at the end of post content.', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Only applies when "Enable Recently Viewed" is also on.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'recently_viewed.count'.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function render_field_rv_count(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = absint( $options['recently_viewed']['count'] ?? 5 );
		?>
		<input
			type="number"
			name="<?php echo esc_attr( self::OPTION_NAME . '[recently_viewed][count]' ); ?>"
			value="<?php echo esc_attr( (string) $value ); ?>"
			min="1"
			max="20"
			class="small-text"
		/>
		<p class="description"><?php esc_html_e( 'How many posts to show in the block (1–20).', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'recently_viewed.title'.
	 *
	 * @since  1.3.0
	 * @return void
	 */
	public function render_field_rv_title(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['recently_viewed']['title'] ?? __( 'Recently Viewed', 'wp-affiliatemanager' );
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[recently_viewed][title]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Recently Viewed', 'wp-affiliatemanager' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Heading shown above the block.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'link_style'.
	 *
	 * @since  4.0.0
	 * @return void
	 */
	public function render_field_link_style(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['link_style'] ?? 'vertical';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][link_style]' ); ?>">
			<option value="vertical" <?php selected( $value, 'vertical' ); ?>>
				<?php esc_html_e( 'Vertical (lista apilada)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="horizontal" <?php selected( $value, 'horizontal' ); ?>>
				<?php esc_html_e( 'Horizontal (fila)', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Disposición visual de los links de afiliado en el frontend.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'display_content'.
	 *
	 * Controla qué elementos visuales se muestran en la card:
	 * - show_logo_and_name: logo + nombre (por defecto).
	 * - show_logo_only:     solo el logo del afiliado.
	 * - show_name_only:     solo el nombre del afiliado.
	 *
	 * @since  0.0.5
	 * @return void
	 */
	public function render_field_display_content(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['display_content'] ?? 'show_logo_and_name';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][display_content]' ); ?>">
			<option value="show_logo_and_name" <?php selected( $value, 'show_logo_and_name' ); ?>>
				<?php esc_html_e( 'Logo + Nombre', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="show_logo_only" <?php selected( $value, 'show_logo_only' ); ?>>
				<?php esc_html_e( 'Solo logo', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="show_name_only" <?php selected( $value, 'show_name_only' ); ?>>
				<?php esc_html_e( 'Solo nombre', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Qué elementos se muestran dentro de cada card de afiliado.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'cta_text'.
	 *
	 * @since  0.0.5
	 * @return void
	 */
	public function render_field_cta_text(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['cta_text'] ?? 'Ver oferta';
		?>
		<input
			type="text"
			name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][cta_text]' ); ?>"
			value="<?php echo esc_attr( $value ); ?>"
			class="regular-text"
			placeholder="<?php esc_attr_e( 'Ver oferta', 'wp-affiliatemanager' ); ?>"
		/>
		<p class="description"><?php esc_html_e( 'Texto del botón CTA. Ejemplos: "Ver oferta", "Comprar", "Disponible aquí".', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'cta_hidden'.
	 *
	 * Cuando está activo, el botón CTA no se renderiza.
	 * La card sigue siendo completamente clicable.
	 *
	 * @since  0.0.5
	 * @return void
	 */
	public function render_field_cta_hidden(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['cta_hidden'] ?? false;
		?>
		<label>
			<input
				type="checkbox"
				name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][cta_hidden]' ); ?>"
				value="1"
				<?php checked( (bool) $value ); ?>
			/>
			<?php esc_html_e( 'Ocultar el botón CTA en las cards', 'wp-affiliatemanager' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'La card seguirá siendo completamente clicable aunque el botón esté oculto.', 'wp-affiliatemanager' ); ?></p>
		<?php
	}

	/**
	 * Renderiza el campo 'frontend_order'.
	 *
	 * Controla el orden visual de las cards en el frontend:
	 * - preserve_post_order: respeta el orden guardado en cada post (drag/drop).
	 * - alphabetical:        ordena por nombre de afiliado al renderizar.
	 *
	 * NOTA: No modifica el orden guardado en DB ni el drag/drop del admin.
	 *
	 * @since  0.0.5
	 * @return void
	 */
	public function render_field_frontend_order(): void {
		$options = get_option( self::OPTION_NAME, $this->get_defaults() );
		$value   = $options['appearance']['frontend_order'] ?? 'preserve_post_order';
		?>
		<select name="<?php echo esc_attr( self::OPTION_NAME . '[appearance][frontend_order]' ); ?>">
			<option value="preserve_post_order" <?php selected( $value, 'preserve_post_order' ); ?>>
				<?php esc_html_e( 'Respetar orden del post (drag & drop)', 'wp-affiliatemanager' ); ?>
			</option>
			<option value="alphabetical" <?php selected( $value, 'alphabetical' ); ?>>
				<?php esc_html_e( 'Orden alfabético por nombre', 'wp-affiliatemanager' ); ?>
			</option>
		</select>
		<p class="description"><?php esc_html_e( 'Solo afecta el orden visual en el frontend. No modifica el orden guardado en el editor.', 'wp-affiliatemanager' ); ?></p>
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
		if ( isset( $input['general']['render_mode'] ) ) {
			$sanitized['general']['render_mode'] = in_array(
				$input['general']['render_mode'],
				array( 'disabled', 'after_content', 'before_content', 'shortcode_only' ),
				true
			) ? $input['general']['render_mode'] : 'after_content';
		}

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
		$sanitized['general']['exclude_admins_from_analytics'] = ! empty( $input['general']['exclude_admins_from_analytics'] );

		// Views Tracking — v1.2.0.
		$sanitized['views']['count_admin_views']     = ! empty( $input['views']['count_admin_views'] );
		$sanitized['views']['count_logged_in_users'] = ! empty( $input['views']['count_logged_in_users'] );
		$sanitized['views']['count_bot_traffic']     = ! empty( $input['views']['count_bot_traffic'] );

		// Recently Viewed Posts — v1.3.0.
		$sanitized['recently_viewed']['enabled']     = ! empty( $input['recently_viewed']['enabled'] );
		$sanitized['recently_viewed']['auto_insert'] = ! empty( $input['recently_viewed']['auto_insert'] );

		$rv_count = absint( $input['recently_viewed']['count'] ?? 5 );
		$sanitized['recently_viewed']['count'] = max( 1, min( 20, $rv_count ) );

		$rv_title = sanitize_text_field( $input['recently_viewed']['title'] ?? '' );
		$sanitized['recently_viewed']['title'] = '' !== $rv_title
			? $rv_title
			: __( 'Recently Viewed', 'wp-affiliatemanager' );

		// Redirect / Interstitial — v0.2.0-alpha2.
		$sanitized['redirect']['enable_interstitial'] = ! empty( $input['redirect']['enable_interstitial'] );
		$sanitized['redirect']['show_related_post_excerpt'] = ! empty( $input['redirect']['show_related_post_excerpt'] );

		// v0.2.0-alpha3.1: delay limitado a valores permitidos del select (0..60, múltiplos de 5).
		$allowed_delays = array( 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55, 60 );
		$delay          = absint( $input['redirect']['redirect_delay'] ?? 5 );
		$delay          = min( $delay, 60 ); // clamp absoluto
		$sanitized['redirect']['redirect_delay'] = in_array( $delay, $allowed_delays, true ) ? $delay : 5;

		$disclaimer = wp_kses_post( $input['redirect']['disclaimer_text'] ?? '' );
		$sanitized['redirect']['disclaimer_text'] = '' !== trim( $disclaimer )
			? $disclaimer
			: __( 'Los precios, disponibilidad y contenido son responsabilidad del sitio externo.', 'wp-affiliatemanager' );

		// v0.2.0-alpha3: textos configurables del interstitial.
		$title = sanitize_text_field( $input['redirect']['interstitial_title'] ?? '' );
		$sanitized['redirect']['interstitial_title'] = '' !== $title
			? $title
			: __( 'Estás saliendo de BunnyChase', 'wp-affiliatemanager' );

		$countdown_text = sanitize_text_field( $input['redirect']['interstitial_countdown_text'] ?? '' );
		$sanitized['redirect']['interstitial_countdown_text'] = '' !== $countdown_text
			? $countdown_text
			: __( 'Redirigiendo en {seconds}s', 'wp-affiliatemanager' );

		// v0.2.0-alpha3.2: texto del botón continuar.
		$button_text = sanitize_text_field( $input['redirect']['interstitial_button_text'] ?? '' );
		$sanitized['redirect']['interstitial_button_text'] = '' !== $button_text
			? $button_text
			: __( 'Continuar', 'wp-affiliatemanager' );

		// v0.2.6: ancho del interstitial.
		$allowed_widths = array( '460', '600', '800', '1000', 'full' );
		$width_val      = sanitize_text_field( $input['redirect']['interstitial_width'] ?? '460' );
		$sanitized['redirect']['interstitial_width'] = in_array( $width_val, $allowed_widths, true ) ? $width_val : '460';

		// v0.2.6: content_slots — array indexado para soporte futuro de múltiples slots.
		$allowed_slot_types     = array( 'none', 'custom_html', 'image_link' );
		$allowed_slot_positions = array( 'before_disclaimer', 'after_disclaimer', 'before_related', 'after_related' );
		$raw_slots              = $input['content_slots'] ?? array();
		$sanitized_slots        = array();

		foreach ( $raw_slots as $index => $raw_slot ) {
			$index = absint( $index );
			if ( ! is_array( $raw_slot ) ) {
				continue;
			}

			$slot_type     = sanitize_text_field( $raw_slot['type'] ?? 'none' );
			$slot_position = sanitize_text_field( $raw_slot['position'] ?? 'after_disclaimer' );

			$sanitized_slots[ $index ] = array(
				'type'      => in_array( $slot_type, $allowed_slot_types, true ) ? $slot_type : 'none',
				'position'  => in_array( $slot_position, $allowed_slot_positions, true ) ? $slot_position : 'after_disclaimer',
				'html' 		=> current_user_can( 'unfiltered_html' ) ? ( $raw_slot['html'] ?? '' ) : wp_kses_post( $raw_slot['html'] ?? '' ),
				'image_url' => esc_url_raw( $raw_slot['image_url'] ?? '' ),
				'dest_url'  => esc_url_raw( $raw_slot['dest_url'] ?? '' ),
				'alt_text'  => sanitize_text_field( $raw_slot['alt_text'] ?? '' ),
			);
		}

		$sanitized['content_slots'] = $sanitized_slots;

		// Appearance.
		if ( isset( $input['appearance']['link_style'] ) ) {
			$sanitized['appearance']['link_style'] = in_array(
				$input['appearance']['link_style'],
				array( 'vertical', 'horizontal' ),
				true
			) ? $input['appearance']['link_style'] : 'vertical';
		}

		if ( isset( $input['appearance']['button_style'] ) ) {
			$sanitized['appearance']['button_style'] = in_array(
				$input['appearance']['button_style'],
				array( 'minimal', 'card', 'banner' ),
				true
			) ? $input['appearance']['button_style'] : 'minimal';
		}

		// display_content.
		if ( isset( $input['appearance']['display_content'] ) ) {
			$sanitized['appearance']['display_content'] = in_array(
				$input['appearance']['display_content'],
				array( 'show_logo_and_name', 'show_logo_only', 'show_name_only' ),
				true
			) ? $input['appearance']['display_content'] : 'show_logo_and_name';
		}

		// cta_text: texto libre, sanitizado como texto plano. Fallback a 'Ver oferta' si queda vacío.
		$cta_text = sanitize_text_field( $input['appearance']['cta_text'] ?? '' );
		$sanitized['appearance']['cta_text'] = '' !== $cta_text ? $cta_text : 'Ver oferta';

		// cta_hidden.
		$sanitized['appearance']['cta_hidden'] = ! empty( $input['appearance']['cta_hidden'] );

		// frontend_order.
		if ( isset( $input['appearance']['frontend_order'] ) ) {
			$sanitized['appearance']['frontend_order'] = in_array(
				$input['appearance']['frontend_order'],
				array( 'preserve_post_order', 'alphabetical' ),
				true
			) ? $input['appearance']['frontend_order'] : 'preserve_post_order';
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
				'render_mode'  => 'after_content',
				'display_mode' => 'automatic',
				'link_target'  => '_blank',
				'nofollow'     => true,
				'track_clicks' => false,
				'exclude_admins_from_analytics' => true,
			),
			'views' => array(
				'count_admin_views'     => false,
				'count_logged_in_users' => true,
				'count_bot_traffic'     => false,
			),
			'recently_viewed' => array(
				'enabled'     => false,
				'auto_insert' => true,
				'count'       => 5,
				'title'       => 'Recently Viewed',
			),
			'redirect' => array(
				'enable_interstitial'          => true,
				'redirect_delay'               => 5,
				'disclaimer_text'              => 'Los precios, disponibilidad y contenido son responsabilidad del sitio externo.',
				'interstitial_title'           => 'Estás saliendo de BunnyChase',
				'interstitial_countdown_text'  => 'Redirigiendo en {seconds}s',
				'interstitial_button_text'     => 'Continuar',
				'show_related_post_excerpt'    => false,
				'interstitial_width'           => '460',
			),
			'content_slots' => array(
				0 => array(
					'type'      => 'none',
					'position'  => 'after_disclaimer',
					'html'      => '',
					'image_url' => '',
					'dest_url'  => '',
					'alt_text'  => '',
				),
			),
			'appearance' => array(
				'link_style'      => 'vertical',
				'template'        => 'default',
				'button_style'    => 'minimal',
				'display_content' => 'show_logo_and_name',
				'cta_text'        => 'Ver oferta',
				'cta_hidden'      => false,
				'frontend_order'  => 'preserve_post_order',
			),
		);
	}
}
