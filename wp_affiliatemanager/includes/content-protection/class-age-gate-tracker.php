<?php
/**
 * Age Gate Tracker — registro atómico de eventos del Age Gate (shown, confirmed, rejected).
 *
 * Responsabilidades:
 *  - Normalizar el parámetro utm_source asegurando longitud acotada y fallback 'direct'.
 *  - Proteger la integridad del origen UTM en el flujo entre presentación y confirmación/rechazo.
 *  - Ejecutar el upsert atómico diario en la tabla {prefix}wpam_age_gate_events.
 *
 * @package WP_AffiliateManager\Content_Protection
 * @since   2.2.0
 */

namespace WP_AffiliateManager\Content_Protection;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Age_Gate_Tracker
 *
 * @since 2.2.0
 */
class Age_Gate_Tracker {

	/**
	 * Longitud máxima permitida para utm_source normalizado.
	 * Coincide con la longitud de columna en la tabla SQL.
	 */
	const UTM_SOURCE_MAX_LENGTH = 50;

	/**
	 * Valor estándar documentado para visitas o envíos sin utm_source.
	 */
	const UTM_SOURCE_DIRECT = 'direct';

	/**
	 * Lista de eventos permitidos para registro en la tabla.
	 */
	const ALLOWED_EVENTS = array( 'shown', 'confirmed', 'rejected' );

	/**
	 * Normaliza un valor de utm_source: sanitizado, minúsculas, sin espacios, longitud acotada.
	 *
	 * @since  2.2.0
	 * @param  string $raw Valor crudo de utm_source (GET o POST).
	 * @return string      Valor normalizado, o 'direct' si está vacío tras sanitizar.
	 */
	public static function normalize_utm_source( string $raw ): string {
		$source = sanitize_text_field( $raw );
		$source = function_exists( 'mb_strtolower' ) ? mb_strtolower( $source ) : strtolower( $source );
		$source = trim( $source );

		if ( '' === $source ) {
			return self::UTM_SOURCE_DIRECT;
		}

		if ( function_exists( 'mb_substr' ) ) {
			$source = mb_substr( $source, 0, self::UTM_SOURCE_MAX_LENGTH );
		} else {
			$source = substr( $source, 0, self::UTM_SOURCE_MAX_LENGTH );
		}

		return '' !== $source ? $source : self::UTM_SOURCE_DIRECT;
	}

	/**
	 * Genera un HMAC de autenticación para preservar de forma íntegra el utm_source
	 * entre la petición GET (presentación del formulario) y la petición POST (confirmación/rechazo).
	 *
	 * @since  2.2.0
	 * @param  string $utm_source Valor normalizado de utm_source.
	 * @param  int    $post_id    ID de la publicación protegida.
	 * @return string             Firma HMAC en formato hexadecimal.
	 */
	public static function hash_utm_source( string $utm_source, int $post_id ): string {
		$payload = $utm_source . '|' . $post_id;
		$salt    = wp_salt( 'auth' );
		return hash_hmac( 'sha256', $payload, $salt );
	}

	/**
	 * Valida de forma segura contra ataques de temporización si el hash recibido
	 * coincide con el origen UTM y la publicación esperada.
	 *
	 * @since  2.2.0
	 * @param  string $utm_source Valor de utm_source recibido por POST.
	 * @param  int    $post_id    ID de la publicación protegida.
	 * @param  string $hash       Firma recibida en el formulario.
	 * @return bool               True si es válido, false en caso contrario.
	 */
	public static function verify_utm_source( string $utm_source, int $post_id, string $hash ): bool {
		$expected = self::hash_utm_source( $utm_source, $post_id );
		return hash_equals( $expected, $hash );
	}

	/**
	 * Registra o incrementa atómicamente un evento del Age Gate para el día actual.
	 *
	 * Usa INSERT ... ON DUPLICATE KEY UPDATE sobre la UNIQUE KEY (event_type, period, post_id, utm_source)
	 * garantizando concurrencia segura, cero lecturas previas y agregación diaria atómica.
	 *
	 * @since  2.2.0
	 * @param  string $event_type 'shown' | 'confirmed' | 'rejected'.
	 * @param  int    $post_id    ID de la publicación protegida.
	 * @param  string $utm_source Origen UTM (opcional, por defecto 'direct').
	 * @return bool               True si la consulta se ejecutó sin error.
	 */
	public static function record_event( string $event_type, int $post_id = 0, string $utm_source = self::UTM_SOURCE_DIRECT ): bool {
		if ( ! in_array( $event_type, self::ALLOWED_EVENTS, true ) ) {
			return false;
		}

		global $wpdb;

		$post_id    = max( 0, $post_id );
		$utm_source = self::normalize_utm_source( $utm_source );
		$period     = wp_date( 'Ymd' );
		$table      = Age_Gate_Events_Table::table_name();

		try {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared
			$result = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO %i (event_type, period, post_id, utm_source, count)
					 VALUES (%s, %s, %d, %s, 1)
					 ON DUPLICATE KEY UPDATE count = count + 1",
					$table,
					$event_type,
					$period,
					$post_id,
					$utm_source
				)
			);
			// phpcs:enable

			if ( false !== $result ) {
				return true;
			}

			return false;
		} catch ( \Throwable $e ) {
			return false;
		}
	}
}
