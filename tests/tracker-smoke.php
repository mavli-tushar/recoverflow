<?php
/**
 * Dependency-free product tracking smoke test.
 * Run with: php tests/tracker-smoke.php
 */
define( 'ABSPATH', __DIR__ . '/' );

function add_action() {}
function absint( $value ) { return abs( (int) $value ); }
function wp_unslash( $value ) { return $value; }
function sanitize_email( $value ) { return strtolower( trim( (string) $value ) ); }
function current_time( $type ) { return '2026-01-10 12:00:00'; }
function check_ajax_referer() { $GLOBALS['synthetic_nonce_checked'] = true; }
function wp_get_current_user() { return $GLOBALS['synthetic_user']; }
function wc_get_product( $id ) { return isset( $GLOBALS['synthetic_products'][ absint( $id ) ] ) ? $GLOBALS['synthetic_products'][ absint( $id ) ] : false; }
function get_post_status( $id ) { return 32 === absint( $id ) ? 'draft' : 'publish'; }
function get_option( $key, $default = false ) { return 'wc_pvr_cart_reminder' === $key ? 1 : $default; }
function wp_die() { throw new WP_PVR_Tracker_End(); }

class WP_PVR_Tracker_End extends Exception {}

class Synthetic_Tracker_User {
	public $ID = 42;
	public $user_email = 'tracker@example.test';
	public function exists() { return ! empty( $GLOBALS['synthetic_user_exists'] ); }
}

class Synthetic_Tracker_Product {
	private $id;
	public function __construct( $id ) { $this->id = $id; }
	public function get_id() { return $this->id; }
}

class Synthetic_Tracker_DB {
	public $prefix = 'wp_';
	public $views = array();
	public function prepare( $query, ...$args ) {
		foreach ( $args as $arg ) {
			$value = is_int( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[ds]/', $value, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		preg_match( '/user_id = (\d+)/', $query, $user );
		preg_match( '/product_id = (\d+)/', $query, $product );
		foreach ( $this->views as $view ) {
			if ( isset( $user[1], $product[1] ) && $view['user_id'] === (int) $user[1] && $view['product_id'] === (int) $product[1] ) {
				return count( $this->views );
			}
		}
		return null;
	}
	public function insert( $table, $data, $formats = array() ) {
		$this->views[] = $data;
		return 1;
	}
}

function tracker_check( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
		exit( 1 );
	}
}

function invoke_tracker( $tracker, $posted_product_id ) {
	$_POST = array( 'nonce' => 'synthetic', 'product_id' => $posted_product_id );
	try {
		$tracker->track();
	} catch ( WP_PVR_Tracker_End $end ) {
		return;
	}
	tracker_check( false, 'track() should terminate its AJAX request' );
}

$GLOBALS['wpdb'] = new Synthetic_Tracker_DB();
$GLOBALS['synthetic_user'] = new Synthetic_Tracker_User();
$GLOBALS['synthetic_user_exists'] = true;
$GLOBALS['synthetic_nonce_checked'] = false;
$GLOBALS['synthetic_products'] = array(
	31 => new Synthetic_Tracker_Product( 31 ),
	32 => new Synthetic_Tracker_Product( 32 ),
	33 => new Synthetic_Tracker_Product( 33 ),
);

require dirname( __DIR__ ) . '/includes/class-tracker.php';
$tracker = new WC_PVR_Tracker();

invoke_tracker( $tracker, '31' );
tracker_check( $GLOBALS['synthetic_nonce_checked'], 'AJAX nonce is checked' );
tracker_check( 1 === count( $GLOBALS['wpdb']->views ), 'valid product view is recorded once' );
tracker_check( 31 === $GLOBALS['wpdb']->views[0]['product_id'], 'tracked product ID is the validated product' );
tracker_check( 42 === $GLOBALS['wpdb']->views[0]['user_id'], 'view is associated with the logged-in synthetic customer' );
tracker_check( 'tracker@example.test' === $GLOBALS['wpdb']->views[0]['email'], 'view uses the logged-in account email' );

invoke_tracker( $tracker, '31' );
tracker_check( 1 === count( $GLOBALS['wpdb']->views ), 'same-day duplicate product view is not inserted twice' );

foreach ( array( 0, '-1', '31x', array( 31 ), 999, 32 ) as $invalid_id ) {
	invoke_tracker( $tracker, $invalid_id );
}
tracker_check( 1 === count( $GLOBALS['wpdb']->views ), 'invalid, deleted, unpublished, and nonscalar product IDs are rejected' );

$GLOBALS['synthetic_user_exists'] = false;
invoke_tracker( $tracker, '31' );
tracker_check( 1 === count( $GLOBALS['wpdb']->views ), 'guest view requests are not recorded' );

$GLOBALS['synthetic_user_exists'] = true;
$tracker->mark_cart( 'synthetic-cart-key', 33 );
tracker_check( 2 === count( $GLOBALS['wpdb']->views ) && 1 === $GLOBALS['wpdb']->views[1]['added_to_cart'], 'cart addition creates an identified cart reminder record' );
tracker_check( '2026-01-10 12:00:00' === $GLOBALS['wpdb']->views[1]['cart_added_at'], 'cart addition records its own reminder-wait timestamp' );

echo "Product tracking smoke tests passed (nonce, valid tracking, cart timestamp, duplicate suppression, invalid IDs, deleted/draft products, guest rejection).\n";
