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
        add_action( 'woocommerce_order_status_processing', array( $this, 'mark_order_purchased' ) );
        add_action( 'woocommerce_order_status_completed', array( $this, 'mark_order_purchased' ) );
        add_action( 'woocommerce_payment_complete', array( $this, 'mark_order_purchased' ) );
        add_action( 'woocommerce_order_status_changed', array( $this, 'mark_order_status_changed' ), 20, 4 );
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

        $product_id = isset( $_POST['product_id'] ) ? $this->valid_product_id( wp_unslash( $_POST['product_id'] ) ) : 0;
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

    if ( ! isset( $_POST['product_id'] ) ) {
        return;
    }
    $cart_reminder = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );

    if ( ! $cart_reminder ) {
        return;
    }

    $product_id = $this->valid_product_id( wp_unslash( $_POST['product_id'] ) );
    if ( ! $product_id ) { return; }

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
                'cart_added_at' => current_time( 'mysql' ),
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
                'cart_added_at' => current_time( 'mysql' ),
                'added_to_cart' => 1,
                'purchased' => 0,
                'view_reminder_sent' => 0,
                'cart_reminder_sent' => 0,
            )
        );

    }
}


   public function mark_cart( $cart_item_key, $product_id ) {

    $product_id = $this->valid_product_id( $product_id );
    if ( ! $product_id ) { return; }

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
                'cart_added_at' => current_time( 'mysql' ),
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
        'cart_added_at' => current_time( 'mysql' ),
        'added_to_cart' => 1,
        'purchased' => 0,
        'view_reminder_sent' => 0,
        'cart_reminder_sent' => 0,
    )
        );

    }
}

    private function valid_product_id( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^[1-9][0-9]*$/', (string) $value ) ) { return 0; }
        $product_id = absint( $value );
        $product = $product_id ? wc_get_product( $product_id ) : false;
        if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) ) { return 0; }
        return $product_id;
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

        if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) || ! $order->is_paid() ) {
            return;
        }

        $user_id = $order->get_user_id();
        $email = sanitize_email( $order->get_billing_email() );
        if ( ! $user_id && ! is_email( $email ) ) { return; }

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
                    "UPDATE $table SET purchased = 1 WHERE product_id = %d AND ((user_id = %d AND %d > 0) OR (email = %s AND %d = 0))",
                    $product_id, absint( $user_id ), absint( $user_id ), $email, absint( $user_id )
                )
            );
        }
    }

    public function mark_order_purchased( $order_id ) { $this->mark_purchase( $order_id ); }

    public function mark_order_status_changed( $order_id, $old_status, $new_status, $order = null ) {
        unset( $old_status, $order );
        if ( in_array( sanitize_key( $new_status ), array( 'processing', 'completed' ), true ) ) {
            $this->mark_purchase( $order_id );
        }
    }

}
