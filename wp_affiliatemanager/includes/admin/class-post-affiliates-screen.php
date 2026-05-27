<?php
/**
 * Admin UI — Board "Post Affiliates".
 *
 * Lista visual de posts con sus affiliate links.
 * Soporta carga incremental, búsqueda por título/cat/tag/status y edición inline.
 *
 * @package WP_AffiliateManager\Admin
 * @since   0.1.0
 * @version 0.1.3
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Posts\Post_Links;
use WP_AffiliateManager\Affiliates\Repository;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Post_Affiliates_Screen
 *
 * @since 0.1.0
 */
class Post_Affiliates_Screen {

	private const INITIAL_LIMIT = 10;
	private const MORE_LIMIT    = 10;
	private const NONCE_ACTION  = 'wpam_post_affiliates';

	/** Statuses válidos para el filtro. 'all' = sin filtro. */
	private const VALID_STATUSES = array( 'all', 'publish', 'draft', 'future' );

	private Post_Links $post_links;
	private Repository $repository;

	public function __construct() {
		$this->post_links = new Post_Links();
		$this->repository = new Repository();
	}

	// =========================================================================
	// Render principal
	// =========================================================================

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-affiliatemanager' ) );
		}

		$affiliates = $this->get_active_affiliates();
		$initial    = $this->query_posts( 0, self::INITIAL_LIMIT, '', 0, 0, 'all' );
		?>
		<div class="wpam-page-content">

			<!-- ── Toolbar ─────────────────────────────────────────── -->
			<div class="wpam-pa-toolbar">

				<!-- Buscador -->
				<div class="wpam-pa-search-wrap">
					<span class="wpam-pa-search-icon">🔍</span>
					<input
						type="search"
						id="wpam-pa-search"
						class="wpam-pa-search"
						placeholder="<?php esc_attr_e( 'Search by title…', 'wp-affiliatemanager' ); ?>"
						autocomplete="off"
					/>
				</div>

				<!-- Selects -->
				<div class="wpam-pa-filters">
					<?php $this->render_category_select(); ?>
					<?php $this->render_tag_select(); ?>
				</div>

				<!-- Filtro de status (segmented control) -->
				<div class="wpam-pa-status-filter" id="wpam-pa-status-filter" role="group" aria-label="<?php esc_attr_e( 'Filter by status', 'wp-affiliatemanager' ); ?>">
					<?php
					$status_options = array(
						'all'     => __( 'All', 'wp-affiliatemanager' ),
						'publish' => __( 'Published', 'wp-affiliatemanager' ),
						'draft'   => __( 'Draft', 'wp-affiliatemanager' ),
						'future'  => __( 'Scheduled', 'wp-affiliatemanager' ),
					);
					foreach ( $status_options as $val => $label ) :
					?>
						<button
							type="button"
							class="wpam-pa-status-pill<?php echo 'all' === $val ? ' wpam-pa-status-pill--active' : ''; ?>"
							data-status="<?php echo esc_attr( $val ); ?>"
						><?php echo esc_html( $label ); ?></button>
					<?php endforeach; ?>
				</div>

			</div><!-- .wpam-pa-toolbar -->

			<!-- ── Board ───────────────────────────────────────────── -->
			<div class="wpam-pa-board" id="wpam-pa-board"
				data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>"
				data-offset="<?php echo esc_attr( (string) count( $initial['posts'] ) ); ?>"
				data-has-more="<?php echo esc_attr( $initial['has_more'] ? '1' : '0' ); ?>"
			>
				<?php if ( empty( $initial['posts'] ) ) : ?>
					<?php $this->render_empty_state(); ?>
				<?php else : ?>
					<?php foreach ( $initial['posts'] as $post ) : ?>
						<?php $this->render_post_row( $post, $affiliates ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<!-- ── Load More ────────────────────────────────────────── -->
			<div class="wpam-pa-load-more-wrap" id="wpam-pa-load-more-wrap"
				style="<?php echo $initial['has_more'] ? '' : 'display:none'; ?>">
				<button type="button" class="wpam-pa-load-more-btn" id="wpam-pa-load-more-btn">
					<?php esc_html_e( 'Load more posts', 'wp-affiliatemanager' ); ?>
					<span class="wpam-pa-load-more-arrow">↓</span>
				</button>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// Render: row de post
	// =========================================================================

	public function render_post_row( array $post, array $affiliates ): void {
		$post_id    = (int) $post['id'];
		$links      = $this->post_links->get_links( $post_id );
		$edit_url   = get_edit_post_link( $post_id, 'raw' );
		$status_map = array(
			'publish' => __( 'Published', 'wp-affiliatemanager' ),
			'draft'   => __( 'Draft', 'wp-affiliatemanager' ),
			'pending' => __( 'Pending', 'wp-affiliatemanager' ),
			'private' => __( 'Private', 'wp-affiliatemanager' ),
			'future'  => __( 'Scheduled', 'wp-affiliatemanager' ),
		);
		$status_label = $status_map[ $post['status'] ] ?? esc_html( $post['status'] );

		// v0.1.3: pre-normalizar dominios de cada afiliado como array para matching JS sin AJAX.
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
				'domains'     => $domains_list,
			);
		}, $affiliates ) );
		?>
		<div
			class="wpam-pa-row"
			id="wpam-pa-row-<?php echo esc_attr( (string) $post_id ); ?>"
			data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
		>
			<!-- ── Thumbnail ────────── -->
			<div class="wpam-pa-thumb">
				<?php if ( $post['thumb_url'] ) : ?>
					<img src="<?php echo esc_url( $post['thumb_url'] ); ?>" alt="" loading="lazy" />
				<?php else : ?>
					<div class="wpam-pa-thumb-placeholder"><span>📄</span></div>
				<?php endif; ?>
			</div>

			<!-- ── Info central ─────── -->
			<div class="wpam-pa-info">
				<a class="wpam-pa-post-title" href="<?php echo esc_url( (string) $edit_url ); ?>">
					<?php echo esc_html( $post['title'] ); ?>
				</a>
				<div class="wpam-pa-meta">
					<span class="wpam-pa-status wpam-pa-status--<?php echo esc_attr( $post['status'] ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
					<span class="wpam-pa-date"><?php echo esc_html( $post['date'] ); ?></span>
				</div>
			</div>

			<!-- ── Chips + botón "+" ── -->
			<div class="wpam-pa-links-area" id="wpam-pa-chips-<?php echo esc_attr( (string) $post_id ); ?>">
				<?php $this->render_chips( $links, $affiliates, $post_id ); ?>
				<button
					type="button"
					class="wpam-pa-add-btn"
					data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
					title="<?php esc_attr_e( 'Add affiliate link', 'wp-affiliatemanager' ); ?>"
				>
					<span class="wpam-pa-add-btn-icon">+</span>
					<span class="wpam-pa-add-btn-label"><?php esc_html_e( 'Add', 'wp-affiliatemanager' ); ?></span>
				</button>
			</div>

			<!-- ── Editor inline (oculto) ── -->
			<div
				class="wpam-pa-editor"
				id="wpam-pa-editor-<?php echo esc_attr( (string) $post_id ); ?>"
				style="display:none;"
				aria-hidden="true"
				data-affiliates="<?php echo esc_attr( $affiliates_json ); ?>"
			>
				<?php $this->render_inline_editor( $post_id, $links, $affiliates ); ?>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// Render: chips visuales
	// =========================================================================

	/**
	 * Renderiza chips visuales con logo + color + nombre.
	 *
	 * @since  0.1.1
	 */
	private function render_chips( array $links, array $affiliates, int $post_id ): void {
		// Indexar afiliados por ID para lookup O(1).
		$aff_index = array();
		foreach ( $affiliates as $aff ) {
			$aff_index[ $aff['id'] ] = $aff;
		}

		if ( empty( $links ) ) {
			echo '<span class="wpam-pa-no-links">' . esc_html__( 'No links', 'wp-affiliatemanager' ) . '</span>';
			return;
		}

		foreach ( $links as $i => $link ) {
			$is_orphan   = ! empty( $link['_orphan'] );
			$aff         = $aff_index[ $link['provider_id'] ] ?? null;
			$label       = $link['custom_label'] ?: ( $aff ? $aff['title'] : $this->get_affiliate_title( $link['provider_id'] ) );
			$logo_url    = $aff['logo_url']    ?? '';
			$brand_color = $aff['brand_color'] ?? '#6c47ff';

			$style = $is_orphan ? '' : sprintf(
				'--chip-color:%s;--chip-bg:%s;',
				esc_attr( $brand_color ),
				esc_attr( $this->hex_to_rgba( $brand_color, 0.10 ) )
			);
			?>
			<button
				type="button"
				class="wpam-pa-chip<?php echo $is_orphan ? ' wpam-pa-chip--orphan' : ''; ?>"
				data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
				data-link-index="<?php echo esc_attr( (string) $i ); ?>"
				title="<?php echo esc_attr( $link['original_url'] ); ?>"
				style="<?php echo esc_attr( $style ); ?>"
			>
				<?php if ( $logo_url && ! $is_orphan ) : ?>
					<img class="wpam-pa-chip-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="" />
				<?php elseif ( $is_orphan ) : ?>
					<span class="wpam-pa-chip-orphan-icon">⚠️</span>
				<?php else : ?>
					<span class="wpam-pa-chip-initial"><?php echo esc_html( strtoupper( substr( $label, 0, 1 ) ) ); ?></span>
				<?php endif; ?>
				<span class="wpam-pa-chip-label"><?php echo esc_html( $label ); ?></span>
			</button>
			<?php
		}
	}

	// =========================================================================
	// Render: editor inline
	// =========================================================================

	/**
	 * Renderiza el editor inline.
	 * El JS hace expand/collapse y puede clonar filas nuevas sin volver al servidor.
	 *
	 * @since  0.1.0
	 * @since  0.1.1 Eliminado el "new-wrap" fijo — JS clona plantilla.
	 * @since  0.1.3 render_link_item ya no incluye select de proveedor.
	 */
	private function render_inline_editor( int $post_id, array $links, array $affiliates ): void {
		?>
		<div class="wpam-edit-form wpam-pa-edit-form">

			<div class="wpam-pa-editor-header">
				<span class="wpam-pa-editor-title">
					<?php esc_html_e( 'Affiliate Links', 'wp-affiliatemanager' ); ?>
				</span>
				<button type="button" class="wpam-pa-editor-close" data-post-id="<?php echo esc_attr( (string) $post_id ); ?>">✕</button>
			</div>

			<!-- Lista de links existentes -->
			<div class="wpam-pa-link-list" id="wpam-pa-link-list-<?php echo esc_attr( (string) $post_id ); ?>">
				<?php if ( empty( $links ) ) : ?>
					<p class="wpam-pa-link-empty" id="wpam-pa-empty-msg-<?php echo esc_attr( (string) $post_id ); ?>">
						<?php esc_html_e( 'No links yet. Click "Add Link" below.', 'wp-affiliatemanager' ); ?>
					</p>
				<?php else : ?>
					<?php foreach ( $links as $i => $link ) : ?>
						<?php $this->render_link_item( $post_id, $i, $link, $affiliates ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="wpam-edit-actions">
				<button
					type="button"
					class="button wpam-pa-add-link-btn"
					data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
				>+ <?php esc_html_e( 'Add Link', 'wp-affiliatemanager' ); ?></button>

				<button
					type="button"
					class="button button-primary wpam-btn-primary wpam-pa-save-btn"
					data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
				><?php esc_html_e( 'Save', 'wp-affiliatemanager' ); ?></button>

				<button
					type="button"
					class="button wpam-pa-cancel-btn"
					data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
				><?php esc_html_e( 'Cancel', 'wp-affiliatemanager' ); ?></button>

				<span class="wpam-saving-indicator" style="display:none;">
					<?php esc_html_e( 'Saving…', 'wp-affiliatemanager' ); ?>
				</span>
			</div>

		</div>
		<?php
	}

	/**
	 * Renderiza un ítem de link.
	 *
	 * v0.1.3: Eliminado el select de afiliado. El usuario solo pega la URL;
	 * la detección ocurre en JS (debounce 500ms) y se valida en PHP.
	 * El afiliado detectado se muestra como chip preview bajo el input.
	 *
	 * @since  0.1.0
	 * @since  0.1.3 Sin select de proveedor. URL + preview de detección + Label.
	 * @param  int        $post_id    ID del post.
	 * @param  int|string $index      Índice numérico o string para nuevos.
	 * @param  array      $link       Datos del link.
	 * @param  array      $affiliates Afiliados activos.
	 */
	private function render_link_item( int $post_id, int|string $index, array $link, array $affiliates ): void {
		$provider_id  = absint( $link['provider_id'] ?? 0 );
		$original_url = esc_url( $link['original_url'] ?? '' );
		$custom_label = esc_attr( $link['custom_label'] ?? '' );
		$item_id      = 'wpam-pa-item-' . $post_id . '-' . $index;

		// Buscar el afiliado actual para renderizar el chip de preview inicial.
		$detected_aff = null;
		if ( $provider_id ) {
			foreach ( $affiliates as $aff ) {
				if ( (int) $aff['id'] === $provider_id ) {
					$detected_aff = $aff;
					break;
				}
			}
		}
		?>
		<div
			class="wpam-pa-link-item<?php echo $detected_aff ? ' wpam-pa-link-item--detected' : ''; ?>"
			id="<?php echo esc_attr( $item_id ); ?>"
		>
			<div class="wpam-edit-grid wpam-pa-link-grid">

				<!-- URL — la detección de afiliado ocurre en este campo -->
				<div class="wpam-edit-field wpam-edit-field--url wpam-pa-url-field">
					<label><?php esc_html_e( 'URL', 'wp-affiliatemanager' ); ?></label>
					<input type="url" class="wpam-input wpam-pa-url-input" value="<?php echo $original_url; ?>" placeholder="https://…" />
					<!-- Preview del afiliado detectado (JS lo actualiza; PHP lo renderiza en items existentes) -->
					<div class="wpam-pa-detect-preview">
						<?php if ( $detected_aff ) : ?>
							<?php $this->render_detect_chip( $detected_aff ); ?>
						<?php endif; ?>
					</div>
					<!-- Error inline (JS lo muestra/oculta) -->
					<div class="wpam-pa-url-error" style="display:none;"></div>
				</div>

				<div class="wpam-edit-field">
					<label><?php esc_html_e( 'Label', 'wp-affiliatemanager' ); ?> <span class="wpam-optional"><?php esc_html_e( '(opt.)', 'wp-affiliatemanager' ); ?></span></label>
					<input type="text" class="wpam-input wpam-pa-label-input" value="<?php echo $custom_label; ?>" placeholder="<?php esc_attr_e( 'e.g. Buy on Amazon', 'wp-affiliatemanager' ); ?>" />
				</div>

				<div class="wpam-edit-field wpam-pa-item-remove-wrap">
					<label>&nbsp;</label>
					<button type="button" class="button wpam-pa-remove-item-btn" title="<?php esc_attr_e( 'Remove this link', 'wp-affiliatemanager' ); ?>">&#x2715;</button>
				</div>

			</div>
		</div>
		<?php
	}

	/**
	 * Renderiza el chip de preview del afiliado detectado.
	 *
	 * @since  0.1.3
	 * @param  array $aff Afiliado normalizado.
	 */
	private function render_detect_chip( array $aff ): void {
		$color    = $aff['brand_color'] ?: '#6c47ff';
		$bg_color = $this->hex_to_rgba( $color, 0.10 );
		$style    = sprintf( '--chip-color:%s;--chip-bg:%s;', esc_attr( $color ), esc_attr( $bg_color ) );
		?>
		<div class="wpam-pa-detect-chip" style="<?php echo esc_attr( $style ); ?>">
			<?php if ( $aff['logo_url'] ) : ?>
				<img class="wpam-pa-chip-logo" src="<?php echo esc_url( $aff['logo_url'] ); ?>" alt="" />
			<?php else : ?>
				<span class="wpam-pa-chip-initial"><?php echo esc_html( strtoupper( substr( $aff['title'], 0, 1 ) ) ); ?></span>
			<?php endif; ?>
			<span class="wpam-pa-detect-chip-name"><?php echo esc_html( $aff['title'] ); ?></span>
		</div>
		<?php
	}

	// =========================================================================
	// Render: filtros
	// =========================================================================

	private function render_category_select(): void {
		$cats = get_categories( array( 'hide_empty' => false, 'orderby' => 'name' ) );
		if ( empty( $cats ) ) {
			return;
		}
		?>
		<select id="wpam-pa-filter-cat" class="wpam-pa-filter-select">
			<option value="0"><?php esc_html_e( 'All categories', 'wp-affiliatemanager' ); ?></option>
			<?php foreach ( $cats as $cat ) : ?>
				<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>"><?php echo esc_html( $cat->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_tag_select(): void {
		$tags = get_tags( array( 'hide_empty' => false, 'orderby' => 'name', 'number' => 200 ) );
		if ( empty( $tags ) ) {
			return;
		}
		?>
		<select id="wpam-pa-filter-tag" class="wpam-pa-filter-select">
			<option value="0"><?php esc_html_e( 'All tags', 'wp-affiliatemanager' ); ?></option>
			<?php foreach ( $tags as $tag ) : ?>
				<option value="<?php echo esc_attr( (string) $tag->term_id ); ?>"><?php echo esc_html( $tag->name ); ?></option>
			<?php endforeach; ?>
		</select>
		<?php
	}

	private function render_empty_state(): void {
		?>
		<div class="wpam-pa-empty">
			<span class="wpam-pa-empty-icon">📝</span>
			<p><?php esc_html_e( 'No posts found.', 'wp-affiliatemanager' ); ?></p>
		</div>
		<?php
	}

	// =========================================================================
	// AJAX — carga de posts
	// =========================================================================

	public function ajax_load_posts(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
		}

		$offset  = absint( $_POST['offset']   ?? 0 );
		$limit   = min( absint( $_POST['limit'] ?? self::MORE_LIMIT ), 50 );
		$search  = sanitize_text_field( wp_unslash( $_POST['search']   ?? '' ) );
		$cat_id  = absint( $_POST['category'] ?? 0 );
		$tag_id  = absint( $_POST['tag']      ?? 0 );
		$status  = sanitize_key( $_POST['status'] ?? 'all' );

		if ( ! in_array( $status, self::VALID_STATUSES, true ) ) {
			$status = 'all';
		}

		$result     = $this->query_posts( $offset, $limit, $search, $cat_id, $tag_id, $status );
		$affiliates = $this->get_active_affiliates();

		ob_start();
		if ( empty( $result['posts'] ) ) {
			if ( 0 === $offset ) {
				$this->render_empty_state();
			}
		} else {
			foreach ( $result['posts'] as $post ) {
				$this->render_post_row( $post, $affiliates );
			}
		}
		$html = ob_get_clean();

		wp_send_json_success( array(
			'html'     => $html,
			'has_more' => $result['has_more'],
			'count'    => count( $result['posts'] ),
		) );
	}

	// =========================================================================
	// AJAX — guardar links del post
	// =========================================================================

	/**
	 * Guarda los links del post enviados desde el editor inline.
	 *
	 * v0.1.3:
	 * - Ya no se recibe provider_id desde el cliente.
	 * - El affiliate se detecta automáticamente por dominio de la URL.
	 * - Si ningún afiliado activo coincide: wp_send_json_error().
	 * - Duplicados de URL (mismo post, misma URL normalizada): wp_send_json_error().
	 *
	 * @since 0.1.0
	 * @since 0.1.3 Auto-detección de afiliado. Validación de duplicados.
	 */
	public function ajax_save_post_links(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array(
				'code'    => 'permission_denied',
				'message' => __( 'Permission denied.', 'wp-affiliatemanager' ),
			) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( array(
				'code'    => 'invalid_post',
				'message' => __( 'Invalid post ID.', 'wp-affiliatemanager' ),
			) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			wp_send_json_error( array(
				'code'    => 'invalid_post',
				'message' => __( 'Post not found.', 'wp-affiliatemanager' ),
			) );
		}

		$raw_json  = wp_unslash( $_POST['links'] ?? '[]' );
		$raw_links = json_decode( $raw_json, true );

		if ( ! is_array( $raw_links ) ) {
			$raw_links = array();
		}

		$valid_links     = array();
		$normalized_urls = array(); // para detección de duplicados

		foreach ( $raw_links as $raw ) {
			if ( ! is_array( $raw ) ) { continue; }

			$raw_url      = trim( (string) ( $raw['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( $raw['custom_label'] ?? '' );

			if ( ! $raw_url ) { continue; }

			if ( false === filter_var( $raw_url, FILTER_VALIDATE_URL ) ) {
				wp_send_json_error( array(
					'code'    => 'invalid_url',
					'message' => sprintf( __( 'Invalid URL: %s', 'wp-affiliatemanager' ), $raw_url ),
				) );
				return;
			}

			$scheme = strtolower( (string) wp_parse_url( $raw_url, PHP_URL_SCHEME ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				wp_send_json_error( array(
					'code'    => 'invalid_url',
					'message' => sprintf( __( 'Only http/https URLs are allowed: %s', 'wp-affiliatemanager' ), $raw_url ),
				) );
				return;
			}

			$original_url = esc_url_raw( $raw_url );
			if ( ! $original_url ) { continue; }

			// v0.1.3: Auto-detección de afiliado por dominio.
			$domain    = wpam_extract_domain_from_url( $original_url );
			$affiliate = $domain ? $this->repository->find_by_domain( $domain ) : null;

			if ( null === $affiliate ) {
				wp_send_json_error( array(
					'code'    => 'no_affiliate_for_url',
					'message' => sprintf( __( 'No active affiliate found for URL: %s', 'wp-affiliatemanager' ), $original_url ),
				) );
				return;
			}

			// v0.1.3: Validación de duplicados (URL normalizada).
			$normalized_url = $this->normalize_url_for_comparison( $original_url );

			if ( in_array( $normalized_url, $normalized_urls, true ) ) {
				wp_send_json_error( array(
					'code'    => 'duplicate_url',
					'message' => sprintf( __( 'Duplicate URL: %s', 'wp-affiliatemanager' ), $original_url ),
				) );
				return;
			}

			$normalized_urls[] = $normalized_url;

			$valid_links[] = array(
				'provider_id'  => $affiliate['id'],
				'original_url' => $original_url,
				'custom_label' => $custom_label,
			);
		}

		foreach ( $valid_links as $i => &$link ) {
			$link['order'] = $i;
		}
		unset( $link );

		if ( empty( $valid_links ) ) {
			delete_post_meta( $post_id, Post_Links::META_KEY );
		} else {
			update_post_meta( $post_id, Post_Links::META_KEY, $valid_links );
		}

		$affiliates = $this->get_active_affiliates();
		$post_data  = $this->normalize_post( $post );

		ob_start();
		$this->render_post_row( $post_data, $affiliates );
		$row_html = ob_get_clean();

		wp_send_json_success( array(
			'row_html' => $row_html,
			'post_id'  => $post_id,
			'count'    => count( $valid_links ),
		) );
	}

	// =========================================================================
	// Query de posts
	// =========================================================================

	/**
	 * @since  0.1.1 Añadido parámetro $status.
	 */
	private function query_posts( int $offset, int $limit, string $search, int $cat_id, int $tag_id, string $status = 'all' ): array {
		$post_status = ( 'all' === $status || '' === $status )
			? array( 'publish', 'draft', 'pending', 'private', 'future' )
			: array( $status );

		$args = array(
			'post_type'              => 'post',
			'post_status'            => $post_status,
			'posts_per_page'         => $limit + 1,
			'offset'                 => $offset,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( $cat_id > 0 || $tag_id > 0 ) {
			$tax_query = array( 'relation' => 'AND' );
			if ( $cat_id > 0 ) {
				$tax_query[] = array( 'taxonomy' => 'category', 'field' => 'term_id', 'terms' => $cat_id );
			}
			if ( $tag_id > 0 ) {
				$tax_query[] = array( 'taxonomy' => 'post_tag', 'field' => 'term_id', 'terms' => $tag_id );
			}
			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query    = new \WP_Query( $args );
		$ids      = $query->posts;
		$has_more = count( $ids ) > $limit;

		if ( $has_more ) {
			array_pop( $ids );
		}

		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $this->normalize_post( $post );
			}
		}

		return array( 'posts' => $posts, 'has_more' => $has_more );
	}

	private function normalize_post( \WP_Post $post ): array {
		$thumb_id  = get_post_thumbnail_id( $post->ID );
		$thumb_url = $thumb_id ? wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';

		return array(
			'id'        => $post->ID,
			'title'     => $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' ),
			'status'    => $post->post_status,
			'date'      => get_the_date( 'd M Y · H:i', $post ),
			'thumb_url' => $thumb_url ?: '',
		);
	}

	// =========================================================================
	// Helpers privados
	// =========================================================================

	private function get_active_affiliates(): array {
		$result = $this->repository->find_all( array( 'active' => true, 'orderby' => 'title', 'order' => 'ASC' ) );
		return $result['items'];
	}

	private function get_affiliate_title( int $provider_id ): string {
		static $cache = array();
		if ( isset( $cache[ $provider_id ] ) ) { return $cache[ $provider_id ]; }
		$post = get_post( $provider_id );
		$cache[ $provider_id ] = $post instanceof \WP_Post ? $post->post_title : '#' . $provider_id;
		return $cache[ $provider_id ];
	}

	/**
	 * Normaliza una URL para comparación de duplicados.
	 *
	 * Reglas: lowercase del host + path sin trailing slash + query preservada.
	 * Estas dos URLs se consideran iguales:
	 *   https://site.com/a
	 *   https://site.com/a/
	 *
	 * @since  0.1.3
	 * @param  string $url URL ya validada y sanitizada.
	 * @return string URL normalizada.
	 */
	private function normalize_url_for_comparison( string $url ): string {
		$parsed = wp_parse_url( $url );
		$scheme = $parsed['scheme'] ?? 'https';
		$host   = strtolower( $parsed['host'] ?? '' );
		$path   = rtrim( $parsed['path'] ?? '', '/' );
		$query  = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
		return $scheme . '://' . $host . $path . $query;
	}

	/**
	 * Convierte un hex a rgba() para el background del chip.
	 *
	 * @since  0.1.1
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
