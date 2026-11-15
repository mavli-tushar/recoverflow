<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PVR_Tracker {

    public function __construct() {

        add_action( 'wp_enqueue_scripts', array( $this, 'scripts' ) );

        add_action( 'wp_ajax_wc_pvr_track', array( $this, 'track' ) );
        add_action( 'wp_ajax_nopriv_wc_pvr_track', array( $this, 'track' ) );

        add_action( 'woocommerce_add_to_cart', array( $this, 'mark_cart' ), 10, 2 );
        add_action( 'woocommerce_ajax_added_to_cart', array( $this, 'mark_cart_ajax' ) );

        add_action( 'woocommerce_thankyou', array( $this, 'mark_purchase' ) );
    }

    public function scripts() {

        if ( ! is_product()) {
            return;
        }

        wp_enqueue_script(
            'wc-pvr-tracker',
            WC_PVR_URL . 'assets/js/tracker.js',
            array( 'jquery' ),
            WC_PVR_VERSION,
            true
        );

        wp_localize_script(
            'wc-pvr-tracker',
            'wc_pvr_data',
            array(
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'delay'      => absint( get_option( 'wc_pvr_delay', 5 ) ),
                'product_id' => absint( get_the_ID() ),
                'nonce'      => wp_create_nonce( 'wc_pvr_track_nonce' ),
            )
        );
    }

    public function track() {

        check_ajax_referer( 'wc_pvr_track_nonce', 'nonce' );

        if ( empty( $_POST['product_id'] ) ) {
            wp_die();
        }

        $product_id = absint( wp_unslash( $_POST['product_id'] ) );

        if ( ! $product_id ) {
            wp_die();
        }

        $user = wp_get_current_user();

        if ( ! $user || ! $user->exists() ) {
            wp_die();
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wc_pvr_views';

        /**
         * Prevent duplicate entry for same user + product for today
         */
        $exists = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id
                FROM $table
                WHERE user_id = %d
                AND product_id = %d
                AND DATE(view_time) = CURDATE()
                LIMIT 1",
                $user->ID,
                $product_id
            )
        );

        if ( $exists ) {
            wp_die();
        }

        $wpdb->insert(
            $table,
            array(
                'user_id'       => absint( $user->ID ),
                'product_id'    => $product_id,
                'email'         => sanitize_email( $user->user_email ),
                'view_time'     => current_time( 'mysql' ),
                'added_to_cart' => 0,
                'purchased'     => 0,
                'view_reminder_sent' => 0,
                'cart_reminder_sent' => 0,
            ),
            array(
                '%d',
                '%d',
                '%s',
                '%s',
                '%d',
                '%d'
            )
        );

        wp_die();

    }

   public function mark_cart_ajax() {

    if ( empty( $_POST['product_id'] ) ) {
        return;
    }
    $cart_reminder = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );

    if ( ! $cart_reminder ) {
        return;
    }

    $product_id = absint( $_POST['product_id'] );

    $user = wp_get_current_user();

    if ( ! $user || ! $user->exists() ) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'wc_pvr_views';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table
             WHERE user_id = %d
             AND product_id = %d
             ORDER BY view_time DESC
             LIMIT 1",
            $user->ID,
            $product_id
        )
    );

    if ( $exists ) {

        $wpdb->update(
            $table,
            array(
                'added_to_cart' => 1,
                'cart_reminder_sent' => 0
            ),
            array( 'id' => $exists )
        );

    } else {

        $wpdb->insert(
            $table,
            array(
                'user_id' => $user->ID,
                'product_id' => $product_id,
                'email' => $user->user_email,
                'view_time' => current_time('mysql'),
                'added_to_cart' => 1,
                'purchased' => 0,
                'view_reminder_sent' => 0,
                'cart_reminder_sent' => 0,
            )
        );

    }
}


   public function mark_cart( $cart_item_key, $product_id ) {

    $user = wp_get_current_user();

    if ( ! $user || ! $user->exists() ) {
        return;
    }

    $cart_reminder = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );

    if ( ! $cart_reminder ) {
        return;
    }

    global $wpdb;

    $table = $wpdb->prefix . 'wc_pvr_views';

    $exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT id FROM $table
             WHERE user_id = %d
             AND product_id = %d
             ORDER BY view_time DESC
             LIMIT 1",
            $user->ID,
            $product_id
        )
    );

    if ( $exists ) {

        $wpdb->update(
            $table,
           array(
                'added_to_cart' => 1,
                'cart_reminder_sent' => 0
            ),
            array( 'id' => $exists )
        );

    } else {

        $wpdb->insert(
            $table,
            array(
        'user_id' => $user->ID,
        'product_id' => $product_id,
        'email' => $user->user_email,
        'view_time' => current_time('mysql'),
        'added_to_cart' => 1,
        'purchased' => 0,
        'view_reminder_sent' => 0,
        'cart_reminder_sent' => 0,
    )
        );

    }
}

    public function mark_purchase( $order_id ) {

        $order_id = absint( $order_id );

        if ( ! $order_id ) {
            return;
        }

        $order = wc_get_order( $order_id );

        if ( ! $order ) {
            return;
        }

        $user_id = $order->get_user_id();

        if ( ! $user_id ) {
            return;
        }

        global $wpdb;

        $table = $wpdb->prefix . 'wc_pvr_views';

        foreach ( $order->get_items() as $item ) {

            $product_id = absint( $item->get_product_id() );

            if ( ! $product_id ) {
                continue;
            }

            /**
             * Update only the most recent view record for that user
             */
            $wpdb->query(
                $wpdb->prepare(
                    "UPDATE $table 
                     SET purchased = 1 
                     WHERE user_id = %d 
                     AND product_id = %d 
                     ORDER BY view_time DESC 
                     LIMIT 1",
                    $user_id,
                    $product_id
                )
            );
        }
    }

}