<?php
/**
 * Standalone smoke test for frontend consent rendering and checkbox parsing.
 * Run with: php tests/consent-frontend-smoke.php
 */

define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function add_filter() {}
function did_action() { return 0; }
function absint( $value ) { return abs( (int) $value ); }
function wp_get_current_user() { return (object) array( 'ID' => 42, 'user_email' => 'synthetic@example.test' ); }
function is_user_logged_in() { return true; }
function current_time() { return '2026-01-01 12:00:00'; }
function get_option( $key, $default = false ) {
	$options = array(
		'wc_pvr_consent_db_version' => '1.4',
		'wc_pvr_email_hash_key' => 'synthetic-test-key',
		'wc_pvr_consent_wording' => 'Synthetic consent wording.',
	);
	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}
function sanitize_email( $email ) { return trim( strtolower( $email ) ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function sanitize_text_field( $value ) { return trim( strip_tags( $value ) ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $value ) ); }
function wp_unslash( $value ) { return $value; }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function __( $value ) { return $value; }
function checked( $checked, $current = true, $echo = true ) {
	$value = (string) $checked === (string) $current ? ' checked="checked"' : '';
	if ( $echo ) { echo $value; }
	return $value;
}
function wp_nonce_field() { echo '<input type="hidden" name="_wpnonce" value="synthetic">'; }

class WC_PVR_Smoke_DB {
	public $prefix = 'wp_';
	public $last_status = 'opted_in';
	public $inserted = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) { $query = preg_replace( '/%[sd]/', "'" . addslashes( (string) $arg ) . "'", $query, 1 ); }
		return $query;
	}
	public function get_var( $query ) {
		if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $match ) ) { return $match[1]; }
		return null;
	}
	public function get_row( $query ) { return (object) array( 'status' => $this->last_status ); }
	public function insert( $table, $data ) { $this->inserted[] = $data; $this->last_status = $data['status']; return 1; }
}

$wpdb = new WC_PVR_Smoke_DB();
require dirname( __DIR__ ) . '/includes/class-consent.php';
$consent = new WC_PVR_Consent();

ob_start();
$consent->render_account_preferences();
$opted_in_form = ob_get_clean();
if ( false === strpos( $opted_in_form, 'class="woocommerce-Button button"' ) || false === strpos( $opted_in_form, 'checked="checked"' ) ) {
	fwrite( STDERR, "Expected frontend-compatible button and checked status.\n" );
	exit( 1 );
}
if ( strpos( $opted_in_form, 'Save email preferences' ) === false ) {
	fwrite( STDERR, "Preference submit button missing.\n" );
	exit( 1 );
}

$posted_consent = new ReflectionMethod( 'WC_PVR_Consent', 'posted_consent' );
$_POST = array( 'wc_pvr_marketing_consent' => '1' );
if ( true !== $posted_consent->invoke( $consent ) ) {
	fwrite( STDERR, "Checked consent value was not recognized.\n" );
	exit( 1 );
}
$_POST = array( 'wc_pvr_marketing_consent' => '0' );
if ( true === $posted_consent->invoke( $consent ) ) {
	fwrite( STDERR, "Unchecked consent value was not recognized as opt-out.\n" );
	exit( 1 );
}

if ( ! WC_PVR_Consent::record_choice( 'synthetic@example.test', 42, false, 'account_preferences', 'synthetic-version' ) || 'opted_out' !== WC_PVR_Consent::status_for_email( 'synthetic@example.test' ) ) {
	fwrite( STDERR, "Synthetic opt-out was not recorded in the test ledger.\n" );
	exit( 1 );
}
ob_start();
$consent->render_account_preferences();
$opted_out_form = ob_get_clean();
if ( strpos( $opted_out_form, 'Current status: opted out' ) === false || strpos( $opted_out_form, 'checked="checked"' ) !== false ) {
	fwrite( STDERR, "Opt-out status was not shown accurately in the preferences form.\n" );
	exit( 1 );
}

echo "Consent frontend smoke tests passed.\n";
