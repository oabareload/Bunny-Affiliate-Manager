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
 *     'order'         => 0,   // Siempre incremental correcto desde 0.
 *   ],
 *   ...
 * ]
 *
 * @package WP_AffiliateManager\Posts
 * @since   3.0.0
 * @version 0.0.3
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
	 * @since  3.0.0
	 * @param  \WP_Post $post Post actual.
	 * @return void
	 */
	public function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'wpam_save_post_links_' . $post->ID, 'wpam_post_links_nonce' );

		$links = $this->get_links( $post->ID );

		// Afiliados activos para el select (incluimos también los del post aunque estén inactivos).
		$repo       = new Repository();
		$result     = $repo->find_all( array( 'active' => true, 'orderby' => 'title', 'order' => 'ASC' ) );
		$affiliates = $result['items'];
		?>
		<div class="wpam-post-links" id="wpam-post-links-wrap">

			<?php if ( empty( $affiliates ) && empty( $links ) ) : ?>

				<!-- Sin afiliados activos y sin links guardados -->
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

				<!-- Lista de links -->
				<div class="wpam-links-list" id="wpam-links-list">
					<?php if ( empty( $links ) ) : ?>
						<div class="wpam-links-placeholder" id="wpam-links-placeholder">
							<span class="wpam-placeholder-icon">🔗</span>
							<span><?php esc_html_e( 'No affiliate links added yet. Click "Add Link" to start.', 'wp-affiliatemanager' ); ?></span>
						</div>
					<?php else : ?>
						<?php foreach ( $links as $index => $link ) : ?>
							<?php $this->render_link_row( $index, $link, $affiliates ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</div>

				<!-- Footer: botón + contador -->
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

		<!-- Template HTML para nuevas filas (clonado por JS, nunca renderizado visualmente) -->
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
	 * @since  3.0.0
	 * @since  0.0.3 Añade indicador visual de provider huérfano.
	 *
	 * @param  int|string $index      Índice (int para existentes, '{{INDEX}}' para template JS).
	 * @param  array      $link       Datos del link. Puede incluir '_orphan' => true si el provider no existe.
	 * @param  array      $affiliates Lista de afiliados activos normalizados.
	 * @return void
	 */
	public function render_link_row( int|string $index, array $link, array $affiliates ): void {
		$provider_id  = isset( $link['provider_id'] )  ? absint( $link['provider_id'] )    : 0;
		$original_url = isset( $link['original_url'] ) ? esc_url( $link['original_url'] )  : '';
		$custom_label = isset( $link['custom_label'] ) ? esc_attr( $link['custom_label'] ) : '';
		$order        = isset( $link['order'] )        ? absint( $link['order'] )           : 0;
		$is_orphan    = ! empty( $link['_orphan'] );
		$orphan_name  = isset( $link['_orphan_title'] ) ? esc_html( $link['_orphan_title'] ) : '';

		// Preview solo si hay datos válidos y el provider existe.
		$preview_url = '';
		if ( $provider_id && $original_url && ! $is_orphan ) {
			$preview_url = wpam_generate_affiliate_url( $provider_id, $original_url );
		} elseif ( $original_url && $is_orphan ) {
			// Provider eliminado: mostrar URL original sin parámetro.
			$preview_url = $original_url;
		}

		$row_classes = 'wpam-link-row';
		if ( $is_orphan ) {
			$row_classes .= ' wpam-link-row--orphan';
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
					<!-- Aviso de provider huérfano -->
					<div class="wpam-orphan-notice">
						<span class="wpam-orphan-icon">⚠️</span>
						<span class="wpam-orphan-text">
							<?php
							if ( $orphan_name ) {
								printf(
									/* translators: %s: nombre del afiliado eliminado */
									esc_html__( 'Affiliate "%s" no longer exists or is inactive. Please select a replacement or remove this link.', 'wp-affiliatemanager' ),
									esc_html( $orphan_name )
								);
							} else {
								esc_html_e( 'The affiliate for this link no longer exists or is inactive. Please select a replacement or remove this link.', 'wp-affiliatemanager' );
							}
							?>
						</span>
					</div>
				<?php endif; ?>

				<!-- Fila superior: provider + URL -->
				<div class="wpam-link-row-top">

					<!-- Provider select -->
					<div class="wpam-link-field wpam-link-field--provider">
						<label><?php esc_html_e( 'Affiliate', 'wp-affiliatemanager' ); ?></label>
						<select
							name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][provider_id]"
							class="wpam-select wpam-provider-select <?php echo $is_orphan ? 'wpam-select--orphan' : ''; ?>"
							data-index="<?php echo esc_attr( (string) $index ); ?>"
						>
							<option value=""><?php esc_html_e( '— Select affiliate —', 'wp-affiliatemanager' ); ?></option>
							<?php foreach ( $affiliates as $affiliate ) : ?>
								<option
									value="<?php echo esc_attr( (string) $affiliate['id'] ); ?>"
									data-param="<?php echo esc_attr( $affiliate['param'] ); ?>"
									data-value="<?php echo esc_attr( $affiliate['value'] ); ?>"
									data-color="<?php echo esc_attr( $affiliate['brand_color'] ); ?>"
									<?php selected( $provider_id, $affiliate['id'] ); ?>
								>
									<?php echo esc_html( $affiliate['title'] ); ?>
									<?php if ( $affiliate['param'] ) : ?>
										(<?php echo esc_html( $affiliate['param'] . '=' . $affiliate['value'] ); ?>)
									<?php endif; ?>
								</option>
							<?php endforeach; ?>
						</select>
					</div>

					<!-- URL input -->
					<div class="wpam-link-field wpam-link-field--url">
						<label><?php esc_html_e( 'Original URL', 'wp-affiliatemanager' ); ?></label>
						<input
							type="url"
							name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][original_url]"
							value="<?php echo $original_url; // Already escaped via esc_url() above. ?>"
							placeholder="https://example.com/product"
							class="wpam-input wpam-url-input"
							data-index="<?php echo esc_attr( (string) $index ); ?>"
						/>
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

					<!--
						Campo order oculto.
						IMPORTANTE: el valor es el índice real de la fila en el DOM.
						JS actualiza este campo en dos momentos:
						  1. Al agregar una fila nueva (via reindexAll).
						  2. Al reordenar mediante drag & drop (FASE 4).
						El PHP re-asigna order = posición final al guardar,
						por lo que este valor solo sirve para preservar el orden durante el envío.
					-->
					<input
						type="hidden"
						name="wpam_links[<?php echo esc_attr( (string) $index ); ?>][order]"
						value="<?php echo esc_attr( (string) $order ); ?>"
						class="wpam-order-input"
					/>

				</div>

				<!-- Preview de URL final -->
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
						<span class="wpam-preview-placeholder"><?php esc_html_e( 'Select an affiliate and enter a URL to see the generated link.', 'wp-affiliatemanager' ); ?></span>
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

	// -------------------------------------------------------------------------
	// Guardado
	// -------------------------------------------------------------------------

	/**
	 * Guarda los links del post.
	 * Hook: save_post
	 *
	 * @since  3.0.0
	 * @since  0.0.3 Order correcto incremental; validación de URL con filter_var.
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

		// --- Sanitizar y validar cada fila ---
		$valid_links = array();

		foreach ( $raw_links as $raw_link ) {
			if ( ! is_array( $raw_link ) ) {
				continue;
			}

			$provider_id  = absint( $raw_link['provider_id'] ?? 0 );
			$raw_url      = trim( (string) ( $raw_link['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( $raw_link['custom_label'] ?? '' );

			// Descartar si falta provider o URL.
			if ( ! $provider_id || ! $raw_url ) {
				continue;
			}

			// Validar URL con filter_var ANTES de sanitizar para detectar URLs malformadas.
			// filter_var con FILTER_VALIDATE_URL rechaza URLs sin esquema, con espacios, etc.
			if ( false === filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
				continue;
			}

			// Solo permitir esquemas seguros.
			$scheme = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				continue;
			}

			// Sanitizar URL después de validarla.
			$original_url = esc_url_raw( $raw_url );
			if ( ! $original_url ) {
				continue;
			}

			// Verificar que el provider existe y es wpam_affiliate.
			$provider_post = get_post( $provider_id );
			if ( ! $provider_post instanceof \WP_Post || CPT::POST_TYPE !== $provider_post->post_type ) {
				continue;
			}

			$valid_links[] = array(
				'provider_id'  => $provider_id,
				'original_url' => $original_url,
				'custom_label' => $custom_label,
				// 'order' se re-asigna a continuación: no confiamos en el valor del cliente.
			);
		}

		if ( empty( $valid_links ) ) {
			delete_post_meta( $post_id, self::META_KEY );
			return;
		}

		// --- Re-asignar order SIEMPRE como índice incremental desde 0 ---
		// No se usa el order enviado por el cliente para evitar el bug de order=0 múltiple.
		// En FASE 4 (drag & drop) se añadirá lógica de reordenación aquí.
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
	 * @since  0.0.3 Maneja providers huérfanos sin warnings; re-asigna order correcto.
	 *
	 * @param  int $post_id ID del post.
	 * @return array[] Lista de links normalizados.
	 *
	 * Cada item del array incluye:
	 *   'provider_id'   int     ID del afiliado.
	 *   'original_url'  string  URL base.
	 *   'custom_label'  string  Etiqueta personalizada (puede ser '').
	 *   'order'         int     Posición (siempre incremental desde 0).
	 *   'final_url'     string  URL con parámetro afiliado (vacía si provider inactivo/eliminado).
	 *   '_orphan'       bool    true si el provider no existe o está inactivo.
	 *   '_orphan_title' string  Nombre del provider huérfano (vacío si fue eliminado de la DB).
	 */
	public function get_links( int $post_id ): array {
		$raw = get_post_meta( $post_id, self::META_KEY, true );

		if ( ! is_array( $raw ) || empty( $raw ) ) {
			return array();
		}

		$repo  = new Repository();
		$links = array();
		$order = 0; // Siempre re-asignamos order incremental al leer.

		foreach ( $raw as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			$provider_id  = absint( $item['provider_id'] ?? 0 );
			$original_url = esc_url_raw( (string) ( $item['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( (string) ( $item['custom_label'] ?? '' ) );

			// Descartar items sin datos mínimos (no deberían existir tras 0.0.3).
			if ( ! $provider_id || ! $original_url ) {
				continue;
			}

			// Verificar existencia y estado del provider.
			$affiliate   = $repo->find( $provider_id );
			$is_orphan   = false;
			$orphan_title = '';
			$final_url   = '';

			if ( null === $affiliate ) {
				// Provider eliminado de la DB.
				$is_orphan = true;

				// Intentar recuperar al menos el título del post (puede estar en trash).
				$provider_post = get_post( $provider_id );
				if ( $provider_post instanceof \WP_Post ) {
					$orphan_title = $provider_post->post_title;
				}
			} elseif ( ! $affiliate['active'] ) {
				// Provider existe pero está desactivado.
				$is_orphan    = true;
				$orphan_title = $affiliate['title'];
			} else {
				// Provider válido y activo: generar URL final.
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
	// Helpers internos
	// -------------------------------------------------------------------------

	/**
	 * Formatea el contador de links para el footer del meta box.
	 *
	 * @since  0.0.3
	 * @param  int $count Número de links.
	 * @return string
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
}
