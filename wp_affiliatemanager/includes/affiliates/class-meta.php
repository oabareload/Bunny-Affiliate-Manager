<?php
/**
 * Metadata del afiliado — registro y guardado de meta boxes.
 *
 * Responsable de:
 * - Registrar los meta boxes en el CPT wpam_affiliate.
 * - Renderizar los campos del formulario.
 * - Guardar y sanitizar los valores al hacer save_post.
 *
 * @package WP_AffiliateManager\Affiliates
 * @since   2.0.0
 */

namespace WP_AffiliateManager\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Meta
 *
 * Gestiona la metadata de cada afiliado (campos personalizados).
 *
 * Meta keys utilizadas:
 *   _wpam_slug        — Slug interno identificador.
 *   _wpam_param       — Parámetro de URL (tag, ref, aff...).
 *   _wpam_value       — Valor del parámetro (bunny-20...).
 *   _wpam_logo_url    — URL del logo del afiliado.
 *   _wpam_brand_color — Color de marca (hex).
 *   _wpam_active      — Estado activo/inactivo (1 / 0).
 *
 * @since 2.0.0
 */
class Meta {

	/**
	 * Meta keys del afiliado.
	 *
	 * @since 2.0.0
	 */
	const KEY_SLUG        = '_wpam_slug';
	const KEY_PARAM       = '_wpam_param';
	const KEY_VALUE       = '_wpam_value';
	const KEY_LOGO_URL    = '_wpam_logo_url';
	const KEY_BRAND_COLOR = '_wpam_brand_color';
	const KEY_ACTIVE      = '_wpam_active';
	/** @since 0.0.6 */
	const KEY_DOMAINS     = '_wpam_domains';
	/** @since 0.0.6 */
	const KEY_VISIBLE     = '_wpam_visible';

	/**
	 * Registra los meta boxes en el CPT.
	 * Se ejecuta en el hook 'add_meta_boxes'.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function register_meta_boxes(): void {
		add_meta_box(
			'wpam_affiliate_details',
			__( 'Affiliate Details', 'wp-affiliatemanager' ),
			array( $this, 'render_details_meta_box' ),
			CPT::POST_TYPE,
			'normal',
			'high'
		);

		add_meta_box(
			'wpam_affiliate_appearance',
			__( 'Appearance', 'wp-affiliatemanager' ),
			array( $this, 'render_appearance_meta_box' ),
			CPT::POST_TYPE,
			'normal',
			'default'
		);

		add_meta_box(
			'wpam_affiliate_status',
			__( 'Status', 'wp-affiliatemanager' ),
			array( $this, 'render_status_meta_box' ),
			CPT::POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * Renderiza el meta box de detalles del afiliado.
	 *
	 * @since  2.0.0
	 * @param  \WP_Post $post Post actual.
	 * @return void
	 */
	public function render_details_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'wpam_save_affiliate_meta_' . $post->ID, 'wpam_affiliate_nonce' );

		$slug  = get_post_meta( $post->ID, self::KEY_SLUG,  true );
		$param = get_post_meta( $post->ID, self::KEY_PARAM, true );
		$value = get_post_meta( $post->ID, self::KEY_VALUE, true );

		// Parámetros comunes sugeridos.
		$common_params = array( 'tag', 'ref', 'aff', 'affiliate', 'partner', 'via', 'utm_source' );
		?>
		<div class="wpam-meta-box">
			<div class="wpam-field-row">
				<label for="wpam_slug"><?php esc_html_e( 'Affiliate Slug', 'wp-affiliatemanager' ); ?></label>
				<input
					type="text"
					id="wpam_slug"
					name="wpam_slug"
					value="<?php echo esc_attr( $slug ); ?>"
					placeholder="amazon, booking, airbnb..."
					class="wpam-input"
				/>
				<p class="wpam-description"><?php esc_html_e( 'Unique internal identifier. Lowercase, no spaces.', 'wp-affiliatemanager' ); ?></p>
			</div>

			<div class="wpam-field-row wpam-field-row--cols">
				<div class="wpam-field-col">
					<label for="wpam_param"><?php esc_html_e( 'Affiliate Parameter', 'wp-affiliatemanager' ); ?></label>
					<input
						type="text"
						id="wpam_param"
						name="wpam_param"
						value="<?php echo esc_attr( $param ); ?>"
						placeholder="tag"
						class="wpam-input"
						list="wpam_param_suggestions"
					/>
					<datalist id="wpam_param_suggestions">
						<?php foreach ( $common_params as $suggestion ) : ?>
							<option value="<?php echo esc_attr( $suggestion ); ?>">
						<?php endforeach; ?>
					</datalist>
					<p class="wpam-description"><?php esc_html_e( 'URL query parameter name.', 'wp-affiliatemanager' ); ?></p>
				</div>

				<div class="wpam-field-col">
					<label for="wpam_value"><?php esc_html_e( 'Affiliate Value', 'wp-affiliatemanager' ); ?></label>
					<input
						type="text"
						id="wpam_value"
						name="wpam_value"
						value="<?php echo esc_attr( $value ); ?>"
						placeholder="bunny-20"
						class="wpam-input"
					/>
					<p class="wpam-description"><?php esc_html_e( 'Value assigned to the parameter.', 'wp-affiliatemanager' ); ?></p>
				</div>
			</div>

			<?php if ( $param && $value ) : ?>
				<div class="wpam-url-preview">
					<span class="wpam-url-preview-label"><?php esc_html_e( 'URL Preview:', 'wp-affiliatemanager' ); ?></span>
					<code class="wpam-url-preview-code">
						https://example.com/product<strong>?<?php echo esc_html( $param ); ?>=<?php echo esc_html( $value ); ?></strong>
					</code>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Renderiza el meta box de apariencia del afiliado.
	 *
	 * @since  2.0.0
	 * @param  \WP_Post $post Post actual.
	 * @return void
	 */
	public function render_appearance_meta_box( \WP_Post $post ): void {
		$logo_url    = get_post_meta( $post->ID, self::KEY_LOGO_URL,    true );
		$brand_color = get_post_meta( $post->ID, self::KEY_BRAND_COLOR, true );

		if ( ! $brand_color ) {
			$brand_color = '#6c47ff';
		}
		?>
		<div class="wpam-meta-box">
			<div class="wpam-field-row">
				<label for="wpam_logo_url"><?php esc_html_e( 'Logo URL', 'wp-affiliatemanager' ); ?></label>
				<div class="wpam-logo-field">
					<?php if ( $logo_url ) : ?>
						<div class="wpam-logo-preview">
							<img src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'Affiliate logo', 'wp-affiliatemanager' ); ?>" />
						</div>
					<?php endif; ?>
					<div class="wpam-logo-input-wrap">
						<input
							type="url"
							id="wpam_logo_url"
							name="wpam_logo_url"
							value="<?php echo esc_url( $logo_url ); ?>"
							placeholder="https://example.com/logo.png"
							class="wpam-input wpam-input--url"
						/>
						<button
							type="button"
							class="button wpam-media-upload-btn"
							data-target="#wpam_logo_url"
							data-preview=".wpam-logo-preview"
						>
							<?php esc_html_e( 'Select from Media Library', 'wp-affiliatemanager' ); ?>
						</button>
					</div>
					<p class="wpam-description"><?php esc_html_e( 'Direct URL to the affiliate logo image.', 'wp-affiliatemanager' ); ?></p>
				</div>
			</div>

			<div class="wpam-field-row">
				<label for="wpam_brand_color"><?php esc_html_e( 'Brand Color', 'wp-affiliatemanager' ); ?></label>
				<div class="wpam-color-field">
					<input
						type="color"
						id="wpam_brand_color"
						name="wpam_brand_color"
						value="<?php echo esc_attr( $brand_color ); ?>"
						class="wpam-color-input"
					/>
					<input
						type="text"
						id="wpam_brand_color_text"
						name="wpam_brand_color_text_display"
						value="<?php echo esc_attr( $brand_color ); ?>"
						class="wpam-input wpam-input--hex"
						maxlength="7"
						placeholder="#6c47ff"
						readonly
					/>
				</div>
				<p class="wpam-description"><?php esc_html_e( 'Used for the affiliate button/card accent color.', 'wp-affiliatemanager' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el meta box de estado (sidebar).
	 *
	 * @since  2.0.0
	 * @param  \WP_Post $post Post actual.
	 * @return void
	 */
	public function render_status_meta_box( \WP_Post $post ): void {
		$is_active = get_post_meta( $post->ID, self::KEY_ACTIVE, true );

		// Por defecto activo en afiliados nuevos.
		if ( '' === $is_active ) {
			$is_active = '1';
		}
		?>
		<div class="wpam-meta-box wpam-meta-box--status">
			<label class="wpam-toggle-label" for="wpam_active">
				<input
					type="checkbox"
					id="wpam_active"
					name="wpam_active"
					value="1"
					<?php checked( '1', $is_active ); ?>
					class="wpam-toggle-input"
				/>
				<span class="wpam-toggle-slider"></span>
				<span class="wpam-toggle-text">
					<?php echo '1' === $is_active
						? esc_html__( 'Active', 'wp-affiliatemanager' )
						: esc_html__( 'Inactive', 'wp-affiliatemanager' );
					?>
				</span>
			</label>
			<p class="wpam-description">
				<?php esc_html_e( 'Inactive affiliates will not render their links on the frontend.', 'wp-affiliatemanager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Guarda la metadata del afiliado al hacer save_post.
	 * Se ejecuta en el hook 'save_post_{post_type}'.
	 *
	 * @since  2.0.0
	 * @param  int $post_id ID del post que se está guardando.
	 * @return void
	 */
	public function save( int $post_id ): void {
		// Verificar nonce.
		if (
			! isset( $_POST['wpam_affiliate_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['wpam_affiliate_nonce'] ) ),
				'wpam_save_affiliate_meta_' . $post_id
			)
		) {
			return;
		}

		// No guardar en autosaves ni revisiones.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Verificar capacidad.
		if ( ! current_user_can( 'manage_options', $post_id ) ) {
			return;
		}

		// --- Sanitizar y guardar cada campo ---

		// Slug: solo letras, números, guiones.
		if ( isset( $_POST['wpam_slug'] ) ) {
			$slug = sanitize_title( wp_unslash( $_POST['wpam_slug'] ) );
			update_post_meta( $post_id, self::KEY_SLUG, $slug );
		}

		// Parámetro: solo letras, números, guiones bajos.
		if ( isset( $_POST['wpam_param'] ) ) {
			$param = sanitize_key( wp_unslash( $_POST['wpam_param'] ) );
			update_post_meta( $post_id, self::KEY_PARAM, $param );
		}

		// Valor del parámetro: texto plano.
		if ( isset( $_POST['wpam_value'] ) ) {
			$value = sanitize_text_field( wp_unslash( $_POST['wpam_value'] ) );
			update_post_meta( $post_id, self::KEY_VALUE, $value );
		}

		// Logo URL: URL segura.
		if ( isset( $_POST['wpam_logo_url'] ) ) {
			$logo_url = esc_url_raw( wp_unslash( $_POST['wpam_logo_url'] ) );
			update_post_meta( $post_id, self::KEY_LOGO_URL, $logo_url );
		}

		// Brand color: solo hex válido.
		if ( isset( $_POST['wpam_brand_color'] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST['wpam_brand_color'] ) );
			if ( $color ) {
				update_post_meta( $post_id, self::KEY_BRAND_COLOR, $color );
			}
		}

		// Estado activo: checkbox (presente = 1, ausente = 0).
		$is_active = isset( $_POST['wpam_active'] ) ? '1' : '0';
		update_post_meta( $post_id, self::KEY_ACTIVE, $is_active );
	}
}
