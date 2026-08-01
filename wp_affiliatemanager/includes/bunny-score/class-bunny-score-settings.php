<?php
/**
 * Bunny Score settings storage.
 *
 * Owns the independent option used by Bunny Score. It deliberately does not
 * use the WordPress Settings API or WPAM_OPTION_KEY.
 *
 * @package WP_AffiliateManager\Bunny_Score
 * @since   1.7.6
 */

namespace WP_AffiliateManager\Bunny_Score;

use WP_AffiliateManager\Bunny_Score\Factor_Types\Factor_Type_Registry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Bunny_Score_Settings {

	public const OPTION_KEY = WPAM_BUNNY_SCORE_SETTINGS_KEY;
	public const SCHEMA_VERSION = 1;
	public const MIGRATION_VERSION = 1;

	public static function defaults(): array {
		return array(
			'schema_version'    => self::SCHEMA_VERSION,
			'migration_version' => self::MIGRATION_VERSION,
			'min_posts_per_tag' => 3,
			'factors'          => array(),
		);
	}

	public static function get(): array {
		self::maybe_migrate();

		$settings = get_option( self::OPTION_KEY, array() );
		$settings = is_array( $settings ) ? $settings : array();

		return array_merge( self::defaults(), $settings );
	}

	public static function update( array $settings ): bool {
		$settings = array_merge( self::defaults(), $settings );
		$settings['schema_version']    = self::SCHEMA_VERSION;
		$settings['migration_version'] = self::MIGRATION_VERSION;
		$settings['min_posts_per_tag'] = self::sanitize_min_posts( $settings['min_posts_per_tag'] ?? 3 );
		$settings['factors']           = self::sanitize_factors( is_array( $settings['factors'] ?? null ) ? $settings['factors'] : array() );

		return update_option( self::OPTION_KEY, $settings, false );
	}

	public static function maybe_migrate(): void {
		$current = get_option( self::OPTION_KEY, null );
		if ( null !== $current ) {
			return;
		}

		$settings = self::defaults();
		$wpam_settings = get_option( WPAM_OPTION_KEY, array() );

		if ( is_array( $wpam_settings ) && isset( $wpam_settings['bunny_score'] ) && is_array( $wpam_settings['bunny_score'] ) ) {
			$legacy = $wpam_settings['bunny_score'];
			$settings['min_posts_per_tag'] = self::sanitize_min_posts( $legacy['min_posts_per_tag'] ?? $settings['min_posts_per_tag'] );
			$settings['factors'] = self::sanitize_factors( is_array( $legacy['factors'] ?? null ) ? $legacy['factors'] : array() );
		}

		add_option( self::OPTION_KEY, $settings, '', false );

		if ( is_array( $wpam_settings ) && array_key_exists( 'bunny_score', $wpam_settings ) ) {
			unset( $wpam_settings['bunny_score'] );
			update_option( WPAM_OPTION_KEY, $wpam_settings );
		}
	}

	public static function sanitize_min_posts( mixed $raw ): int {
		return max( 1, absint( $raw ) );
	}

	public static function sanitize_factors( array $factors ): array {
		$sanitized = array();
		$used_ids = array();
		$valid_type_ids = Factor_Type_Registry::get_ids();

		foreach ( $factors as $factor ) {
			if ( ! is_array( $factor ) ) {
				continue;
			}

			$id = sanitize_key( $factor['id'] ?? '' );
			if ( '' === $id ) {
				$id = sanitize_key( sanitize_title( $factor['label'] ?? '' ) );
			}
			if ( '' === $id ) {
				continue;
			}

			$base_id = $id;
			$suffix = 2;
			while ( isset( $used_ids[ $id ] ) ) {
				$id = $base_id . '_' . $suffix;
				++$suffix;
			}
			$used_ids[ $id ] = true;

			$type = in_array( $factor['type'] ?? 'boolean', $valid_type_ids, true )
				? $factor['type']
				: 'boolean';

			$common = array(
				'id'                       => $id,
				'label'                    => sanitize_text_field( $factor['label'] ?? '' ),
				'type'                     => $type,
				'enabled'                  => ! empty( $factor['enabled'] ),
				'optional'                 => ! empty( $factor['optional'] ),
				'max_percent'              => max( 0.0, floatval( $factor['max_percent'] ?? 0 ) ),
				'max_percent_positive'     => max( 0.0, floatval( $factor['max_percent'] ?? 0 ) ),
				'max_percent_negative'     => max( 0.0, floatval( $factor['max_percent_negative'] ?? 0 ) ),
				'supports_not_applicable'  => ! empty( $factor['supports_not_applicable'] ),
				'no_data_penalty_ratio'    => max( 0.0, min( 100.0, floatval( $factor['no_data_penalty_ratio'] ?? 0 ) ) ),
				'source_label'             => sanitize_text_field( $factor['source_label'] ?? '' ),
				'precision'                => absint( $factor['precision'] ?? 2 ),
			);

			$type_specific = Factor_Type_Registry::get( $type )->sanitize_config( $factor );
			$sanitized[ $id ] = array_merge( $common, $type_specific );
		}

		return array_values( $sanitized );
	}
}
