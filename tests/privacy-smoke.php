<?php
/** Dependency-free checks for privacy integration registration and bounded cleanup. */
define( 'ABSPATH', __DIR__ . '/' );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['rf_test_options'] = array(
	'wc_pvr_consent_db_version' => '1.4',
	'wc_pvr_email_hash_key' => 'synthetic-key',
	'wc_pvr_retention_views_days' => 30,
	'wc_pvr_retention_history_days' => 30,
	'wc_pvr_retention_consent_days' => 30,
);
$GLOBALS['rf_test_scheduled'] = array();
function add_action() {}
function add_filter() {}
function __( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function get_option( $key, $default = false ) { return array_key_exists( $key, $GLOBALS['rf_test_options'] ) ? $GLOBALS['rf_test_options'][ $key ] : $default; }
function update_option( $key, $value ) { $GLOBALS['rf_test_options'][ $key ] = $value; return true; }
function sanitize_email( $email ) { return trim( strtolower( (string) $email ) ); }
function is_email( $email ) { return false !== filter_var( $email, FILTER_VALIDATE_EMAIL ); }
function get_user_by() { return false; }
function wp_next_scheduled( $hook ) { return isset( $GLOBALS['rf_test_scheduled'][ $hook ] ) ? $GLOBALS['rf_test_scheduled'][ $hook ] : false; }
function wp_schedule_event( $time, $recurrence, $hook ) { $GLOBALS['rf_test_scheduled'][ $hook ] = $time; return true; }
function current_datetime() { return new DateTimeImmutable( '2026-01-01 12:00:00', new DateTimeZone( 'UTC' ) ); }

class WC_PVR_Privacy_Test_DB {
	public $prefix = 'wp_';
	public $queries = array();
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = is_numeric( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'SHOW TABLES LIKE' ) && preg_match( "/'([^']*wc_pvr_[^']*)'/", $query, $match ) ) { return $match[1]; }
		return null;
	}
	public function get_col( $query ) {
		$this->queries[] = $query;
		return range( 1, 250 );
	}
	public function query( $query ) { $this->queries[] = $query; return 250; }
}
$wpdb = new WC_PVR_Privacy_Test_DB();
require dirname( __DIR__ ) . '/includes/class-consent.php';
require dirname( __DIR__ ) . '/includes/class-privacy.php';
$privacy = new WC_PVR_Privacy();
$exporters = $privacy->register_exporter( array() );
$erasers = $privacy->register_eraser( array() );
if ( ! isset( $exporters['wc-pvr-reminders']['callback'], $erasers['wc-pvr-reminders']['callback'] ) ) { fwrite( STDERR, "Privacy callbacks were not registered.\n" ); exit( 1 ); }
$export = $privacy->export_personal_data( 'invalid-email', 1 );
$erase = $privacy->erase_personal_data( 'invalid-email', 1 );
if ( empty( $export['done'] ) || ! empty( $export['data'] ) || empty( $erase['done'] ) || ! empty( $erase['items_removed'] ) ) { fwrite( STDERR, "Invalid email was not safely ignored.\n" ); exit( 1 ); }
$privacy->schedule_cleanup();
if ( ! wp_next_scheduled( 'wc_pvr_retention_cleanup' ) ) { fwrite( STDERR, "Daily cleanup was not scheduled.\n" ); exit( 1 ); }
$privacy->run_retention_cleanup();
$deletes = array_values( array_filter( $wpdb->queries, static function ( $query ) { return 0 === strpos( $query, 'DELETE FROM' ); } ) );
if ( count( $deletes ) !== 3 ) { fwrite( STDERR, "Expected bounded deletes for views, history, and historical consent.\n" ); exit( 1 ); }
foreach ( $deletes as $query ) {
	if ( ! preg_match( '/WHERE id IN \(([^)]+)\)/', $query, $match ) || count( explode( ',', $match[1] ) ) !== 250 ) { fwrite( STDERR, "Cleanup did not constrain the delete to 250 selected IDs.\n" ); exit( 1 ); }
}
if ( empty( get_option( 'wc_pvr_last_retention_cleanup_at' ) ) ) { fwrite( STDERR, "Cleanup completion was not recorded.\n" ); exit( 1 ); }
echo "Privacy smoke checks passed (registration, invalid identity, schedule, and 250-row batch bounds).\n";
