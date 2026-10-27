<?php
/**
 * Age Gate orchestrator for NSFW content protection.
 *
 * Handles detection, cookie signature & verification, form submissions,
 * SFW fallback, feeds, REST API, oEmbed, and caching controls.
 *
 * @package WP_AffiliateManager\Content_Protection
 * @since   2.1.0
 */

namespace WP_AffiliateManager\Content_Protection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Age_Gate
 */
class Age_Gate {

	/**
	 * Cookie version identifier for signature payloads.
	 *
	 * @var string
	 */
	const COOKIE_VERSION = 'v1';

	/**
	 * Centralized check to determine if a specific post requires age protection.
	 *
	 * Evaluates post_tag and category against configured IDs.
	 * Uses WordPress native term cache (has_term) for zero redundant queries.
	 *
	 * @param int $post_id Post ID.
	 * @return bool True if post is protected by active criteria, false otherwise.
	 */
	public function is_protected_post( int $post_id ): bool {
		if ( $post_id <= 0 ) {
			return false;
		}

		$options = get_option( WPAM_OPTION_KEY, array() );
		$cp      = $options['content_protection'] ?? array();

		if ( empty( $cp['enabled'] ) ) {
			return false;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'post' !== $post->post_type ) {
			return false;
		}

		// Check tag criteria.
		if ( ! empty( $cp['tags_enabled'] ) && ! empty( $cp['tag_ids'] ) && is_array( $cp['tag_ids'] ) ) {
			$tag_ids = array_values( array_filter( array_map( 'absint', $cp['tag_ids'] ) ) );
			if ( ! empty( $tag_ids ) && has_term( $tag_ids, 'post_tag', $post_id ) ) {
				return true;
			}
		}

		// Check category criteria.
		if ( ! empty( $cp['categories_enabled'] ) && ! empty( $cp['category_ids'] ) && is_array( $cp['category_ids'] ) ) {
			$cat_ids = array_values( array_filter( array_map( 'absint', $cp['category_ids'] ) ) );
			if ( ! empty( $cat_ids ) && has_term( $cat_ids, 'category', $post_id ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Returns the configured cookie name, sanitized.
	 *
	 * @return string
	 */
	public function get_cookie_name(): string {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$name    = sanitize_key( $options['content_protection']['cookie_name'] ?? 'wpam_age_gate' );
		return ! empty( $name ) ? $name : 'wpam_age_gate';
	}

	/**
	 * Returns the configured cookie duration in seconds.
	 *
	 * @return int
	 */
	public function get_cookie_duration(): int {
		$options  = get_option( WPAM_OPTION_KEY, array() );
		$duration = (int) ( $options['content_protection']['cookie_duration'] ?? ( DAY_IN_SECONDS * 30 ) );
		return $duration >= 300 ? $duration : ( DAY_IN_SECONDS * 30 );
	}

	/**
	 * Validates the HMAC signature and expiration of the age gate cookie.
	 *
	 * Format: {version}|{expiration_timestamp}|{hmac}
	 *
	 * @return bool True if valid and not expired, false otherwise.
	 */
	public function has_valid_cookie(): bool {
		$cookie_name = $this->get_cookie_name();

		if ( empty( $_COOKIE[ $cookie_name ] ) || ! is_string( $_COOKIE[ $cookie_name ] ) ) {
			return false;
		}

		$raw_value = wp_unslash( $_COOKIE[ $cookie_name ] );
		$parts     = explode( '|', $raw_value );

		if ( count( $parts ) !== 3 ) {
			return false;
		}

		list( $version, $expires, $hmac ) = $parts;

		if ( self::COOKIE_VERSION !== $version ) {
			return false;
		}

		$expires_timestamp = (int) $expires;
		if ( $expires_timestamp < time() ) {
			return false;
		}

		$payload  = $version . '|' . $expires_timestamp;
		$salt     = wp_salt( 'auth' );
		$expected = hash_hmac( 'sha256', $payload, $salt );

		return hash_equals( $expected, $hmac );
	}

	/**
	 * Sets a signed age gate cookie on the visitor's browser.
	 *
	 * @return void
	 */
	public function set_signed_cookie(): void {
		$version   = self::COOKIE_VERSION;
		$duration  = $this->get_cookie_duration();
		$expires   = time() + $duration;
		$payload   = $version . '|' . $expires;
		$salt      = wp_salt( 'auth' );
		$hmac      = hash_hmac( 'sha256', $payload, $salt );
		$value     = $payload . '|' . $hmac;
		$name      = $this->get_cookie_name();
		$path      = defined( 'COOKIEPATH' ) ? COOKIEPATH : '/';
		$domain    = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
		$secure    = is_ssl();
		$httponly  = true;

		if ( ! headers_sent() ) {
			if ( PHP_VERSION_ID >= 70300 ) {
				setcookie(
					$name,
					$value,
					array(
						'expires'  => $expires,
						'path'     => $path,
						'domain'   => $domain,
						'secure'   => $secure,
						'httponly' => $httponly,
						'samesite' => 'Lax',
					)
				);
			} else {
				setcookie( $name, $value, $expires, $path, $domain, $secure, $httponly );
			}
		}

		$_COOKIE[ $name ] = $value;
	}

	/**
	 * Determines if the current visitor or user can access the post.
	 *
	 * Allows access if:
	 * 1. Post is not protected.
	 * 2. Visitor has a valid signed cookie.
	 * 3. Authenticated user has edit capability on the post (author/editor preview).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_access_post( int $post_id ): bool {
		if ( ! $this->is_protected_post( $post_id ) ) {
			return true;
		}

		if ( $this->has_valid_cookie() ) {
			return true;
		}

		if ( is_user_logged_in() && current_user_can( 'edit_post', $post_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Intercepts singular post requests via template_redirect (priority 1).
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( is_admin() ) {
			return;
		}

		// Never interfere with BAM affiliate redirect flows (/go, /goa, /goext).
		if ( function_exists( '\WP_AffiliateManager\Redirect\is_wpam_redirect' ) && \WP_AffiliateManager\Redirect\is_wpam_redirect() ) {
			return;
		}

		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post_id = (int) get_queried_object_id();
		if ( $post_id <= 0 ) {
			return;
		}

		if ( ! $this->is_protected_post( $post_id ) ) {
			return;
		}

		// Authorized editor preview bypass.
		if ( is_preview() && current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Handle oEmbed iframe requests for protected posts.
		if ( is_embed() ) {
			if ( ! $this->can_access_post( $post_id ) ) {
				nocache_headers();
				if ( ! headers_sent() ) {
					header( 'X-Robots-Tag: noindex, nofollow', true );
				}
				$this->signal_litespeed_nocache( 'WPAM Age Gate Embed' );
				status_header( 403 );
				wp_die(
					esc_html__( 'Contenido protegido por edad.', 'wp-affiliatemanager' ),
					esc_html__( 'Acceso restringido', 'wp-affiliatemanager' ),
					array( 'response' => 403 )
				);
			}
			return;
		}

		// User already verified with valid signed cookie.
		if ( $this->has_valid_cookie() ) {
			// Prevent shared caches (CDN, proxies) from caching protected HTML for unverified visitors.
			if ( ! headers_sent() ) {
				header( 'Cache-Control: private, must-revalidate', false );
			}
			$this->signal_litespeed_nocache( 'WPAM Age Gate Authorized' );
			return;
		}

		// Handle POST confirmation or rejection.
		if ( 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['wpam_age_gate_action'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['wpam_age_gate_nonce'] ?? '' ) );
			if ( ! wp_verify_nonce( $nonce, 'wpam_age_gate_' . $post_id ) ) {
				wp_safe_redirect( get_permalink( $post_id ), 303 );
				exit;
			}

			$action = sanitize_text_field( wp_unslash( $_POST['wpam_age_gate_action'] ) );

			if ( 'confirm' === $action ) {
				$this->set_signed_cookie();
				wp_safe_redirect( get_permalink( $post_id ), 303 );
				exit;
			}

			if ( 'reject' === $action ) {
				$fallback_url = $this->get_sfw_fallback_url( $post_id );
				wp_safe_redirect( $fallback_url, 303 );
				exit;
			}
		}

		// Unverified GET request: render the Age Gate confirmation screen.
		$this->send_nocache_headers();
		$this->signal_litespeed_nocache( 'WPAM Age Gate Screen' );

		$options = get_option( WPAM_OPTION_KEY, array() );
		$cp      = $options['content_protection'] ?? array();

		$renderer = new Age_Gate_Renderer();
		$renderer->render(
			array(
				'action_url'   => get_permalink( $post_id ),
				'nonce'        => wp_create_nonce( 'wpam_age_gate_' . $post_id ),
				'title'        => $cp['gate_title'] ?? __( 'Contenido para mayores de 18 años', 'wp-affiliatemanager' ),
				'message'      => $cp['gate_message'] ?? __( 'Esta publicación contiene material clasificado para adultos. Debes ser mayor de edad para acceder.', 'wp-affiliatemanager' ),
				'confirm_text' => $cp['confirm_button_text'] ?? __( 'Tengo 18 años o más — Entrar', 'wp-affiliatemanager' ),
				'reject_text'  => $cp['reject_button_text'] ?? __( 'Soy menor de 18 años — Salir a contenido seguro', 'wp-affiliatemanager' ),
			)
		);
		exit;
	}

	/**
	 * Finds a verified SFW post or falls back to home_url('/').
	 *
	 * Uses NOT IN tax_query matching active criteria to exclude protected posts.
	 *
	 * @param int $current_post_id Currently requested protected post ID to exclude.
	 * @return string Safe URL to redirect to.
	 */
	public function get_sfw_fallback_url( int $current_post_id ): string {
		$options = get_option( WPAM_OPTION_KEY, array() );
		$cp      = $options['content_protection'] ?? array();

		$query_args = array(
			'post_type'              => 'post',
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'post__not_in'           => array( $current_post_id ),
			'orderby'                => 'rand',
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		$tax_query = array( 'relation' => 'AND' );

		if ( ! empty( $cp['tags_enabled'] ) && ! empty( $cp['tag_ids'] ) && is_array( $cp['tag_ids'] ) ) {
			$tag_ids = array_values( array_filter( array_map( 'absint', $cp['tag_ids'] ) ) );
			if ( ! empty( $tag_ids ) ) {
				$tax_query[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $tag_ids,
					'operator' => 'NOT IN',
				);
			}
		}

		if ( ! empty( $cp['categories_enabled'] ) && ! empty( $cp['category_ids'] ) && is_array( $cp['category_ids'] ) ) {
			$cat_ids = array_values( array_filter( array_map( 'absint', $cp['category_ids'] ) ) );
			if ( ! empty( $cat_ids ) ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $cat_ids,
					'operator' => 'NOT IN',
				);
			}
		}

		if ( count( $tax_query ) > 1 ) {
			$query_args['tax_query'] = $tax_query;
		}

		$query = new \WP_Query( $query_args );

		if ( ! empty( $query->posts ) ) {
			$sfw_id = (int) $query->posts[0];
			// Double check against centralized logic to guarantee consistency.
			if ( ! $this->is_protected_post( $sfw_id ) ) {
				$permalink = get_permalink( $sfw_id );
				if ( ! empty( $permalink ) ) {
					return $permalink;
				}
			}
		}

		return home_url( '/' );
	}

	/**
	 * Excludes protected posts from public feeds (RSS, Atom, RDF).
	 *
	 * Hooked to 'pre_get_posts'.
	 *
	 * @param \WP_Query $query Main query.
	 * @return void
	 */
	public function filter_feed_queries( \WP_Query $query ): void {
		if ( is_admin() || ! $query->is_feed() ) {
			return;
		}

		$options = get_option( WPAM_OPTION_KEY, array() );
		$cp      = $options['content_protection'] ?? array();

		if ( empty( $cp['enabled'] ) ) {
			return;
		}

		$tax_query = $query->get( 'tax_query' );
		if ( ! is_array( $tax_query ) ) {
			$tax_query = array();
		}

		$tax_exclusions = array( 'relation' => 'AND' );

		if ( ! empty( $cp['tags_enabled'] ) && ! empty( $cp['tag_ids'] ) && is_array( $cp['tag_ids'] ) ) {
			$tag_ids = array_values( array_filter( array_map( 'absint', $cp['tag_ids'] ) ) );
			if ( ! empty( $tag_ids ) ) {
				$tax_exclusions[] = array(
					'taxonomy' => 'post_tag',
					'field'    => 'term_id',
					'terms'    => $tag_ids,
					'operator' => 'NOT IN',
				);
			}
		}

		if ( ! empty( $cp['categories_enabled'] ) && ! empty( $cp['category_ids'] ) && is_array( $cp['category_ids'] ) ) {
			$cat_ids = array_values( array_filter( array_map( 'absint', $cp['category_ids'] ) ) );
			if ( ! empty( $cat_ids ) ) {
				$tax_exclusions[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $cat_ids,
					'operator' => 'NOT IN',
				);
			}
		}

		if ( count( $tax_exclusions ) > 1 ) {
			$tax_query[] = $tax_exclusions;
			$query->set( 'tax_query', $tax_query );
		}
	}

	/**
	 * Replaces feed content for protected posts as defense-in-depth.
	 *
	 * Hooked to 'the_content_feed' and 'the_excerpt_rss'.
	 *
	 * @param string $content Post feed content or excerpt.
	 * @return string Filtered content.
	 */
	public function filter_feed_content( string $content ): string {
		$post_id = (int) get_the_ID();
		if ( $post_id > 0 && $this->is_protected_post( $post_id ) ) {
			return esc_html__( '[Contenido protegido para mayores de edad — Visite el sitio para verificar su edad]', 'wp-affiliatemanager' );
		}
		return $content;
	}

	/**
	 * Protects REST API post endpoints from unauthorized disclosure.
	 *
	 * - Single post request (/wp/v2/posts/<id>): Returns 403 WP_Error.
	 * - Collection request (/wp/v2/posts): Redacts content, excerpt, and media.
	 *
	 * Hooked to 'rest_prepare_post'.
	 *
	 * @param \WP_REST_Response|\WP_Error $response Response object.
	 * @param \WP_Post                     $post     Post object.
	 * @param \WP_REST_Request            $request  REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function filter_rest_post( $response, $post, $request ) {
		if ( is_wp_error( $response ) || ! ( $response instanceof \WP_REST_Response ) || ! ( $post instanceof \WP_Post ) ) {
			return $response;
		}

		if ( ! $this->is_protected_post( $post->ID ) ) {
			return $response;
		}

		// Check authorized access (valid cookie or authorized editor).
		if ( $this->can_access_post( $post->ID ) ) {
			return $response;
		}

		// Unauthorized: if single post endpoint, respond with HTTP 403 Forbidden.
		$route = is_object( $request ) && method_exists( $request, 'get_route' ) ? $request->get_route() : '';
		if ( $route === '/wp/v2/posts/' . $post->ID ) {
			return new \WP_Error(
				'wpam_rest_age_restricted',
				__( 'Contenido protegido para mayores de edad. Se requiere verificación previa en el sitio.', 'wp-affiliatemanager' ),
				array( 'status' => 403 )
			);
		}

		// Collection endpoint: redact sensitive fields.
		$data = $response->get_data();
		$data['content'] = array(
			'rendered'  => '<p>' . esc_html__( '[Contenido restringido por edad]', 'wp-affiliatemanager' ) . '</p>',
			'protected' => true,
		);
		$data['excerpt'] = array(
			'rendered'  => '<p>' . esc_html__( '[Extracto restringido por edad]', 'wp-affiliatemanager' ) . '</p>',
			'protected' => true,
		);
		$data['featured_media']      = 0;
		$data['wpam_age_restricted'] = true;

		$response->set_data( $data );
		return $response;
	}

	/**
	 * Blocks oEmbed response data for protected posts if visitor is unauthorized.
	 *
	 * Hooked to 'oembed_response_data'.
	 *
	 * @param array|false $data Embed response data.
	 * @param \WP_Post    $post Post object.
	 * @return array|false False to deny oEmbed, or original data array.
	 */
	public function filter_oembed_data( $data, $post ) {
		if ( ! ( $post instanceof \WP_Post ) ) {
			return $data;
		}

		if ( ! $this->is_protected_post( $post->ID ) ) {
			return $data;
		}

		if ( $this->can_access_post( $post->ID ) ) {
			return $data;
		}

		// Return false so WordPress returns 404 for oEmbed consumers.
		return false;
	}

	/**
	 * Emits strict no-cache HTTP headers.
	 *
	 * @return void
	 */
	private function send_nocache_headers(): void {
		nocache_headers();
		if ( ! headers_sent() ) {
			header( 'Cache-Control: private, no-store, no-cache, must-revalidate, max-age=0', true );
			header( 'Pragma: no-cache', true );
			header( 'X-Robots-Tag: noindex, nofollow, noarchive', true );
		}
	}

	/**
	 * Signals LiteSpeed Cache to not cache the current response.
	 *
	 * Safe to call even if LiteSpeed is not installed.
	 *
	 * @param string $reason Reason string.
	 * @return void
	 */
	private function signal_litespeed_nocache( string $reason = '' ): void {
		do_action( 'litespeed_control_set_nocache', $reason );
	}
}
