<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PVR_Reminder {

    public function __construct() {

        add_action( 'wc_pvr_cron', array( $this, 'send_pvr_reminders' ) );

        $this->schedule();

    }

    public function schedule() {

        $interval = sanitize_text_field( get_option( 'wc_pvr_cron_interval', 'hourly' ) );

        if ( ! wp_next_scheduled( 'wc_pvr_cron' ) ) {

            wp_schedule_event( time(), $interval, 'wc_pvr_cron' );

        }

    }

    public function send_pvr_reminders() {

        global $wpdb;

        $table = $wpdb->prefix . 'wc_pvr_views';

        $cart_reminder = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );

        if ( $cart_reminder ) {

            $views = $wpdb->get_results(
                "SELECT * FROM {$table}
                WHERE purchased = 0
                AND (
                    (added_to_cart = 0 AND view_reminder_sent = 0)
                    OR
                    (added_to_cart = 1 AND cart_reminder_sent = 0)
                )"
            );

        } else {

            $views = $wpdb->get_results(
                "SELECT * FROM {$table}
                WHERE purchased = 0
                AND added_to_cart = 0
                AND view_reminder_sent = 0"
            );

        }
        if ( empty( $views ) ) {
            return;
        }

        foreach ( $views as $view ) {

            $product_id = absint( $view->product_id );

            if ( ! $product_id ) {
                continue;
            }

            $product = wc_get_product( $product_id );

            if ( ! $product ) {
                continue;
            }

            $email = sanitize_email( $view->email );

            if ( ! is_email( $email ) ) {
                continue;
            }

            $product_name = $product->get_name();
            $product_link = get_permalink( $product_id );

            $subject_template = get_option(
                'wc_pvr_email_subject',
                'You viewed {product_name}'
            );

            $cart_enabled = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
            if ( $view->added_to_cart && $cart_enabled  ) {

                $message_template = get_option(
                    'wc_pvr_cart_email_message',
                    ''
                );

            } else {

                $message_template = get_option(
                    'wc_pvr_email_message',
                    ''
                );

            }

            $subject = str_replace(
                '{product_name}',
                $product_name,
                sanitize_text_field( $subject_template )
            );

            $product_html_link = '<a href="' . esc_url( $product_link ) . '">' . esc_url( $product_link ) . '</a>';

            $message = str_replace(
                array( '{product_name}', '{product_link}', '{customer_name}' ),
                array( $product_name, $product_html_link, '' ),
                wp_kses_post( $message_template )
            );
           $headers = array('Content-Type: text/html; charset=UTF-8');

            wp_mail(
                $email,
                $subject,
                $message,
                $headers
            );

            if ( $view->added_to_cart ) {

                $wpdb->update(
                    $table,
                    array(
                        'cart_reminder_sent' => 1
                    ),
                    array(
                        'id' => absint( $view->id )
                    )
                );
            } else {

                $wpdb->update(
                    $table,
                    array(
                        'view_reminder_sent' => 1
                    ),
                    array(
                        'id' => absint( $view->id )
                    )
                );

            }

        }

    }

}