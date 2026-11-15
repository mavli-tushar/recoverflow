<?php
/*
Plugin Name: WooCommerce Product View Reminder Lite
Description: Sends reminder email when a customer views a WooCommerce product but does not purchase it.
Version: 1.0
Author: Tushar Malvi
Requires Plugins: woocommerce
Text Domain: wc-product-view-reminder-lite
Domain Path: /languages
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
|--------------------------------------------------------------------------
| Plugin Constants
|--------------------------------------------------------------------------
*/

define( 'WC_PVR_VERSION', '1.0' );
define( 'WC_PVR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WC_PVR_URL', plugin_dir_url( __FILE__ ) );

/*
|--------------------------------------------------------------------------
| Activation Check (Prevent activation without WooCommerce)
|--------------------------------------------------------------------------
*/

function wc_pvr_activate_plugin() {

    if ( ! class_exists( 'WooCommerce' ) ) {

        deactivate_plugins( plugin_basename( __FILE__ ) );

        wp_die(
            esc_html__( 'WooCommerce Product View Reminder Lite requires WooCommerce to be installed and active.', 'wc-product-view-reminder-lite' ),
            esc_html__( 'Plugin dependency check', 'wc-product-view-reminder-lite' ),
            array( 'back_link' => true )
        );
    }

}
register_activation_hook( __FILE__, 'wc_pvr_activate_plugin' );


/*
|--------------------------------------------------------------------------
| Check WooCommerce Dependency
|--------------------------------------------------------------------------
*/

function wc_pvr_check_woocommerce() {

    if ( ! class_exists( 'WooCommerce' ) ) {

        add_action( 'admin_notices', 'wc_pvr_missing_wc_notice' );

        return false;
    }

    return true;
}

add_action( 'plugins_loaded', 'wc_pvr_check_woocommerce', 5 );


/*
|--------------------------------------------------------------------------
| Admin Notice
|--------------------------------------------------------------------------
*/

function wc_pvr_missing_wc_notice() {
    ?>
    <div class="notice notice-error">
        <p>
            <?php esc_html_e( 'WooCommerce Product View Reminder Lite requires WooCommerce to be installed and active.', 'wc-product-view-reminder-lite' ); ?>
        </p>
    </div>
    <?php
}


/*
|--------------------------------------------------------------------------
| Initialize Plugin
|--------------------------------------------------------------------------
*/

function wc_pvr_init() {

    if ( ! wc_pvr_check_woocommerce() ) {
        return;
    }

    require_once WC_PVR_PATH . 'includes/class-admin-settings.php';
    require_once WC_PVR_PATH . 'includes/class-tracker.php';
    require_once WC_PVR_PATH . 'includes/class-reminder.php';

    new WC_PVR_Admin_Settings();
    new WC_PVR_Tracker();
    new WC_PVR_Reminder();
}

add_action( 'plugins_loaded', 'wc_pvr_init', 20 );


/*
|--------------------------------------------------------------------------
| Add Settings Link
|--------------------------------------------------------------------------
*/

function wc_pvr_add_settings_link( $links ) {

    $settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=wc-pvr' ) ) . '">' . esc_html__( 'Settings', 'wc-product-view-reminder-lite' ) . '</a>';

    array_unshift( $links, $settings_link );

    return $links;
}

add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'wc_pvr_add_settings_link' );


/*
|--------------------------------------------------------------------------
| Create Database Table
|--------------------------------------------------------------------------
*/

function wc_pvr_create_table() {

    global $wpdb;

    $table_name = $wpdb->prefix . 'wc_pvr_views';

    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id BIGINT(20) UNSIGNED DEFAULT NULL,
        product_id BIGINT(20) UNSIGNED NOT NULL,
        email VARCHAR(200) DEFAULT NULL,
        added_to_cart TINYINT(1) DEFAULT 0,
        purchased TINYINT(1) DEFAULT 0,
        view_time DATETIME NOT NULL,

        view_reminder_sent TINYINT(1) DEFAULT 0,
        cart_reminder_sent TINYINT(1) DEFAULT 0,

        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta( $sql );
}

register_activation_hook( __FILE__, 'wc_pvr_create_table' );


/*
|--------------------------------------------------------------------------
| Custom Cron Intervals
|--------------------------------------------------------------------------
*/

function wc_pvr_custom_cron( $schedules ) {

    $schedules['5min'] = array(
        'interval' => 300,
        'display'  => __( 'Every 5 Minutes', 'wc-product-view-reminder-lite' )
    );

    $schedules['15min'] = array(
        'interval' => 900,
        'display'  => __( 'Every 15 Minutes', 'wc-product-view-reminder-lite' )
    );

    $schedules['30min'] = array(
        'interval' => 1800,
        'display'  => __( 'Every 30 Minutes', 'wc-product-view-reminder-lite' )
    );

    return $schedules;
}

add_filter( 'cron_schedules', 'wc_pvr_custom_cron' );


/*
|--------------------------------------------------------------------------
| Schedule Cron
|--------------------------------------------------------------------------
*/

function wc_pvr_schedule_cron() {

    if ( ! wp_next_scheduled( 'wc_pvr_cron' ) ) {

        $interval = get_option( 'wc_pvr_cron_interval', 'hourly' );

        wp_schedule_event( time(), $interval, 'wc_pvr_cron' );
    }

}

add_action( 'wp', 'wc_pvr_schedule_cron' );


/*
|--------------------------------------------------------------------------
| Reschedule Cron
|--------------------------------------------------------------------------
*/

function wc_pvr_reschedule_cron() {

    $timestamp = wp_next_scheduled( 'wc_pvr_cron' );

    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wc_pvr_cron' );
    }

    $interval = get_option( 'wc_pvr_cron_interval', 'hourly' );

    wp_schedule_event( time(), $interval, 'wc_pvr_cron' );
}

add_action( 'update_option_wc_pvr_cron_interval', 'wc_pvr_reschedule_cron' );


/*
|--------------------------------------------------------------------------
| Clear Cron On Deactivation
|--------------------------------------------------------------------------
*/

function wc_pvr_clear_cron() {

    $timestamp = wp_next_scheduled( 'wc_pvr_cron' );

    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'wc_pvr_cron' );
    }

}

register_deactivation_hook( __FILE__, 'wc_pvr_clear_cron' );