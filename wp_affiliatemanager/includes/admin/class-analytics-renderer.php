<?php
/**
 * Analytics Renderer — genera el HTML de las secciones de analítica.
 *
 * Responsabilidad única: formatear datos que ya vienen resueltos por las
 * Query classes (Top_Posts_Query, Views_Query, Score_Query) en markup HTML.
 *
 * REGLA: esta clase NUNCA ejecuta SQL propio. Puede llamar a funciones de
 * WordPress (get_post(), get_the_post_thumbnail_url(), get_edit_post_link(),
 * get_permalink(), etc.) para enriquecer visualmente los datos recibidos
 * (thumbnail, edit_url, título de un post/afiliado relacionado), pero nunca
 * a $wpdb ni a ninguna tabla wpam_* directamente. Esa responsabilidad es
 * exclusiva de las Query classes.
 *
 * Usada tanto por Admin_Menu (Dashboard) como por Analytics_Screen
 * (Analytics), para que ambas pantallas compartan exactamente el mismo
 * markup sin duplicar HTML.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.4.0
 */

namespace WP_AffiliateManager\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Analytics_Renderer {

	// -------------------------------------------------------------------------
	// Stat card genérica
	// -------------------------------------------------------------------------

	/**
	 * Renderiza una card de estadística individual.
	 *
	 * @since  1.4.0
	 * @param  string $label
	 * @param  string $value
	 * @param  string $icon
	 * @return void
	 */
	public static function render_stat_card( string $label, string $value, string $icon ): void {
		?>
		<div class="wpam-stat-card">
			<span class="wpam-stat-icon"><?php echo esc_html( $icon ); ?></span>
			<span class="wpam-stat-value"><?php echo esc_html( $value ); ?></span>
			<span class="wpam-stat-label"><?php echo esc_html( $label ); ?></span>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Top Affiliates
	// -------------------------------------------------------------------------

	/**
	 * Renderiza la sección "Top Affiliates".
	 *
	 * $affiliates ya viene completamente resuelto por
	 * Top_Posts_Query::get_top_affiliates() (id, title, click_count,
	 * logo_url, brand_color) — el renderer solo formatea.
	 *
	 * @since  1.4.0
	 * @param  array[] $affiliates
	 * @param  int     $total_clicks Total de clicks del rango, para las barras de %.
	 * @return void
	 */
	public static function render_top_affiliates_section( array $affiliates, int $total_clicks ): void {
		?>
		<div class="wpam-analytics-card">
			<h3 class="wpam-analytics-card-title">
				<span>🏆</span> <?php esc_html_e( 'Top Affiliates', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $affiliates ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<ul class="wpam-top-list">
				<?php foreach ( $affiliates as $aff ) :
					$pct   = $total_clicks > 0 ? round( ( $aff['click_count'] / $total_clicks ) * 100 ) : 0;
					$color = esc_attr( $aff['brand_color'] ?: '#6c47ff' );
				?>
					<li class="wpam-top-item">
						<div class="wpam-top-item-lead">
							<?php if ( $aff['logo_url'] ) : ?>
								<img class="wpam-top-logo" src="<?php echo esc_url( $aff['logo_url'] ); ?>" alt="" />
							<?php else : ?>
								<span class="wpam-top-initial" style="background:<?php echo $color; ?>"><?php echo esc_html( strtoupper( substr( $aff['title'], 0, 1 ) ) ); ?></span>
							<?php endif; ?>
							<span class="wpam-top-name"><?php echo esc_html( $aff['title'] ); ?></span>
						</div>
						<div class="wpam-top-item-meta">
							<div class="wpam-top-bar-wrap">
								<div class="wpam-top-bar" style="width:<?php echo esc_attr( (string) $pct ); ?>%;background:<?php echo $color; ?>"></div>
							</div>
							<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( $aff['click_count'] ) ); ?></span>
							<span class="wpam-top-pct"><?php echo esc_html( $pct . '%' ); ?></span>
						</div>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Top lists genéricas — Clicked / Viewed / Scored
	// -------------------------------------------------------------------------

	/**
	 * Enriquece filas crudas de una *_Query::get()/get_cached() (id, title,
	 * <count_field>, permalink) con thumb_url y edit_url, usando únicamente
	 * funciones de WordPress (get_post_thumbnail_id, wp_get_attachment_image_url,
	 * get_edit_post_link) — nunca SQL directo.
	 *
	 * Cuando $resource_type === 'global' (v1.8.2), $items ya viene con un
	 * resource_type PROPIO por elemento (ver Views_Query::get_global()) —
	 * cada fila se resuelve según su propio tipo en vez de asumir uno único
	 * para toda la lista.
	 *
	 * @since  1.4.0
	 * @since  1.8.2 Soporta $resource_type === 'global' (resolución por-item).
	 * @param  array[] $items
	 * @return array[]
	 */
	private static function enrich_for_display( array $items, string $resource_type = 'post' ): array {
		if ( 'global' === $resource_type ) {
			foreach ( $items as &$item ) {
				$item_type = $item['resource_type'] ?? 'post';

				if ( in_array( $item_type, array( 'category', 'tag' ), true ) ) {
					$taxonomy           = 'category' === $item_type ? 'category' : 'post_tag';
					$edit_link          = get_edit_term_link( $item['id'], $taxonomy );
					$item['thumb_url']  = '';
					$item['edit_url']   = is_string( $edit_link ) ? $edit_link : '';
				} elseif ( in_array( $item_type, array( 'post', 'page' ), true ) ) {
					$thumb_id           = get_post_thumbnail_id( $item['id'] );
					$item['thumb_url']  = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
					$item['edit_url']   = (string) get_edit_post_link( $item['id'], 'raw' );
				} elseif ( 'home' === $item_type ) {
					$item['thumb_url'] = '';
					$item['edit_url']  = home_url( '/' );
				} else {
					// search / 404 — sin identidad propia, sin thumbnail ni enlace.
					$item['thumb_url'] = '';
					$item['edit_url']  = '';
				}
			}
			unset( $item );

			return $items;
		}

		if ( in_array( $resource_type, array( 'category', 'tag' ), true ) ) {
			$taxonomy = 'category' === $resource_type ? 'category' : 'post_tag';

			foreach ( $items as &$item ) {
				$item['thumb_url'] = '';
				$edit_link          = get_edit_term_link( $item['id'], $taxonomy );
				$item['edit_url']   = is_string( $edit_link ) ? $edit_link : '';
			}
			unset( $item );

			return $items;
		}

		// post / page — get_edit_post_link() funciona igual para ambos post_types.
		foreach ( $items as &$item ) {
			$thumb_id         = get_post_thumbnail_id( $item['id'] );
			$item['thumb_url'] = $thumb_id ? (string) wp_get_attachment_image_url( $thumb_id, 'thumbnail' ) : '';
			$item['edit_url']  = (string) get_edit_post_link( $item['id'], 'raw' );
		}
		unset( $item );

		return $items;
	}

	/**
	 * Lista `<ul class="wpam-top-list">` compartida entre Top Clicked Posts,
	 * Top Viewed Posts y Top Scored Posts. Solo cambia qué campo de conteo
	 * se usa.
	 *
	 * Cuando $resource_type === 'global' (v1.8.2), cada fila muestra además
	 * el badge de tipo (mismo estilo que Recent Views) para distinguir un
	 * Post de una Category en el mismo ranking mezclado.
	 *
	 * @since  1.4.0
	 * @since  1.8.2 Badge de tipo cuando $resource_type === 'global'.
	 * @param  array[] $items       Cada elemento debe tener: id, title, permalink y $count_field.
	 * @param  string  $count_field 'click_count' | 'view_count' | 'score'.
	 * @return void
	 */
	private static function render_top_list( array $items, string $count_field, string $resource_type = 'post' ): void {
		$items      = self::enrich_for_display( $items, $resource_type );
		$show_badge = ( 'global' === $resource_type );
		?>
		<ul class="wpam-top-list">
		<?php foreach ( $items as $item ) : ?>
			<li class="wpam-top-item">
				<div class="wpam-top-item-lead">
					<?php if ( $item['thumb_url'] ) : ?>
						<img class="wpam-top-thumb" src="<?php echo esc_url( $item['thumb_url'] ); ?>" alt="" />
					<?php else : ?>
						<span class="wpam-top-thumb-placeholder">📄</span>
					<?php endif; ?>
					<?php if ( $item['edit_url'] ) : ?>
						<a class="wpam-top-name" href="<?php echo esc_url( $item['edit_url'] ); ?>"><?php echo esc_html( $item['title'] ); ?></a>
					<?php else : ?>
						<span class="wpam-top-name"><?php echo esc_html( $item['title'] ); ?></span>
					<?php endif; ?>
					<?php if ( $show_badge ) : ?>
						<span class="wpam-recent-type-badge"><?php echo esc_html( self::type_badge_label( $item['resource_type'] ?? 'post' ) ); ?></span>
					<?php endif; ?>
				</div>
				<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( $item[ $count_field ] ) ); ?></span>
			</li>
		<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Top Clicked Posts.
	 *
	 * Renombrado desde "Top Posts" en v1.4.0 — solo el nombre visible y el
	 * método de render cambian; Top_Posts_Query, el shortcode [wpam_top_posts]
	 * y WPAM_API::get_top_posts() no se tocan.
	 *
	 * @since  1.4.0
	 * @param  array[] $posts Ver Top_Posts_Query::get_cached().
	 * @return void
	 */
	public static function render_top_clicked_posts_section( array $posts ): void {
		?>
		<div class="wpam-analytics-card">
			<h3 class="wpam-analytics-card-title">
				<span>📝</span> <?php esc_html_e( 'Top Clicked Posts', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<?php self::render_top_list( $posts, 'click_count' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Top Viewed Posts — mismo diseño visual que Top Clicked Posts.
	 *
	 * @since  1.4.0
	 * @param  array[] $posts Ver Views_Query::get_cached().
	 * @return void
	 */
	public static function render_top_viewed_posts_section( array $posts, string $resource_type = 'post' ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>👁️</span> <?php esc_html_e( 'Top Viewed', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No views recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<?php self::render_top_list( $posts, 'view_count', $resource_type ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Etiqueta legible para el badge de tipo — fuente única de verdad
	 * compartida entre Recent Views y Top Viewed (modo Global). Mismos 7
	 * valores que Resource_Resolver::TYPES.
	 *
	 * @since  1.8.2
	 * @param  string $resource_type
	 * @return string
	 */
	private static function type_badge_label( string $resource_type ): string {
		switch ( $resource_type ) {
			case 'post':
				return __( 'Post', 'wp-affiliatemanager' );
			case 'page':
				return __( 'Page', 'wp-affiliatemanager' );
			case 'category':
				return __( 'Category', 'wp-affiliatemanager' );
			case 'tag':
				return __( 'Tag', 'wp-affiliatemanager' );
			case 'home':
				return __( 'Home', 'wp-affiliatemanager' );
			case 'search':
				return __( 'Search', 'wp-affiliatemanager' );
			case '404':
				return __( '404', 'wp-affiliatemanager' );
			default:
				return '';
		}
	}

	/**
	 * Renderiza una lista simple "label + count" — usada para Top Search Terms
	 * y Top 404 URLs (v1.8.0). Sin thumbnail, sin edit_url: esos 2 tipos no
	 * tienen una entidad de WordPress detrás, solo texto agregado.
	 *
	 * @since  1.8.0
	 * @param  string $title Encabezado de la card (ya traducido).
	 * @param  string $icon
	 * @param  array[] $items Cada elemento: [ 'term'|'url' => string, 'count' => int ].
	 * @param  string $label_key 'term' o 'url' — qué clave de $items contiene el texto a mostrar.
	 * @return void
	 */
	public static function render_top_terms_section( string $title, string $icon, array $items, string $label_key ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span><?php echo esc_html( $icon ); ?></span> <?php echo esc_html( $title ); ?>
			</h3>
			<?php if ( empty( $items ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No views recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<ul class="wpam-top-list">
				<?php foreach ( $items as $item ) : ?>
					<li class="wpam-top-item">
						<div class="wpam-top-item-lead">
							<span class="wpam-top-thumb-placeholder"><?php echo esc_html( $icon ); ?></span>
							<span class="wpam-top-name"><?php echo esc_html( $item[ $label_key ] ); ?></span>
						</div>
						<span class="wpam-top-count"><?php echo esc_html( number_format_i18n( (int) $item['count'] ) ); ?></span>
					</li>
				<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Top Scored Posts — mismo diseño visual que Top Clicked Posts / Top Viewed Posts.
	 *
	 * @since  1.4.0
	 * @param  array[] $posts Ver Score_Query::get_cached().
	 * @return void
	 */
	public static function render_top_scored_posts_section( array $posts ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>⭐</span> <?php esc_html_e( 'Top Scored Posts', 'wp-affiliatemanager' ); ?>
			</h3>
			<?php if ( empty( $posts ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No activity recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<?php self::render_top_list( $posts, 'score' ); ?>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Recent Clicks
	// -------------------------------------------------------------------------

	/**
	 * Renderiza "Recent Clicks".
	 *
	 * $clicks es el array crudo de Top_Posts_Query::get_recent()
	 * (ts, post_id, affiliate_id, destination_url) — el enriquecimiento
	 * (título de post/afiliado, host de destino) se resuelve aquí con
	 * get_post() / wp_parse_url() / get_date_from_gmt(), sin SQL directo.
	 *
	 * @since  1.4.0
	 * @param  array[] $clicks
	 * @return void
	 */
	public static function render_recent_clicks_section( array $clicks ): void {
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>🕐</span> <?php esc_html_e( 'Recent Clicks', 'wp-affiliatemanager' ); ?>
				<span class="wpam-analytics-card-sub"><?php esc_html_e( 'Last 20', 'wp-affiliatemanager' ); ?></span>
			</h3>
			<?php if ( empty( $clicks ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No clicks recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<div class="wpam-table-wrap">
					<table class="wpam-table wpam-recent-clicks-table">
						<thead><tr>
							<th><?php esc_html_e( 'Date / Time', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Affiliate', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Post', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Destination', 'wp-affiliatemanager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $clicks as $click ) :
							$aff_post  = get_post( absint( $click['affiliate_id'] ) );
							$aff_name  = $aff_post instanceof \WP_Post ? $aff_post->post_title : '—';
							$src_post  = get_post( absint( $click['post_id'] ) );
							$src_title = $src_post instanceof \WP_Post ? $src_post->post_title : '—';
							$src_url   = $src_post instanceof \WP_Post ? (string) get_edit_post_link( $src_post->ID, 'raw' ) : '';
							$dest_host = (string) ( wp_parse_url( $click['destination_url'], PHP_URL_HOST ) ?: $click['destination_url'] );
							$ts_local  = get_date_from_gmt( $click['ts'], 'd M Y · H:i' );
						?>
							<tr>
								<td class="wpam-recent-ts"><?php echo esc_html( $ts_local ); ?></td>
								<td><?php echo esc_html( $aff_name ); ?></td>
								<td><?php if ( $src_url ) : ?><a href="<?php echo esc_url( $src_url ); ?>"><?php echo esc_html( $src_title ); ?></a><?php else : ?><?php echo esc_html( $src_title ); ?><?php endif; ?></td>
								<td><span class="wpam-dest-host"><?php echo esc_html( $dest_host ); ?></span></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Recent Views
	// -------------------------------------------------------------------------

	/**
	 * Renderiza "Recent Views".
	 *
	 * $views es el array crudo de Views_Query::get_recent()
	 * (post_id, resource_type, period, count) — mezcla de los 7 tipos de
	 * recurso soportados. El enriquecimiento (título, enlace) se resuelve
	 * aquí en 2 pasadas para evitar N+1: primero se agrupan los IDs por
	 * resource_type, luego se resuelven en lote (get_posts()/get_terms(),
	 * nunca una consulta individual por fila) antes de renderizar.
	 *
	 * Resolución por tipo:
	 * - post/page: título + enlace de edición (get_edit_post_link()) —
	 *   exactamente el mismo comportamiento que tenía Posts antes de v1.8.0.
	 * - category/tag: nombre del término + enlace de edición del término.
	 * - home: etiqueta fija "Home" enlazada a home_url('/').
	 * - search: etiqueta fija "Search" sin enlace — wpam_views solo guarda
	 *   resource_id=0 para búsquedas (el término real vive en la tabla
	 *   auxiliar wpam_views_search_terms, agregada de forma independiente sin
	 *   relación directa fila-a-fila con wpam_views, así que no hay un
	 *   término específico que asociar a esta fila sin introducir una
	 *   asociación artificial).
	 * - 404: etiqueta fija "404 Not Found" sin enlace, mismo motivo que Search.
	 *
	 * @since  1.4.0
	 * @since  1.8.0 Generalizado a los 7 resource_type (antes asumía Posts
	 *               implícitamente y descartaba silenciosamente cualquier
	 *               fila donde get_post() no devolviera un WP_Post).
	 * @param  array[] $views
	 * @return void
	 */
	public static function render_recent_views_section( array $views ): void {
		$views = self::resolve_recent_views_display( $views );
		?>
		<div class="wpam-analytics-card wpam-analytics-card--full">
			<h3 class="wpam-analytics-card-title">
				<span>👁️</span> <?php esc_html_e( 'Recent Views', 'wp-affiliatemanager' ); ?>
				<span class="wpam-analytics-card-sub"><?php esc_html_e( 'Last 20', 'wp-affiliatemanager' ); ?></span>
			</h3>
			<?php if ( empty( $views ) ) : ?>
				<p class="wpam-analytics-empty"><?php esc_html_e( 'No views recorded yet.', 'wp-affiliatemanager' ); ?></p>
			<?php else : ?>
				<div class="wpam-table-wrap">
					<table class="wpam-table wpam-recent-views-table">
						<thead><tr>
							<th><?php esc_html_e( 'Date', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Resource', 'wp-affiliatemanager' ); ?></th>
							<th><?php esc_html_e( 'Views', 'wp-affiliatemanager' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $views as $view ) :
							$date_display = mysql2date( get_option( 'date_format' ), $view['period'] . '000000' );
						?>
							<tr>
								<td class="wpam-recent-ts"><?php echo esc_html( $date_display ); ?></td>
								<td>
									<?php if ( $view['url'] ) : ?>
										<a href="<?php echo esc_url( $view['url'] ); ?>"><?php echo esc_html( $view['label'] ); ?></a>
									<?php else : ?>
										<?php echo esc_html( $view['label'] ); ?>
									<?php endif; ?>
									<span class="wpam-recent-type-badge"><?php echo esc_html( $view['type_label'] ); ?></span>
								</td>
								<td><?php echo esc_html( number_format_i18n( (int) $view['count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Resuelve las filas crudas de get_recent() (post_id, resource_type,
	 * period, count) a [label, url, type_label, period, count] listo para
	 * renderizar. Batch por tipo (2 llamadas a get_posts()/get_terms() como
	 * máximo, nunca una consulta por fila) en vez de resolver una a una.
	 *
	 * @since  1.8.0
	 * @param  array[] $views Filas crudas de Views_Query::get_recent().
	 * @return array[] Filas enriquecidas, mismo orden que la entrada.
	 */
	private static function resolve_recent_views_display( array $views ): array {
		if ( empty( $views ) ) {
			return array();
		}

		// Paso 1: agrupar IDs por resource_type.
		$post_page_ids = array();
		$category_ids  = array();
		$tag_ids       = array();

		foreach ( $views as $view ) {
			$id = (int) $view['post_id'];

			switch ( $view['resource_type'] ) {
				case 'post':
				case 'page':
					$post_page_ids[ $id ] = true;
					break;
				case 'category':
					$category_ids[ $id ] = true;
					break;
				case 'tag':
					$tag_ids[ $id ] = true;
					break;
			}
		}

		// Paso 2: resolver en lote — como máximo 1 query por grupo, nunca por fila.
		$post_map = array();
		if ( ! empty( $post_page_ids ) ) {
			$found = get_posts( array(
				'post__in'            => array_keys( $post_page_ids ),
				'post_type'           => array( 'post', 'page' ),
				'post_status'         => 'any',
				'posts_per_page'      => count( $post_page_ids ),
				'orderby'             => 'post__in',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			) );
			foreach ( $found as $post ) {
				$post_map[ $post->ID ] = $post;
			}
		}

		$term_map = array(); // Clave "taxonomy:id" — category_id=5 y tag_id=5 no deben colisionar.
		if ( ! empty( $category_ids ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'category',
				'include'    => array_keys( $category_ids ),
				'hide_empty' => false,
			) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_map[ 'category:' . $term->term_id ] = $term;
				}
			}
		}
		if ( ! empty( $tag_ids ) ) {
			$terms = get_terms( array(
				'taxonomy'   => 'post_tag',
				'include'    => array_keys( $tag_ids ),
				'hide_empty' => false,
			) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$term_map[ 'tag:' . $term->term_id ] = $term;
				}
			}
		}

		// Paso 3: construir las filas de salida usando únicamente los mapas ya resueltos.
		$resolved = array();

		foreach ( $views as $view ) {
			$id            = (int) $view['post_id'];
			$resource_type = (string) $view['resource_type'];
			$row           = array(
				'period' => $view['period'],
				'count'  => $view['count'],
				'label'  => '',
				'url'    => '',
			);

			switch ( $resource_type ) {
				case 'post':
				case 'page':
					if ( ! isset( $post_map[ $id ] ) ) {
						continue 2; // Post/page borrado desde el tracking — mismo comportamiento que antes de v1.8.0.
					}
					$post              = $post_map[ $id ];
					$row['label']      = $post->post_title ?: __( '(no title)', 'wp-affiliatemanager' );
					$row['url']        = (string) get_edit_post_link( $id, 'raw' );
					$row['type_label'] = self::type_badge_label( $resource_type );
					break;

				case 'category':
				case 'tag':
					$term_key = $resource_type . ':' . $id;
					if ( ! isset( $term_map[ $term_key ] ) ) {
						continue 2; // Término borrado — mismo criterio que un post borrado.
					}
					$term              = $term_map[ $term_key ];
					$edit_link         = get_edit_term_link( $term->term_id, 'category' === $resource_type ? 'category' : 'post_tag' );
					$row['label']      = $term->name;
					$row['url']        = is_string( $edit_link ) ? $edit_link : '';
					$row['type_label'] = self::type_badge_label( $resource_type );
					break;

				case 'home':
					$row['label']      = __( 'Home', 'wp-affiliatemanager' );
					$row['url']        = home_url( '/' );
					$row['type_label'] = self::type_badge_label( 'home' );
					break;

				case 'search':
					// wpam_views solo guarda resource_id=0 para búsquedas; el término
					// real vive en wpam_views_search_terms (tabla agregada
					// independiente, sin relación fila-a-fila con esta). Mostrar la
					// etiqueta genérica evita una asociación artificial entre ambas.
					$row['label']      = __( 'Search', 'wp-affiliatemanager' );
					$row['type_label'] = self::type_badge_label( 'search' );
					break;

				case '404':
					// Mismo motivo que 'search' — ver comentario arriba, aplicado a wpam_views_404.
					$row['label']      = __( '404 Not Found', 'wp-affiliatemanager' );
					$row['type_label'] = self::type_badge_label( '404' );
					break;

				default:
					continue 2; // resource_type desconocido — no debería ocurrir, pero no romper el listado.
			}

			$resolved[] = $row;
		}

		return $resolved;
	}
}
