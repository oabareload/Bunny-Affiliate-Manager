<?php
/**
 * Widget WordPress — Top Posts más clicados.
 *
 * Datos:   Top_Posts_Query::get_cached()
 * Render:  Top_Posts_Renderer::render()
 *
 * Esta clase no contiene lógica SQL ni lógica de presentación.
 * Se ocupa únicamente de la integración con la API de widgets de WordPress.
 *
 * @package WP_AffiliateManager\Frontend
 * @since   1.0.0
 */

namespace WP_AffiliateManager\Frontend;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Widget_Top_Posts extends \WP_Widget {

	/** ID base del widget. */
	const WIDGET_ID = 'wpam_top_posts';

	/** Rangos válidos. */
	private const VALID_PERIODS = array( 'today', 'week', 'month', 'total' );

	/** Layouts válidos. */
	private const VALID_LAYOUTS = array( 'horizontal', 'vertical' );

	// -------------------------------------------------------------------------
	// Constructor
	// -------------------------------------------------------------------------

	public function __construct() {
		parent::__construct(
			self::WIDGET_ID,
			__( 'Top Posts (Bunny Affiliate)', 'wp-affiliatemanager' ),
			array(
				'description'                 => __( 'Displays the most clicked posts tracked by Bunny Affiliate Manager.', 'wp-affiliatemanager' ),
				'customize_selective_refresh' => true,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Registro
	// -------------------------------------------------------------------------

	/**
	 * Registra el widget con WordPress.
	 *
	 * Llamado desde class-plugin.php vía widgets_init.
	 */
	public static function register(): void {
		register_widget( __CLASS__ );
	}

	// -------------------------------------------------------------------------
	// Frontend render
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el widget en el frontend.
	 *
	 * @param array $args     Argumentos del sidebar (before_widget, after_widget, etc.).
	 * @param array $instance Configuración guardada de esta instancia del widget.
	 */
	public function widget( $args, $instance ): void {
		// Resolver opciones con defaults.
		$period         = $this->resolve_period( $instance['period'] ?? 'total' );
		$layout         = $this->resolve_layout( $instance['layout'] ?? 'horizontal' );
		$limit          = max( 1, min( 100, (int) ( $instance['limit'] ?? 10 ) ) );
		$title          = sanitize_text_field( $instance['title'] ?? '' );
		$show_title     = (bool) ( $instance['show_title'] ?? true );
		$show_thumbnail = (bool) ( $instance['show_thumbnail'] ?? true );
		$max_width      = sanitize_text_field( $instance['max_width'] ?? '' );

		// Validar thumbnail_size — Top_Posts_Renderer es la fuente única de esta lógica.
		$thumbnail_size = Top_Posts_Renderer::resolve_thumbnail_size(
			sanitize_key( $instance['thumbnail_size'] ?? 'medium' )
		);

		// Obtener posts — Top_Posts_Query es la fuente única de datos y caché.
		$posts = Top_Posts_Query::get_cached( $period, $limit );

		// Encolar CSS del widget si no está ya encolado.
		if ( ! wp_style_is( 'wpam-top-posts-widget', 'enqueued' ) ) {
			wp_enqueue_style( 'wpam-top-posts-widget' );
		}

		// Envolver con el markup del sidebar.
		echo $args['before_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — WordPress core output.

		// Delegar render al renderer — fuente única de HTML de salida.
		echo Top_Posts_Renderer::render( $posts, array( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — renderer escapa internamente.
			'title'          => $title,
			'show_title'     => $show_title,
			'layout'         => $layout,
			'thumbnail_size' => $thumbnail_size,
			'max_width'      => $max_width,
			'show_thumbnail' => $show_thumbnail,
		) );

		echo $args['after_widget']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — WordPress core output.
	}

	// -------------------------------------------------------------------------
	// Formulario de configuración (admin)
	// -------------------------------------------------------------------------

	/**
	 * Renderiza el formulario de configuración en el panel de widgets.
	 *
	 * @param array $instance Configuración guardada de esta instancia.
	 * @return string
	 */
	public function form( $instance ): string {
		// Valores actuales con defaults.
		$title          = sanitize_text_field( $instance['title']          ?? '' );
		$show_title     = (bool)              ( $instance['show_title']    ?? true );
		$period         = $this->resolve_period( $instance['period']       ?? 'total' );
		$layout         = $this->resolve_layout( $instance['layout']       ?? 'horizontal' );
		$limit          = max( 1, min( 100, (int) ( $instance['limit']     ?? 10 ) ) );
		$show_thumbnail = (bool)              ( $instance['show_thumbnail'] ?? true );
		$thumbnail_size = Top_Posts_Renderer::resolve_thumbnail_size(
			sanitize_key( $instance['thumbnail_size'] ?? 'medium' )
		);
		$max_width      = sanitize_text_field( $instance['max_width']      ?? '' );

		// Tamaños de imagen registrados — Top_Posts_Renderer es la fuente única.
		$image_sizes = Top_Posts_Renderer::get_registered_image_sizes();
		?>

		<!-- Título -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>">
				<?php esc_html_e( 'Title:', 'wp-affiliatemanager' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $title ); ?>"
			/>
		</p>

		<!-- Mostrar título -->
		<p>
			<input
				type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_title' ) ); ?>"
				value="1"
				<?php checked( $show_title ); ?>
			/>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_title' ) ); ?>">
				<?php esc_html_e( 'Show title', 'wp-affiliatemanager' ); ?>
			</label>
		</p>

		<!-- Layout -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>">
				<?php esc_html_e( 'Layout:', 'wp-affiliatemanager' ); ?>
			</label>
			<select
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'layout' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'layout' ) ); ?>"
			>
				<option value="horizontal" <?php selected( $layout, 'horizontal' ); ?>>
					<?php esc_html_e( 'Horizontal', 'wp-affiliatemanager' ); ?>
				</option>
				<option value="vertical" <?php selected( $layout, 'vertical' ); ?>>
					<?php esc_html_e( 'Vertical', 'wp-affiliatemanager' ); ?>
				</option>
			</select>
		</p>

		<!-- Período -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>">
				<?php esc_html_e( 'Period:', 'wp-affiliatemanager' ); ?>
			</label>
			<select
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'period' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'period' ) ); ?>"
			>
				<option value="today" <?php selected( $period, 'today' ); ?>>
					<?php esc_html_e( 'Today', 'wp-affiliatemanager' ); ?>
				</option>
				<option value="week" <?php selected( $period, 'week' ); ?>>
					<?php esc_html_e( 'Last 7 Days', 'wp-affiliatemanager' ); ?>
				</option>
				<option value="month" <?php selected( $period, 'month' ); ?>>
					<?php esc_html_e( 'Last 30 Days', 'wp-affiliatemanager' ); ?>
				</option>
				<option value="total" <?php selected( $period, 'total' ); ?>>
					<?php esc_html_e( 'Total', 'wp-affiliatemanager' ); ?>
				</option>
			</select>
		</p>

		<!-- Número de posts -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>">
				<?php esc_html_e( 'Number of posts:', 'wp-affiliatemanager' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'limit' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'limit' ) ); ?>"
				type="number"
				min="1"
				max="100"
				value="<?php echo esc_attr( (string) $limit ); ?>"
			/>
		</p>

		<!-- Mostrar thumbnail -->
		<p>
			<input
				type="checkbox"
				id="<?php echo esc_attr( $this->get_field_id( 'show_thumbnail' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'show_thumbnail' ) ); ?>"
				value="1"
				<?php checked( $show_thumbnail ); ?>
			/>
			<label for="<?php echo esc_attr( $this->get_field_id( 'show_thumbnail' ) ); ?>">
				<?php esc_html_e( 'Show thumbnail', 'wp-affiliatemanager' ); ?>
			</label>
		</p>

		<!-- Tamaño de imagen — generado dinámicamente desde WordPress -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'thumbnail_size' ) ); ?>">
				<?php esc_html_e( 'Image size:', 'wp-affiliatemanager' ); ?>
			</label>
			<select
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'thumbnail_size' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'thumbnail_size' ) ); ?>"
			>
				<?php foreach ( $image_sizes as $size ) : ?>
					<option value="<?php echo esc_attr( $size ); ?>" <?php selected( $thumbnail_size, $size ); ?>>
						<?php echo esc_html( $size ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</p>

		<!-- Ancho máximo -->
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'max_width' ) ); ?>">
				<?php esc_html_e( 'Max width (optional, e.g. 400px, 100%):', 'wp-affiliatemanager' ); ?>
			</label>
			<input
				class="widefat"
				id="<?php echo esc_attr( $this->get_field_id( 'max_width' ) ); ?>"
				name="<?php echo esc_attr( $this->get_field_name( 'max_width' ) ); ?>"
				type="text"
				value="<?php echo esc_attr( $max_width ); ?>"
				placeholder="e.g. 400px"
			/>
		</p>

		<?php
		return '';
	}

	// -------------------------------------------------------------------------
	// Sanitización al guardar
	// -------------------------------------------------------------------------

	/**
	 * Sanitiza y guarda los valores del formulario.
	 *
	 * @param  array $new_instance Nuevos valores enviados.
	 * @param  array $old_instance Valores anteriores.
	 * @return array  Valores sanitizados a guardar.
	 */
	public function update( $new_instance, $old_instance ): array {
		return array(
			'title'          => sanitize_text_field( $new_instance['title']          ?? '' ),
			'show_title'     => ! empty( $new_instance['show_title'] ),
			'period'         => $this->resolve_period( $new_instance['period']       ?? 'total' ),
			'layout'         => $this->resolve_layout( $new_instance['layout']       ?? 'horizontal' ),
			'limit'          => max( 1, min( 100, (int) ( $new_instance['limit']     ?? 10 ) ) ),
			'show_thumbnail' => ! empty( $new_instance['show_thumbnail'] ),
			'thumbnail_size' => Top_Posts_Renderer::resolve_thumbnail_size(
				sanitize_key( $new_instance['thumbnail_size'] ?? 'medium' )
			),
			'max_width'      => sanitize_text_field( $new_instance['max_width']      ?? '' ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers de validación internos
	// -------------------------------------------------------------------------

	private function resolve_period( string $period ): string {
		return in_array( $period, self::VALID_PERIODS, true ) ? $period : 'total';
	}

	private function resolve_layout( string $layout ): string {
		return in_array( $layout, self::VALID_LAYOUTS, true ) ? $layout : 'horizontal';
	}
}
