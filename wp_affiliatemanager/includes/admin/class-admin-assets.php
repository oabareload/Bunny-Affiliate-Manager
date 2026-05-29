<?php
/**
 * Módulo de assets de administración.
 *
 * @package WP_AffiliateManager\Admin
 * @since   1.0.0
 * @version 0.1.4
 */

namespace WP_AffiliateManager\Admin;

use WP_AffiliateManager\Affiliates\CPT;
use WP_AffiliateManager\Affiliates\Repository;
use WP_AffiliateManager\Posts\Post_Links;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin_Assets {

	private string $version;

	private array $plugin_screens = array(
		'toplevel_page_wpam-dashboard',
		'bunny-affiliates_page_wpam-affiliates',
		'bunny-affiliates_page_wpam-post-affiliates',
		'bunny-affiliates_page_wpam-settings',
	);

	public function __construct( string $version ) {
		$this->version = $version;
	}

	// -------------------------------------------------------------------------
	// Enqueue styles
	// -------------------------------------------------------------------------

	public function enqueue_styles( string $hook_suffix ): void {
		if ( $this->is_plugin_screen( $hook_suffix ) ) {
			wp_enqueue_style(
				'bunny-admin',
				WPAM_PLUGIN_URL . 'assets/css/bunny-admin.css',
				array(),
				$this->version
			);

			wp_enqueue_style(
				'wpam-admin-styles',
				WPAM_PLUGIN_URL . 'assets/css/admin.css',
				array( 'bunny-admin' ),
				$this->version
			);
		}

		if ( 'bunny-affiliates_page_wpam-settings' === $hook_suffix ) {
			wp_enqueue_style(
				'wpam-settings-styles',
				WPAM_PLUGIN_URL . 'assets/css/settings.css',
				array( 'wpam-admin-styles' ),
				$this->version
			);
		}

		if ( $this->is_supported_post_screen( $hook_suffix ) ) {
			wp_enqueue_style(
				'wpam-post-links-styles',
				WPAM_PLUGIN_URL . 'assets/css/post-links.css',
				array(),
				$this->version
			);
		}

		// Post Affiliates screen (v0.1.0).
		if ( 'bunny-affiliates_page_wpam-post-affiliates' === $hook_suffix ) {
			wp_enqueue_style(
				'wpam-post-affiliates-styles',
				WPAM_PLUGIN_URL . 'assets/css/post-affiliates.css',
				array( 'wpam-admin-styles' ),
				$this->version
			);
		}
	}

	// -------------------------------------------------------------------------
	// Enqueue scripts
	// -------------------------------------------------------------------------

	public function enqueue_scripts( string $hook_suffix ): void {
		if ( $this->is_plugin_screen( $hook_suffix ) ) {
			wp_enqueue_script(
				'wpam-admin-scripts',
				WPAM_PLUGIN_URL . 'assets/js/admin.js',
				array( 'jquery' ),
				$this->version,
				true
			);

			wp_localize_script(
				'wpam-admin-scripts',
				'wpamAdminData',
				array(
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'nonce'     => wp_create_nonce( 'wpam_admin_nonce' ),
					'crudNonce' => wp_create_nonce( 'wpam_inline_crud' ),
					'pluginUrl' => WPAM_PLUGIN_URL,
					'version'   => $this->version,
					'i18n'      => array(
						'confirm_delete' => __( 'Delete this affiliate permanently?', 'wp-affiliatemanager' ),
						'error_generic'  => __( 'An error occurred. Please try again.', 'wp-affiliatemanager' ),
						'saving'         => __( 'Saving...', 'wp-affiliatemanager' ),
						'saved'          => __( 'Saved!', 'wp-affiliatemanager' ),
						'active'         => __( 'Active', 'wp-affiliatemanager' ),
						'inactive'       => __( 'Inactive', 'wp-affiliatemanager' ),
						'cancel'         => __( 'Cancel', 'wp-affiliatemanager' ),
					),
				)
			);

			// Media Library: CPT edit screen + pantalla inline de affiliates (v0.0.6).
			if ( $this->is_cpt_edit_screen( $hook_suffix ) || 'bunny-affiliates_page_wpam-affiliates' === $hook_suffix ) {
				wp_enqueue_media();
			}

			// Post Affiliates screen (v0.1.0).
			// v0.1.4: domain-detector.js como dependencia compartida con post-links.js.
			if ( 'bunny-affiliates_page_wpam-post-affiliates' === $hook_suffix ) {
				wp_enqueue_script(
					'wpam-domain-detector',
					WPAM_PLUGIN_URL . 'assets/js/domain-detector.js',
					array(),
					$this->version,
					true
				);

				wp_enqueue_script(
					'wpam-post-affiliates-scripts',
					WPAM_PLUGIN_URL . 'assets/js/post-affiliates.js',
					array( 'jquery', 'wpam-domain-detector' ),
					$this->version,
					true
				);

				wp_localize_script(
					'wpam-post-affiliates-scripts',
					'wpamPAData',
					array(
						'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
						'moreLimit' => 10,
						'i18n'      => array(
							'saving'             => __( 'Saving...', 'wp-affiliatemanager' ),
							'saved'              => __( 'Saved!', 'wp-affiliatemanager' ),
							'error'              => __( 'Error. Please try again.', 'wp-affiliatemanager' ),
							'loading'            => __( 'Loading...', 'wp-affiliatemanager' ),
							'no_more'            => __( 'No more posts.', 'wp-affiliatemanager' ),
							'confirm_del'        => __( 'Remove this link?', 'wp-affiliatemanager' ),
							'remove_link'        => __( 'Remove this link', 'wp-affiliatemanager' ),
							'label_url'          => __( 'URL', 'wp-affiliatemanager' ),
							'label_label'        => __( 'Label', 'wp-affiliatemanager' ),
							'label_optional'     => __( 'opt.', 'wp-affiliatemanager' ),
							'label_placeholder'  => __( 'e.g. Buy on Amazon', 'wp-affiliatemanager' ),
							'url_invalid'        => __( 'Enter a valid URL (https://...).', 'wp-affiliatemanager' ),
							'no_affiliate_found' => __( 'No active affiliate found for this URL.', 'wp-affiliatemanager' ),
							'save_blocked'       => __( 'Fix errors before saving.', 'wp-affiliatemanager' ),
							'duplicate_url'      => __( 'Duplicate URL detected. Remove the duplicate before saving.', 'wp-affiliatemanager' ),
						),
					)
				);
			}
		}

		// Post editor (post.php / post-new.php).
		// v0.1.4: domain-detector.js como dependencia compartida.
		if ( $this->is_supported_post_screen( $hook_suffix ) ) {
			wp_enqueue_script(
				'wpam-domain-detector',
				WPAM_PLUGIN_URL . 'assets/js/domain-detector.js',
				array(),
				$this->version,
				true
			);

			wp_enqueue_script(
				'wpam-post-links-scripts',
				WPAM_PLUGIN_URL . 'assets/js/post-links.js',
				array( 'jquery', 'wpam-domain-detector' ),
				$this->version,
				true
			);

			$post_id    = $this->get_current_post_id();
			$next_index = $this->get_next_row_index( $post_id );

			wp_localize_script(
				'wpam-post-links-scripts',
				'wpamPostLinksData',
				array(
					'nextIndex'  => $next_index,
					'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
					'nonce'      => wp_create_nonce( 'wpam_post_links_nonce' ),
					// v0.1.4: afiliados activos con dominios pre-normalizados para DomainDetector.
					'affiliates' => $this->get_affiliates_for_js(),
					'i18n'       => array(
						'no_links'           => __( 'No affiliate links added yet. Click "Add Link" to start.', 'wp-affiliatemanager' ),
						'no_links_count'     => __( '0 links', 'wp-affiliatemanager' ),
						'one_link'           => __( '1 link', 'wp-affiliatemanager' ),
						/* translators: %d: número de links */
						'n_links'            => __( '%d links', 'wp-affiliatemanager' ),
						'preview_placeholder'=> __( 'Paste an affiliate URL to detect the affiliate automatically.', 'wp-affiliatemanager' ),
						'url_invalid'        => __( 'Enter a valid URL (https://...).', 'wp-affiliatemanager' ),
						'no_affiliate_found' => __( 'No active affiliate found for this URL.', 'wp-affiliatemanager' ),
						'final_url'          => __( 'Final URL:', 'wp-affiliatemanager' ),
						'open_tab'           => __( 'Open in new tab', 'wp-affiliatemanager' ),
					),
				)
			);
		}
	}

	// -------------------------------------------------------------------------
	// Detección de pantallas
	// -------------------------------------------------------------------------

	private function is_plugin_screen( string $hook_suffix ): bool {
		if ( in_array( $hook_suffix, $this->plugin_screens, true ) ) {
			return true;
		}
		return $this->is_cpt_edit_screen( $hook_suffix );
	}

	private function is_cpt_edit_screen( string $hook_suffix ): bool {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}
		return CPT::POST_TYPE === $this->get_current_post_type( $hook_suffix );
	}

	private function is_supported_post_screen( string $hook_suffix ): bool {
		if ( ! in_array( $hook_suffix, array( 'post.php', 'post-new.php' ), true ) ) {
			return false;
		}

		$current_type = $this->get_current_post_type( $hook_suffix );
		$supported    = (array) apply_filters( 'wpam_post_links_post_types', array( 'post' ) );

		return in_array( $current_type, $supported, true );
	}

	private function get_current_post_type( string $hook_suffix ): string {
		$post_type = get_post_type();

		if ( ! $post_type && 'post-new.php' === $hook_suffix && isset( $_GET['post_type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$post_type = sanitize_key( $_GET['post_type'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}

		return $post_type ?: 'post';
	}

	private function get_current_post_id(): int {
		if ( isset( $_GET['post'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return absint( $_GET['post'] ); // phpcs:ignore WordPress.Security.NonceVerification
		}
		return 0;
	}

	private function get_next_row_index( int $post_id ): int {
		if ( $post_id <= 0 ) {
			return 100;
		}

		$links = get_post_meta( $post_id, Post_Links::META_KEY, true );

		if ( ! is_array( $links ) || empty( $links ) ) {
			return 100;
		}

		return max( 100, count( $links ) + 50 );
	}

	/**
	 * Retorna los afiliados activos con dominios pre-normalizados para JS.
	 *
	 * Mismo formato que usa Post_Affiliates_Screen::render_post_row() en data-affiliates,
	 * más los campos param/value necesarios para generar la URL final client-side.
	 *
	 * @since  0.1.4
	 * @return array[]
	 */
	private function get_affiliates_for_js(): array {
		$repo   = new Repository();
		$result = $repo->find_all( array( 'active' => true, 'orderby' => 'title', 'order' => 'ASC' ) );

		return array_map( function( $a ) {
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
		}, $result['items'] );
	}
}
