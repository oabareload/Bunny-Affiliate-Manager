<?php
/**
 * Meta box "Affiliate Links" en posts.
 *
 * Estructura de datos guardada en _wpam_links:
 * [
 *   [
 *     'provider_id'   => 42,
 *     'original_url'  => 'https://...',
 *     'custom_label'  => 'Buy now',
 *     'order'         => 0,
 *   ],
 *   ...
 * ]
 *
 * @package WP_AffiliateManager\Posts
 * @since   3.0.0
 * @version 0.1.4
 */

namespace WP_AffiliateManager\Posts;

use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Affiliates\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Links
 *
 * @since 3.0.0
 */
class Post_Links {

	/** Meta key donde se guardan los links del post. */
	const META_KEY = '_wpam_links';

	/** @var string[] Post types que soportan el meta box. */
	private array $supported_post_types;

	/**
	 * Constructor.
	 *
	 * @since 3.0.0
	 */
	public function __construct() {
		/**
		 * Filtra los post types que muestran el meta box de affiliate links.
		 *
		 * @since 3.0.0
		 * @param string[] $post_types
		 */
		$this->supported_post_types = (array) apply_filters(
			'wpam_post_links_post_types',
			array( 'post' )
		);
	}

	// -------------------------------------------------------------------------
	// Registro del meta box
	// -------------------------------------------------------------------------

	/**
	 * Registra el meta box en todos los post types soportados.
	 * Hook: add_meta_boxes
	 *
	 * @since  3.0.0
	 * @return void
	 */
	public function register_meta_box(): void {
		foreach ( $this->supported_post_types as $post_type ) {
			add_meta_box(
				'wpam_post_links',
				__( 'Affiliate Links', 'wp-affiliatemanager' ),
				array( $this, 'render_meta_box' ),
				$post_type,
				'normal',
				'default'
			);
		}
	}

	// -------------------------------------------------------------------------
	// Renderizado
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el meta box completo.
	 *
	 * v0.1.4: El wrap ahora incluye data-affiliates con dominios pre-normalizados
	 * para que el JS (domain-detector.js) pueda detectar el afiliado sin AJAX.
	 *
	 * @since  3.0.0
	 * @since  0.1.4 Añade data-affiliates al wrap. Elimina select de afiliado.
	 * @param  \WP_Post $post Post actual.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'wpam_save_post_links_' . $post->ID, 'wpam_post_links_nonce' );

		$links = $this->get_links( $post->ID );

		$repo       = new Repository();
		$result     = $repo->find_all( array( 'active' => true, 'orderby' => 'title', 'order' => 'ASC' ) );
		$affiliates = $result['items'];

		// v0.1.4: Pre-normalizar dominios para matching en JS (igual que en Post_Affiliates_Screen).
		$affiliates_json = wp_json_encode( array_map( function( $a ) {
			$domains_list = array();
			if ( $a['domains'] ) {
				foreach ( explode( ',', $a['domains'] ) as $entry ) {
					$normalized = wpam_normalize_domain( $entry );
					if ( $normalized ) {
						$domains_list[] = $normalized;
					}
				}
			}
			return array(
				'id'          => $a['id'],
				'title'       => $a['title'],
				'logo_url'    => $a['logo_url'],
				'brand_color' => $a['brand_color'],
				'param'       => $a['param'],
				'value'       => $a['value'],
				'domains'     => $domains_list,
			);
		}, $affiliates ) );
		?>
		<div class="wpam-post-links" id="wpam-post-links-wrap" data-affiliates="<?php echo esc_attr( $affiliates_json ); ?>">

			<?php if ( empty( $affiliates ) && empty( $links ) ) : ?>

				<div class="wpam-post-links-empty-providers">
					<p>
						<?php
						printf(
							wp_kses(
								/* translators: %s: URL pantalla afiliados */
								__( 'No active affiliates found. <a href="%s">Add your first affiliate</a> to start linking.', 'wp-affiliatemanager' ),
								array( 'a' => array( 'href' => array() ) )
							),
							esc_url( admin_url( 'admin.php?page=wpam-affiliates' ) )
						);
						?>
					</p>
				</div>

			<?php else : ?>

				<div class="wpam-links-list" id="wpam-links-list">
					<?php if ( empty( $links ) ) : ?>
						<div class="wpam-links-placeholder" id="wpam-links-placeholder">
							<span class="wpam-placeholder-icon">🔗</span>
							<span><?php esc_html_e( 'Paste an affiliate URL to start.', 'wp-affiliatemanager' ); ?></span>
						</div>
					<?php else : ?>
						<?php foreach ( $links as $index => $link ) : ?>
							<?php $this->render_link_row( $index, $link, $affiliates ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<div class="wpam-links-footer">
					<button
						type="button"
						class="button wpam-add-link-btn"
						id="wpam-add-link-btn"
						<?php if ( empty( $affiliates ) ) : ?>
							disabled
							title="<?php esc_attr_e( 'Add at least one active affiliate first.', 'wp-affiliatemanager' ); ?>"
						<?php endif; ?>
					>
						+ <?php esc_html_e( 'Add Link', 'wp-affiliatemanager' ); ?>
					</button>
					<span class="wpam-links-count-info" id="wpam-links-count">
						<?php echo esc_html( $this->format_link_count( count( $links ) ) ); ?>
					</span>
					<?php if ( empty( $affiliates ) ) : ?>
						<span class="wpam-footer-warning">
							⚠️ <?php
							printf(
								wp_kses(
									/* translators: %s: URL pantalla afiliados */
									__( '<a href="%s">Activate an affiliate</a> to add new links.', 'wp-affiliatemanager' ),
									array( 'a' => array( 'href' => array() ) )
								),
								esc_url( admin_url( 'admin.php?page=wpam-affiliates' ) )
							);
							?>
						</span>
					<?php endif; ?>
				</div>

			<?php endif; ?>

		</div>

		<!-- Template HTML para nuevas filas (clonado por JS) -->
		<?php if ( ! empty( $affiliates ) ) : ?>
			<script type="text/html" id="wpam-link-row-template">
				<?php $this->render_link_row( '{{INDEX}}', array(), $affiliates ); ?>
			</script>
		<?php endif; ?>
		<?php
	}

	/**
	 * Renderiza una fila de link individual.
	 *
	 * v0.1.4: Eliminado el select de afiliado. El usuario pega la URL y JS detecta
	 * el afiliado automáticamente usando window.WPAMDomainDetector.
	 * El chip de detección y el error inline los gestiona post-links.js.
	 *
	 * @since  3.0.0
	 * @since  0.0.3 Indicador visual de provider huérfano.
	 * @since  0.1.4 Sin select de afiliado. Añade .wpam-detect-preview y .wpam-detect-error.
	 *
	 * @param  int|string $index      Índice (int para existentes, '{{INDEX}}' para template JS).
	 * @param  array      $link       Datos del link.
	 * @param  array      $affiliates Lista de afiliados activos normalizados (no usado en render v0.1.4).
	 * @return void
	 */
	public function render_link_row( int|string $index, array $link, array $affiliates ): void {
		$provider_id  = isset( $link['provider_id'] )  ? absint( $link['provider_id'] )    : 0;
		$original_url = isset( $link['original_url'] ) ? esc_url( $link['original_url'] )  : '';
		$custom_label = isset( $link['custom_label'] ) ? esc_attr( $link['custom_label'] ) : '';
		$order        = isset( $link['order'] )        ? absint( $link['order'] )           : 0;
		$is_orphan    = ! empty( $link['_orphan'] );
		$orphan_name  = isset( $link['_orphan_title'] ) ? esc_html( $link['_orphan_title'] ) : '';

		// Buscar el afiliado actual para el chip de preview inicial (items existentes).
		$detected_aff = null;
		if ( $provider_id && ! $is_orphan ) {
			foreach ( $affiliates as $aff ) {
				if ( (int) $aff['id'] === $provider_id ) {
					$detected_aff = $aff;
					break;
				}
			}
		}

		$preview_url = '';
		if ( $detected_aff && $original_url ) {
			$preview_url = wpam_generate_affiliate_url( $provider_id, $original_url );
		} elseif ( $original_url && $is_orphan ) {
			$preview_url = $original_url;
		}

		$row_classes = 'wpam-link-row';
		if ( $is_orphan ) {
			$row_classes .= ' wpam-link-row--orphan';
		} elseif ( $detected_aff ) {
			$row_classes .= ' wpam-link-row--detected';
		}
		?>
		<div
			class="<?php echo esc_attr( $row_classes ); ?>"
			data-index="<?php echo esc_attr( (string) $index ); ?>"
		>
			<!-- Handle drag & drop (preparado para FASE 4) -->
			<div class="wpam-link-row-handle" title="<?php esc_attr_e( 'Drag to reorder', 'wp-affiliatemanager' ); ?>">
				<span class="wpam-handle-icon">⠿</span>
			</div>

			<div class="wpam-link-row-fields">

				<?php if ( $is_orphan ) : ?>
					<div class="wpam-orphan-notice">
						<span class="wpam-orphan-icon">⚠️</span>
						<span class="wpam-orphan-text">
							<?php
							if ( $orphan_name ) {
								printf(
									/* translators: %s: nombre del afiliado eliminado */
									esc_html__( 'Affiliate "%s" no longer exists or is inactive. Edit the URL or remove this link.', 'wp-affiliatemanager' ),
									esc_html( $orphan_name )
								);
							} else {
								esc_html_e( 'The affiliate for this link no longer exists or is inactive. Edit the URL or remove this link.', 'wp-affiliatemanager' );
							}
							?>
						</span>
					</div>
				<?php endif; ?>

				<!-- Fila superior: URL + chip de detección -->
				<div class="wpam-link-row-top">

					<!-- URL input — único campo de entrada; la detección ocurre aquí -->
					<div class="wpam-link-field wpam-link-field--url">
						<label><?php esc_html_e( 'Affiliate URL', 'wp-affiliatemanager' ); ?></label>
						<input
							type="url"
							name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][original_url]"
							value="<?php echo $original_url; // Already escaped via esc_url() above. ?>"
							placeholder="https://..."
							class="wpam-input wpam-url-input"
							data-index="<?php echo esc_attr( (string) $index ); ?>"
						/>
						<!-- Preview del afiliado detectado (JS lo actualiza en tiempo real) -->
						<div class="wpam-detect-preview">
							<?php if ( $detected_aff ) : ?>
								<?php $this->render_detect_chip( $detected_aff ); ?>
							<?php endif; ?>
						</div>
						<!-- Error inline de detección (JS lo muestra/oculta) -->
						<div class="wpam-detect-error" style="display:none;"></div>
					</div>

				</div>

				<!-- Fila media: label personalizado -->
				<div class="wpam-link-row-middle">
					<div class="wpam-link-field wpam-link-field--label">
						<label>
							<?php esc_html_e( 'Custom Label', 'wp-affiliatemanager' ); ?>
							<span class="wpam-optional"><?php esc_html_e( '(optional)', 'wp-affiliatemanager' ); ?></span>
						</label>
						<input
							type="text"
							name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][custom_label]"
							value="<?php echo $custom_label; // Already escaped via esc_attr() above. ?>"
							placeholder="<?php esc_attr_e( 'e.g. Buy on Amazon', 'wp-affiliatemanager' ); ?>"
							class="wpam-input wpam-label-input"
						/>
					</div>

					<input
						type="hidden"
						name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][order]"
						value="<?php echo esc_attr( (string) $order ); ?>"
						class="wpam-order-input"
					/>
				</div>

				<!-- Preview de URL final (JS lo actualiza al detectar; PHP lo pre-renderiza en items existentes) -->
				<div class="wpam-link-preview" data-index="<?php echo esc_attr( (string) $index ); ?>">
					<?php if ( $preview_url && ! $is_orphan ) : ?>
						<span class="wpam-preview-label"><?php esc_html_e( 'Final URL:', 'wp-affiliatemanager' ); ?></span>
						<code class="wpam-preview-url"><?php echo esc_html( $preview_url ); ?></code>
						<a
							href="<?php echo esc_url( $preview_url ); ?>"
							target="_blank"
							rel="noopener noreferrer"
							class="wpam-preview-open"
							title="<?php esc_attr_e( 'Open in new tab', 'wp-affiliatemanager' ); ?>"
						>↗</a>
					<?php elseif ( $preview_url && $is_orphan ) : ?>
						<span class="wpam-preview-label wpam-preview-label--warning"><?php esc_html_e( 'Original URL (no affiliate applied):', 'wp-affiliatemanager' ); ?></span>
						<code class="wpam-preview-url wpam-preview-url--warning"><?php echo esc_html( $preview_url ); ?></code>
					<?php else : ?>
						<span class="wpam-preview-placeholder"><?php esc_html_e( 'Paste an affiliate URL to detect the affiliate automatically.', 'wp-affiliatemanager' ); ?></span>
					<?php endif; ?>
				</div>

			</div>

			<!-- Botón eliminar -->
			<button
				type="button"
				class="wpam-remove-link-btn"
				title="<?php esc_attr_e( 'Remove this link', 'wp-affiliatemanager' ); ?>"
				aria-label="<?php esc_attr_e( 'Remove affiliate link', 'wp-affiliatemanager' ); ?>"
			>✕</button>

		</div>
		<?php
	}

	/**
	 * Renderiza el chip de preview del afiliado detectado para items existentes.
	 *
	 * @since  0.1.4
	 * @param  array $aff Afiliado normalizado.
	 */
	private function render_detect_chip( array $aff ): void {
		$color    = $aff['brand_color'] ?: '#6c47ff';
		$bg_color = $this->hex_to_rgba( $color, 0.10 );
		$style    = sprintf( '--chip-color:%s;--chip-bg:%s;', esc_attr( $color ), esc_attr( $bg_color ) );
		?>
		<div class="wpam-detect-chip" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( $aff['logo_url'] ) : ?>
				<img class="wpam-detect-chip-logo" src="<?php echo esc_url( $aff['logo_url'] ); ?>" alt="" />
			<?php else : ?>
				<span class="wpam-detect-chip-initial"><?php echo esc_html( strtoupper( substr( $aff['title'], 0, 1 ) ) ); ?></span>
			<?php endif; ?>
			<span class="wpam-detect-chip-name"><?php echo esc_html( $aff['title'] ); ?></span>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Guardado
	// -------------------------------------------------------------------------

	/**
	 * Guarda los links del post.
	 * Hook: save_post
	 *
	 * v0.1.4: Ya no se recibe provider_id del formulario.
	 * El afiliado se detecta automáticamente por dominio usando
	 * Repository::find_by_domain() + wpam_extract_domain_from_url().
	 * Los links sin afiliado coincidente se descartan silenciosamente.
	 *
	 * @since  3.0.0
	 * @since  0.0.3 Order correcto incremental.
	 * @since  0.1.4 Auto-detección de afiliado por dominio. Sin provider_id manual.
	 *
	 * @param  int $post_id ID del post.
	 * @return void
	 */
	public function save( int $post_id ): void {
		// --- Seguridad ---
		if (
			! isset( $_POST['wpam_post_links_nonce'] ) ||
			! wp_verify_nonce(
				sanitize_text_field( wp_unslash( $_POST['wpam_post_links_nonce'] ) ),
				'wpam_save_post_links_' . $post_id
			)
		) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		$post_type = get_post_type( $post_id );
		if ( ! in_array( $post_type, $this->supported_post_types, true ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// --- Obtener datos crudos ---
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$raw_links = isset( $_POST['wpam_links'] ) ? wp_unslash( $_POST['wpam_links'] ) : array();

		if ( ! is_array( $raw_links ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		$repo        = new Repository();
		$valid_links = array();

		foreach ( $raw_links as $raw_link ) {
			if ( ! is_array( $raw_link ) ) {
				continue;
			}

			$raw_url      = trim( (string) ( $raw_link['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( $raw_link['custom_label'] ?? '' );

			if ( ! $raw_url ) {
				continue;
			}

			if ( false === filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			$scheme = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}

			$original_url = esc_url_raw( $raw_url );
			if ( ! $original_url ) {
				continue;
			}

			// v0.1.4: Auto-detección de afiliado por dominio.
			// Reutiliza wpam_extract_domain_from_url() y Repository::find_by_domain()
			// exactamente igual que ajax_save_post_links() en Post_Affiliates_Screen.
			$domain    = wpam_extract_domain_from_url( $original_url );
			$affiliate = $domain ? $repo->find_by_domain( $domain ) : null;

			if ( null === $affiliate ) {
				// Sin afiliado coincidente: descartar silenciosamente.
				// (El error ya se mostró en el cliente vía JS).
				continue;
			}

			$valid_links[] = array(
				'provider_id'  => $affiliate['id'],
				'original_url' => $original_url,
				'custom_label' => $custom_label,
			);
		}

		if ( empty( $valid_links ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// Re-asignar order siempre como índice incremental desde 0.
		foreach ( $valid_links as $i => &$link ) {
			$link['order'] = $i;
		}
		unset( $link );

		update_post_meta( $post_id, self::META_KEY, $valid_links );
	}

	// -------------------------------------------------------------------------
	// Lectura / normalización
	// -------------------------------------------------------------------------

	/**
	 * Retorna los links guardados de un post, normalizados y seguros.
	 *
	 * @since  3.0.0
	 * @since  0.0.3 Maneja providers huérfanos; re-asigna order correcto.
	 *
	 * @param  int $post_id ID del post.
	 * @return array[]
	 */
	public function get_links( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$repo  = new Repository();
		$links = array();
		$order = 0;

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$provider_id  = absint( $item['provider_id'] ?? 0 );
			$original_url = esc_url_raw( (string) ( $item['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( (string) ( $item['custom_label'] ?? '' ) );

			if ( ! $provider_id || ! $original_url ) {
				continue;
			}

			$affiliate    = $repo->find( $provider_id );
			$is_orphan    = false;
			$orphan_title = '';
			$final_url    = '';

			if ( null === $affiliate ) {
				$is_orphan     = true;
				$provider_post = get_post( $provider_id );
				if ( $provider_post instanceof \WP_Post ) {
					$orphan_title = $provider_post->post_title;
				}
			} elseif ( ! $affiliate['active'] ) {
				$is_orphan    = true;
				$orphan_title = $affiliate['title'];
			} else {
				$final_url = wpam_generate_affiliate_url( $provider_id, $original_url );
			}

			$links[] = array(
				'provider_id'   => $provider_id,
				'original_url'  => $original_url,
				'custom_label'  => $custom_label,
				'order'         => $order,
				'final_url'     => $final_url,
				'_orphan'       => $is_orphan,
				'_orphan_title' => $orphan_title,
			);

			$order++;
		}

		return $links;
	}

	// -------------------------------------------------------------------------
	// Helpers privados
	// -------------------------------------------------------------------------

	/**
	 * Formatea el contador de links para el footer del meta box.
	 *
	 * @since  0.0.3
	 */
	private function format_link_count( int $count ): string {
		if ( 0 === $count ) {
			return __( '0 links', 'wp-affiliatemanager' );
		}
		return sprintf(
			/* translators: %d: número de affiliate links */
			_n( '%d link', '%d links', $count, 'wp-affiliatemanager' ),
			$count
		);
	}

	/**
	 * Convierte hex a rgba() para el chip de preview.
	 *
	 * @since  0.1.4
	 */
	private function hex_to_rgba( string $hex, float $alpha ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return 'rgba(108,71,255,0.10)';
		}
		$r = hexdec( substr( $hex, 0, 2 ) );
		$g = hexdec( substr( $hex, 2, 2 ) );
		$b = hexdec( substr( $hex, 4, 2 ) );
		return "rgba({$r},{$g},{$b},{$alpha})";
	}
}
