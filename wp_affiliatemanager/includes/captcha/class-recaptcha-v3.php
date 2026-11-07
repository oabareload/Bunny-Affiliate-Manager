<?php
/**
 * Centralized Google reCAPTCHA v3 Service.
 *
 * Provides server-side verification, response validation, threshold enforcement,
 * and degraded-mode handling for Age Gate, views, and affiliate redirects.
 *
 * @package WP_AffiliateManager\Captcha
 * @since   2.3.0
 */

namespace WP_AffiliateManager\Captcha;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Recaptcha_V3
 */
class Recaptcha_V3 {

	/**
	 * Default minimum score threshold.
	 */
	const DEFAULT_THRESHOLD = 0.10;

	/**
	 * Google reCAPTCHA siteverify endpoint.
	 */
	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * HTTP timeout in seconds for verification requests.
	 */
	const TIMEOUT = 10;

	/**
	 * Allowed actions across the plugin.
	 */
	const ACTION_AGE_GATE_CONFIRM = 'age_gate_confirm';
	const ACTION_VIEW             = 'view';
	const ACTION_AFFILIATE_GO     = 'affiliate_redirect';

	/**
	 * Retrieves the recaptcha configuration sub-array from wpam_settings.
	 *
	 * @return array
	 */
	public static function get_options(): array {
		$options = get_option( WPAM_OPTION_KEY, array() );
		return is_array( $options['recaptcha'] ?? null ) ? $options['recaptcha'] : array();
	}

	/**
	 * Checks whether reCAPTCHA v3 protection is toggled on in settings.
	 *
	 * @return bool
	 */
	public static function is_enabled(): bool {
		$options = self::get_options();
		return ! empty( $options['enabled'] );
	}

	/**
	 * Checks whether both public site key and private secret key are configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::get_site_key() && '' !== self::get_secret_key();
	}

	/**
	 * Checks whether reCAPTCHA v3 is both enabled and fully configured with valid keys.
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return self::is_enabled() && self::is_configured();
	}

	/**
	 * Returns the detailed configuration status of reCAPTCHA v3.
	 *
	 * @return string 'disabled' | 'active' | 'missing_keys' | 'missing_site_key' | 'missing_secret_key'
	 */
	public static function get_status(): string {
		if ( ! self::is_enabled() ) {
			return 'disabled';
		}
		$has_site   = '' !== self::get_site_key();
		$has_secret = '' !== self::get_secret_key();

		if ( $has_site && $has_secret ) {
			return 'active';
		}
		if ( ! $has_site && ! $has_secret ) {
			return 'missing_keys';
		}
		if ( ! $has_site ) {
			return 'missing_site_key';
		}
		return 'missing_secret_key';
	}

	/**
	 * Returns the configured v3 public site key.
	 *
	 * @return string
	 */
	public static function get_site_key(): string {
		$options = self::get_options();
		return trim( (string) ( $options['site_key_v3'] ?? '' ) );
	}

	/**
	 * Returns the configured v3 secret key.
	 *
	 * @return string
	 */
	public static function get_secret_key(): string {
		$options = self::get_options();
		return trim( (string) ( $options['secret_key_v3'] ?? '' ) );
	}

	/**
	 * Returns the minimum score threshold (between 0.00 and 1.00).
	 *
	 * @return float
	 */
	public static function get_threshold(): float {
		$options = self::get_options();
		$val     = isset( $options['threshold'] ) ? (float) $options['threshold'] : self::DEFAULT_THRESHOLD;
		if ( $val < 0.0 ) {
			return 0.0;
		}
		if ( $val > 1.0 ) {
			return 1.0;
		}
		return round( $val, 2 );
	}

	/**
	 * Checks whether score threshold enforcement is enabled.
	 *
	 * When true, requests with scores below threshold are blocked.
	 * When false, the token is still verified on the server (blocking invalid tokens
	 * or verification failures), but the score itself is not used to block access.
	 *
	 * @return bool
	 */
	public static function is_threshold_enforced(): bool {
		$options = self::get_options();
		if ( isset( $options['enforce_threshold'] ) ) {
			return ! empty( $options['enforce_threshold'] );
		}
		// Fallback compatibility with previous block_on_threshold key.
		if ( isset( $options['block_on_threshold'] ) ) {
			return ! empty( $options['block_on_threshold'] );
		}
		return true; // Threshold application is enabled by default.
	}

	/**
	 * Logs a reCAPTCHA configuration error without revealing any keys, tokens, or sensitive data.
	 *
	 * @param string $status Configuration status slug.
	 * @return void
	 */
	public static function log_configuration_error( string $status = '' ): void {
		if ( '' === $status ) {
			$status = self::get_status();
		}
		$allowed_statuses = array( 'missing_keys', 'missing_site_key', 'missing_secret_key', 'invalid_keys' );
		$safe_status      = in_array( $status, $allowed_statuses, true ) ? $status : 'invalid_configuration';

		error_log( sprintf(
			'[Bunny Affiliate Manager] reCAPTCHA v3 is enabled but not properly configured (%s). Security verification is blocked.',
			$safe_status
		) );
	}

	/**
	 * Determines whether a real float score satisfies the configured minimum threshold.
	 *
	 * @param float      $score     Evaluation score [0.0, 1.0].
	 * @param float|null $threshold Minimum required score. Defaults to configured threshold.
	 * @return bool True if score >= threshold, false otherwise.
	 */
	public static function meets_threshold( float $score, ?float $threshold = null ): bool {
		if ( null === $threshold ) {
			$threshold = self::get_threshold();
		}
		return round( (float) $score, 4 ) >= round( (float) $threshold, 4 );
	}

	/**
	 * Verifies a reCAPTCHA v3 response token against Google's siteverify endpoint.
	 *
	 * Normalizes output, verifies action name, validates score range, and enforces
	 * threshold when enabled.
	 *
	 * @param string $token           Token produced by grecaptcha.execute().
	 * @param string $expected_action Expected action name to validate.
	 * @return array{
	 *     success: bool,
	 *     score: float|null,
	 *     action: string,
	 *     error_code: string,
	 *     error_message: string,
	 *     meets_threshold: bool,
	 *     blocked: bool
	 * }
	 */
	public static function verify( string $token, string $expected_action = '' ): array {
		if ( ! self::is_enabled() ) {
			return array(
				'success'         => true,
				'score'           => null,
				'action'          => $expected_action,
				'error_code'      => 'disabled',
				'error_message'   => __( 'reCAPTCHA v3 está desactivado.', 'wp-affiliatemanager' ),
				'meets_threshold' => true,
				'blocked'         => false,
			);
		}

		if ( ! self::is_configured() ) {
			$status = self::get_status();
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => $expected_action,
				'error_code'      => $status,
				'error_message'   => sprintf( __( 'reCAPTCHA v3 no está configurado correctamente (%s).', 'wp-affiliatemanager' ), $status ),
				'meets_threshold' => false,
				'blocked'         => false,
			);
		}

		$token = trim( $token );
		if ( '' === $token ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => '',
				'error_code'      => 'token_missing',
				'error_message'   => __( 'Token de reCAPTCHA ausente.', 'wp-affiliatemanager' ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$secret = self::get_secret_key();

		$body = array(
			'secret'   => $secret,
			'response' => $token,
		);

		$remote_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		if ( '' !== $remote_ip ) {
			$body['remoteip'] = $remote_ip;
		}

		$response = wp_remote_post(
			self::VERIFY_URL,
			array(
				'timeout' => self::TIMEOUT,
				'body'    => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => '',
				'error_code'      => 'connection_error',
				'error_message'   => $response->get_error_message(),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => '',
				'error_code'      => 'http_error',
				'error_message'   => sprintf( __( 'Respuesta HTTP %d desde la API de verificación de Google.', 'wp-affiliatemanager' ), $code ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$raw_body = wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );
		if ( ! is_array( $data ) ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => '',
				'error_code'      => 'malformed_response',
				'error_message'   => __( 'Respuesta JSON malformada desde la API de Google.', 'wp-affiliatemanager' ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		if ( empty( $data['success'] ) ) {
			$codes = isset( $data['error-codes'] ) && is_array( $data['error-codes'] )
				? implode( ', ', array_map( 'sanitize_text_field', $data['error-codes'] ) )
				: 'verification_failed';
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => (string) ( $data['action'] ?? '' ),
				'error_code'      => 'rejected_by_google',
				'error_message'   => $codes,
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$returned_action = (string) ( $data['action'] ?? '' );
		if ( '' !== $expected_action && $returned_action !== $expected_action ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => $returned_action,
				'error_code'      => 'unexpected_action',
				'error_message'   => sprintf( __( 'Acción esperada "%1$s", recibida "%2$s".', 'wp-affiliatemanager' ), $expected_action, $returned_action ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		if ( ! isset( $data['score'] ) || ! is_numeric( $data['score'] ) ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => $returned_action,
				'error_code'      => 'invalid_score',
				'error_message'   => __( 'Puntuación de evaluación no disponible o inválida.', 'wp-affiliatemanager' ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$score = (float) $data['score'];
		if ( $score < 0.0 || $score > 1.0 ) {
			return array(
				'success'         => false,
				'score'           => null,
				'action'          => $returned_action,
				'error_code'      => 'score_out_of_range',
				'error_message'   => __( 'Puntuación fuera de rango [0.0, 1.0].', 'wp-affiliatemanager' ),
				'meets_threshold' => false,
				'blocked'         => true,
			);
		}

		$meets_threshold    = self::meets_threshold( $score );
		$threshold_enforced = self::is_threshold_enforced();
		$blocked            = $threshold_enforced && ! $meets_threshold;

		return array(
			'success'         => true,
			'score'           => $score,
			'action'          => $returned_action,
			'error_code'      => '',
			'error_message'   => '',
			'meets_threshold' => $meets_threshold,
			'blocked'         => $blocked,
		);
	}
}
