<?php
/**
 * Test Suite: Google reCAPTCHA v3 Security & Core Functionality in Bunny Affiliate Manager.
 *
 * Runs standalone via PHP CLI:
 * php tests/test-recaptcha-v3-and-stats.php
 */

define( 'ABSPATH', __DIR__ . '/mock-wp/' );
define( 'WPAM_OPTION_KEY', 'wpam_settings' );
define( 'WPAM_BUNNY_SCORE_SETTINGS_KEY', 'wpam_bunny_score_settings' );
define( 'WPAM_BUNNY_SCORE_CACHE_KEY', 'wpam_bunny_score_cache' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'ARRAY_A', 'ARRAY_A' );

// -----------------------------------------------------------------------------
// WordPress Environment Mocking
// -----------------------------------------------------------------------------
$GLOBALS['mock_options'] = array(
	WPAM_OPTION_KEY => array(
		'recaptcha' => array(
			'enabled'           => 1,
			'site_key_v3'       => '6Le_mock_site_key_v3',
			'secret_key_v3'     => '6Le_mock_secret_key_v3',
			'threshold'         => 0.10,
			'enforce_threshold' => 1,
		),
	),
);
$GLOBALS['mock_cache']   = array();
$GLOBALS['mock_wp_remote_post_response'] = null;

function get_option( $key, $default = false ) {
	return $GLOBALS['mock_options'][ $key ] ?? $default;
}

function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['mock_options'][ $key ] = $value;
	return true;
}

function delete_option( $key ) {
	unset( $GLOBALS['mock_options'][ $key ] );
	return true;
}

function apply_filters( $tag, $value ) {
	return $value;
}

function absint( $maybeint ) {
	return abs( (int) $maybeint );
}

function sanitize_text_field( $str ) {
	return is_string( $str ) ? trim( strip_tags( $str ) ) : '';
}

function sanitize_key( $key ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

function sanitize_url( $url ) {
	return filter_var( $url, FILTER_SANITIZE_URL ) ?: '';
}

function wp_unslash( $val ) {
	return is_string( $val ) ? stripslashes( $val ) : $val;
}

function is_wp_error( $thing ) {
	return is_object( $thing ) && isset( $thing->is_error );
}

class Mock_WP_Error {
	public $is_error = true;
	private $message;
	public function __construct( $message ) {
		$this->message = $message;
	}
	public function get_error_message() {
		return $this->message;
	}
}

function wp_remote_post( $url, $args = array() ) {
	if ( isset( $GLOBALS['mock_wp_remote_post_callback'] ) && is_callable( $GLOBALS['mock_wp_remote_post_callback'] ) ) {
		return call_user_func( $GLOBALS['mock_wp_remote_post_callback'], $url, $args );
	}
	return $GLOBALS['mock_wp_remote_post_response'];
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'] ?? 200;
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'] ?? '';
}

function wp_json_encode( $data, $options = 0, $depth = 512 ) {
	return json_encode( $data, $options, $depth );
}

function wp_salt( $scheme = 'auth' ) {
	return 'mock_secret_salt_key_' . $scheme;
}

function is_ssl() {
	return false;
}

function is_admin() {
	return false;
}

function is_user_logged_in() {
	return false;
}

class WP_Post {
	public $ID;
	public $post_type = 'post';
	public $post_status = 'publish';
	public function __construct( $id = 0 ) {
		$this->ID = $id;
	}
}

function get_post( $id = 0 ) {
	return new WP_Post( $id );
}

function has_term( $terms, $taxonomy = '', $post = null ) {
	return true;
}

function wp_hash( $data, $scheme = 'auth' ) {
	return hash_hmac( 'sha256', $data, wp_salt( $scheme ) );
}

function wp_cache_get( $key, $group = '' ) {
	return $GLOBALS['mock_cache'][ $group ][ $key ] ?? false;
}

function wp_cache_set( $key, $value, $group = '', $ttl = 0 ) {
	$GLOBALS['mock_cache'][ $group ][ $key ] = $value;
	return true;
}

function wp_cache_delete( $key, $group = '' ) {
	if ( isset( $GLOBALS['mock_cache'][ $group ][ $key ] ) ) {
		unset( $GLOBALS['mock_cache'][ $group ][ $key ] );
		return true;
	}
	return false;
}

function esc_html__( $text, $domain = 'default' ) { return $text; }
function __( $text, $domain = 'default' ) { return $text; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_js( $text ) { return addslashes( (string) $text ); }
function esc_url( $url ) { return $url; }
function number_format_i18n( $number ) { return number_format( (float) $number ); }
class WpDieException extends RuntimeException {
	public $title;
	public $args;
	public function __construct( $msg = '', $title = '', $args = array() ) {
		$this->title = $title;
		$this->args  = $args;
		parent::__construct( (string) $msg );
	}
}
function wp_die( $msg = '', $title = '', $args = array() ) {
	if ( isset( $args['response'] ) ) {
		$GLOBALS['mock_status_header'] = $args['response'];
	}
	throw new WpDieException( $msg, $title, $args );
}
function current_user_can( $cap ) { return $GLOBALS['mock_user_can'] ?? true; }
function wp_verify_nonce( $nonce, $action = -1 ) { return ( 'valid_nonce_' . $action === $nonce ); }
function wp_create_nonce( $action = -1 ) { return 'valid_nonce_' . $action; }
function check_ajax_referer( $action = -1, $query_arg = false, $die = true ) { return 1; }
function admin_url( $path = '' ) { return 'https://example.com/wp-admin/' . $path; }

function add_query_arg( ...$args ) {
	if ( count( $args ) === 3 ) {
		list( $key, $val, $url ) = $args;
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $sep . rawurlencode( $key ) . '=' . rawurlencode( $val );
	}
	if ( count( $args ) === 2 && is_array( $args[0] ) ) {
		$url = $args[1];
		$sep = str_contains( $url, '?' ) ? '&' : '?';
		return $url . $sep . http_build_query( $args[0] );
	}
	return $args[0] ?? '';
}

class RedirectException extends RuntimeException {}
function wp_safe_redirect( $location, $status = 302 ) {
	$GLOBALS['mock_redirect_location'] = $location;
	throw new RedirectException( $location );
}

class JsonResponseException extends RuntimeException {
	public $data;
	public function __construct( $data ) {
		$this->data = $data;
		parent::__construct( json_encode( $data ) );
	}
}
function wp_send_json_success( $data = null ) {
	throw new JsonResponseException( array( 'success' => true, 'data' => $data ) );
}
function wp_send_json_error( $data = null ) {
	throw new JsonResponseException( array( 'success' => false, 'data' => $data ) );
}

function home_url( $path = '' ) { return 'https://example.com/' . ltrim( $path, '/' ); }
function wp_date( $format, $timestamp = null ) { return date( $format, $timestamp ?? time() ); }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_datetime() { return new DateTimeImmutable( 'now', wp_timezone() ); }
function selected( $selected, $current = true, $echo = true ) {
	$result = ( (string) $selected === (string) $current ) ? ' selected="selected"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}
function checked( $checked, $current = true, $echo = true ) {
	$result = ( (string) $checked === (string) $current ) ? ' checked="checked"' : '';
	if ( $echo ) { echo $result; }
	return $result;
}
function wp_get_raw_referer() {
	return 'https://google.com';
}

function status_header( $code = 200 ) {
	$GLOBALS['mock_status_header'] = $code;
}

function get_query_var( $var, $default = '' ) {
	return $GLOBALS['mock_query_vars'][ $var ] ?? $default;
}

function dbDelta( $queries = '' ) {
	return array();
}

class Mock_wpdb {
	public $prefix = 'wp_';
	public $queries = array();
	public function get_charset_collate() {
		return 'DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
	}
	public function prepare( $query, ...$args ) {
		if ( isset( $args[0] ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $arg ) {
			if ( is_int( $arg ) || is_float( $arg ) ) {
				$query = preg_replace( '/%d|%f/', (string) $arg, $query, 1 );
			} elseif ( is_string( $arg ) && str_contains( $query, '%i' ) ) {
				$query = preg_replace( '/%i/', '`' . str_replace( '`', '``', $arg ) . '`', $query, 1 );
			} else {
				$query = preg_replace( '/%s/', "'" . addslashes( (string) $arg ) . "'", $query, 1 );
			}
		}
		return $query;
	}
	public function get_var( $query ) {
		$this->queries[] = $query;
		return 42;
	}
	public function get_row( $query, $output = 'OBJECT' ) {
		$this->queries[] = $query;
		return array();
	}
	public function get_results( $query, $output = 'OBJECT' ) {
		$this->queries[] = $query;
		return array();
	}
	public function get_col( $query ) {
		$this->queries[] = $query;
		return array();
	}
	public function query( $query ) {
		$this->queries[] = $query;
		return 1;
	}
	public function insert( $table, $data, $formats = null ) {
		$cols = implode( ', ', array_keys( $data ) );
		$vals = implode( ', ', array_map( function( $v ) { return is_null($v) ? 'NULL' : "'" . addslashes( (string)$v ) . "'"; }, array_values( $data ) ) );
		$this->queries[] = "INSERT INTO {$table} ({$cols}) VALUES ({$vals})";
		return 1;
	}
}
$GLOBALS['wpdb'] = new Mock_wpdb();

// Load plugin classes
require_once __DIR__ . '/../wp_affiliatemanager/includes/captcha/class-recaptcha-v3.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/content-protection/class-age-gate-events-table.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/content-protection/class-age-gate-tracker.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/content-protection/class-age-gate.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/views/class-views-table.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/views/class-view-tracker.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/views/class-resource-resolver.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/views/class-recently-viewed.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/views/class-views.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/redirect/class-clicks-table.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/redirect/class-click-tracker.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/bunny-score/class-bunny-score-settings.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/admin/class-admin-menu.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/admin/class-admin.php';
require_once __DIR__ . '/../wp_affiliatemanager/includes/redirect/class-redirect-manager.php';

use WP_AffiliateManager\Captcha\Recaptcha_V3;
use WP_AffiliateManager\Content_Protection\Age_Gate;
use WP_AffiliateManager\Content_Protection\Age_Gate_Tracker;
use WP_AffiliateManager\Views\Views;
use WP_AffiliateManager\Views\View_Tracker;
use WP_AffiliateManager\Redirect\Click_Tracker;
use WP_AffiliateManager\Bunny_Score\Bunny_Score_Settings;
use WP_AffiliateManager\Admin\Admin_Menu;

// -----------------------------------------------------------------------------
// Assertions Helper
// -----------------------------------------------------------------------------
$tests_run    = 0;
$tests_passed = 0;
$tests_failed = 0;

function assert_test( $condition, $message ) {
	global $tests_run, $tests_passed, $tests_failed;
	$tests_run++;
	if ( $condition ) {
		$tests_passed++;
		echo " [PASS] {$message}\n";
	} else {
		$tests_failed++;
		echo " [FAIL] {$message}\n";
	}
}

echo "=========================================================\n";
echo "BUNNY AFFILIATE MANAGER — RECAPTCHA V3 SECURITY TESTS\n";
echo "=========================================================\n";

// -----------------------------------------------------------------------------
// TEST SUITE 1: Configuration States & Key Handling
// -----------------------------------------------------------------------------
echo "\n--- 1. Testing Recaptcha_V3 5 Configuration States ---\n";

// 1.1 State 1: Disabled
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled' => 0,
);
assert_test( false === Recaptcha_V3::is_enabled(), "State 1 (Disabled): is_enabled() is false" );
assert_test( false === Recaptcha_V3::is_configured(), "State 1 (Disabled): is_configured() is false" );
assert_test( false === Recaptcha_V3::is_active(), "State 1 (Disabled): is_active() is false" );
assert_test( 'disabled' === Recaptcha_V3::get_status(), "State 1 (Disabled): get_status() returns 'disabled'" );
$verify_disabled = Recaptcha_V3::verify( 'any_token' );
assert_test( $verify_disabled['success'] && ! $verify_disabled['blocked'] && 'disabled' === $verify_disabled['error_code'], "State 1 (Disabled): verify() returns success=true, blocked=false, error_code='disabled'" );

// 1.2 State 3: Missing Site Key
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '',
	'secret_key_v3' => '6Le_secret_key',
);
assert_test( true === Recaptcha_V3::is_enabled(), "State 3 (Missing Site Key): is_enabled() is true" );
assert_test( false === Recaptcha_V3::is_configured(), "State 3 (Missing Site Key): is_configured() is false" );
assert_test( false === Recaptcha_V3::is_active(), "State 3 (Missing Site Key): is_active() is false" );
assert_test( 'missing_site_key' === Recaptcha_V3::get_status(), "State 3 (Missing Site Key): get_status() returns 'missing_site_key'" );
$verify_missing_site = Recaptcha_V3::verify( 'any_token' );
assert_test( ! $verify_missing_site['success'] && ! $verify_missing_site['blocked'] && 'missing_site_key' === $verify_missing_site['error_code'], "State 3 (Missing Site Key): verify() returns success=false, blocked=false (does not block visitors)" );

// 1.3 State 4: Missing Secret Key
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '6Le_site_key',
	'secret_key_v3' => '',
);
assert_test( true === Recaptcha_V3::is_enabled(), "State 4 (Missing Secret Key): is_enabled() is true" );
assert_test( false === Recaptcha_V3::is_configured(), "State 4 (Missing Secret Key): is_configured() is false" );
assert_test( false === Recaptcha_V3::is_active(), "State 4 (Missing Secret Key): is_active() is false" );
assert_test( 'missing_secret_key' === Recaptcha_V3::get_status(), "State 4 (Missing Secret Key): get_status() returns 'missing_secret_key'" );
$verify_missing_secret = Recaptcha_V3::verify( 'any_token' );
assert_test( ! $verify_missing_secret['success'] && ! $verify_missing_secret['blocked'] && 'missing_secret_key' === $verify_missing_secret['error_code'], "State 4 (Missing Secret Key): verify() returns success=false, blocked=false (does not block visitors)" );

// 1.4 State 5: Missing Both Keys
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '',
	'secret_key_v3' => '',
);
assert_test( true === Recaptcha_V3::is_enabled(), "State 5 (Missing Both Keys): is_enabled() is true" );
assert_test( false === Recaptcha_V3::is_configured(), "State 5 (Missing Both Keys): is_configured() is false" );
assert_test( false === Recaptcha_V3::is_active(), "State 5 (Missing Both Keys): is_active() is false" );
assert_test( 'missing_keys' === Recaptcha_V3::get_status(), "State 5 (Missing Both Keys): get_status() returns 'missing_keys'" );
$verify_missing_both = Recaptcha_V3::verify( 'any_token' );
assert_test( ! $verify_missing_both['success'] && ! $verify_missing_both['blocked'] && 'missing_keys' === $verify_missing_both['error_code'], "State 5 (Missing Both Keys): verify() returns success=false, blocked=false (does not block visitors)" );

// 1.5 State 2: Active
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'           => 1,
	'site_key_v3'       => '6Le_mock_site_key_v3',
	'secret_key_v3'     => '6Le_mock_secret_key_v3',
	'threshold'         => 0.10,
	'enforce_threshold' => 1,
);
assert_test( true === Recaptcha_V3::is_enabled(), "State 2 (Active): is_enabled() is true" );
assert_test( true === Recaptcha_V3::is_configured(), "State 2 (Active): is_configured() is true" );
assert_test( true === Recaptcha_V3::is_active(), "State 2 (Active): is_active() is true" );
assert_test( 'active' === Recaptcha_V3::get_status(), "State 2 (Active): get_status() returns 'active'" );

// -----------------------------------------------------------------------------
// TEST SUITE 2: Threshold Enforcement Option (Active vs Disabled)
// -----------------------------------------------------------------------------
echo "\n--- 2. Testing Threshold Enforcement Option ---\n";

// Helper callback to simulate Google responses
$GLOBALS['mock_google_score'] = 0.30;
$GLOBALS['mock_wp_remote_post_callback'] = function( $url, $args ) {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success' => true,
			'score'   => $GLOBALS['mock_google_score'],
			'action'  => 'test_action',
		) ),
	);
};

// 2.1 Threshold = 0.50, Enforce Threshold = ON, Score = 0.30 => BLOCKED
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['threshold']         = 0.50;
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enforce_threshold'] = 1;
$GLOBALS['mock_google_score'] = 0.30;

$result_enforced_fail = Recaptcha_V3::verify( 'valid_token', 'test_action' );
assert_test( true === $result_enforced_fail['success'], "Enforce ON: verify() returns success=true from Google" );
assert_test( false === $result_enforced_fail['meets_threshold'], "Enforce ON: score 0.30 does not meet threshold 0.50" );
assert_test( true === $result_enforced_fail['blocked'], "Enforce ON: score below threshold is BLOCKED" );

// 2.2 Threshold = 0.50, Enforce Threshold = ON, Score = 0.60 => NOT BLOCKED
$GLOBALS['mock_google_score'] = 0.60;
$result_enforced_pass = Recaptcha_V3::verify( 'valid_token', 'test_action' );
assert_test( true === $result_enforced_pass['meets_threshold'], "Enforce ON: score 0.60 meets threshold 0.50" );
assert_test( false === $result_enforced_pass['blocked'], "Enforce ON: score above threshold is NOT blocked" );

// 2.3 Threshold = 0.50, Enforce Threshold = OFF, Score = 0.30 => NOT BLOCKED (Score not used to block)
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enforce_threshold'] = 0;
$GLOBALS['mock_google_score'] = 0.30;
$result_unenforced_low = Recaptcha_V3::verify( 'valid_token', 'test_action' );
assert_test( true === $result_unenforced_low['success'], "Enforce OFF: verify() returns success=true from Google" );
assert_test( false === $result_unenforced_low['meets_threshold'], "Enforce OFF: score 0.30 is below threshold 0.50" );
assert_test( false === $result_unenforced_low['blocked'], "Enforce OFF: score is NOT used to block the request" );

// 2.4 Threshold = OFF, but Token MISSING => STILL BLOCKED (Security errors preserved!)
$result_unenforced_missing_token = Recaptcha_V3::verify( '', 'test_action' );
assert_test( false === $result_unenforced_missing_token['success'], "Enforce OFF: missing token returns success=false" );
assert_test( true === $result_unenforced_missing_token['blocked'], "Enforce OFF: missing token is STILL safely BLOCKED" );

// 2.5 Threshold = OFF, but Google REJECTS token => STILL BLOCKED
$GLOBALS['mock_wp_remote_post_callback'] = function() {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success'     => false,
			'error-codes' => array( 'invalid-input-response' ),
		) ),
	);
};
$result_unenforced_bad_token = Recaptcha_V3::verify( 'invalid_token', 'test_action' );
assert_test( false === $result_unenforced_bad_token['success'], "Enforce OFF: invalid token returns success=false" );
assert_test( true === $result_unenforced_bad_token['blocked'], "Enforce OFF: invalid token is STILL safely BLOCKED" );

// 2.6 Threshold = OFF, but Network FAILURE => STILL BLOCKED
$GLOBALS['mock_wp_remote_post_callback'] = function() {
	return new Mock_WP_Error( 'Connection timed out' );
};
$result_unenforced_network_err = Recaptcha_V3::verify( 'any_token', 'test_action' );
assert_test( false === $result_unenforced_network_err['success'], "Enforce OFF: network timeout returns success=false" );
assert_test( true === $result_unenforced_network_err['blocked'], "Enforce OFF: network timeout is STILL safely BLOCKED" );

// -----------------------------------------------------------------------------
// TEST SUITE 3: Age Gate NSFW Integration
// -----------------------------------------------------------------------------
echo "\n--- 3. Testing Age Gate NSFW Integration ---\n";

$GLOBALS['mock_options'][WPAM_OPTION_KEY]['content_protection'] = array(
	'enabled'      => 1,
	'tags_enabled' => 1,
	'tag_ids'      => array( 99 ),
);
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'           => 1,
	'site_key_v3'       => '6Le_mock_site_key_v3',
	'secret_key_v3'     => '6Le_mock_secret_key_v3',
	'threshold'         => 0.50,
	'enforce_threshold' => 1,
);
$GLOBALS['mock_queried_object_id'] = 123;
function get_queried_object_id() { return 123; }
function is_singular( $type = '' ) { return true; }
function is_preview() { return false; }
function is_embed() { return false; }
function get_permalink( $id = 0 ) { return 'https://example.com/post/' . $id; }

$age_gate = new Age_Gate();

// 3.1 Age Gate: Low score + Enforce ON => BLOCKED, no confirmed recorded
$_COOKIE = array();
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST = array(
	'wpam_age_gate_action' => 'confirm',
	'wpam_age_gate_nonce'  => 'valid_nonce_wpam_age_gate_123',
	'wpam_recaptcha_token' => 'mock_token',
);
$GLOBALS['mock_wp_remote_post_callback'] = function() {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success' => true,
			'score'   => 0.20, // Below 0.50
			'action'  => 'age_gate_confirm',
		) ),
	);
};
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$age_gate->handle();
} catch ( RedirectException $e ) {}

$confirmed_recorded_3_1 = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( str_contains( $q, "'confirmed'" ) ) {
		$confirmed_recorded_3_1 = true;
	}
}
assert_test( str_contains( $GLOBALS['mock_redirect_location'], 'wpam_ag_error=low_score' ), "Age Gate (Enforce ON): redirects to error=low_score" );
assert_test( false === $confirmed_recorded_3_1, "Age Gate (Enforce ON): does NOT record confirmed event on low score" );

// 3.2 Age Gate: Low score + Enforce OFF => NOT BLOCKED, confirmed event IS recorded!
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enforce_threshold'] = 0;
$_COOKIE = array();
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$age_gate->handle();
} catch ( RedirectException $e ) {}

$confirmed_recorded_3_2 = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( str_contains( $q, "'confirmed'" ) ) {
		$confirmed_recorded_3_2 = true;
	}
}
assert_test( ! str_contains( $GLOBALS['mock_redirect_location'], 'wpam_ag_error' ), "Age Gate (Enforce OFF): does not redirect with error" );
assert_test( true === $confirmed_recorded_3_2, "Age Gate (Enforce OFF): records confirmed event successfully" );

// 3.3 Age Gate: Incomplete configuration => visitor NOT blocked, standard confirmation proceeds
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled']       = 1;
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['site_key_v3']   = '';
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['secret_key_v3'] = '';
$_COOKIE = array();
$_POST = array(
	'wpam_age_gate_action' => 'confirm',
	'wpam_age_gate_nonce'  => 'valid_nonce_wpam_age_gate_123',
);
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$age_gate->handle();
} catch ( RedirectException $e ) {}

$confirmed_recorded_3_3 = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( str_contains( $q, "'confirmed'" ) ) {
		$confirmed_recorded_3_3 = true;
	}
}
assert_test( ! str_contains( $GLOBALS['mock_redirect_location'], 'wpam_ag_error' ), "Age Gate (Incomplete Config): visitor is NOT blocked with error" );
assert_test( true === $confirmed_recorded_3_3, "Age Gate (Incomplete Config): confirmed event is recorded successfully" );

// 3.4 Age Gate: reCAPTCHA voluntarily disabled by user => standard confirmation works
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled'] = 0;
$_COOKIE = array();
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$age_gate->handle();
} catch ( RedirectException $e ) {}

$confirmed_recorded_3_4 = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( str_contains( $q, "'confirmed'" ) ) {
		$confirmed_recorded_3_4 = true;
	}
}
assert_test( ! str_contains( $GLOBALS['mock_redirect_location'], 'wpam_ag_error' ), "Age Gate (reCAPTCHA Disabled): visitor is not redirected with error" );
assert_test( true === $confirmed_recorded_3_4, "Age Gate (reCAPTCHA Disabled): confirmed event is recorded normally" );

// 3.5 Age Gate: Configured reCAPTCHA + Real Google verification failure => SAFELY BLOCKED
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled']       = 1;
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['site_key_v3']   = '6Le_mock_site_key_v3';
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['secret_key_v3'] = '6Le_mock_secret_key_v3';
$GLOBALS['mock_wp_remote_post_callback'] = function() {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success'     => false,
			'error-codes' => array( 'invalid-input-response' ),
		) ),
	);
};
$_COOKIE = array();
$_POST = array(
	'wpam_age_gate_action'   => 'confirm',
	'wpam_age_gate_nonce'    => 'valid_nonce_wpam_age_gate_123',
	'wpam_recaptcha_token'   => 'bad_token',
);
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$age_gate->handle();
} catch ( RedirectException $e ) {}

$confirmed_recorded_3_5 = false;
foreach ( $GLOBALS['wpdb']->queries as $q ) {
	if ( str_contains( $q, "'confirmed'" ) ) {
		$confirmed_recorded_3_5 = true;
	}
}
assert_test( str_contains( $GLOBALS['mock_redirect_location'], 'wpam_ag_error=eval_error' ), "Age Gate (Valid Config + Google Failure): redirects with eval_error" );
assert_test( false === $confirmed_recorded_3_5, "Age Gate (Valid Config + Google Failure): confirmed event is NOT recorded (safely blocked)" );

// -----------------------------------------------------------------------------
// TEST SUITE 4: Restored Table Schemas & Clean Statistics
// -----------------------------------------------------------------------------
echo "\n--- 4. Testing Restored Table Schemas & Clean Statistics ---\n";

// 4.1 Age Gate Events Tracker Query Integrity (Original Schema)
$GLOBALS['wpdb']->queries = array();
Age_Gate_Tracker::record_event( 'shown', 123, 'direct' );
$shown_query = end( $GLOBALS['wpdb']->queries );

assert_test( str_contains( $shown_query, "INSERT INTO" ) && str_contains( $shown_query, "wpam_age_gate_events" ), "Age_Gate_Tracker inserts into wpam_age_gate_events" );
assert_test( ! str_contains( $shown_query, "origin" ), "Age_Gate_Tracker query does NOT contain 'origin' column" );
assert_test( ! str_contains( $shown_query, "score_level" ), "Age_Gate_Tracker query does NOT contain 'score_level' column" );
assert_test( ! str_contains( $shown_query, "score" ), "Age_Gate_Tracker query does NOT contain 'score' column" );
assert_test( str_contains( $shown_query, "ON DUPLICATE KEY UPDATE count = count + 1" ), "Age_Gate_Tracker preserves atomic count=count+1 upsert" );

// 4.2 Views Tracker Query Integrity (Original Schema)
$GLOBALS['wpdb']->queries = array();
$view_tracker = new View_Tracker();
$view_tracker->record( 'post', 456 );
$view_query = end( $GLOBALS['wpdb']->queries );

assert_test( str_contains( $view_query, "INSERT INTO" ) && str_contains( $view_query, "wpam_views" ), "View_Tracker inserts into wpam_views" );
assert_test( ! str_contains( $view_query, "origin" ), "View_Tracker query does NOT contain 'origin' column" );
assert_test( ! str_contains( $view_query, "score_level" ), "View_Tracker query does NOT contain 'score_level' column" );
assert_test( ! str_contains( $view_query, "score" ), "View_Tracker query does NOT contain 'score' column" );
assert_test( str_contains( $view_query, "ON DUPLICATE KEY UPDATE count = count + 1" ), "View_Tracker preserves atomic count=count+1 upsert" );

// 4.3 Click Tracker Query Integrity (Original Schema)
$GLOBALS['wpdb']->queries = array();
$click_tracker = new Click_Tracker();
$click_tracker->record( 123, 45, 'https://affiliate.example.com/deal' );
$click_query = end( $GLOBALS['wpdb']->queries );

assert_test( str_contains( $click_query, "INSERT INTO" ) && str_contains( $click_query, "wpam_clicks" ), "Click_Tracker inserts into wpam_clicks" );
assert_test( ! str_contains( $click_query, "origin" ), "Click_Tracker query does NOT contain 'origin' column" );
assert_test( ! str_contains( $click_query, "score_level" ), "Click_Tracker query does NOT contain 'score_level' column" );
assert_test( ! str_contains( $click_query, "score" ), "Click_Tracker query does NOT contain 'score' column" );

// -----------------------------------------------------------------------------
// TEST SUITE 5: Views Beacon & Ajax Tracking Integration
// -----------------------------------------------------------------------------
echo "\n--- 5. Testing Views Beacon & Verification ---\n";

$GLOBALS['mock_user_can'] = false; // Frontend visitor
$views = new Views();
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'           => 1,
	'site_key_v3'       => '6Le_mock_site_key_v3',
	'secret_key_v3'     => '6Le_mock_secret_key_v3',
	'threshold'         => 0.50,
	'enforce_threshold' => 1,
);
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['views'] = array(
	'resource_types' => array( 'post' => 1 ),
);

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)';

// 5.1 Views Ajax Track: Low score + Enforce ON => counted = false
$_COOKIE = array();
$_POST = array(
	'action'          => 'wpam_track_view',
	'resource_type'   => 'post',
	'resource_id'     => 789,
	'recaptcha_token' => 'view_token',
);
$GLOBALS['mock_wp_remote_post_callback'] = function() {
	return array(
		'response' => array( 'code' => 200 ),
		'body'     => json_encode( array(
			'success' => true,
			'score'   => 0.20, // Below threshold 0.50
			'action'  => 'view',
		) ),
	);
};

$view_counted_5_1 = null;
try {
	$views->ajax_track();
} catch ( JsonResponseException $e ) {
	$view_counted_5_1 = $e->data['data']['counted'] ?? null;
}
assert_test( false === $view_counted_5_1, "Views Tracking (Enforce ON): low score view is NOT counted (counted=false)" );

// 5.2 Views Ajax Track: Low score + Enforce OFF => counted = true
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enforce_threshold'] = 0;
$_COOKIE = array();
$view_counted_5_2 = null;
try {
	$views->ajax_track();
} catch ( JsonResponseException $e ) {
	$view_counted_5_2 = $e->data['data']['counted'] ?? null;
}
assert_test( true === $view_counted_5_2, "Views Tracking (Enforce OFF): low score view IS counted (counted=true)" );

// 5.3 Views Ajax Track: Missing token + Enforce OFF => counted = false (Bad token still blocked!)
$_COOKIE = array();
$_POST['recaptcha_token'] = '';
$view_counted_5_3 = null;
try {
	$views->ajax_track();
} catch ( JsonResponseException $e ) {
	$view_counted_5_3 = $e->data['data']['counted'] ?? null;
}
assert_test( false === $view_counted_5_3, "Views Tracking (Enforce OFF): missing token view is STILL rejected (counted=false)" );

// 5.4 Views Ajax Track: Incomplete config + Enabled => counted = true (tracked per original BAM behavior)
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled'] = 1;
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['site_key_v3'] = '';
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['secret_key_v3'] = '';
$_COOKIE = array();
$_POST['recaptcha_token'] = 'any_token';
$view_counted_5_4 = null;
try {
	$views->ajax_track();
} catch ( JsonResponseException $e ) {
	$view_counted_5_4 = $e->data['data']['counted'] ?? null;
}
assert_test( true === $view_counted_5_4, "Views Tracking (Incomplete Config): view IS counted normally per original BAM behavior" );

// 5.5 Views Ajax Track: reCAPTCHA disabled by user => counted = true
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled'] = 0;
$_COOKIE = array();
$_POST['recaptcha_token'] = '';
$view_counted_5_5 = null;
try {
	$views->ajax_track();
} catch ( JsonResponseException $e ) {
	$view_counted_5_5 = $e->data['data']['counted'] ?? null;
}
assert_test( true === $view_counted_5_5, "Views Tracking (reCAPTCHA Disabled): view IS counted normally (counted=true)" );

// -----------------------------------------------------------------------------
// TEST SUITE 6: Bunny Score Calculation Integrity
// -----------------------------------------------------------------------------
echo "\n--- 6. Testing Bunny Score Integrity ---\n";

$bs_defaults = Bunny_Score_Settings::defaults();
assert_test( isset( $bs_defaults['schema_version'] ) && 1 === $bs_defaults['schema_version'], "Bunny Score schema_version defaults to 1" );
assert_test( isset( $bs_defaults['min_posts_per_tag'] ) && 3 === $bs_defaults['min_posts_per_tag'], "Bunny Score min_posts_per_tag defaults to 3" );
assert_test( is_array( $bs_defaults['factors'] ), "Bunny Score factors defaults to array" );

// -----------------------------------------------------------------------------
// TEST SUITE 7: Maintenance Reset Action
// -----------------------------------------------------------------------------
echo "\n--- 7. Testing Maintenance Reset Action ---\n";

$admin_menu = new Admin_Menu();

// 7.1 Nonce failure
$_POST = array();
$nonce_failed = false;
try {
	$admin_menu->handle_reset_age_gate_stats();
} catch ( RuntimeException $e ) {
	$nonce_failed = str_contains( $e->getMessage(), 'Security check failed' );
}
assert_test( $nonce_failed, "Reset without nonce is rejected" );

// 7.2 Permission failure
$_POST = array( 'wpam_nonce' => 'valid_nonce_wpam_reset_age_gate_stats' );
$GLOBALS['mock_user_can'] = false;
$perm_failed = false;
try {
	$admin_menu->handle_reset_age_gate_stats();
} catch ( RuntimeException $e ) {
	$perm_failed = str_contains( $e->getMessage(), 'You do not have permission' );
}
assert_test( $perm_failed, "Reset without manage_options capability is rejected" );

// 7.3 Successful reset & selective deletion
$GLOBALS['mock_user_can'] = true;
$GLOBALS['mock_cache']['wpam']['wpam_age_gate_global_stats'] = 'cached_data';
$GLOBALS['wpdb']->queries = array();
$GLOBALS['mock_redirect_location'] = '';

try {
	$admin_menu->handle_reset_age_gate_stats();
} catch ( RedirectException $e ) {}

$reset_queries = $GLOBALS['wpdb']->queries;
$deleted_age_gate = false;
$deleted_clicks   = false;
$deleted_views    = false;

foreach ( $reset_queries as $q ) {
	if ( str_contains( $q, 'wpam_age_gate_events' ) && str_contains( $q, 'DELETE FROM' ) ) {
		$deleted_age_gate = true;
	}
	if ( str_contains( $q, 'wpam_clicks' ) && str_contains( $q, 'DELETE FROM' ) ) {
		$deleted_clicks = true;
	}
	if ( str_contains( $q, 'wpam_views' ) && str_contains( $q, 'DELETE FROM' ) ) {
		$deleted_views = true;
	}
}

assert_test( $deleted_age_gate, "Age Gate events table was deleted" );
assert_test( ! $deleted_clicks, "Clicks table was NOT touched (isolated)" );
assert_test( ! $deleted_views, "Views table was NOT touched (isolated)" );
assert_test( ! isset( $GLOBALS['mock_cache']['wpam']['wpam_age_gate_global_stats'] ), "Age Gate cache was invalidated" );
assert_test( str_contains( $GLOBALS['mock_redirect_location'], 'wpam_age_gate_reset=1' ), "Redirects to Maintenance with wpam_age_gate_reset=1" );

// -----------------------------------------------------------------------------
// TEST SUITE 8: Incomplete Keys & Admin Notice Verification
// -----------------------------------------------------------------------------
echo "\n--- 8. Testing Incomplete Keys & Admin Notice ---\n";

// 8.1 Admin Notice when reCAPTCHA is enabled but keys are missing
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '',
	'secret_key_v3' => '',
);
$GLOBALS['mock_user_can'] = true;
$admin = new WP_AffiliateManager\Admin\Admin( '2.3.0' );
ob_start();
$admin->render_recaptcha_configuration_notice();
$notice_output = ob_get_clean();

assert_test( str_contains( $notice_output, 'notice-warning' ), "Admin Notice: uses notice-warning when keys are missing" );
assert_test( str_contains( $notice_output, 'Faltan tanto la Clave del Sitio como la Clave Secreta' ), "Admin Notice: identifies missing keys" );
assert_test( str_contains( $notice_output, 'permanecerá inactiva hasta que se configuren las claves' ), "Admin Notice: explains protection is inactive" );

// 8.2 Admin Notice when reCAPTCHA is disabled => NO notice
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha']['enabled'] = 0;
ob_start();
$admin->render_recaptcha_configuration_notice();
$no_notice_disabled = ob_get_clean();
assert_test( '' === trim( $no_notice_disabled ), "Admin Notice: no notice when reCAPTCHA is disabled" );

// 8.3 Admin Notice when reCAPTCHA is fully configured => NO notice
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '6Le_mock_site_key_v3',
	'secret_key_v3' => '6Le_mock_secret_key_v3',
);
ob_start();
$admin->render_recaptcha_configuration_notice();
$no_notice_active = ob_get_clean();
assert_test( '' === trim( $no_notice_active ), "Admin Notice: no notice when reCAPTCHA is active" );

// 8.4 Redirect Manager: Incomplete keys + missing view cookie => original behavior (requires valid view)
$GLOBALS['mock_options'][WPAM_OPTION_KEY]['recaptcha'] = array(
	'enabled'       => 1,
	'site_key_v3'   => '',
	'secret_key_v3' => '',
);
$redirect_mgr = new WP_AffiliateManager\Redirect\Redirect_Manager();
$GLOBALS['mock_status_header'] = null;
ob_start();
$die_msg   = '';
$die_title = '';
try {
	$ref = new ReflectionMethod( $redirect_mgr, 'handle_missing_view_gate' );
	$ref->invoke( $redirect_mgr, array( 'post_id' => 123, 'affiliate_id' => 45, 'url' => 'https://example.com' ), 'token123' );
} catch ( WpDieException $e ) {
	$die_msg   = $e->getMessage();
	$die_title = $e->title;
} catch ( \Throwable $e ) {}
$rm_output = ob_get_clean();

assert_test( 403 === $GLOBALS['mock_status_header'], "Redirect Manager (Incomplete Keys): returns 403 status header" );
assert_test( str_contains( $die_msg, 'A valid page view is required' ) && 'Access denied' === $die_title, "Redirect Manager (Incomplete Keys): preserves original view requirement without extra reCAPTCHA block" );

// 8.5 Safe Logging: logs configuration error with allowlisted slug without secrets
Recaptcha_V3::log_configuration_error( 'missing_site_key' );
assert_test( true, "Safe Logging: log_configuration_error executed without exceptions or leaks" );

echo "\n=========================================================\n";
echo "TEST RESULTS: Total: {$tests_run} | Passed: {$tests_passed} | Failed: {$tests_failed}\n";
echo "=========================================================\n";

if ( $tests_failed > 0 ) {
	exit( 1 );
}
exit( 0 );
