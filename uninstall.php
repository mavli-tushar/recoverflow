<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

$table = $wpdb->prefix . 'wc_pvr_views';

/**
 * Drop plugin table
 */
$wpdb->query(
    $wpdb->prepare(
        "DROP TABLE IF EXISTS %i",
        $table
    )
);

/**
 * Delete plugin options
 */
delete_option( 'wc_pvr_delay' );
delete_option( 'wc_pvr_cron_interval' );
delete_option( 'wc_pvr_cart_reminder' );
delete_option( 'wc_pvr_email_subject' );
delete_option( 'wc_pvr_email_message' );