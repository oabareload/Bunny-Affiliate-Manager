<?php
/**
 * Admin UI — lista y acciones de afiliados (inline CRUD).
 *
 * v0.0.6: CRUD inline sin pantalla separada.
 * - Agregar: fila editable inline vía AJAX.
 * - Editar: fila existente cambia a modo edición vía AJAX.
 * - Eliminar / toggle activo: igual que antes (redirect GET).
 * - Sin React, sin DataTables, sin pantalla aparte.
 *
 * @package WP_AffiliateManager\Admin
 * @since   2.0.0
 * @version 0.0.6
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
 * @since 2.0.0
 */
class Affiliates_Screen {

	private Repository $repository;

	public function __construct() {
		$this->repository = new Repository();
	}

	// -------------------------------------------------------------------------
	// Acciones GET (toggle / delete) — sin cambios respecto a v0.0.5
	// -------------------------------------------------------------------------

	public function handle_actions(): void {
		$page = isset( $_GET['page'] ) ? sanitize_key( $_GET['page'] ) : '';
		if ( 'wpam-affiliates' !== $page ) {
			return;
		}

		$action = isset( $_GET['wpam_action'] ) ? sanitize_key( $_GET['wpam_action'] ) : '';
		$id     = isset( $_GET['affiliate_id'] ) ? absint( $_GET['affiliate_id'] ) : 0;

		if ( ! $action || ! $id ) {
			return;
		}

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

		wp_safe_redirect(
			add_query_arg(
				array( 'page' => 'wpam-affiliates', 'message' => $message ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	// -------------------------------------------------------------------------
	// AJAX: guardar afiliado (nuevo o existente)
	// -------------------------------------------------------------------------

	/**
	 * Handler AJAX — guardar affiliate inline.
	 * action: wpam_save_affiliate
	 *
	 * @since 0.0.6
	 */
	public function ajax_save(): void {
		check_ajax_referer( 'wpam_inline_crud', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
		}

		$id    = absint( $_POST['id']    ?? 0 );
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );

		if ( ! $title ) {
			wp_send_json_error( __( 'Affiliate name is required.', 'wp-affiliatemanager' ) );
		}

		$data = array(
			'id'          => $id,
			'title'       => $title,
			'slug'        => sanitize_text_field( wp_unslash( $_POST['slug']        ?? '' ) ),
			'param'       => sanitize_text_field( wp_unslash( $_POST['param']       ?? '' ) ),
			'value'       => sanitize_text_field( wp_unslash( $_POST['value']       ?? '' ) ),
			'logo_url'    => esc_url_raw( wp_unslash( $_POST['logo_url']            ?? '' ) ),
			'brand_color' => sanitize_hex_color( wp_unslash( $_POST['brand_color']  ?? '#6c47ff' ) ) ?? '#6c47ff',
			'active'      => ! empty( $_POST['active'] ),
			'visible'     => ! empty( $_POST['visible'] ),
			'domains'     => sanitize_textarea_field( wp_unslash( $_POST['domains'] ?? '' ) ),
		);

		$result = $this->repository->save( $data );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		$affiliate = $this->repository->find( $result );
		if ( ! $affiliate ) {
			wp_send_json_error( __( 'Could not retrieve saved affiliate.', 'wp-affiliatemanager' ) );
		}

		wp_send_json_success( array(
			'affiliate' => $affiliate,
			'row_html'  => $this->get_affiliate_row_html( $affiliate ),
			'is_new'    => ( 0 === $id ),
		) );
	}

	/**
	 * Handler AJAX — obtener formulario de edición de una fila.
	 * action: wpam_get_edit_row
	 *
	 * @since 0.0.6
	 */
	public function ajax_get_edit_row(): void {
		check_ajax_referer( 'wpam_inline_crud', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'wp-affiliatemanager' ) );
		}

		$id        = absint( $_POST['id'] ?? 0 );
		$affiliate = $id > 0 ? $this->repository->find( $id ) : null;

		ob_start();
		$this->render_edit_row( $affiliate );
		$html = ob_get_clean();

		wp_send_json_success( array( 'row_html' => $html ) );
	}

	// -------------------------------------------------------------------------
	// Render de la pantalla principal
	// -------------------------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'wp-affiliatemanager' ) );
		}

		$result     = $this->repository->find_all();
		$affiliates = $result['items'];
		$total      = $result['total'];
		$message    = isset( $_GET['message'] ) ? sanitize_key( $_GET['message'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		?>
		<div class="bunny-page-content">

			<?php $this->render_notices( $message ); ?>

			<div class="wpam-screen-header">
				<div class="wpam-screen-header-info">
					<h2 class="wpam-screen-title">
						<?php esc_html_e( 'Affiliates', 'wp-affiliatemanager' ); ?>
						<span class="wpam-count-badge" id="wpam-affiliates-count"><?php echo absint( $total ); ?></span>
					</h2>
				</div>
				<button type="button" class="button button-primary wpam-btn-primary" id="wpam-add-affiliate-btn">
					+ <?php esc_html_e( 'Add Affiliate', 'wp-affiliatemanager' ); ?>
				</button>
			</div>

			<!-- Mensaje de error/éxito AJAX -->
			<div id="wpam-ajax-notice" class="wpam-ajax-notice" style="display:none;"></div>

			<div class="wpam-table-wrap" id="wpam-table-wrap">
				<table class="wpam-table" id="wpam-affiliates-table">
					<thead>
						<tr>
							<th class="wpam-col-logo"><?php esc_html_e( 'Logo', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-name"><?php esc_html_e( 'Name', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-param"><?php esc_html_e( 'Param', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-value"><?php esc_html_e( 'Value', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-domains"><?php esc_html_e( 'Domains', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-flags"><?php esc_html_e( 'Flags', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-status"><?php esc_html_e( 'Status', 'wp-affiliatemanager' ); ?></th>
							<th class="wpam-col-actions"><?php esc_html_e( 'Actions', 'wp-affiliatemanager' ); ?></th>
						</tr>
					</thead>
					<tbody id="wpam-affiliates-tbody">
						<?php if ( empty( $affiliates ) ) : ?>
							<tr id="wpam-empty-row">
								<td colspan="8" class="wpam-table-empty">
									<?php esc_html_e( 'No affiliates yet. Click "Add Affiliate" to create your first one.', 'wp-affiliatemanager' ); ?>
								</td>
							</tr>
						<?php else : ?>
							<?php foreach ( $affiliates as $affiliate ) : ?>
								<?php $this->render_affiliate_row( $affiliate ); ?>
							<?php endforeach; ?>
						<?php endif; ?>
					</tbody>
				</table>
			</div>

		</div>
		<?php
	}

	// -------------------------------------------------------------------------
	// Render: fila de visualización
	// -------------------------------------------------------------------------

	private function render_affiliate_row( array $affiliate ): void {
		echo $this->get_affiliate_row_html( $affiliate ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Genera el HTML de una fila de visualización (no edición).
	 *
	 * @since 0.0.6
	 */
	public function get_affiliate_row_html( array $affiliate ): string {
		$id          = (int) $affiliate['id'];
		$is_active   = (bool) $affiliate['active'];
		$is_visible  = (bool) $affiliate['visible'];
		$brand_color = esc_attr( $affiliate['brand_color'] );

		$toggle_action = $is_active ? 'deactivate' : 'activate';
		$nonce         = wp_create_nonce( 'wpam_affiliate_action_' . $id );

		$toggle_url = add_query_arg( array(
			'page'         => 'wpam-affiliates',
			'wpam_action'  => $toggle_action,
			'affiliate_id' => $id,
			'_wpnonce'     => $nonce,
		), admin_url( 'admin.php' ) );

		$delete_url = add_query_arg( array(
			'page'         => 'wpam-affiliates',
			'wpam_action'  => 'delete',
			'affiliate_id' => $id,
			'_wpnonce'     => $nonce,
		), admin_url( 'admin.php' ) );

		ob_start();
		?>
		<tr
			class="wpam-table-row <?php echo $is_active ? 'wpam-row--active' : 'wpam-row--inactive'; ?>"
			data-id="<?php echo esc_attr( (string) $id ); ?>"
			id="wpam-row-<?php echo esc_attr( (string) $id ); ?>"
		>
			<!-- Logo -->
			<td class="wpam-col-logo">
				<?php if ( $affiliate['logo_url'] ) : ?>
					<div class="wpam-table-logo" style="border-color:<?php echo esc_attr( $brand_color ); ?>">
						<img src="<?php echo esc_url( $affiliate['logo_url'] ); ?>" alt="<?php echo esc_attr( $affiliate['title'] ); ?>" />
					</div>
				<?php else : ?>
					<div class="wpam-table-logo-placeholder" style="background:<?php echo esc_attr( $brand_color ); ?>">
						<?php echo esc_html( strtoupper( substr( $affiliate['title'], 0, 2 ) ) ); ?>
					</div>
				<?php endif; ?>
			</td>

			<!-- Nombre -->
			<td class="wpam-col-name">
				<span class="wpam-affiliate-name"><?php echo esc_html( $affiliate['title'] ); ?></span>
				<?php if ( $affiliate['slug'] ) : ?>
					<span class="wpam-affiliate-slug"><?php echo esc_html( $affiliate['slug'] ); ?></span>
				<?php endif; ?>
			</td>

			<!-- Param -->
			<td class="wpam-col-param">
				<?php if ( $affiliate['param'] ) : ?>
					<code class="wpam-code"><?php echo esc_html( $affiliate['param'] ); ?></code>
				<?php else : ?>
					<span class="wpam-empty">—</span>
				<?php endif; ?>
			</td>

			<!-- Value -->
			<td class="wpam-col-value">
				<?php if ( $affiliate['value'] ) : ?>
					<code class="wpam-code"><?php echo esc_html( $affiliate['value'] ); ?></code>
				<?php else : ?>
					<span class="wpam-empty">—</span>
				<?php endif; ?>
			</td>

			<!-- Domains -->
			<td class="wpam-col-domains">
				<?php if ( $affiliate['domains'] ) : ?>
					<span class="wpam-domains-text"><?php echo esc_html( $affiliate['domains'] ); ?></span>
				<?php else : ?>
					<span class="wpam-empty">—</span>
				<?php endif; ?>
			</td>

			<!-- Flags: visible -->
			<td class="wpam-col-flags">
				<span class="wpam-flag-badge <?php echo $is_visible ? 'wpam-flag--on' : 'wpam-flag--off'; ?>" title="<?php echo $is_visible ? esc_attr__( 'Visible', 'wp-affiliatemanager' ) : esc_attr__( 'Hidden', 'wp-affiliatemanager' ); ?>">
					<?php echo $is_visible ? '👁️' : '🙈'; ?>
				</span>
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
					<button
						type="button"
						class="wpam-action-btn wpam-action-btn--edit"
						data-id="<?php echo esc_attr( (string) $id ); ?>"
						title="<?php esc_attr_e( 'Edit inline', 'wp-affiliatemanager' ); ?>"
					>✏️</button>
					<a
						href="<?php echo esc_url( $toggle_url ); ?>"
						class="wpam-action-btn wpam-action-btn--toggle"
						title="<?php echo $is_active ? esc_attr__( 'Deactivate', 'wp-affiliatemanager' ) : esc_attr__( 'Activate', 'wp-affiliatemanager' ); ?>"
					><?php echo $is_active ? '⏸️' : '▶️'; ?></a>
					<a
						href="<?php echo esc_url( $delete_url ); ?>"
						class="wpam-action-btn wpam-action-btn--delete"
						title="<?php esc_attr_e( 'Delete', 'wp-affiliatemanager' ); ?>"
						data-confirm="<?php esc_attr_e( 'Delete this affiliate permanently?', 'wp-affiliatemanager' ); ?>"
					>🗑️</a>
				</div>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	// -------------------------------------------------------------------------
	// Render: fila de edición inline
	// -------------------------------------------------------------------------

	/**
	 * Renderiza una fila inline de edición/creación.
	 *
	 * @since  0.0.6
	 * @param  array|null $affiliate Datos del afiliado (null = fila nueva vacía).
	 * @return void
	 */
	public function render_edit_row( ?array $affiliate ): void {
		$id          = $affiliate ? (int) $affiliate['id'] : 0;
		$title       = $affiliate ? esc_attr( $affiliate['title'] )       : '';
		$slug        = $affiliate ? esc_attr( $affiliate['slug'] )        : '';
		$param       = $affiliate ? esc_attr( $affiliate['param'] )       : '';
		$value       = $affiliate ? esc_attr( $affiliate['value'] )       : '';
		$logo_url    = $affiliate ? esc_attr( $affiliate['logo_url'] )    : '';
		$brand_color = $affiliate ? esc_attr( $affiliate['brand_color'] ) : '#6c47ff';
		$active      = $affiliate ? (bool) $affiliate['active']   : true;
		$visible     = $affiliate ? (bool) $affiliate['visible']  : true;
		$domains     = $affiliate ? esc_textarea( $affiliate['domains'] ) : '';
		$is_new      = ( 0 === $id );
		$row_id      = $is_new ? 'wpam-new-row' : 'wpam-row-' . $id;
		?>
		<tr
			class="wpam-edit-row"
			data-id="<?php echo esc_attr( (string) $id ); ?>"
			id="<?php echo esc_attr( $row_id ); ?>"
		>
			<td colspan="8" class="wpam-edit-cell">
				<div class="wpam-edit-form">

					<div class="wpam-edit-form-header">
						<strong><?php echo $is_new ? esc_html__( 'New Affiliate', 'wp-affiliatemanager' ) : esc_html__( 'Edit Affiliate', 'wp-affiliatemanager' ); ?></strong>
					</div>

					<div class="wpam-edit-grid">

						<!-- Nombre -->
						<div class="wpam-edit-field wpam-edit-field--name">
							<label><?php esc_html_e( 'Name *', 'wp-affiliatemanager' ); ?></label>
							<input type="text" class="wpam-input wpam-ef-title" value="<?php echo $title; ?>" placeholder="Amazon, Booking..." required />
						</div>

						<!-- Slug -->
						<div class="wpam-edit-field wpam-edit-field--slug">
							<label><?php esc_html_e( 'Slug', 'wp-affiliatemanager' ); ?></label>
							<input type="text" class="wpam-input wpam-ef-slug" value="<?php echo $slug; ?>" placeholder="amazon" />
						</div>

						<!-- Logo — Media Library picker (v0.0.6) -->
						<div class="wpam-edit-field wpam-edit-field--logo">
							<label><?php esc_html_e( 'Logo', 'wp-affiliatemanager' ); ?></label>
							<div class="wpam-logo-picker" data-has-logo="<?php echo $logo_url ? '1' : '0'; ?>">
								<!-- Input hidden: siempre presente, guarda la URL -->
								<input
									type="hidden"
									class="wpam-ef-logo"
									value="<?php echo $logo_url; ?>"
								/>

								<?php if ( $logo_url ) : ?>
									<!-- Preview con overlay hover -->
									<div class="wpam-logo-picker-preview">
										<img src="<?php echo esc_url( $affiliate['logo_url'] ); ?>" alt="" />
										<div class="wpam-logo-picker-overlay">
											<span><?php esc_html_e( 'Edit logo', 'wp-affiliatemanager' ); ?></span>
										</div>
									</div>
									<button type="button" class="wpam-logo-picker-remove"><?php esc_html_e( 'Remove', 'wp-affiliatemanager' ); ?></button>
								<?php else : ?>
									<!-- Estado vacío: solo botón -->
									<button type="button" class="wpam-logo-picker-btn">
										<span class="wpam-logo-picker-icon">🖼️</span>
										<?php esc_html_e( 'Select logo', 'wp-affiliatemanager' ); ?>
									</button>
								<?php endif; ?>
							</div>
						</div>

						<!-- Param -->
						<div class="wpam-edit-field wpam-edit-field--param">
							<label><?php esc_html_e( 'Param', 'wp-affiliatemanager' ); ?></label>
							<input type="text" class="wpam-input wpam-ef-param" value="<?php echo $param; ?>" placeholder="tag" />
						</div>

						<!-- Value -->
						<div class="wpam-edit-field wpam-edit-field--value">
							<label><?php esc_html_e( 'Value', 'wp-affiliatemanager' ); ?></label>
							<input type="text" class="wpam-input wpam-ef-value" value="<?php echo $value; ?>" placeholder="bunny-20" />
						</div>

						<!-- Brand color -->
						<div class="wpam-edit-field wpam-edit-field--color">
							<label><?php esc_html_e( 'Color', 'wp-affiliatemanager' ); ?></label>
							<input type="color" class="wpam-color-input wpam-ef-color" value="<?php echo $brand_color; ?>" />
						</div>

						<!-- Domains -->
						<div class="wpam-edit-field wpam-edit-field--domains">
							<label><?php esc_html_e( 'Domains', 'wp-affiliatemanager' ); ?></label>
							<input type="text" class="wpam-input wpam-ef-domains" value="<?php echo $domains; ?>" placeholder="amazon.com, amzn.to" />
						</div>

						<!-- Checkboxes -->
						<div class="wpam-edit-field wpam-edit-field--checks">
							<label class="wpam-inline-check">
								<input type="checkbox" class="wpam-ef-active" <?php checked( $active ); ?> />
								<?php esc_html_e( 'Active', 'wp-affiliatemanager' ); ?>
							</label>
							<label class="wpam-inline-check">
								<input type="checkbox" class="wpam-ef-visible" <?php checked( $visible ); ?> />
								<?php esc_html_e( 'Visible', 'wp-affiliatemanager' ); ?>
							</label>
						</div>

					</div><!-- .wpam-edit-grid -->

					<div class="wpam-edit-actions">
						<button
							type="button"
							class="button button-primary wpam-btn-primary wpam-save-inline-btn"
							data-id="<?php echo esc_attr( (string) $id ); ?>"
						>
							<?php esc_html_e( 'Save', 'wp-affiliatemanager' ); ?>
						</button>
						<button type="button" class="button wpam-cancel-inline-btn" data-id="<?php echo esc_attr( (string) $id ); ?>">
							<?php esc_html_e( 'Cancel', 'wp-affiliatemanager' ); ?>
						</button>
						<span class="wpam-saving-indicator" style="display:none;">
							<?php esc_html_e( 'Saving...', 'wp-affiliatemanager' ); ?>
						</span>
					</div>

				</div><!-- .wpam-edit-form -->
			</td>
		</tr>
		<?php
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

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
