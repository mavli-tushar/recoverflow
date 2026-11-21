<?php
/**
 * Synthetic LocalWP integration smoke test. Run with LocalWP PHP and php.ini.
 * This refuses non-local WordPress environments and uses only unique .test
 * addresses. All temporary user, product, view, consent, history, and purchase
 * rows are removed in finally/shutdown cleanup.
 */

if ( PHP_SAPI !== 'cli' ) { fwrite( STDERR, "Run this test from the LocalWP PHP CLI.\n" ); exit( 2 ); }
define( 'WP_USE_THEMES', false );
require dirname( __DIR__, 4 ) . '/wp-load.php';
if ( 'local' !== wp_get_environment_type() ) { fwrite( STDERR, "Refusing to run: WordPress environment is not local.\n" ); exit( 2 ); }
if ( ! class_exists( 'WooCommerce' ) || ! class_exists( 'WC_PVR_Consent' ) || ! class_exists( 'WC_PVR_Privacy' ) ) { fwrite( STDERR, "WooCommerce or RecoverFlow is not active.\n" ); exit( 2 ); }

global $wpdb;
$key = strtolower( wp_generate_password( 12, false, false ) );
$items = array();
$failed = false;
$cursor_saved = false;
$cron_filter = null;
$cleanup = function () use ( &$items, $wpdb ) {
	foreach ( $items as $item ) {
		$email = $item['email'];
		$hash = WC_PVR_Consent::email_hash( $email );
		$wpdb->delete( $wpdb->prefix . 'wc_pvr_views', array( 'email' => $email ) );
		if ( WC_PVR_Consent::is_installed() && $hash ) {
			$wpdb->delete( WC_PVR_Consent::history_table_name(), array( 'email_hash' => $hash ) );
			$wpdb->delete( WC_PVR_Consent::purchases_table_name(), array( 'email_hash' => $hash ) );
			$wpdb->delete( WC_PVR_Consent::table_name(), array( 'email_hash' => $hash ) );
		}
		if ( ! empty( $item['order_id'] ) ) { $order = wc_get_order( absint( $item['order_id'] ) ); if ( $order ) { $order->delete( true ); } }
		if ( ! empty( $item['product_id'] ) ) { wp_delete_post( absint( $item['product_id'] ), true ); }
		if ( ! empty( $item['user_id'] ) ) { require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( absint( $item['user_id'] ) ); }
	}
};
register_shutdown_function( $cleanup );
$assert = function ( $condition, $message ) {
	if ( ! $condition ) { throw new RuntimeException( $message ); }
	echo "PASS: {$message}\n";
};

try {
	$schema_before = get_option( 'wc_pvr_consent_db_version', '' );
	$backfill_before = get_option( 'wc_pvr_orders_backfill_complete', false );
	$page_before = get_option( 'wc_pvr_orders_backfill_page', false );
	update_option( 'wc_pvr_consent_db_version', '1.3', false );
	WC_PVR_Consent::maybe_install();
	$assert( '1.4' === get_option( 'wc_pvr_consent_db_version' ), 'additive schema upgrade reached version 1.4' );
	$assert( (string) $backfill_before === (string) get_option( 'wc_pvr_orders_backfill_complete' ) && (string) $page_before === (string) get_option( 'wc_pvr_orders_backfill_page' ), 'schema upgrade preserved purchase backfill state' );
	$index_rows = $wpdb->get_results( 'SHOW INDEX FROM ' . $wpdb->prefix . 'wc_pvr_views' );
		$index_names = array_unique( array_map( static function ( $row ) { return $row->Key_name; }, (array) $index_rows ) );
	$assert( in_array( 'view_time', $index_names, true ) && in_array( 'user_id', $index_names, true ) && in_array( 'customer_email', $index_names, true ), 'view retention and identity indexes exist' );
	$view_columns = $wpdb->get_col( 'SHOW COLUMNS FROM ' . $wpdb->prefix . 'wc_pvr_views', 0 );
	$assert( in_array( 'cart_added_at', (array) $view_columns, true ), 'cart reminder timestamp column exists with legacy-null compatibility' );

	$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
	$assert( ! empty( $admins ), 'LocalWP has an administrator for read-only admin rendering' );
	wp_set_current_user( absint( $admins[0] ) );
	$admin = new WC_PVR_Admin_Settings();
	ob_start(); $admin->consent_page(); $consent_html = ob_get_clean();
	ob_start(); $admin->system_status_page(); $status_html = ob_get_clean();
	ob_start(); $admin->help_page(); $help_html = ob_get_clean();
	$assert( false !== strpos( $consent_html, 'Consent Management' ) && false !== strpos( $consent_html, 'default deny' ), 'Consent Management page renders its summary and eligibility guidance' );
	$assert( false !== strpos( $status_html, 'Last completed cron run' ) && false !== strpos( $status_html, 'Retention cleanup scheduled' ), 'System Status distinguishes scheduled and completed cron runs' );
	$assert( false !== strpos( $help_html, 'Tracking delay (seconds)' ) && false !== strpos( $help_html, 'manual send' ) && false !== strpos( $help_html, 'Privacy, data export, and retention' ), 'Help page renders guidance for timing, manual reminders, and privacy' );

	$exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
	$erasers = apply_filters( 'wp_privacy_personal_data_erasers', array() );
	$assert( isset( $exporters['wc-pvr-reminders'], $erasers['wc-pvr-reminders'] ), 'WordPress privacy exporter and eraser are registered' );

	$privacy = new WC_PVR_Privacy();
	$emails = array( 'recoverflow-' . $key . '-manual@example.test', 'recoverflow-' . $key . '-cron@example.test' );
	foreach ( $emails as $email ) {
		$user_id = wp_insert_user( array( 'user_login' => 'rf' . $key . wp_rand( 100, 999 ), 'user_pass' => wp_generate_password( 24 ), 'user_email' => $email, 'role' => 'customer' ) );
		$assert( ! is_wp_error( $user_id ), 'synthetic customer created for ' . $email );
		$items[] = array( 'email' => $email, 'user_id' => $user_id, 'product_id' => 0, 'order_id' => 0 );
		$item_key = count( $items ) - 1;
		$product = new WC_Product_Simple();
		$product->set_name( 'RecoverFlow synthetic ' . $key );
		$product->set_status( 'publish' );
		$product->set_regular_price( '9.99' );
		$product_id = $product->save();
		$items[ $item_key ]['product_id'] = $product_id;
		$assert( $product_id > 0, 'synthetic product created for ' . $email );
		$assert( WC_PVR_Consent::record_choice( $email, $user_id, true, 'phase3_local_test', 'synthetic-v1' ), 'explicit synthetic consent recorded for ' . $email );
		$order = wc_create_order( array( 'customer_id' => $user_id ) );
		$assert( $order instanceof WC_Order, 'synthetic unpaid WooCommerce order created for ' . $email );
		$order->set_billing_email( $email );
		$order->add_product( wc_get_product( $product_id ), 1 );
		$order->calculate_totals();
		$order->set_status( 'pending' );
		$order->save();
		$items[ $item_key ]['order_id'] = absint( $order->get_id() );
		$order_store = isset( $GLOBALS['wc_pvr_order_store'] ) ? $GLOBALS['wc_pvr_order_store'] : null;
		$assert( $order_store instanceof WC_PVR_Order_Store && $order_store->sync_order( $order ), 'synthetic WooCommerce order indexed through CRUD APIs' );
		$view_time = '-cron@example.test' === substr( $email, -18 ) ? current_datetime()->modify( '-30 hours' )->format( 'Y-m-d H:i:s' ) : current_time( 'mysql' );
		$wpdb->insert( $wpdb->prefix . 'wc_pvr_views', array( 'user_id' => $user_id, 'product_id' => $product_id, 'email' => $email, 'added_to_cart' => 0, 'purchased' => 0, 'view_time' => $view_time, 'view_reminder_sent' => 0, 'cart_reminder_sent' => 0 ) );
		$view_id = absint( $wpdb->insert_id );
		$items[ $item_key ]['view_id'] = $view_id;
	}
	ob_start(); $admin->consent_page(); $consent_rows_html = ob_get_clean();
	$assert( false !== strpos( $consent_rows_html, 'phase3_local_test' ) && false !== strpos( $consent_rows_html, 'Opted in' ) && false !== strpos( $consent_rows_html, 'email' ), 'Consent Management renders synthetic source, channel, and status details' );

	$manual_email = $emails[0];
	$manual_item = $items[0];
	$service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
	$assert( $service instanceof WC_PVR_Reminder, 'reminder service is available' );
	if ( get_option( 'wc_pvr_orders_backfill_complete', 0 ) ) {
		$manual_result = $service->send_manual_reminder( $manual_item['view_id'], get_current_user_id() );
		$assert( ! is_wp_error( $manual_result ) && ! empty( $manual_result['accepted'] ), 'manual synthetic reminder is accepted by wp_mail' );
		$duplicate_result = $service->send_manual_reminder( $manual_item['view_id'], get_current_user_id() );
		$assert( is_wp_error( $duplicate_result ) && 'already_sent' === $duplicate_result->get_error_code(), 'duplicate manual send is blocked' );
		$manual_history = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::history_table_name() . ' WHERE email_hash = %s AND result = %s', WC_PVR_Consent::email_hash( $manual_email ), 'accepted' ) ) );
		$assert( 1 === $manual_history, 'Reminder History records one accepted manual send' );

		$cron_email = $emails[1];
		$cron_item = $items[1];
		$old_cursor = get_transient( 'wc_pvr_queue_cursor' );
		$cursor_saved = true;
		set_transient( 'wc_pvr_queue_cursor', max( 0, $cron_item['view_id'] - 1 ), HOUR_IN_SECONDS );
		$cron_filter = static function ( $opted_out, $user_id, $email ) use ( $cron_email ) { return strtolower( $email ) !== strtolower( $cron_email ); };
		add_filter( 'wc_pvr_customer_has_marketing_opted_out', $cron_filter, 999, 3 );
		do_action( 'wc_pvr_cron' );
		remove_filter( 'wc_pvr_customer_has_marketing_opted_out', $cron_filter, 999 );
		$cron_filter = null;
		if ( false === $old_cursor ) { delete_transient( 'wc_pvr_queue_cursor' ); } else { set_transient( 'wc_pvr_queue_cursor', absint( $old_cursor ), DAY_IN_SECONDS ); }
		$cursor_saved = false;
		$cron_sent = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT view_reminder_sent FROM ' . $wpdb->prefix . 'wc_pvr_views WHERE id = %d', $cron_item['view_id'] ) ) );
		$cron_history = absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::history_table_name() . ' WHERE email_hash = %s AND trigger_type = %s AND result = %s', WC_PVR_Consent::email_hash( $cron_email ), 'automatic', 'accepted' ) ) );
		$assert( 1 === $cron_sent && 1 === $cron_history, 'cron processed the due synthetic record with no live-customer mail eligibility' );
		$cron_completed_at = get_option( 'wc_pvr_last_cron_completed_at', '' );
		$assert( ! empty( $cron_completed_at ), 'cron completion timestamp was recorded' );
		ob_start(); $admin->system_status_page(); $status_after_cron = ob_get_clean();
		$assert( false !== strpos( $status_after_cron, (string) $cron_completed_at ), 'System Status displays the recorded cron completion time' );
	} else {
		echo "SKIP: email/cron checks because the purchase lookup is incomplete; test did not change that setting.\n";
	}

	$export = $privacy->export_personal_data( $manual_email, 1 );
	$export_groups = array_map( static function ( $row ) { return $row['item_id']; }, $export['data'] );
	$assert( ! empty( $export_groups ) && ! empty( $export['done'] ), 'privacy exporter returns synthetic customer records' );
	$has_purchase_reference = (bool) array_filter( $export_groups, static function ( $item_id ) { return 0 === strpos( $item_id, 'purchase-' ); } );
	$assert( $has_purchase_reference, 'privacy export includes WooCommerce purchase lookup references' );
	$erase = $privacy->erase_personal_data( $manual_email, 1 );
	$assert( ! empty( $erase['done'] ) && ! empty( $erase['items_retained'] ) && WC_PVR_Consent::is_opted_out( $manual_email ), 'privacy eraser removed synthetic data and retained only opt-out suppression' );
	$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wc_pvr_views WHERE email = %s', $manual_email ) ) ), 'privacy erasure removed the synthetic view row' );
	$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::history_table_name() . ' WHERE email_hash = %s', WC_PVR_Consent::email_hash( $manual_email ) ) ) ), 'privacy erasure removed synthetic reminder history' );
	$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::purchases_table_name() . ' WHERE email_hash = %s', WC_PVR_Consent::email_hash( $manual_email ) ) ) ), 'privacy erasure removed RecoverFlow purchase lookup references' );
	$suppression = $wpdb->get_row( $wpdb->prepare( 'SELECT status,source,user_id FROM ' . WC_PVR_Consent::table_name() . ' WHERE email_hash = %s ORDER BY id DESC LIMIT 1', WC_PVR_Consent::email_hash( $manual_email ) ) );
	$assert( $suppression && 'opted_out' === $suppression->status && 'privacy_eraser' === $suppression->source && null === $suppression->user_id, 'privacy erasure retained only a user-unlinked hash suppression row' );
	$assert( wc_get_order( $manual_item['order_id'] ) instanceof WC_Order, 'privacy erasure left the WooCommerce order unchanged' );

	$privacy->schedule_cleanup();
	$assert( (bool) wp_next_scheduled( 'wc_pvr_retention_cleanup' ), 'daily retention cleanup is scheduled' );
	echo "LocalWP synthetic integration checks completed.\n";
} catch ( Throwable $error ) {
	fwrite( STDERR, 'FAIL: ' . $error->getMessage() . "\n" );
	$failed = true;
} finally {
	if ( $cron_filter ) { remove_filter( 'wc_pvr_customer_has_marketing_opted_out', $cron_filter, 999 ); }
	if ( $cursor_saved ) {
		if ( isset( $old_cursor ) && false !== $old_cursor ) { set_transient( 'wc_pvr_queue_cursor', absint( $old_cursor ), DAY_IN_SECONDS ); }
		else { delete_transient( 'wc_pvr_queue_cursor' ); }
	}
	$cleanup();
	if ( isset( $schema_before ) && '' !== (string) $schema_before && '1.4' !== (string) $schema_before ) { update_option( 'wc_pvr_consent_db_version', $schema_before, false ); }
	if ( isset( $backfill_before ) && false !== $backfill_before ) { update_option( 'wc_pvr_orders_backfill_complete', $backfill_before, false ); }
	if ( isset( $page_before ) && false !== $page_before ) { update_option( 'wc_pvr_orders_backfill_page', $page_before, false ); }
}
if ( ! $failed && ! empty( $items ) ) {
	foreach ( $items as $item ) {
		$email = $item['email'];
		$hash = WC_PVR_Consent::email_hash( $email );
		$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wc_pvr_views WHERE email = %s', $email ) ) ), 'synthetic view data was cleaned for ' . $email );
		$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::table_name() . ' WHERE email_hash = %s', $hash ) ) ), 'synthetic consent data was cleaned for ' . $email );
		$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::history_table_name() . ' WHERE email_hash = %s', $hash ) ) ), 'synthetic history was cleaned for ' . $email );
		$assert( 0 === absint( $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . WC_PVR_Consent::purchases_table_name() . ' WHERE email_hash = %s', $hash ) ) ), 'synthetic purchase lookup was cleaned for ' . $email );
		$assert( ! get_user_by( 'email', $email ), 'synthetic customer was deleted for ' . $email );
		$assert( empty( $item['product_id'] ) || ! wc_get_product( absint( $item['product_id'] ) ), 'synthetic product was deleted for ' . $email );
		$assert( empty( $item['order_id'] ) || ! wc_get_order( absint( $item['order_id'] ) ), 'synthetic WooCommerce order was deleted for ' . $email );
	}
}
if ( $failed ) { exit( 1 ); }
