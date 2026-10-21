<?php
/**
 * Dynamic Default URL template — sanitize, resolve and meta-key lookup.
 *
 * A template is a small array stored in the affiliate's post meta
 * (`Meta::KEY_DEFAULT_URL_TEMPLATE`) next to the static Default URL:
 *
 *   array(
 *     'base_url' => 'https://example.com/',
 *     'params'   => array(
 *       array( 'name' => 's',    'source' => 'meta', 'value' => 'figure_series', 'required' => true ),
 *       array( 'name' => 'type', 'source' => 'text', 'value' => 'figure',        'required' => false ),
 *     ),
 *   )
 *
 * It is resolved per post, at runtime, from `Post_Links::merge_default_links()`.
 * Nothing resolved is ever stored.
 *
 * @package WP_AffiliateManager\Affiliates
 * @since   1.8.14
 */

namespace WP_AffiliateManager\Affiliates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class URL_Template
 *
 * Static helpers only: no state, no hooks, no instances.
 *
 * @since 1.8.14
 */
class URL_Template {

	/** Maximum number of parameters per template. */
	const MAX_PARAMS = 20;

	/** Maximum length of a meta key (matches the postmeta.meta_key column). */
	const MAX_KEY_LENGTH = 255;

	/**
	 * Prefixes / exact keys of WordPress-internal post meta, hidden from the
	 * meta-key picker. `_wpam_` is the plugin's own internal meta.
	 *
	 * @var string[]
	 */
	const INTERNAL_KEY_PREFIXES = array( '_wp_', '_edit_', '_oembed_', '_menu_item_', '_wpam_' );

	/** @var string[] */
	const INTERNAL_KEYS = array( '_thumbnail_id', '_pingme', '_encloseme' );

	// -------------------------------------------------------------------------
	// Sanitization (save time)
	// -------------------------------------------------------------------------

	/**
	 * Validates and normalizes a template coming from the admin form.
	 *
	 * @since  1.8.14
	 * @param  mixed $raw JSON string (as posted by the modal) or an already-decoded array.
	 * @return array|null Normalized template; empty array when the template is
	 *                    cleared/not configured; null when the input is invalid
	 *                    (callers must keep whatever is stored, same as an invalid
	 *                    static Default URL).
	 */
	public static function sanitize( mixed $raw ): ?array {
		if ( is_string( $raw ) ) {
			$raw = trim( $raw );
			if ( '' === $raw ) {
				return array();
			}
			$raw = json_decode( $raw, true );
		}

		if ( ! is_array( $raw ) ) {
			return null;
		}

		$base = self::scalar_string( $raw['base_url'] ?? '' );
		$rows = $raw['params'] ?? array();

		if ( '' === $base && empty( $rows ) ) {
			return array();
		}

		$base = self::sanitize_base_url( $base );
		if ( '' === $base || ! is_array( $rows ) || count( $rows ) > self::MAX_PARAMS ) {
			return null;
		}

		$params = array();
		$seen   = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				return null;
			}

			$name   = self::scalar_string( $row['name'] ?? '' );
			$source = self::scalar_string( $row['source'] ?? 'text' );
			$value  = self::scalar_string( $row['value'] ?? '' );

			// Fully empty rows are dropped silently.
			if ( '' === $name && '' === $value ) {
				continue;
			}

			// Names: letters, digits, "_" and "-" only. Dots/brackets/spaces are
			// rejected because PHP's parse_str() (used later by add_query_arg())
			// would rewrite them.
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $name ) || isset( $seen[ $name ] ) ) {
				return null;
			}
			$seen[ $name ] = true;

			if ( 'meta' === $source ) {
				$value = substr( sanitize_text_field( $value ), 0, self::MAX_KEY_LENGTH );
				if ( '' === $value ) {
					return null;
				}
				$required = filter_var( $row['required'] ?? false, FILTER_VALIDATE_BOOLEAN );
			} elseif ( 'text' === $source ) {
				$value = sanitize_text_field( $value );
				if ( '' === $value ) {
					return null;
				}
				$required = false; // Not applicable to fixed text.
			} else {
				return null;
			}

			$params[] = array(
				'name'     => $name,
				'source'   => $source,
				'value'    => $value,
				'required' => $required,
			);
		}

		return array(
			'base_url' => $base,
			'params'   => $params,
		);
	}

	/**
	 * Validates the base URL: absolute http(s) URL with a host.
	 *
	 * @param  string $url Raw base URL.
	 * @return string Clean URL or '' when invalid.
	 */
	private static function sanitize_base_url( string $url ): string {
		if ( '' === $url || false === filter_var( $url, FILTER_VALIDATE_URL ) ) {
			return '';
		}

		$clean = esc_url_raw( $url, array( 'http', 'https' ) );
		if ( '' === $clean || '' === (string) wp_parse_url( $clean, PHP_URL_HOST ) ) {
			return '';
		}

		return $clean;
	}

	/**
	 * Trimmed string for scalar input, '' for anything else (arrays, objects, null).
	 *
	 * @param  mixed $value Raw value.
	 * @return string
	 */
	private static function scalar_string( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	// -------------------------------------------------------------------------
	// Resolution (runtime)
	// -------------------------------------------------------------------------

	/**
	 * Builds the final URL of a template for one post.
	 *
	 * Meta values come from get_post_meta(), which loads (and caches) all of the
	 * post's meta in a single query — and that cache is already warm because
	 * Post_Links::get_links() read `_wpam_links` from the same post. So resolving
	 * a template adds no query per parameter or per meta key.
	 *
	 * Rules:
	 *  - Only string / int / float meta values are usable; arrays, objects and
	 *    booleans count as "empty". Values are trimmed; "" is empty ("0" is not).
	 *  - Required meta empty → the whole template is unavailable ('').
	 *  - Optional meta empty → that parameter is omitted.
	 *  - Names and values are RFC 3986 percent-encoded (space → %20).
	 *
	 * @since  1.8.14
	 * @param  array $template Stored template (see class docblock).
	 * @param  int   $post_id  Post the link is being resolved for.
	 * @return string URL, or '' when the template is unavailable for this post.
	 */
	public static function resolve( array $template, int $post_id ): string {
		$base   = self::scalar_string( $template['base_url'] ?? '' );
		$params = $template['params'] ?? array();

		if ( '' === $base || $post_id <= 0 || ! is_array( $params ) ) {
			return '';
		}

		$pairs = array();

		foreach ( $params as $param ) {
			if ( ! is_array( $param ) ) {
				continue;
			}

			$name = self::scalar_string( $param['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}

			if ( 'meta' === ( $param['source'] ?? '' ) ) {
				$value = self::get_meta_value( $post_id, self::scalar_string( $param['value'] ?? '' ) );

				if ( '' === $value ) {
					if ( ! empty( $param['required'] ) ) {
						return '';
					}
					continue;
				}
			} else {
				$value = self::scalar_string( $param['value'] ?? '' );
				if ( '' === $value ) {
					continue;
				}
			}

			$pairs[] = rawurlencode( $name ) . '=' . rawurlencode( $value );
		}

		if ( empty( $pairs ) ) {
			return $base;
		}

		// Keep a fragment (#...) at the very end and respect an existing query.
		$fragment = '';
		$hash_pos = strpos( $base, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $base, $hash_pos );
			$base     = substr( $base, 0, $hash_pos );
		}

		if ( false === strpos( $base, '?' ) ) {
			$separator = '?';
		} elseif ( in_array( substr( $base, -1 ), array( '?', '&' ), true ) ) {
			$separator = '';
		} else {
			$separator = '&';
		}

		return $base . $separator . implode( '&', $pairs ) . $fragment;
	}

	/**
	 * Scalar meta value of a post as a trimmed string ('' when missing/empty/non-scalar).
	 *
	 * @param  int    $post_id Post ID.
	 * @param  string $key     Meta key.
	 * @return string
	 */
	private static function get_meta_value( int $post_id, string $key ): string {
		if ( '' === $key ) {
			return '';
		}

		$raw = get_post_meta( $post_id, $key, true );

		if ( is_int( $raw ) || is_float( $raw ) ) {
			return (string) $raw;
		}

		return is_string( $raw ) ? trim( $raw ) : '';
	}

	// -------------------------------------------------------------------------
	// Meta-key lookup (admin picker only)
	// -------------------------------------------------------------------------

	/**
	 * Distinct meta keys used by posts of the supported post types, filtered by
	 * name. Keys only — never values. One aggregate query, admin-only, on demand.
	 *
	 * @since  1.8.14
	 * @param  string $search Case-insensitive substring of the key ('' = no filter).
	 * @param  int    $limit  Max results.
	 * @return string[] Sorted meta keys.
	 */
	public static function find_meta_keys( string $search, int $limit = 20 ): array {
		global $wpdb;

		$post_types = array_values( array_filter( array_map(
			'sanitize_key',
			(array) apply_filters( 'wpam_post_links_post_types', array( 'post' ) )
		) ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		$args = $post_types;
		$sql  = "SELECT DISTINCT pm.meta_key
			FROM {$wpdb->postmeta} pm
			INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			WHERE p.post_type IN (" . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';

		if ( '' !== $search ) {
			$sql   .= ' AND pm.meta_key LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		foreach ( self::INTERNAL_KEY_PREFIXES as $prefix ) {
			$sql   .= ' AND pm.meta_key NOT LIKE %s';
			$args[] = $wpdb->esc_like( $prefix ) . '%';
		}

		$sql .= ' AND pm.meta_key NOT IN (' . implode( ',', array_fill( 0, count( self::INTERNAL_KEYS ), '%s' ) ) . ')';
		$args = array_merge( $args, self::INTERNAL_KEYS );

		$sql   .= ' ORDER BY pm.meta_key ASC LIMIT %d';
		$args[] = max( 1, $limit );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery
		$keys = $wpdb->get_col( $wpdb->prepare( $sql, $args ) );

		return is_array( $keys ) ? array_map( 'strval', $keys ) : array();
	}
}
