<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$tables = array(
	$wpdb->prefix . 'wc_pvr_views',
	$wpdb->prefix . 'wc_pvr_consent',
	$wpdb->prefix . 'wc_pvr_email_history',
	$wpdb->prefix . 'wc_pvr_order_products',
);

foreach ( $tables as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- trusted prefix + fixed table names.
}

$options = array(
	'wc_pvr_delay',
	'wc_pvr_reminder_delay',
	'wc_pvr_cron_interval',
	'wc_pvr_cart_reminder',
	'wc_pvr_frequency_days',
	'wc_pvr_quiet_hours_enabled',
	'wc_pvr_quiet_hours_start',
	'wc_pvr_quiet_hours_end',
	'wc_pvr_exclude_product_ids',
	'wc_pvr_exclude_category_ids',
	'wc_pvr_email_subject',
	'wc_pvr_email_message',
	'wc_pvr_cart_email_message',
	'wc_pvr_email_heading',
	'wc_pvr_email_button_text',
	'wc_pvr_email_sender_name',
	'wc_pvr_email_sender_email',
	'wc_pvr_consent_enabled',
	'wc_pvr_consent_registration',
	'wc_pvr_consent_checkout',
	'wc_pvr_consent_wording',
	'wc_pvr_retention_views_days',
	'wc_pvr_retention_history_days',
	'wc_pvr_retention_consent_days',
	'wc_pvr_preserve_optout_after_erasure',
	'wc_pvr_email_hash_key',
	'wc_pvr_consent_db_version',
	'wc_pvr_consent_indexes_verified',
	'wc_pvr_orders_backfill_complete',
	'wc_pvr_orders_backfill_page',
	'wc_pvr_last_cron_completed_at',
	'wc_pvr_last_retention_cleanup_at',
	'wc_pvr_last_retention_cleanup_counts',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'wc_pvr_queue_cursor' );

wp_clear_scheduled_hook( 'wc_pvr_cron' );
wp_clear_scheduled_hook( 'wc_pvr_retention_cleanup' );
wp_clear_scheduled_hook( 'wc_pvr_order_backfill' );

flush_rewrite_rules();
