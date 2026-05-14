<?php
/**
 * Admin UI — lista y acciones de afiliados.
 *
 * Gestiona la pantalla "Affiliates" dentro del menú del plugin:
 * listado, acciones rápidas (toggle activo/inactivo, eliminar).
 * El formulario de creación/edición usa el CPT nativo de WordPress
 * (post.php / post-new.php) con los meta boxes registrados en Meta.
 *
 * @package WP_AffiliateManager\Admin
 * @since   2.0.0
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Affiliates\CPT;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Affiliates_Screen
 *
 * Renderiza la pantalla de administración de afiliados y procesa
 * las acciones (toggle, delete) enviadas desde esa pantalla.
 *
 * @since 2.0.0
 */
class Affiliates_Screen {

	/**
	 * @since 2.0.0
	 * @var   Repository
	 */
	private Repository $repository;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		$this->repository = new Repository();
	}

	/**
	 * Procesa acciones GET antes de renderizar la pantalla.
	 * Se ejecuta en 'admin_init'.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function handle_actions(): void {
		// Solo actuar en nuestra página.
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'wpam-affiliates' !== $page ) {
			return;
		}

		$action = isset( $_GET['wpam_action'] ) ? sanitize_key( $_GET['wpam_action'] ) : '';
		$id     = isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0;

		if ( ! $action || ! $id ) {
			return;
		}

		// Verificar nonce y capacidad para todas las acciones.
		if (
			! isset( $_GET['_wpnonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'wpam_affiliate_action_' . $id )
		) {
			wp_die( esc_html__( 'Security check failed.', 'wp-affiliatemanager' ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'wp-affiliatemanager' ) );
		}

		switch ( $action ) {
			case 'activate':
				$this->repository->set_active( $id, true );
				$message = 'activated';
				break;

			case 'deactivate':
				$this->repository->set_active( $id, false );
				$message = 'deactivated';
				break;

			case 'delete':
				$this->repository->delete( $id );
				$message = 'deleted';
				break;

			default:
				return;
		}

		// Redirigir de vuelta a la lista con mensaje.
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'wpam-affiliates',
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Renderiza la pantalla completa de Affiliates.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-affiliatemanager' ) );
		}

		$result     = $this->repository->find_all();
		$affiliates = $result['items'];
		$total      = $result['total'];

		$message = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="wpam-page-content">

			<?php $this->render_notices( $message ); ?>

			<div class="wpam-screen-header">
				<div class="wpam-screen-header-info">
					<h2 class="wpam-screen-title">
						<?php esc_html_e( 'Affiliates', 'wp-affiliatemanager' ); ?>
						<span class="wpam-count-badge"><?php echo absint( $total ); ?></span>
					</h2>
				</div>
				<a
					href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . CPT::POST_TYPE ) ); ?>"
					class="button button-primary wpam-btn-primary"
				>
					+ <?php esc_html_e( 'Add New Affiliate', 'wp-affiliatemanager' ); ?>
				</a>
			</div>

			<?php if ( empty( $affiliates ) ) : ?>
				<?php $this->render_empty_state(); ?>
			<?php else : ?>
				<?php $this->render_affiliates_table( $affiliates ); ?>
			<?php endif; ?>

		</div>
		<?php
	}

	/**
	 * Renderiza la tabla de afiliados.
	 *
	 * @since  2.0.0
	 * @param  array $affiliates Lista de afiliados normalizados.
	 * @return void
	 */
	private function render_affiliates_table( array $affiliates ): void {
		?>
		<div class="wpam-table-wrap">
			<table class="wpam-table">
				<thead>
					<tr>
						<th class="wpam-col-logo"><?php esc_html_e( 'Logo', 'wp-affiliatemanager' ); ?></th>
						<th class="wpam-col-name"><?php esc_html_e( 'Affiliate Name', 'wp-affiliatemanager' ); ?></th>
						<th class="wpam-col-param"><?php esc_html_e( 'Parameter', 'wp-affiliatemanager' ); ?></th>
						<th class="wpam-col-value"><?php esc_html_e( 'Value', 'wp-affiliatemanager' ); ?></th>
						<th class="wpam-col-status"><?php esc_html_e( 'Status', 'wp-affiliatemanager' ); ?></th>
						<th class="wpam-col-actions"><?php esc_html_e( 'Actions', 'wp-affiliatemanager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $affiliates as $affiliate ) : ?>
						<?php $this->render_affiliate_row( $affiliate ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * Renderiza una fila de la tabla de afiliados.
	 *
	 * @since  2.0.0
	 * @param  array $affiliate Datos normalizados del afiliado.
	 * @return void
	 */
	private function render_affiliate_row( array $affiliate ): void {
		$id          = (int) $affiliate['id'];
		$is_active   = (bool) $affiliate['active'];
		$brand_color = esc_attr( $affiliate['brand_color'] );

		$toggle_action = $is_active ? 'deactivate' : 'activate';
		$toggle_nonce  = wp_create_nonce( 'wpam_affiliate_action_' . $id );
		$delete_nonce  = wp_create_nonce( 'wpam_affiliate_action_' . $id );

		$toggle_url = add_query_arg( array(
			'page'         => 'wpam-affiliates',
			'wpam_action'  => $toggle_action,
			'affiliate_id' => $id,
			'_wpnonce'     => $toggle_nonce,
		), admin_url( 'admin.php' ) );

		$delete_url = add_query_arg( array(
			'page'         => 'wpam-affiliates',
			'wpam_action'  => 'delete',
			'affiliate_id' => $id,
			'_wpnonce'     => $delete_nonce,
		), admin_url( 'admin.php' ) );

		$edit_url = get_edit_post_link( $id, 'raw' );
		?>
		<tr class="wpam-table-row <?php echo $is_active ? 'wpam-row--active' : 'wpam-row--inactive'; ?>">

			<!-- Logo -->
			<td class="wpam-col-logo">
				<?php if ( $affiliate['logo_url'] ) : ?>
					<div class="wpam-table-logo" style="border-color: <?php echo esc_attr( $brand_color ); ?>">
						<img
							src="<?php echo esc_url( $affiliate['logo_url'] ); ?>"
							alt="<?php echo esc_attr( $affiliate['title'] ); ?>"
						/>
					</div>
				<?php else : ?>
					<div class="wpam-table-logo-placeholder" style="background: <?php echo esc_attr( $brand_color ); ?>">
						<?php echo esc_html( strtoupper( substr( $affiliate['title'], 0, 2 ) ) ); ?>
					</div>
				<?php endif; ?>
			</td>

			<!-- Nombre -->
			<td class="wpam-col-name">
				<a href="<?php echo esc_url( $edit_url ); ?>" class="wpam-affiliate-name">
					<?php echo esc_html( $affiliate['title'] ); ?>
				</a>
				<?php if ( $affiliate['slug'] ) : ?>
					<span class="wpam-affiliate-slug"><?php echo esc_html( $affiliate['slug'] ); ?></span>
				<?php endif; ?>
			</td>

			<!-- Parámetro -->
			<td class="wpam-col-param">
				<?php if ( $affiliate['param'] ) : ?>
					<code class="wpam-code"><?php echo esc_html( $affiliate['param'] ); ?></code>
				<?php else : ?>
					<span class="wpam-empty">—</span>
				<?php endif; ?>
			</td>

			<!-- Valor -->
			<td class="wpam-col-value">
				<?php if ( $affiliate['value'] ) : ?>
					<code class="wpam-code"><?php echo esc_html( $affiliate['value'] ); ?></code>
				<?php else : ?>
					<span class="wpam-empty">—</span>
				<?php endif; ?>
			</td>

			<!-- Status -->
			<td class="wpam-col-status">
				<span class="wpam-status-badge wpam-status-badge--<?php echo $is_active ? 'active' : 'inactive'; ?>">
					<?php echo $is_active ? esc_html__( 'Active', 'wp-affiliatemanager' ) : esc_html__( 'Inactive', 'wp-affiliatemanager' ); ?>
				</span>
			</td>

			<!-- Acciones -->
			<td class="wpam-col-actions">
				<div class="wpam-row-actions">
					<a href="<?php echo esc_url( $edit_url ); ?>" class="wpam-action-btn wpam-action-btn--edit" title="<?php esc_attr_e( 'Edit', 'wp-affiliatemanager' ); ?>">
						✏️
					</a>
					<a
						href="<?php echo esc_url( $toggle_url ); ?>"
						class="wpam-action-btn wpam-action-btn--toggle"
						title="<?php echo $is_active ? esc_attr__( 'Deactivate', 'wp-affiliatemanager' ) : esc_attr__( 'Activate', 'wp-affiliatemanager' ); ?>"
					>
						<?php echo $is_active ? '⏸️' : '▶️'; ?>
					</a>
					<a
						href="<?php echo esc_url( $delete_url ); ?>"
						class="wpam-action-btn wpam-action-btn--delete"
						title="<?php esc_attr_e( 'Delete', 'wp-affiliatemanager' ); ?>"
						data-confirm="<?php esc_attr_e( 'Are you sure you want to permanently delete this affiliate?', 'wp-affiliatemanager' ); ?>"
					>
						🗑️
					</a>
				</div>
			</td>

		</tr>
		<?php
	}

	/**
	 * Renderiza el estado vacío cuando no hay afiliados.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	private function render_empty_state(): void {
		?>
		<div class="wpam-empty-state">
			<span class="wpam-empty-state-icon">📦</span>
			<h3><?php esc_html_e( 'No affiliates yet', 'wp-affiliatemanager' ); ?></h3>
			<p><?php esc_html_e( 'Add your first affiliate program to start generating tracked links.', 'wp-affiliatemanager' ); ?></p>
			<a
				href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . CPT::POST_TYPE ) ); ?>"
				class="button button-primary wpam-btn-primary"
			>
				+ <?php esc_html_e( 'Add First Affiliate', 'wp-affiliatemanager' ); ?>
			</a>
		</div>
		<?php
	}

	/**
	 * Renderiza el notice de resultado de acción.
	 *
	 * @since  2.0.0
	 * @param  string $message Clave del mensaje.
	 * @return void
	 */
	private function render_notices( string $message ): void {
		if ( ! $message ) {
			return;
		}

		$messages = array(
			'activated'   => __( 'Affiliate activated successfully.', 'wp-affiliatemanager' ),
			'deactivated' => __( 'Affiliate deactivated successfully.', 'wp-affiliatemanager' ),
			'deleted'     => __( 'Affiliate permanently deleted.', 'wp-affiliatemanager' ),
		);

		if ( ! isset( $messages[ $message ] ) ) {
			return;
		}

		$type = ( 'deleted' === $message ) ? 'warning' : 'success';
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible wpam-notice">
			<p><?php echo esc_html( $messages[ $message ] ); ?></p>
		</div>
		<?php
	}
}
