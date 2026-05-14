<?php
/**
 * Registro del Custom Post Type: wpam_affiliate.
 *
 * Responsable ÚNICAMENTE de registrar el CPT y sus taxonomías.
 * Sin lógica de negocio ni UI aquí.
 *
 * @package WP_AffiliateManager\Affiliates
 * @since   2.0.0
 */

namespace WP_AffiliateManager\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CPT
 *
 * Registra el Custom Post Type `wpam_affiliate` en WordPress.
 *
 * @since 2.0.0
 */
class CPT {

	/**
	 * Nombre del Custom Post Type.
	 *
	 * @since 2.0.0
	 * @var   string
	 */
	const POST_TYPE = 'wpam_affiliate';

	/**
	 * Registra el CPT en WordPress.
	 * Se ejecuta en el hook 'init'.
	 *
	 * @since  2.0.0
	 * @return void
	 */
	public function register(): void {
		$labels = array(
			'name'               => __( 'Affiliates', 'wp-affiliatemanager' ),
			'singular_name'      => __( 'Affiliate', 'wp-affiliatemanager' ),
			'add_new'            => __( 'Add New', 'wp-affiliatemanager' ),
			'add_new_item'       => __( 'Add New Affiliate', 'wp-affiliatemanager' ),
			'edit_item'          => __( 'Edit Affiliate', 'wp-affiliatemanager' ),
			'new_item'           => __( 'New Affiliate', 'wp-affiliatemanager' ),
			'view_item'          => __( 'View Affiliate', 'wp-affiliatemanager' ),
			'search_items'       => __( 'Search Affiliates', 'wp-affiliatemanager' ),
			'not_found'          => __( 'No affiliates found.', 'wp-affiliatemanager' ),
			'not_found_in_trash' => __( 'No affiliates found in Trash.', 'wp-affiliatemanager' ),
			'menu_name'          => __( 'Affiliates', 'wp-affiliatemanager' ),
		);

		$args = array(
			'labels'              => $labels,
			'description'         => __( 'Affiliate programs managed by Bunny Affiliate Manager.', 'wp-affiliatemanager' ),
			'public'              => false,   // No accesible vía URL pública.
			'publicly_queryable'  => false,   // Sin frontend.
			'show_ui'             => true,    // Visible en wp-admin.
			'show_in_menu'        => false,   // Lo gestionamos manualmente en nuestro menú.
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => false,
			'show_in_rest'        => false,   // Sin Gutenberg por ahora.
			'query_var'           => false,
			'rewrite'             => false,
			'capability_type'     => 'post',
			'capabilities'        => array(
				'edit_post'          => 'manage_options',
				'read_post'          => 'manage_options',
				'delete_post'        => 'manage_options',
				'edit_posts'         => 'manage_options',
				'edit_others_posts'  => 'manage_options',
				'publish_posts'      => 'manage_options',
				'read_private_posts' => 'manage_options',
				'delete_posts'       => 'manage_options',
			),
			'has_archive'         => false,
			'hierarchical'        => false,
			'menu_position'       => null,
			'supports'            => array( 'title' ),  // Solo el título; metadata via meta boxes.
			'taxonomies'          => array(),
			'can_export'          => true,
			'delete_with_user'    => false,
		);

		register_post_type( self::POST_TYPE, $args );
	}
}
