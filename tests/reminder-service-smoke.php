<?php
/**
 * Dependency-free smoke tests for reminder eligibility, sends, and purchase matching.
 * Run with: php tests/reminder-service-smoke.php
 * All fixtures use synthetic identities and in-memory WooCommerce/DB doubles.
 */
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'MINUTE_IN_SECONDS', 60 );

function add_action() {}
function remove_action() {}
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function absint( $value ) { return abs( (int) $value ); }
function __( $value ) { return $value; }
function esc_html__( $value ) { return esc_html( $value ); }
function esc_html_e( $value ) { echo esc_html( $value ); }
function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
function sanitize_text_field( $value ) { return trim( strip_tags( (string) $value ) ); }
function sanitize_email( $value ) { return strtolower( trim( (string) $value ) ); }
function is_email( $value ) { return false !== filter_var( $value, FILTER_VALIDATE_EMAIL ); }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function wp_timezone() { return new DateTimeZone( 'UTC' ); }
function current_datetime() { return new DateTimeImmutable( 'now', wp_timezone() ); }
function current_time( $type = 'mysql' ) { return current_datetime()->format( 'Y-m-d H:i:s' ); }
function wp_date( $format, $timestamp = null ) {
	if ( 'G' === $format && array_key_exists( 'synthetic_hour', $GLOBALS ) ) {
		return (string) absint( $GLOBALS['synthetic_hour'] );
	}
	return gmdate( $format, null === $timestamp ? current_datetime()->getTimestamp() : $timestamp );
}
function get_option( $key, $default = false ) {
	if ( isset( $GLOBALS['synthetic_options'] ) && array_key_exists( $key, $GLOBALS['synthetic_options'] ) ) { return $GLOBALS['synthetic_options'][ $key ]; }
	$options = array(
		'wc_pvr_orders_backfill_complete' => 1,
		'wc_pvr_reminder_delay' => 24,
		'wc_pvr_cart_reminder' => 0,
		'wc_pvr_frequency_days' => 7,
		'wc_pvr_quiet_hours_enabled' => 0,
		'wc_pvr_quiet_hours_start' => 9,
		'wc_pvr_quiet_hours_end' => 20,
		'wc_pvr_exclude_product_ids' => '',
		'wc_pvr_exclude_category_ids' => '',
		'wc_pvr_email_subject' => 'You viewed {product_name}',
		'wc_pvr_email_message' => '<p>Hello {customer_name}: {product_name} {product_image} {product_link} {cta_button}</p>',
		'wc_pvr_email_hash_key' => 'synthetic-test-secret',
		'wc_pvr_email_sender_name' => 'Synthetic Shop',
		'wc_pvr_email_sender_email' => 'shop@example.test',
		'admin_email' => 'admin@example.test',
	);
	return array_key_exists( $key, $options ) ? $options[ $key ] : $default;
}
function update_option( $key, $value, $autoload = null ) {
	$GLOBALS['synthetic_options'][ $key ] = $value;
	return true;
}
function set_transient( $key, $value, $expiration = 0 ) { $GLOBALS['synthetic_transients'][ $key ] = $value; return true; }
function get_transient( $key ) { return isset( $GLOBALS['synthetic_transients'][ $key ] ) ? $GLOBALS['synthetic_transients'][ $key ] : false; }
function wp_get_attachment_image_url( $id, $size = '' ) { return $id ? 'https://example.test/image/' . absint( $id ) . '.jpg' : false; }
function wc_placeholder_img_src( $size = '' ) { return 'https://example.test/placeholder.jpg'; }
function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
	$terms = isset( $GLOBALS['synthetic_product_cats'][ absint( $post_id ) ] ) ? $GLOBALS['synthetic_product_cats'][ absint( $post_id ) ] : array();
	return ! empty( $args['fields'] ) && 'ids' === $args['fields'] ? $terms : $terms;
}
function get_post_status() { return 'publish'; }
function get_userdata() { return false; }
function get_bloginfo( $key ) { return 'name' === $key ? 'Synthetic Shop' : ''; }
function wp_strip_all_tags( $value ) { return strip_tags( (string) $value ); }
function wp_kses_post( $value ) { return strip_tags( (string) $value, '<p><br><strong><em><a>' ); }
function esc_html( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $value ) { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function esc_url_raw( $value ) { return filter_var( (string) $value, FILTER_SANITIZE_URL ); }
function wpautop( $value ) { return '<p>' . nl2br( (string) $value ) . '</p>'; }
function wc_price( $price ) { return '$' . number_format( (float) $price, 2 ); }
function wp_get_environment_type() { return 'local'; }
function wp_mail( $to, $subject, $message, $headers = array() ) {
	$GLOBALS['synthetic_mail_count']++;
	$GLOBALS['synthetic_last_mail'] = compact( 'to', 'subject', 'message', 'headers' );
	return $GLOBALS['synthetic_mail_result'];
}
function wc_get_product( $id ) { return isset( $GLOBALS['synthetic_products'][ absint( $id ) ] ) ? $GLOBALS['synthetic_products'][ absint( $id ) ] : false; }
function wc_get_orders( $args ) { return $GLOBALS['synthetic_crud_orders']; }
function wc_get_order( $id ) { return isset( $GLOBALS['synthetic_orders'][ absint( $id ) ] ) ? $GLOBALS['synthetic_orders'][ absint( $id ) ] : false; }
function apply_filters( $tag, $value, ...$args ) {
	if ( 'wc_pvr_customer_is_eligible_for_marketing' === $tag ) { return $GLOBALS['synthetic_external_eligible']; }
	if ( 'wc_pvr_customer_has_marketing_opted_out' === $tag && $GLOBALS['synthetic_force_optout'] ) { return true; }
	if ( 'wc_pvr_customer_has_marketing_opted_out' === $tag && ! empty( $GLOBALS['synthetic_force_optout_false'] ) ) { return false; }
	return $value;
}

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) { $this->code = $code; $this->message = $message; }
	public function get_error_code() { return $this->code; }
	public function get_error_message() { return $this->message; }
}
class Synthetic_Product {
	public $id;
	public $parent;
	public $kind;
	public $image_id;
	public function __construct( $id, $kind = 'simple', $parent = 0, $image_id = 501 ) { $this->id = $id; $this->kind = $kind; $this->parent = $parent; $this->image_id = $image_id; }
	public function get_id() { return $this->id; }
	public function get_name() { return 'Synthetic <Product>'; }
	public function get_permalink() { return 'https://example.test/product/' . $this->id; }
	public function get_price() { return '12.50'; }
	public function get_image_id() { return $this->image_id; }
	public function is_type( $type ) { return $this->kind === $type; }
	public function get_parent_id() { return $this->parent; }
}
class Synthetic_Order_Item {
	private $product;
	private $variation;
	public function __construct( $product, $variation = 0 ) { $this->product = $product; $this->variation = $variation; }
	public function get_product_id() { return $this->product; }
	public function get_variation_id() { return $this->variation; }
}
class WC_Order {
	public $id; public $status; public $paid; public $customer; public $email; public $items;
	public function __construct( $id, $status, $paid, $customer, $email, $items ) { $this->id = $id; $this->status = $status; $this->paid = $paid; $this->customer = $customer; $this->email = $email; $this->items = $items; }
	public function get_id() { return $this->id; }
	public function get_status() { return $this->status; }
	public function is_paid() { return $this->paid; }
	public function get_customer_id() { return $this->customer; }
	public function get_billing_email() { return $this->email; }
	public function get_user_id() { return $this->customer; }
	public function get_items( $type = '' ) { return $this->items; }
}
class WC_PVR_Consent {
	public static function is_installed() { return true; }
	public static function history_table_name() { return 'wp_wc_pvr_email_history'; }
	public static function purchases_table_name() { return 'wp_wc_pvr_order_products'; }
	public static function status_for_email( $email ) { return isset( $GLOBALS['synthetic_consent'][ strtolower( $email ) ] ) ? $GLOBALS['synthetic_consent'][ strtolower( $email ) ] : ''; }
	public static function email_hash( $email ) { return is_email( $email ) ? hash_hmac( 'sha256', strtolower( $email ), get_option( 'wc_pvr_email_hash_key' ) ) : ''; }
}
class Synthetic_DB {
	public $prefix = 'wp_';
	public $views = array();
	public $history = array();
	public $purchase_rows = array();
	public $lock_available = true;
	public $insert_id = 0;
	public $fail_history_insert = false;
	public $fail_history_update = false;
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) { $args = $args[0]; }
		foreach ( $args as $arg ) {
			$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . addslashes( (string) $arg ) . "'";
			$query = preg_replace( '/%[sd]/', $replacement, $query, 1 );
		}
		return $query;
	}
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'GET_LOCK' ) ) { return $this->lock_available ? '1' : '0'; }
		if ( false !== strpos( $query, 'RELEASE_LOCK' ) ) { return '1'; }
		if ( false !== strpos( $query, 'wc_pvr_email_history' ) ) {
			preg_match( "/email_hash = '([a-f0-9]{64})'/", $query, $hash_match );
			$hash = isset( $hash_match[1] ) ? $hash_match[1] : '';
			if ( false !== strpos( $query, 'COUNT(*)' ) ) {
				return count( array_filter( $this->history, static function( $row ) use ( $hash ) { return $row['email_hash'] === $hash && in_array( $row['result'], array( 'accepted', 'failed' ), true ); } ) );
			}
			if ( false !== strpos( $query, "result = 'pending'" ) ) {
				preg_match( "/attempted_at >= '([^']+)'/", $query, $cutoff_match );
				$cutoff = isset( $cutoff_match[1] ) ? $cutoff_match[1] : '';
				foreach ( $this->history as $row ) { if ( $row['email_hash'] === $hash && 'pending' === $row['result'] && $row['attempted_at'] >= $cutoff ) { return $row['id'] ?? 1; } }
				return null;
			}
			preg_match( "/product_id = [\'\"]?(\d+)/", $query, $product_match );
			$product_id = isset( $product_match[1] ) ? (int) $product_match[1] : 0;
			preg_match( "/attempted_at >= '([^']+)'/", $query, $cutoff_match );
			$cutoff = isset( $cutoff_match[1] ) ? $cutoff_match[1] : '';
			foreach ( $this->history as $row ) {
				if ( $row['email_hash'] !== $hash || 'accepted' !== $row['result'] ) { continue; }
				if ( $product_id && (int) ( $row['product_id'] ?? 0 ) !== $product_id ) { continue; }
				if ( $cutoff && ( $row['attempted_at'] ?? '' ) < $cutoff ) { continue; }
				return $row['id'] ?? 1;
			}
			return null;
		}
		return '1';
	}
	public function get_row( $query ) {
		if ( preg_match( '/WHERE id = [\'\"]?(\d+)/', $query, $m ) && isset( $this->views[ (int) $m[1] ] ) ) { return clone $this->views[ (int) $m[1] ]; }
		return null;
	}
	public function get_results( $query ) {
		if ( false === strpos( $query, 'wc_pvr_email_history' ) ) { return array(); }
		if ( preg_match( '/view_id = [\'\"]?(\d+)/', $query, $m ) ) {
			$id = (int) $m[1];
			$rows = array_filter( $this->history, static function( $row ) use ( $id ) { return (int) $row['view_id'] === $id; } );
			return array_map( static function( $row ) { return (object) $row; }, array_values( $rows ) );
		}
		return array_map( static function( $row ) { return (object) $row; }, $this->history );
	}
	public function get_col( $query ) {
		if ( false === strpos( $query, 'wc_pvr_order_products' ) ) { return array(); }
		preg_match( '/product_id = [\'\"]?(\d+)/', $query, $p );
		preg_match( '/variation_id = [\'\"]?(\d+)/', $query, $v );
		preg_match( '/user_id = [\'\"]?(\d+)/', $query, $u );
		preg_match( '/email_hash = [\'\"]?([a-f0-9]{64})/', $query, $h );
		$ids = array();
		foreach ( $this->purchase_rows as $row ) {
			$product_match = ! empty( $p[1] ) && (int) $row['product_id'] === (int) $p[1];
			if ( ! empty( $v[1] ) ) { $product_match = $product_match || (int) $row['variation_id'] === (int) $v[1]; }
			$identity_match = ( ! empty( $u[1] ) && (int) $row['user_id'] === (int) $u[1] ) || ( ! empty( $h[1] ) && hash_equals( $h[1], $row['email_hash'] ) );
			if ( $product_match && $identity_match && in_array( $row['order_status'], array( 'processing', 'completed' ), true ) ) { $ids[] = $row['order_id']; }
		}
		return array_values( array_unique( $ids ) );
	}
	public function update( $table, $data, $where ) {
		if ( 'wp_wc_pvr_email_history' === $table ) {
			if ( $this->fail_history_update ) { return false; }
			foreach ( $this->history as &$row ) { if ( (int) $row['id'] === (int) $where['id'] ) { $row = array_merge( $row, $data ); unset( $row ); return 1; } }
			unset( $row );
			return 0;
		}
		foreach ( $data as $key => $value ) { $this->views[ (int) $where['id'] ]->$key = $value; }
		return 1;
	}
	public function insert( $table, $data, $formats = array() ) {
		if ( 'wp_wc_pvr_email_history' === $table && $this->fail_history_insert ) { return false; }
		$this->insert_id = count( $this->history ) + 1;
		$data['id'] = $this->insert_id;
		$this->history[] = $data;
		return 1;
	}
}

$GLOBALS['synthetic_options'] = array();
$GLOBALS['synthetic_transients'] = array();
$GLOBALS['synthetic_product_cats'] = array();
$GLOBALS['synthetic_external_eligible'] = false;
$GLOBALS['synthetic_force_optout'] = false;
$GLOBALS['synthetic_force_optout_false'] = false;
$GLOBALS['synthetic_mail_count'] = 0;
$GLOBALS['synthetic_mail_result'] = true;
$GLOBALS['synthetic_consent'] = array( 'consented@example.test' => 'opted_in', 'guest@example.test' => 'opted_in', 'failed@example.test' => 'opted_in', 'queued@example.test' => 'opted_in', 'rate@example.test' => 'opted_in' );
$GLOBALS['synthetic_products'] = array( 10 => new Synthetic_Product( 10 ), 11 => new Synthetic_Product( 11 ), 101 => new Synthetic_Product( 101, 'variation', 10 ), 102 => new Synthetic_Product( 102, 'variation', 10 ) );
$GLOBALS['synthetic_orders'] = array();
$GLOBALS['synthetic_crud_orders'] = array();
$wpdb = new Synthetic_DB();
$GLOBALS['wpdb'] = $wpdb;
require dirname( __DIR__ ) . '/includes/class-reminder.php';
require dirname( __DIR__ ) . '/includes/class-order-store.php';
$GLOBALS['synthetic_order_store'] = new WC_PVR_Order_Store();

function check( $condition, $message ) { if ( ! $condition ) { fwrite( STDERR, "FAIL: {$message}\n" ); exit( 1 ); } }
function view_fixture( $id, $product = 10, $email = 'consented@example.test', $user = 42, $hours_ago = 30, $cart = 0 ) {
	return (object) array( 'id' => $id, 'product_id' => $product, 'email' => $email, 'user_id' => $user, 'view_time' => current_datetime()->modify( '-' . $hours_ago . ' hours' )->format( 'Y-m-d H:i:s' ), 'cart_added_at' => null, 'added_to_cart' => $cart, 'purchased' => 0, 'view_reminder_sent' => 0, 'cart_reminder_sent' => 0 );
}
$GLOBALS['wc_pvr_order_store'] = $GLOBALS['synthetic_order_store'];
$service = new WC_PVR_Reminder();

$early = view_fixture( 1, 10, 'consented@example.test', 42, 23 );
check( 'waiting_period' === $service->can_send( $early, false )->get_error_code(), 'reminder waits for the configured 24 hours' );
$due = view_fixture( 2, 10, 'consented@example.test', 42, 25 );
check( true === $service->can_send( $due, false ), 'reminder is eligible after 24 hours' );

$no_consent = view_fixture( 3, 10, 'no-consent@example.test', 0, 48 );
check( 'consent_required' === $service->can_send( $no_consent, true )->get_error_code(), 'marketing is denied by default' );
$GLOBALS['synthetic_external_eligible'] = true;
$GLOBALS['synthetic_force_optout'] = true;
$no_consent->email = 'consented@example.test';
check( 'customer_opted_out' === $service->can_send( $no_consent, true )->get_error_code(), 'opt-out overrides external positive eligibility' );
$GLOBALS['synthetic_external_eligible'] = false;
$GLOBALS['synthetic_force_optout'] = false;
$GLOBALS['synthetic_consent']['ledger-optout@example.test'] = 'opted_out';
$GLOBALS['synthetic_force_optout_false'] = true;
$ledger_optout = view_fixture( 31, 10, 'ledger-optout@example.test', 42, 48 );
check( 'customer_opted_out' === $service->can_send( $ledger_optout, true )->get_error_code(), 'ledger opt-out remains authoritative when integration opt-out filter returns false' );
$GLOBALS['synthetic_force_optout_false'] = false;

$cart = view_fixture( 4, 10, 'consented@example.test', 42, 30, 1 );
check( 'cart_reminder_disabled' === $service->can_send( $cart, true )->get_error_code(), 'cart reminder setting is enforced' );
$GLOBALS['synthetic_options']['wc_pvr_cart_reminder'] = 1;
check( true === $service->can_send( $cart, true ), 'enabled cart reminder is eligible when remaining checks pass' );
$recent_cart = view_fixture( 41, 10, 'cart-wait@example.test', 0, 36, 1 );
$GLOBALS['synthetic_consent']['cart-wait@example.test'] = 'opted_in';
$recent_cart->cart_added_at = current_datetime()->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );
check( 'waiting_period' === $service->can_send( $recent_cart, false )->get_error_code(), 'cart reminder wait starts at cart-add time, not the earlier product view' );
$recent_cart->cart_added_at = current_datetime()->modify( '-25 hours' )->format( 'Y-m-d H:i:s' );
check( true === $service->can_send( $recent_cart, false ), 'cart reminder becomes eligible after 24 hours from cart addition' );
$cart->cart_reminder_sent = 1;
check( 'already_sent' === $service->can_send( $cart, true )->get_error_code(), 'cart reminder accepted flag prevents another send' );
$GLOBALS['synthetic_options']['wc_pvr_cart_reminder'] = 0;
$GLOBALS['synthetic_products'][999] = false;
$invalid = view_fixture( 5, 0, 'consented@example.test', 42, 30 );
check( 'invalid_product' === $service->can_send( $invalid, true )->get_error_code(), 'zero product IDs are rejected' );
$deleted = view_fixture( 6, 999, 'consented@example.test', 42, 30 );
check( 'invalid_product' === $service->can_send( $deleted, true )->get_error_code(), 'deleted products are rejected' );

$wpdb->views[ 7 ] = view_fixture( 7, 10, 'consented@example.test', 42 );
$GLOBALS['synthetic_mail_result'] = true;
$first = $service->send_manual_reminder( 7, 1 );
check( ! is_wp_error( $first ) && ! empty( $first['accepted'] ), 'successful wp_mail() is recorded as accepted' );
check( 'accepted' === end( $wpdb->history )['result'], 'accepted send appears in attempt history' );
$rate_limited = view_fixture( 70, 10, 'consented@example.test', 42 );
check( 'customer_frequency_limit' === $service->can_send( $rate_limited, true )->get_error_code(), 'weekly frequency limit blocks another reminder for the same product' );
$other_product = view_fixture( 71, 11, 'consented@example.test', 42 );
check( true === $service->can_send( $other_product, true ), 'a different product can still receive a reminder within seven days' );
$mail_count = $GLOBALS['synthetic_mail_count'];
$duplicate = $service->send_manual_reminder( 7, 1 );
check( is_wp_error( $duplicate ) && 'already_sent' === $duplicate->get_error_code(), 'duplicate accepted reminder is blocked' );
check( $GLOBALS['synthetic_mail_count'] === $mail_count, 'duplicate attempt does not call wp_mail() again' );
check( false === strpos( $GLOBALS['synthetic_last_mail']['message'], '<script' ), 'email template strips unsafe dynamic/template markup' );
check( false !== strpos( $GLOBALS['synthetic_last_mail']['message'], 'https://example.test/image/501.jpg' ), 'product image placeholder is rendered in the email body' );

foreach ( $wpdb->history as &$history_row ) {
	if ( (int) ( $history_row['product_id'] ?? 0 ) === 10 && 'accepted' === ( $history_row['result'] ?? '' ) && WC_PVR_Consent::email_hash( 'consented@example.test' ) === ( $history_row['email_hash'] ?? '' ) ) {
		$history_row['attempted_at'] = current_datetime()->modify( '-4 days' )->format( 'Y-m-d H:i:s' );
	}
}
unset( $history_row );
$GLOBALS['synthetic_options']['wc_pvr_frequency_days'] = 3;
$cooled_down = view_fixture( 72, 10, 'consented@example.test', 42 );
check( true === $service->can_send( $cooled_down, true ), 'configurable 3-day cooldown allows a resend after the window' );
$GLOBALS['synthetic_options']['wc_pvr_frequency_days'] = 14;
check( 'customer_frequency_limit' === $service->can_send( $cooled_down, true )->get_error_code(), 'longer cooldown still blocks the same product' );
$GLOBALS['synthetic_options']['wc_pvr_frequency_days'] = 7;

$GLOBALS['synthetic_options']['wc_pvr_exclude_product_ids'] = '10';
check( 'product_excluded' === $service->can_send( view_fixture( 73, 10, 'consented@example.test', 42 ), true )->get_error_code(), 'excluded product IDs block reminders' );
$GLOBALS['synthetic_options']['wc_pvr_exclude_product_ids'] = '';
$GLOBALS['synthetic_options']['wc_pvr_exclude_category_ids'] = '22';
$GLOBALS['synthetic_product_cats'][11] = array( 22 );
check( 'product_excluded' === $service->can_send( view_fixture( 74, 11, 'consented@example.test', 42 ), true )->get_error_code(), 'excluded categories block reminders' );
$GLOBALS['synthetic_options']['wc_pvr_exclude_category_ids'] = '';
$GLOBALS['synthetic_product_cats'] = array();

$GLOBALS['synthetic_options']['wc_pvr_quiet_hours_enabled'] = 1;
$GLOBALS['synthetic_options']['wc_pvr_quiet_hours_start'] = 9;
$GLOBALS['synthetic_options']['wc_pvr_quiet_hours_end'] = 20;
$GLOBALS['synthetic_hour'] = 22;
check( false === WC_PVR_Reminder::is_within_send_window(), 'quiet hours close the automatic send window at night' );
$mail_before_quiet = $GLOBALS['synthetic_mail_count'];
$service->send_pvr_reminders();
check( $GLOBALS['synthetic_mail_count'] === $mail_before_quiet, 'cron skips automatic sends outside quiet hours' );
$GLOBALS['synthetic_hour'] = 10;
check( true === WC_PVR_Reminder::is_within_send_window(), 'quiet hours reopen during the configured window' );
$GLOBALS['synthetic_options']['wc_pvr_quiet_hours_enabled'] = 0;
unset( $GLOBALS['synthetic_hour'] );

$wpdb->views[ 8 ] = view_fixture( 8, 10, 'failed@example.test', 0 );
$GLOBALS['synthetic_mail_result'] = false;
$failed = $service->send_manual_reminder( 8, 1 );
check( is_wp_error( $failed ) && 'mail_failed' === $failed->get_error_code(), 'failed wp_mail() returns a failure' );
check( 'failed' === end( $wpdb->history )['result'], 'failed send is recorded as failed' );
$mail_count = $GLOBALS['synthetic_mail_count'];
$retry = $service->send_manual_reminder( 8, 1 );
check( is_wp_error( $retry ) && 'retry_backoff' === $retry->get_error_code(), 'failed send respects retry backoff' );
check( $GLOBALS['synthetic_mail_count'] === $mail_count, 'retry backoff does not call wp_mail() early' );

$locked_view = view_fixture( 80, 10, 'rate@example.test', 0 );
$wpdb->views[ 80 ] = $locked_view;
$wpdb->lock_available = false;
$locked_result = $service->send_manual_reminder( 80, 1 );
check( is_wp_error( $locked_result ) && 'send_in_progress' === $locked_result->get_error_code(), 'atomic claim contention blocks a concurrent attempt' );
$wpdb->lock_available = true;
for ( $index = 0; $index < 3; $index++ ) {
	$wpdb->history[] = array( 'id' => 100 + $index, 'view_id' => 90 + $index, 'email_hash' => WC_PVR_Consent::email_hash( 'rate@example.test' ), 'result' => 'failed', 'attempted_at' => current_time() );
}
check( 'customer_rate_limit' === $service->can_send( view_fixture( 81, 10, 'rate@example.test', 0 ), true )->get_error_code(), 'three failures in a rolling 24-hour window block additional sends' );

$order = new WC_Order( 201, 'processing', true, 42, 'consented@example.test', array( new Synthetic_Order_Item( 10 ) ) );
$GLOBALS['synthetic_orders'][201] = $order;
$wpdb->purchase_rows = array( array( 'order_id' => 201, 'product_id' => 10, 'variation_id' => 0, 'user_id' => 42, 'email_hash' => WC_PVR_Consent::email_hash( 'consented@example.test' ), 'order_status' => 'processing' ) );
check( true === $GLOBALS['synthetic_order_store']->has_paid_purchase( view_fixture( 9 ), true ), 'logged-in processing order suppresses reminder' );
$guest_order = new WC_Order( 202, 'completed', true, 0, 'guest@example.test', array( new Synthetic_Order_Item( 10 ) ) );
$GLOBALS['synthetic_orders'][202] = $guest_order;
$wpdb->purchase_rows = array( array( 'order_id' => 202, 'product_id' => 10, 'variation_id' => 0, 'user_id' => 0, 'email_hash' => WC_PVR_Consent::email_hash( 'guest@example.test' ), 'order_status' => 'completed' ) );
check( true === $GLOBALS['synthetic_order_store']->has_paid_purchase( view_fixture( 10, 10, 'guest@example.test', 0 ), true ), 'guest paid order matches by billing email' );
$sibling = new WC_Order( 203, 'processing', true, 0, 'guest@example.test', array( new Synthetic_Order_Item( 10, 102 ) ) );
$GLOBALS['synthetic_orders'][203] = $sibling;
$GLOBALS['synthetic_crud_orders'] = array( $sibling );
$wpdb->purchase_rows = array( array( 'order_id' => 203, 'product_id' => 10, 'variation_id' => 102, 'user_id' => 0, 'email_hash' => WC_PVR_Consent::email_hash( 'guest@example.test' ), 'order_status' => 'processing' ) );
check( false === $GLOBALS['synthetic_order_store']->has_paid_purchase( view_fixture( 11, 101, 'guest@example.test', 0 ), true ), 'purchase of sibling variation does not suppress a specific variation' );
$same = new WC_Order( 204, 'completed', true, 0, 'guest@example.test', array( new Synthetic_Order_Item( 10, 101 ) ) );
$GLOBALS['synthetic_orders'][204] = $same;
$wpdb->purchase_rows[] = array( 'order_id' => 204, 'product_id' => 10, 'variation_id' => 101, 'user_id' => 0, 'email_hash' => WC_PVR_Consent::email_hash( 'guest@example.test' ), 'order_status' => 'completed' );
check( true === $GLOBALS['synthetic_order_store']->has_paid_purchase( view_fixture( 12, 101, 'guest@example.test', 0 ), true ), 'purchase of the tracked variation suppresses its reminder' );

$GLOBALS['synthetic_consent']['unknown@example.test'] = 'opted_in';
$wpdb->views[ 13 ] = view_fixture( 13, 10, 'unknown@example.test', 43 );
$GLOBALS['synthetic_mail_result'] = true;
$wpdb->fail_history_update = true;
$mail_count = $GLOBALS['synthetic_mail_count'];
$unknown_outcome = $service->send_manual_reminder( 13, 1 );
check( is_wp_error( $unknown_outcome ) && 'send_outcome_unknown' === $unknown_outcome->get_error_code(), 'mail result persistence failure is reported as unknown' );
check( 'pending' === end( $wpdb->history )['result'], 'pre-send attempt row remains visible when outcome persistence fails' );
$wpdb->fail_history_update = false;
$other_product_view = view_fixture( 130, 11, 'unknown@example.test', 43 );
$wpdb->views[ 130 ] = $other_product_view;
check( 'send_outcome_unknown' === $service->can_send( $other_product_view, true )->get_error_code(), 'pending outcome blocks another product reminder for the same customer' );
$retry_unknown = $service->send_manual_reminder( 13, 1 );
check( is_wp_error( $retry_unknown ) && 'send_outcome_unknown' === $retry_unknown->get_error_code(), 'pending outcome blocks a duplicate retry for the original view' );
check( $GLOBALS['synthetic_mail_count'] === $mail_count + 1, 'unknown outcome retry does not call wp_mail() twice' );

$GLOBALS['synthetic_consent']['unrecorded@example.test'] = 'opted_in';
$wpdb->views[ 14 ] = view_fixture( 14, 10, 'unrecorded@example.test', 44 );
$wpdb->fail_history_insert = true;
$mail_count = $GLOBALS['synthetic_mail_count'];
$unrecorded = $service->send_manual_reminder( 14, 1 );
check( is_wp_error( $unrecorded ) && 'history_write_failed' === $unrecorded->get_error_code(), 'email is not sent when an attempt row cannot be created' );
check( $GLOBALS['synthetic_mail_count'] === $mail_count, 'history insert failure prevents unlogged mail delivery' );
$wpdb->fail_history_insert = false;

echo "Reminder service smoke tests passed (eligibility, sends, retries, per-customer pending guard, duplicate guard, cart option, IDs, consent, logged-in/guest purchases, variation matching, quiet hours, cooldown, product image, exclusions).\n";
