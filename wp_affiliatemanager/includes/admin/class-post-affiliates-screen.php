<?php
/**
 * Admin UI — Board "Post Affiliates".
 *
 * Lista visual de posts con sus affiliate links.
 * Soporta carga incremental, búsqueda por título/cat/tag y edición inline.
 *
 * Diseño:
 * - Carga inicial: 20 posts (offset=0).
 * - Load More: 10 en 10, append al board.
 * - Editor inline: ya renderizado en el row, oculto, expand/collapse por JS.
 * - Guardado: wpam_save_post_links recibe el array completo del post.
 * - Reutiliza: Post_Links::META_KEY, Post_Links::get_links(), Repository.
 *
 * @package WP_AffiliateManager\Admin
 * @since   0.1.0
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

	private const INITIAL_LIMIT = 20;
	private const MORE_LIMIT    = 10;
	private const NONCE_ACTION  = 'wpam_post_affiliates';

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

		// Afiliados activos para el selector inline (necesarios en el HTML inicial).
		$affiliates = $this->get_active_affiliates();

		// Bloque inicial de posts (sin AJAX — SSR para primer paint rápido).
		$initial = $this->query_posts( 0, self::INITIAL_LIMIT, '', 0, 0 );
		?>
		<div class="wpam-page-content">

			<!-- Barra de búsqueda y filtros -->
			<div class="wpam-pa-toolbar">
				<div class="wpam-pa-search-wrap">
					<input
						type="search"
						id="wpam-pa-search"
						class="wpam-pa-search"
						placeholder="<?php esc_attr_e( 'Search by title…', 'wp-affiliatemanager' ); ?>"
						autocomplete="off"
					/>
				</div>

				<div class="wpam-pa-filters">
					<?php $this->render_category_select(); ?>
					<?php $this->render_tag_select(); ?>
				</div>
			</div>

			<!-- Contenedor del board (rows se append-an aquí) -->
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

			<!-- Load More -->
			<div class="wpam-pa-load-more-wrap" id="wpam-pa-load-more-wrap"
				style="<?php echo $initial['has_more'] ? '' : 'display:none'; ?>">
				<button type="button" class="button wpam-pa-load-more-btn" id="wpam-pa-load-more-btn">
					<?php esc_html_e( 'Load more posts', 'wp-affiliatemanager' ); ?>
				</button>
			</div>

		</div>
		<?php
	}

	// =========================================================================
	// Render: row de post
	// =========================================================================

	/**
	 * Renderiza un row completo: thumbnail + info + chips + editor inline.
	 *
	 * @since 0.1.0
	 * @param array $post       Array normalizado del post (id, title, status, date, thumb_url).
	 * @param array $affiliates Lista de afiliados activos normalizados.
	 */
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
		?>
		<div
			class="wpam-pa-row"
			id="wpam-pa-row-<?php echo esc_attr( (string) $post_id ); ?>"
			data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
		>
			<!-- ── Thumbnail ─────────────────────────────────────────── -->
			<div class="wpam-pa-thumb">
				<?php if ( $post['thumb_url'] ) : ?>
					<img src="<?php echo esc_url( $post['thumb_url'] ); ?>" alt="" loading="lazy" />
				<?php else : ?>
					<div class="wpam-pa-thumb-placeholder">
						<span>📄</span>
					</div>
				<?php endif; ?>
			</div>

			<!-- ── Info central ──────────────────────────────────────── -->
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

			<!-- ── Links chips + botón "+" ───────────────────────────── -->
			<div class="wpam-pa-links-area" id="wpam-pa-chips-<?php echo esc_attr( (string) $post_id ); ?>">
				<?php $this->render_chips( $links, $post_id ); ?>
				<button
					type="button"
					class="wpam-pa-add-btn"
					data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
					title="<?php esc_attr_e( 'Add affiliate link', 'wp-affiliatemanager' ); ?>"
				>+</button>
			</div>

			<!-- ── Editor inline (oculto, expand/collapse por JS) ────── -->
			<div
				class="wpam-pa-editor"
				id="wpam-pa-editor-<?php echo esc_attr( (string) $post_id ); ?>"
				style="display:none;"
				aria-hidden="true"
			>
				<?php $this->render_inline_editor( $post_id, $links, $affiliates ); ?>
			</div>

		</div>
		<?php
	}

	/**
	 * Renderiza los chips de affiliate links de un post.
	 *
	 * @since  0.1.0
	 * @param  array $links   Links normalizados del post.
	 * @param  int   $post_id ID del post.
	 */
	private function render_chips( array $links, int $post_id ): void {
		if ( empty( $links ) ) {
			echo '<span class="wpam-pa-no-links">' . esc_html__( 'No links', 'wp-affiliatemanager' ) . '</span>';
			return;
		}

		foreach ( $links as $i => $link ) {
			$label     = $link['custom_label'] ?: $this->get_affiliate_title( $link['provider_id'] );
			$is_orphan = ! empty( $link['_orphan'] );
			?>
			<button
				type="button"
				class="wpam-pa-chip <?php echo $is_orphan ? 'wpam-pa-chip--orphan' : ''; ?>"
				data-post-id="<?php echo esc_attr( (string) $post_id ); ?>"
				data-link-index="<?php echo esc_attr( (string) $i ); ?>"
				title="<?php echo esc_attr( $link['original_url'] ); ?>"
			><?php echo esc_html( $label ); ?><?php echo $is_orphan ? ' ⚠️' : ''; ?></button>
			<?php
		}
	}

	/**
	 * Renderiza el editor inline para los links de un post.
	 * Ya está en el DOM — JS solo hace expand/collapse.
	 *
	 * @since  0.1.0
	 * @param  int   $post_id    ID del post.
	 * @param  array $links      Links actuales del post.
	 * @param  array $affiliates Afiliados activos.
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
					<p class="wpam-pa-link-empty"><?php esc_html_e( 'No links yet. Click "Add Link" below.', 'wp-affiliatemanager' ); ?></p>
				<?php else : ?>
					<?php foreach ( $links as $i => $link ) : ?>
						<?php $this->render_link_item( $post_id, $i, $link, $affiliates ); ?>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<!-- Formulario "Add new link" (siempre presente, oculto por defecto) -->
			<div class="wpam-pa-new-link-wrap" id="wpam-pa-new-wrap-<?php echo esc_attr( (string) $post_id ); ?>" style="display:none;">
				<?php $this->render_link_item( $post_id, '__new__', array(), $affiliates ); ?>
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
					class="button wpam-pa-editor-close"
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
	 * Renderiza un ítem de link (existente o vacío para nuevo).
	 *
	 * @since  0.1.0
	 * @param  int        $post_id    ID del post.
	 * @param  int|string $index      Índice numérico o '__new__'.
	 * @param  array      $link       Datos del link (vacío para nuevo).
	 * @param  array      $affiliates Afiliados activos.
	 */
	private function render_link_item( int $post_id, int|string $index, array $link, array $affiliates ): void {
		$provider_id  = absint( $link['provider_id'] ?? 0 );
		$original_url = esc_url( $link['original_url'] ?? '' );
		$custom_label = esc_attr( $link['custom_label'] ?? '' );
		$is_new       = ( '__new__' === $index );
		$item_id      = 'wpam-pa-item-' . $post_id . '-' . $index;
		?>
		<div class="wpam-pa-link-item" id="<?php echo esc_attr( $item_id ); ?>" data-index="<?php echo esc_attr( (string) $index ); ?>">

			<div class="wpam-edit-grid wpam-pa-link-grid">

				<!-- Affiliate select -->
				<div class="wpam-edit-field">
					<label><?php esc_html_e( 'Affiliate', 'wp-affiliatemanager' ); ?></label>
					<select class="wpam-select wpam-pa-provider-select">
						<option value=""><?php esc_html_e( '— Select —', 'wp-affiliatemanager' ); ?></option>
						<?php foreach ( $affiliates as $aff ) : ?>
							<option
								value="<?php echo esc_attr( (string) $aff['id'] ); ?>"
								<?php selected( $provider_id, $aff['id'] ); ?>
							><?php echo esc_html( $aff['title'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</div>

				<!-- URL -->
				<div class="wpam-edit-field wpam-edit-field--url">
					<label><?php esc_html_e( 'URL', 'wp-affiliatemanager' ); ?></label>
					<input
						type="url"
						class="wpam-input wpam-pa-url-input"
						value="<?php echo $original_url; ?>"
						placeholder="https://…"
					/>
				</div>

				<!-- Label -->
				<div class="wpam-edit-field">
					<label><?php esc_html_e( 'Label', 'wp-affiliatemanager' ); ?> <span class="wpam-optional"><?php esc_html_e( '(optional)', 'wp-affiliatemanager' ); ?></span></label>
					<input
						type="text"
						class="wpam-input wpam-pa-label-input"
						value="<?php echo $custom_label; ?>"
						placeholder="<?php esc_attr_e( 'e.g. Buy on Amazon', 'wp-affiliatemanager' ); ?>"
					/>
				</div>

				<!-- Botón eliminar item -->
				<div class="wpam-edit-field wpam-pa-item-remove-wrap">
					<label>&nbsp;</label>
					<button
						type="button"
						class="button wpam-pa-remove-item-btn"
						title="<?php esc_attr_e( 'Remove this link', 'wp-affiliatemanager' ); ?>"
					>✕</button>
				</div>

			</div>
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
	// AJAX — carga de posts (inicial + load more + búsqueda)
	// =========================================================================

	/**
	 * Handler AJAX unificado para carga de posts.
	 * action: wpam_load_posts
	 * params: offset, limit, search, category, tag
	 *
	 * @since 0.1.0
	 */
	public function ajax_load_posts(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
		}

		$offset   = absint( $_POST['offset']   ?? 0 );
		$limit    = absint( $_POST['limit']    ?? self::MORE_LIMIT );
		$search   = sanitize_text_field( wp_unslash( $_POST['search']   ?? '' ) );
		$cat_id   = absint( $_POST['category'] ?? 0 );
		$tag_id   = absint( $_POST['tag']      ?? 0 );

		// Clampear limit a un máximo razonable.
		$limit = min( $limit, 50 );

		$result     = $this->query_posts( $offset, $limit, $search, $cat_id, $tag_id );
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
	 * Handler AJAX para guardar el array completo de links de un post.
	 * action: wpam_save_post_links
	 * params: post_id, links (JSON array)
	 *
	 * Recibe el array final del frontend (add/edit/delete ya aplicados en cliente).
	 * Reutiliza estrictamente Post_Links::META_KEY y la estructura _wpam_links.
	 *
	 * @since 0.1.0
	 */
	public function ajax_save_post_links(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
		}

		$post_id = absint( $_POST['post_id'] ?? 0 );
		if ( ! $post_id ) {
			wp_send_json_error( __( 'Invalid post ID.', 'wp-affiliatemanager' ) );
		}

		$post = get_post( $post_id );
		if ( ! $post instanceof \WP_Post || 'post' !== $post->post_type ) {
			wp_send_json_error( __( 'Post not found.', 'wp-affiliatemanager' ) );
		}

		// Links recibidos como JSON desde el cliente.
		$raw_json = wp_unslash( $_POST['links'] ?? '[]' );
		$raw_links = json_decode( $raw_json, true );

		if ( ! is_array( $raw_links ) ) {
			$raw_links = array();
		}

		// Sanitizar y validar — misma lógica que Post_Links::save().
		$valid_links = array();
		foreach ( $raw_links as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$provider_id  = absint( $raw['provider_id']  ?? 0 );
			$raw_url      = trim( (string) ( $raw['original_url'] ?? '' ) );
			$custom_label = sanitize_text_field( $raw['custom_label'] ?? '' );

			if ( ! $provider_id || ! $raw_url ) {
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

			// Verificar que el provider es un wpam_affiliate real.
			$provider_post = get_post( $provider_id );
			if ( ! $provider_post instanceof \WP_Post || 'wpam_affiliate' !== $provider_post->post_type ) {
				continue;
			}

			$valid_links[] = array(
				'provider_id'  => $provider_id,
				'original_url' => $original_url,
				'custom_label' => $custom_label,
			);
		}

		// Re-asignar order incremental (igual que Post_Links::save).
		foreach ( $valid_links as $i => &$link ) {
			$link['order'] = $i;
		}
		unset( $link );

		if ( empty( $valid_links ) ) {
			delete_post_meta( $post_id, Post_Links::META_KEY );
		} else {
			update_post_meta( $post_id, Post_Links::META_KEY, $valid_links );
		}

		// Devolver el row actualizado.
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
	 * Consulta posts con parámetros mínimos para performance.
	 *
	 * @since  0.1.0
	 * @param  int    $offset   Número de posts a saltar.
	 * @param  int    $limit    Número de posts a traer.
	 * @param  string $search   Búsqueda por título.
	 * @param  int    $cat_id   ID de categoría (0 = todas).
	 * @param  int    $tag_id   ID de tag (0 = todos).
	 * @return array{posts: array[], has_more: bool}
	 */
	private function query_posts( int $offset, int $limit, string $search, int $cat_id, int $tag_id ): array {
		$args = array(
			'post_type'              => 'post',
			'post_status'            => array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'         => $limit + 1, // +1 para detectar has_more.
			'offset'                 => $offset,
			'orderby'                => 'date',
			'order'                  => 'DESC',
			// Performance.
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			// Limitar campos.
			'fields'                 => 'ids',
		);

		if ( $search ) {
			$args['s'] = $search;
		}

		if ( $cat_id > 0 || $tag_id > 0 ) {
			$tax_query = array( 'relation' => 'AND' );

			if ( $cat_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $cat_id,
				);
			}

			if ( $tag_id > 0 ) {
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $tag_id,
				);
			}

			$args['tax_query'] = $tax_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
		}

		$query = new \WP_Query( $args );
		$ids   = $query->posts; // Array de IDs gracias a 'fields' => 'ids'.

		$has_more = count( $ids ) > $limit;
		if ( $has_more ) {
			array_pop( $ids ); // Eliminar el +1 extra.
		}

		$posts = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[] = $this->normalize_post( $post );
			}
		}

		return array(
			'posts'    => $posts,
			'has_more' => $has_more,
		);
	}

	/**
	 * Normaliza un WP_Post a array mínimo para el board.
	 * Solo campos necesarios para el render (no content, no excerpt).
	 *
	 * @since  0.1.0
	 * @param  \WP_Post $post Post de WordPress.
	 * @return array
	 */
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
	// Helpers
	// =========================================================================

	/**
	 * Retorna todos los afiliados activos para los selects.
	 *
	 * @since  0.1.0
	 * @return array
	 */
	private function get_active_affiliates(): array {
		$result = $this->repository->find_all( array(
			'active'   => true,
			'orderby'  => 'title',
			'order'    => 'ASC',
		) );
		return $result['items'];
	}

	/**
	 * Retorna el título de un afiliado por ID (con cache básico de request).
	 *
	 * @since  0.1.0
	 * @param  int $provider_id ID del afiliado.
	 * @return string
	 */
	private function get_affiliate_title( int $provider_id ): string {
		static $cache = array();

		if ( isset( $cache[ $provider_id ] ) ) {
			return $cache[ $provider_id ];
		}

		$post = get_post( $provider_id );
		$cache[ $provider_id ] = $post instanceof \WP_Post ? $post->post_title : '#' . $provider_id;

		return $cache[ $provider_id ];
	}
}
