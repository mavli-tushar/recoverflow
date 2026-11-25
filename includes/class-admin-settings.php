<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PVR_Admin_Settings {

    public function __construct() {

        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles_or_js' ) );
        add_action( 'admin_post_wc_pvr_send_reminder', array( $this, 'handle_manual_send' ) );
        add_action( 'admin_post_wc_pvr_send_test_email', array( $this, 'handle_test_email' ) );
        add_action( 'admin_post_wc_pvr_start_order_backfill', array( $this, 'handle_start_order_backfill' ) );
        add_action( 'admin_post_wc_pvr_record_revocation', array( $this, 'handle_record_revocation' ) );

    }

    public function admin_styles_or_js( $hook ) {

        if ( false === strpos( (string) $hook, 'wc-pvr' ) && false === strpos( (string) $hook, 'wc-pvr-' ) && false === strpos( (string) $hook, 'recoverflow_page' ) ) {
            return;
        }

        wp_enqueue_style(
            'wc-pvr-admin-style',
            WC_PVR_URL . 'assets/css/admin-style.css',
            array(),
            WC_PVR_VERSION
        );

        wp_enqueue_script(
            'wc-pvr-admin-js',
            WC_PVR_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            WC_PVR_VERSION,
            true
        );

        $next_run = wp_next_scheduled( 'wc_pvr_cron' );
        $remaining = $next_run ? ( $next_run - time() ) : 0;

        wp_localize_script(
            'wc-pvr-admin-js',
            'wc_pvr_data',
            array(
                'remaining' => (int) $remaining,
                'i18n'      => array(
                    'dueNow'    => __( 'A check is due now. Refresh to see the next one.', 'wc-product-view-reminder-lite' ),
                    'nextCheck' => __( 'Next check in: %m minutes %s seconds', 'wc-product-view-reminder-lite' ),
                ),
            )
        );
    }

    public function menu() {

        add_menu_page(
            __( 'RecoverFlow', 'wc-product-view-reminder-lite' ),
            __( 'RecoverFlow', 'wc-product-view-reminder-lite' ),
            'manage_options',
            'wc-pvr-dashboard',
            array( $this, 'dashboard_page' ),
            'dashicons-email-alt',
            56
        );

        add_submenu_page( 'wc-pvr-dashboard', __( 'Dashboard', 'wc-product-view-reminder-lite' ), __( 'Dashboard', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-dashboard', array( $this, 'dashboard_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Customer Reminders', 'wc-product-view-reminder-lite' ), __( 'Customer Reminders', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-reminders', array( $this, 'reminders_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Reminder History', 'wc-product-view-reminder-lite' ), __( 'Reminder History', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-history', array( $this, 'history_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Analytics', 'wc-product-view-reminder-lite' ), __( 'Analytics', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-analytics', array( $this, 'analytics_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Email Templates', 'wc-product-view-reminder-lite' ), __( 'Email Templates', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-templates', array( $this, 'templates_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Consent Management', 'wc-product-view-reminder-lite' ), __( 'Consent Management', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-consent', array( $this, 'consent_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'System Status', 'wc-product-view-reminder-lite' ), __( 'System Status', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-status', array( $this, 'system_status_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Settings', 'wc-product-view-reminder-lite' ), __( 'Settings', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr', array( $this, 'settings_page' ) );
        add_submenu_page( 'wc-pvr-dashboard', __( 'Help', 'wc-product-view-reminder-lite' ), __( 'Help', 'wc-product-view-reminder-lite' ), 'manage_options', 'wc-pvr-help', array( $this, 'help_page' ) );

    }

    public function handle_manual_send() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to send reminders.', 'wc-product-view-reminder-lite' ) ); }
        $view_id = isset( $_POST['view_id'] ) && is_scalar( $_POST['view_id'] ) ? absint( wp_unslash( $_POST['view_id'] ) ) : 0;
        check_admin_referer( 'wc_pvr_send_reminder_' . $view_id );
        if ( ! isset( $_POST['confirmed'] ) || ! is_scalar( $_POST['confirmed'] ) || '1' !== (string) wp_unslash( $_POST['confirmed'] ) ) {
            wp_safe_redirect( add_query_arg( array( 'page' => 'wc-pvr-reminders', 'wc_pvr_send_result' => 'failed' ), admin_url( 'admin.php' ) ) );
            exit;
        }
        $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
        $result = $service instanceof WC_PVR_Reminder ? $service->send_manual_reminder( $view_id, get_current_user_id() ) : new WP_Error( 'service_unavailable', __( 'Reminder service is unavailable.', 'wc-product-view-reminder-lite' ) );
        $status = is_wp_error( $result ) ? ( 'send_outcome_unknown' === $result->get_error_code() ? 'unknown' : 'failed' ) : 'accepted';
        $url = add_query_arg( array( 'page' => 'wc-pvr-reminders', 'wc_pvr_send_result' => $status ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_test_email() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to send a test email.', 'wc-product-view-reminder-lite' ) ); }
        check_admin_referer( 'wc_pvr_send_test_email' );
        $recipient = isset( $_POST['test_recipient'] ) && is_scalar( $_POST['test_recipient'] ) ? sanitize_email( wp_unslash( $_POST['test_recipient'] ) ) : '';
        $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
        $sent = $service instanceof WC_PVR_Reminder ? $service->send_local_test_email( $recipient ) : false;
        $url = add_query_arg( array( 'page' => 'wc-pvr-templates', 'wc_pvr_test_result' => $sent ? 'accepted' : 'blocked' ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    public function handle_start_order_backfill() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to start the order index.', 'wc-product-view-reminder-lite' ) ); }
        check_admin_referer( 'wc_pvr_start_order_backfill' );
        if ( ! WC_PVR_Consent::is_installed() ) { wp_die( esc_html__( 'Set up RecoverFlow storage before indexing WooCommerce orders.', 'wc-product-view-reminder-lite' ) ); }
        update_option( 'wc_pvr_orders_backfill_complete', 0, false );
        update_option( 'wc_pvr_orders_backfill_page', 1, false );
        if ( ! wp_next_scheduled( 'wc_pvr_order_backfill' ) ) { wp_schedule_single_event( time() + 5, 'wc_pvr_order_backfill' ); }
        wp_safe_redirect( add_query_arg( 'wc_pvr_backfill', 'started', admin_url( 'admin.php?page=wc-pvr' ) ) );
        exit;
    }

    private function views_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function render_page_header( $title, $description, $actions = array() ) {
        echo '<header class="wc-pvr-page-header"><div><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $description ) . '</p></div>';
        if ( $actions ) {
            echo '<div class="wc-pvr-page-actions">';
            foreach ( $actions as $action ) {
                $class = isset( $action['class'] ) ? $action['class'] : 'button button-secondary';
                echo '<a class="' . esc_attr( $class ) . '" href="' . esc_url( admin_url( 'admin.php?page=' . $action['page'] ) ) . '">' . esc_html( $action['label'] ) . '</a>';
            }
            echo '</div>';
        }
        echo '</header>';
    }

    private function status_badge( $label, $tone = 'neutral', $tooltip = '' ) {
        $allowed_tones = array( 'success', 'warning', 'danger', 'info', 'neutral' );
        if ( ! in_array( $tone, $allowed_tones, true ) ) { $tone = 'neutral'; }
        $title = '' !== $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '';
        return '<span class="wc-pvr-badge wc-pvr-badge--' . esc_attr( $tone ) . '"' . $title . '>' . esc_html( $label ) . '</span>';
    }

    private function display_datetime( $value ) {
        if ( ! is_scalar( $value ) || '' === (string) $value ) { return __( 'Not available', 'wc-product-view-reminder-lite' ); }
        try {
            $date = new DateTimeImmutable( (string) $value, wp_timezone() );
            return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $date->getTimestamp() );
        } catch ( Exception $exception ) {
            return (string) $value;
        }
    }

    private function machine_datetime( $value ) {
        if ( ! is_scalar( $value ) || '' === (string) $value ) { return ''; }
        try {
            return ( new DateTimeImmutable( (string) $value, wp_timezone() ) )->format( DateTimeInterface::ATOM );
        } catch ( Exception $exception ) {
            return '';
        }
    }

    private function customer_initials( $name, $email ) {
        $name = trim( (string) $name );
        if ( '' === $name && is_email( $email ) ) { $name = strtok( $email, '@' ); }
        $parts = preg_split( '/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY );
        $initials = '';
        if ( $parts ) {
            $first_initial = function_exists( 'mb_substr' ) ? mb_substr( $parts[0], 0, 1, 'UTF-8' ) : ( preg_match( '/^./u', $parts[0], $matches ) ? $matches[0] : substr( $parts[0], 0, 1 ) );
            $initials = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $first_initial, 'UTF-8' ) : strtoupper( $first_initial );
            if ( count( $parts ) > 1 ) {
                $last_part = end( $parts );
                $last_initial = function_exists( 'mb_substr' ) ? mb_substr( $last_part, 0, 1, 'UTF-8' ) : ( preg_match( '/^./u', $last_part, $matches ) ? $matches[0] : substr( $last_part, 0, 1 ) );
                $initials .= function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $last_initial, 'UTF-8' ) : strtoupper( $last_initial );
            }
        }
        return '' !== $initials ? $initials : 'C';
    }

    private function reminder_block_label( $error ) {
        if ( ! is_wp_error( $error ) ) { return __( 'Ready', 'wc-product-view-reminder-lite' ); }
        $labels = array(
            'already_sent' => __( 'Already sent', 'wc-product-view-reminder-lite' ),
            'already_purchased' => __( 'Purchased', 'wc-product-view-reminder-lite' ),
            'purchase_suppressed' => __( 'Already bought', 'wc-product-view-reminder-lite' ),
            'customer_opted_out' => __( 'Opted out', 'wc-product-view-reminder-lite' ),
            'consent_required' => __( 'Needs consent', 'wc-product-view-reminder-lite' ),
            'invalid_product' => __( 'Product unavailable', 'wc-product-view-reminder-lite' ),
            'invalid_email' => __( 'Email unavailable', 'wc-product-view-reminder-lite' ),
            'waiting_period' => __( 'Still waiting', 'wc-product-view-reminder-lite' ),
            'customer_frequency_limit' => __( 'Emailed recently', 'wc-product-view-reminder-lite' ),
            'customer_rate_limit' => __( 'Emailed recently', 'wc-product-view-reminder-lite' ),
            'frequency_limit' => __( 'Emailed recently', 'wc-product-view-reminder-lite' ),
            'retry_backoff' => __( 'Will retry later', 'wc-product-view-reminder-lite' ),
            'retry_limit_reached' => __( 'Too many failed tries', 'wc-product-view-reminder-lite' ),
            'send_outcome_unknown' => __( 'Check Reminder History', 'wc-product-view-reminder-lite' ),
            'purchase_index_building' => __( 'Purchase lookup in progress', 'wc-product-view-reminder-lite' ),
            'purchase_check_unavailable' => __( 'Couldn\'t check purchases', 'wc-product-view-reminder-lite' ),
            'cart_reminder_disabled' => __( 'Cart reminders disabled', 'wc-product-view-reminder-lite' ),
            'product_excluded' => __( 'Excluded product', 'wc-product-view-reminder-lite' ),
        );
        $code = $error->get_error_code();
        return isset( $labels[ $code ] ) ? $labels[ $code ] : __( 'Unavailable', 'wc-product-view-reminder-lite' );
    }

    /**
     * Compact vertical column chart for Dashboard / Analytics.
     *
     * @param array  $points Array of [ day => Y-m-d, total => int, segments => [ key => int ] ].
     * @param array  $series Map of segment key => label.
     * @param string $empty_message Message when every day is zero.
     * @param string $aria_label Accessible name for the chart.
     */
    private function render_activity_chart( $points, $series, $empty_message, $aria_label ) {
        $max = 1;
        $grand_total = 0;
        foreach ( (array) $points as $point ) {
            $total = isset( $point['total'] ) ? absint( $point['total'] ) : 0;
            $grand_total += $total;
            $max = max( $max, $total );
        }

        if ( 0 === $grand_total ) {
            echo '<div class="wc-pvr-chart-empty"><p>' . esc_html( $empty_message ) . '</p></div>';
            return;
        }

        if ( count( $series ) > 1 ) {
            echo '<div class="wc-pvr-chart-legend">';
            foreach ( $series as $key => $label ) {
                echo '<span><i class="wc-pvr-legend-dot wc-pvr-legend-dot--' . esc_attr( $key ) . '" aria-hidden="true"></i>' . esc_html( $label ) . '</span>';
            }
            echo '</div>';
        }

        echo '<div class="wc-pvr-column-chart" role="img" aria-label="' . esc_attr( $aria_label ) . '">';
        foreach ( (array) $points as $point ) {
            $day = isset( $point['day'] ) ? (string) $point['day'] : '';
            $total = isset( $point['total'] ) ? absint( $point['total'] ) : 0;
            $segments = isset( $point['segments'] ) && is_array( $point['segments'] ) ? $point['segments'] : array();
            $height = $total ? max( 10, (int) round( 100 * $total / $max ) ) : 0;
            try {
                $stamp = ( new DateTimeImmutable( $day . ' 12:00:00', wp_timezone() ) )->getTimestamp();
                $short = wp_date( 'j', $stamp );
                $weekday = wp_date( 'D', $stamp );
                $full = wp_date( get_option( 'date_format' ), $stamp );
            } catch ( Exception $exception ) {
                $short = $day;
                $weekday = '';
                $full = $day;
            }
            $tip_parts = array();
            foreach ( $series as $key => $label ) {
                $value = isset( $segments[ $key ] ) ? absint( $segments[ $key ] ) : ( 'views' === $key ? $total : 0 );
                if ( $value ) {
                    $tip_parts[] = $label . ': ' . number_format_i18n( $value );
                }
            }
            $title = trim( $full . ( $tip_parts ? ' — ' . implode( ', ', $tip_parts ) : '' ) );
            echo '<div class="wc-pvr-column' . ( $total ? '' : ' wc-pvr-column--empty' ) . '" title="' . esc_attr( $title ) . '">';
            echo '<span class="wc-pvr-column-value">' . ( $total ? esc_html( number_format_i18n( $total ) ) : '&nbsp;' ) . '</span>';
            echo '<span class="wc-pvr-column-bar-wrap"><span class="wc-pvr-column-bar" style="height:' . esc_attr( $height ) . '%">';
            if ( $total && count( $series ) > 1 ) {
                foreach ( $series as $key => $label ) {
                    $value = isset( $segments[ $key ] ) ? absint( $segments[ $key ] ) : 0;
                    if ( ! $value ) { continue; }
                    echo '<span class="wc-pvr-column-segment wc-pvr-column-segment--' . esc_attr( $key ) . '" style="flex:' . esc_attr( $value ) . ' 1 0"></span>';
                }
            } elseif ( $total ) {
                echo '<span class="wc-pvr-column-segment wc-pvr-column-segment--views" style="flex:1"></span>';
            }
            echo '</span></span>';
            echo '<span class="wc-pvr-column-label"><strong>' . esc_html( $short ) . '</strong><small>' . esc_html( $weekday ) . '</small></span>';
            echo '</div>';
        }
        echo '</div>';
    }

    /** Collapse daily points into weekly buckets when a range is too wide for readable columns. */
    private function maybe_bucket_chart_points( $points, $days ) {
        if ( $days <= 31 || empty( $points ) ) {
            return array( $points, false );
        }
        $buckets = array();
        foreach ( (array) $points as $point ) {
            try {
                $date = new DateTimeImmutable( $point['day'] . ' 12:00:00', wp_timezone() );
            } catch ( Exception $exception ) {
                continue;
            }
            $week_key = $date->modify( 'monday this week' )->format( 'Y-m-d' );
            if ( ! isset( $buckets[ $week_key ] ) ) {
                $buckets[ $week_key ] = array(
                    'day' => $week_key,
                    'total' => 0,
                    'segments' => array(),
                );
            }
            $buckets[ $week_key ]['total'] += absint( $point['total'] );
            foreach ( (array) $point['segments'] as $key => $value ) {
                if ( ! isset( $buckets[ $week_key ]['segments'][ $key ] ) ) {
                    $buckets[ $week_key ]['segments'][ $key ] = 0;
                }
                $buckets[ $week_key ]['segments'][ $key ] += absint( $value );
            }
        }
        return array( array_values( $buckets ), true );
    }

    public function dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header(
            __( 'RecoverFlow Dashboard', 'wc-product-view-reminder-lite' ),
            __( 'A quick look at recent product views, reminder activity and setup.', 'wc-product-view-reminder-lite' ),
            array(
                array( 'page' => 'wc-pvr-reminders', 'label' => __( 'Customer Reminders', 'wc-product-view-reminder-lite' ), 'class' => 'button button-primary' ),
                array( 'page' => 'wc-pvr-analytics', 'label' => __( 'Analytics', 'wc-product-view-reminder-lite' ) ),
                array( 'page' => 'wc-pvr-templates', 'label' => __( 'Email Templates', 'wc-product-view-reminder-lite' ) ),
            )
        );
        if ( ! $this->views_table_exists() ) {
            echo '<div class="wc-pvr-empty"><h2>' . esc_html__( 'Tracking storage is not ready', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'Finish the RecoverFlow storage setup in Settings to start seeing activity.', 'wc-product-view-reminder-lite' ) . '</p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=wc-pvr' ) ) . '">' . esc_html__( 'Open Settings', 'wc-product-view-reminder-lite' ) . '</a></div></div>';
            return;
        }
        $range_start = current_datetime()->modify( '-29 days' )->setTime( 0, 0, 0 );
        $range_end = current_datetime()->modify( '+1 day' )->setTime( 0, 0, 0 );
        $range_from = $range_start->format( 'Y-m-d H:i:s' );
        $range_to = $range_end->format( 'Y-m-d H:i:s' );
        $history_table = WC_PVR_Consent::history_table_name();
        $history_ready = $this->history_table_exists();
        $stats = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total_views, COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS identified_customers, SUM(purchased = 1) AS purchased FROM {$table} WHERE view_time >= %s AND view_time < %s", $range_from, $range_to ) );
        $delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
        $cutoff = current_datetime()->modify( '-' . $delay . ' hours' )->format( 'Y-m-d H:i:s' );
        $cart_enabled = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
        $pending = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE purchased = 0 AND ((added_to_cart = 0 AND view_reminder_sent = 0 AND view_time <= %s) OR (added_to_cart = 1 AND cart_reminder_sent = 0 AND %d = 1 AND COALESCE(cart_added_at,view_time) <= %s))", $cutoff, $cart_enabled, $cutoff ) ) );
        $accepted = $history_ready ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE result = 'accepted' AND attempted_at >= %s AND attempted_at < %s", $range_from, $range_to ) ) ) : 0;
        $failed = $history_ready ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE result = 'failed' AND attempted_at >= %s AND attempted_at < %s", $range_from, $range_to ) ) ) : 0;
        $cards = array(
            array( 'label' => __( 'Product views · 30 days', 'wc-product-view-reminder-lite' ), 'value' => absint( $stats->total_views ), 'tone' => 'blue' ),
            array( 'label' => __( 'Identified customers', 'wc-product-view-reminder-lite' ), 'value' => absint( $stats->identified_customers ), 'tone' => 'violet' ),
            array( 'label' => __( 'Ready to send', 'wc-product-view-reminder-lite' ), 'value' => $pending, 'tone' => 'amber' ),
            array( 'label' => __( 'Reminders sent · 30 days', 'wc-product-view-reminder-lite' ), 'value' => $accepted, 'tone' => 'green' ),
            array( 'label' => __( 'Failed attempts · 30 days', 'wc-product-view-reminder-lite' ), 'value' => $failed, 'tone' => 'red' ),
        );
        echo '<div class="wc-pvr-dashboard-grid">';
        foreach ( $cards as $card ) {
            echo '<section class="wc-pvr-stat-card wc-pvr-stat-card--' . esc_attr( $card['tone'] ) . '"><h2>' . esc_html( $card['label'] ) . '</h2><p>' . esc_html( number_format_i18n( $card['value'] ) ) . '</p></section>';
        }

        echo '</div><p class="wc-pvr-note">' . esc_html__( '"Ready to send" counts customers who have passed the waiting period. Consent and purchase checks still decide whether an email goes out. "Sent" doesn\'t confirm the email reached the inbox.', 'wc-product-view-reminder-lite' ) . '</p>';

        $chart_start = current_datetime()->setTime( 0, 0, 0 )->modify( '-13 days' );
        $chart_end = current_datetime()->modify( '+1 day' )->setTime( 0, 0, 0 );
        $chart_from = $chart_start->format( 'Y-m-d H:i:s' );
        $chart_to = $chart_end->format( 'Y-m-d H:i:s' );
        $daily_views = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(view_time) AS day,COUNT(*) AS total FROM {$table} WHERE view_time >= %s AND view_time < %s GROUP BY DATE(view_time) ORDER BY day ASC", $chart_from, $chart_to ) );
        $daily_view_map = array();
        foreach ( (array) $daily_views as $daily ) { $daily_view_map[ $daily->day ] = absint( $daily->total ); }
        $daily_attempt_map = array();
        if ( $history_ready ) {
            $daily_attempts = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(attempted_at) AS day,SUM(result = 'accepted') AS accepted,SUM(result = 'failed') AS failed,SUM(result = 'blocked') AS blocked FROM {$history_table} WHERE attempted_at >= %s AND attempted_at < %s GROUP BY DATE(attempted_at) ORDER BY day ASC", $chart_from, $chart_to ) );
            foreach ( (array) $daily_attempts as $daily ) { $daily_attempt_map[ $daily->day ] = array( 'accepted' => absint( $daily->accepted ), 'failed' => absint( $daily->failed ), 'blocked' => absint( $daily->blocked ) ); }
        }
        $chart_days = array();
        $view_max = 1;
        $attempt_max = 1;
        for ( $index = 0; $index < 14; $index++ ) {
            $day = $chart_start->modify( '+' . $index . ' days' )->format( 'Y-m-d' );
            $attempt = isset( $daily_attempt_map[ $day ] ) ? $daily_attempt_map[ $day ] : array( 'accepted' => 0, 'failed' => 0, 'blocked' => 0 );
            $chart_days[] = array( 'day' => $day, 'views' => isset( $daily_view_map[ $day ] ) ? $daily_view_map[ $day ] : 0, 'attempts' => $attempt );
            $view_max = max( $view_max, $chart_days[ $index ]['views'] );
            $attempt_max = max( $attempt_max, array_sum( $attempt ) );
        }
        $view_points = array();
        $attempt_points = array();
        foreach ( $chart_days as $point ) {
            $view_points[] = array(
                'day' => $point['day'],
                'total' => absint( $point['views'] ),
                'segments' => array( 'views' => absint( $point['views'] ) ),
            );
            $attempt_total = array_sum( $point['attempts'] );
            $attempt_points[] = array(
                'day' => $point['day'],
                'total' => $attempt_total,
                'segments' => array(
                    'accepted' => absint( $point['attempts']['accepted'] ),
                    'failed' => absint( $point['attempts']['failed'] ),
                    'blocked' => absint( $point['attempts']['blocked'] ),
                ),
            );
        }
        unset( $view_max, $attempt_max );
        echo '<div class="wc-pvr-dashboard-grid wc-pvr-dashboard-grid--charts">';
        echo '<section class="wc-pvr-card wc-pvr-chart-card"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Product interest', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'How many product pages customers viewed over the last 2 weeks.', 'wc-product-view-reminder-lite' ) . '</p></div></div>';
        $this->render_activity_chart(
            $view_points,
            array( 'views' => __( 'Views', 'wc-product-view-reminder-lite' ) ),
            __( 'No product views yet in the last 2 weeks. Views appear after a signed-in customer stays on a product page long enough.', 'wc-product-view-reminder-lite' ),
            __( 'Product views for the last 14 days', 'wc-product-view-reminder-lite' )
        );
        echo '</section>';
        echo '<section class="wc-pvr-card wc-pvr-chart-card"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Reminder results', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'How reminder emails went over the last 2 weeks.', 'wc-product-view-reminder-lite' ) . '</p></div></div>';
        $this->render_activity_chart(
            $attempt_points,
            array(
                'accepted' => __( 'Sent', 'wc-product-view-reminder-lite' ),
                'failed' => __( 'Failed', 'wc-product-view-reminder-lite' ),
                'blocked' => __( 'Blocked', 'wc-product-view-reminder-lite' ),
            ),
            __( 'No reminders were tried in the last 2 weeks yet. Once the waiting period ends and a customer is eligible, results will show here.', 'wc-product-view-reminder-lite' ),
            __( 'Reminder results for the last 14 days', 'wc-product-view-reminder-lite' )
        );
        echo '</section></div>';

        $lookup_ready = (bool) get_option( 'wc_pvr_orders_backfill_complete', 0 );
        $ledger_ready = WC_PVR_Consent::is_installed();
        $next_cron = wp_next_scheduled( 'wc_pvr_cron' );
        $last_cron = get_option( 'wc_pvr_last_cron_completed_at', '' );
        echo '<section class="wc-pvr-card wc-pvr-queue-health"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Queue and setup health', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'A quick check of the systems that must be ready before a reminder can be sent.', 'wc-product-view-reminder-lite' ) . '</p></div><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=wc-pvr-status' ) ) . '">' . esc_html__( 'View System Status', 'wc-product-view-reminder-lite' ) . '</a></div><div class="wc-pvr-health-grid"><div><span>' . esc_html__( 'Consent storage', 'wc-product-view-reminder-lite' ) . '</span>' . $this->status_badge( $ledger_ready ? __( 'Ready', 'wc-product-view-reminder-lite' ) : __( 'Setup required', 'wc-product-view-reminder-lite' ), $ledger_ready ? 'success' : 'warning' ) . '</div><div><span>' . esc_html__( 'Purchase lookup', 'wc-product-view-reminder-lite' ) . '</span>' . $this->status_badge( $lookup_ready ? __( 'Complete', 'wc-product-view-reminder-lite' ) : __( 'Building or not started', 'wc-product-view-reminder-lite' ), $lookup_ready ? 'success' : 'warning' ) . '</div><div><span>' . esc_html__( 'Reminder check', 'wc-product-view-reminder-lite' ) . '</span>' . $this->status_badge( $next_cron ? __( 'Scheduled', 'wc-product-view-reminder-lite' ) : __( 'Not scheduled', 'wc-product-view-reminder-lite' ), $next_cron ? 'info' : 'warning', $next_cron ? $this->display_datetime( wp_date( 'Y-m-d H:i:s', $next_cron ) ) : '' ) . '</div><div><span>' . esc_html__( 'Last completed run', 'wc-product-view-reminder-lite' ) . '</span><strong>' . esc_html( $last_cron ? $this->display_datetime( $last_cron ) : __( 'None recorded', 'wc-product-view-reminder-lite' ) ) . '</strong></div></div><p class="wc-pvr-note">' . esc_html__( 'A scheduled check doesn\'t prove it has run. “Ready to send” is counted before each customer\'s consent and purchase checks.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section class="wc-pvr-card"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Recent reminder activity', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'Latest reminder attempts. “Sent” means your site handed the email to its mail service, not that it was delivered.', 'wc-product-view-reminder-lite' ) . '</p></div><a class="button button-secondary" href="' . esc_url( admin_url( 'admin.php?page=wc-pvr-history' ) ) . '">' . esc_html__( 'Open Reminder History', 'wc-product-view-reminder-lite' ) . '</a></div>';
        $recent = $history_ready ? $wpdb->get_results( "SELECT id,attempted_at,product_id,reminder_type,trigger_type,result,failure_code,retry_count FROM {$history_table} ORDER BY attempted_at DESC,id DESC LIMIT 6" ) : array();
        if ( empty( $recent ) ) {
            echo '<div class="wc-pvr-empty wc-pvr-empty--compact"><p>' . esc_html__( 'No reminder attempts have been recorded yet.', 'wc-product-view-reminder-lite' ) . '</p></div>';
        } else {
            echo '<div class="wc-pvr-recent-list">';
            foreach ( $recent as $attempt ) {
                $product = wc_get_product( absint( $attempt->product_id ) );
                $result_text = 'accepted' === $attempt->result ? __( 'Sent', 'wc-product-view-reminder-lite' ) : ( 'failed' === $attempt->result ? __( 'Failed', 'wc-product-view-reminder-lite' ) : ( 'blocked' === $attempt->result ? __( 'Blocked', 'wc-product-view-reminder-lite' ) : __( 'Pending review', 'wc-product-view-reminder-lite' ) ) );
                $tone = 'accepted' === $attempt->result ? 'success' : ( 'failed' === $attempt->result ? 'danger' : ( 'blocked' === $attempt->result ? 'warning' : 'info' ) );
                echo '<article class="wc-pvr-recent-item"><div class="wc-pvr-recent-product">' . ( $product ? wp_kses_post( $product->get_image( array( 40, 40 ) ) ) : '<span class="wc-pvr-product-placeholder" aria-hidden="true">—</span>' ) . '<div><strong>' . esc_html( $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $attempt->product_id ) ) ) . '</strong><span>' . esc_html( ucfirst( $attempt->reminder_type ) . ' · ' . ucfirst( $attempt->trigger_type ) ) . '</span></div></div><div class="wc-pvr-recent-meta">' . $this->status_badge( $result_text, $tone ) . '<time datetime="' . esc_attr( $this->machine_datetime( $attempt->attempted_at ) ) . '">' . esc_html( $this->display_datetime( $attempt->attempted_at ) ) . '</time>';
                if ( $attempt->failure_code || 'pending' === $attempt->result ) {
                    echo '<details><summary>' . esc_html__( 'Details', 'wc-product-view-reminder-lite' ) . '</summary><p>' . esc_html( 'pending' === $attempt->result ? __( 'We don\'t know whether this email went out. Check Reminder History before trying again.', 'wc-product-view-reminder-lite' ) : $this->failure_reason_label( $attempt->failure_code ) ) . '</p></details>';
                }
                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section></div>';
    }

    public function reminders_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'Customer Reminders', 'wc-product-view-reminder-lite' ), __( 'Check each customer\'s consent, purchases and reminder timing before sending.', 'wc-product-view-reminder-lite' ), array( array( 'page' => 'wc-pvr-history', 'label' => __( 'Reminder History', 'wc-product-view-reminder-lite' ) ) ) );
        if ( ! $this->views_table_exists() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tracking storage is not available.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        if ( isset( $_GET['wc_pvr_send_result'] ) ) {
            $send_result = isset( $_GET['wc_pvr_send_result'] ) && is_scalar( $_GET['wc_pvr_send_result'] ) ? sanitize_key( wp_unslash( $_GET['wc_pvr_send_result'] ) ) : '';
            $accepted = 'accepted' === $send_result;
            $unknown = 'unknown' === $send_result;
            $notice_class = $accepted ? 'notice-success' : ( $unknown ? 'notice-warning' : 'notice-error' );
            $notice_text = $accepted ? __( 'Reminder sent. We can\'t confirm it reached the customer\'s inbox.', 'wc-product-view-reminder-lite' ) : ( $unknown ? __( 'The email may have been sent, but the result wasn\'t saved. Check Reminder History and don\'t try again until you know the outcome.', 'wc-product-view-reminder-lite' ) : __( 'The reminder wasn\'t sent. Open Reminder History to see why.', 'wc-product-view-reminder-lite' ) );
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $notice_text ) . '</p></div>';
        }
        $per_page = 20;
        $paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
        $search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $view_filter = isset( $_GET['view_status'] ) && is_scalar( $_GET['view_status'] ) ? sanitize_key( wp_unslash( $_GET['view_status'] ) ) : '';
        if ( ! in_array( $view_filter, array( '', 'pending', 'sent', 'purchased', 'cart', 'product' ), true ) ) { $view_filter = ''; }
        $where_parts = array();
        $args = array();
        if ( '' !== $search ) { $where_parts[] = '(email LIKE %s OR CAST(user_id AS CHAR) = %s)'; $args[] = '%' . $wpdb->esc_like( $search ) . '%'; $args[] = $search; }
        if ( 'pending' === $view_filter ) { $where_parts[] = 'purchased = 0 AND view_reminder_sent = 0 AND cart_reminder_sent = 0'; }
        elseif ( 'sent' === $view_filter ) { $where_parts[] = '(view_reminder_sent = 1 OR cart_reminder_sent = 1)'; }
        elseif ( 'purchased' === $view_filter ) { $where_parts[] = 'purchased = 1'; }
        elseif ( 'cart' === $view_filter ) { $where_parts[] = 'added_to_cart = 1'; }
        elseif ( 'product' === $view_filter ) { $where_parts[] = 'added_to_cart = 0'; }
        $where = $where_parts ? ' WHERE ' . implode( ' AND ', $where_parts ) : '';
        $total_sql = "SELECT COUNT(*) FROM {$table}{$where}";
        $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( $total_sql, $args ) ) : $wpdb->get_var( $total_sql ) );
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = ( $paged - 1 ) * $per_page;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY view_time DESC,id DESC LIMIT %d OFFSET %d", $query_args ) );

        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-reminders"><label for="wc-pvr-customer-search">' . esc_html__( 'Search email or customer ID', 'wc-product-view-reminder-lite' ) . ' <input id="wc-pvr-customer-search" type="search" name="s" value="' . esc_attr( $search ) . '"></label><label for="wc-pvr-reminder-filter">' . esc_html__( 'Record type or status', 'wc-product-view-reminder-lite' ) . ' <select id="wc-pvr-reminder-filter" name="view_status"><option value="">' . esc_html__( 'All records', 'wc-product-view-reminder-lite' ) . '</option>';
        foreach ( array( 'pending' => __( 'Open records', 'wc-product-view-reminder-lite' ), 'sent' => __( 'Reminder sent', 'wc-product-view-reminder-lite' ), 'purchased' => __( 'Purchased', 'wc-product-view-reminder-lite' ), 'cart' => __( 'Cart records', 'wc-product-view-reminder-lite' ), 'product' => __( 'Product view records', 'wc-product-view-reminder-lite' ) ) as $filter_value => $filter_label ) { echo '<option value="' . esc_attr( $filter_value ) . '" ' . selected( $view_filter, $filter_value, false ) . '>' . esc_html( $filter_label ) . '</option>'; }
        echo '</select></label><button class="button button-primary">' . esc_html__( 'Apply filters', 'wc-product-view-reminder-lite' ) . '</button></form>';
        if ( empty( $rows ) ) { echo '<div class="wc-pvr-empty"><h2>' . esc_html__( 'No reminder records found', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( $search || $view_filter ? __( 'Nothing matches your search or filter. Try different search text or choose "All records".', 'wc-product-view-reminder-lite' ) : __( 'Nothing here yet. Records appear once a logged-in customer views a product.', 'wc-product-view-reminder-lite' ) ) . '</p></div></div>'; return; }

        echo '<div class="wc-pvr-table-wrap"><table class="widefat striped wc-pvr-reminders-table"><thead><tr><th>' . esc_html__( 'Customer', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Product', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Last viewed', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Consent', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Eligibility', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Scheduled', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Reminder status', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Purchase', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Actions', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $product = wc_get_product( absint( $row->product_id ) );
            $user = $row->user_id ? get_userdata( absint( $row->user_id ) ) : false;
            $email = sanitize_email( $row->email );
            $consent = WC_PVR_Consent::status_for_email( $email );
            $external_ok = is_email( $email ) && (bool) apply_filters( 'wc_pvr_customer_is_eligible_for_marketing', false, absint( $row->user_id ), $email, $row );
            $ledger_opted_out = 'opted_out' === $consent;
            $integration_opted_out = is_email( $email ) && (bool) apply_filters( 'wc_pvr_customer_has_marketing_opted_out', $ledger_opted_out, absint( $row->user_id ), $email, $row );
            $opted_out = is_email( $email ) && ( $ledger_opted_out || $integration_opted_out );
            $eligible = ! $opted_out && ( 'opted_in' === $consent || $external_ok );
            $delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
            try {
                $waiting_start = ! empty( $row->added_to_cart ) && ! empty( $row->cart_added_at ) ? $row->cart_added_at : $row->view_time;
                $scheduled_at = ( new DateTimeImmutable( $waiting_start, wp_timezone() ) )->modify( '+' . $delay . ' hours' );
                $scheduled = $scheduled_at->format( 'Y-m-d H:i:s' );
                $is_waiting = $scheduled_at > current_datetime();
            } catch ( Exception $exception ) {
                $scheduled = '';
                $is_waiting = false;
            }
            if ( $row->purchased ) { $status = __( 'Purchased', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Suppressed', 'wc-product-view-reminder-lite' ); }
            elseif ( ! $product ) { $status = __( 'Invalid product', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Unavailable', 'wc-product-view-reminder-lite' ); }
            elseif ( ! is_email( $email ) ) { $status = __( 'Invalid email', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $opted_out ) { $status = __( 'Suppressed: opted out', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( ! $eligible ) { $status = __( 'Consent required', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $row->view_reminder_sent || $row->cart_reminder_sent ) { $status = __( 'Reminder sent', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $is_waiting ) { $status = __( 'Waiting period', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible; waiting', 'wc-product-view-reminder-lite' ); }
            else { $status = __( 'Pending', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible', 'wc-product-view-reminder-lite' ); }
            $customer = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
            if ( '' === $customer && $user && 0 !== strcasecmp( trim( $user->display_name ), $email ) ) { $customer = $user->display_name; }
            $consent_label = 'opted_in' === $consent ? __( 'Opted in', 'wc-product-view-reminder-lite' ) : ( 'opted_out' === $consent || $opted_out ? __( 'Opted out', 'wc-product-view-reminder-lite' ) : ( $external_ok ? __( 'Allowed by consent tool', 'wc-product-view-reminder-lite' ) : __( 'No consent', 'wc-product-view-reminder-lite' ) ) );
            $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
            $manual_allowed = $service instanceof WC_PVR_Reminder ? $service->can_send( $row, true, false ) : new WP_Error( 'service_unavailable', __( 'Reminder service is unavailable.', 'wc-product-view-reminder-lite' ) );
            if ( is_wp_error( $manual_allowed ) && ! in_array( $manual_allowed->get_error_code(), array( 'already_sent', 'waiting_period' ), true ) && $eligible && $product && ! $row->purchased ) {
                $eligibility_label = $this->reminder_block_label( $manual_allowed );
                $status = $eligibility_label;
            }
            $purchase_blocked = is_wp_error( $manual_allowed ) && in_array( $manual_allowed->get_error_code(), array( 'purchase_suppressed', 'already_purchased' ), true );
            $status_tone = ( $row->purchased || ! $product || ! is_email( $email ) || $opted_out || $purchase_blocked ) ? 'danger' : ( ( $row->view_reminder_sent || $row->cart_reminder_sent ) ? 'success' : ( $is_waiting || ! $eligible || is_wp_error( $manual_allowed ) ? 'warning' : 'info' ) );
            $eligibility_tone = ( $row->purchased || ! $product || $opted_out || $purchase_blocked ) ? 'danger' : ( $is_waiting || ! $eligible || is_wp_error( $manual_allowed ) ? 'warning' : 'success' );
            $consent_tone = $opted_out ? 'danger' : ( $eligible ? 'success' : 'warning' );
            echo '<tr><td><div class="wc-pvr-customer-cell"><span class="wc-pvr-avatar" aria-hidden="true">' . esc_html( $this->customer_initials( $customer, $email ) ) . '</span><div>';
            if ( '' !== $customer ) { echo '<strong>' . esc_html( $customer ) . '</strong>'; }
            echo '<code>' . esc_html( $email ) . '</code>';
            if ( $user ) { echo '<small>' . esc_html( sprintf( __( 'Customer #%d', 'wc-product-view-reminder-lite' ), absint( $user->ID ) ) ) . '</small>'; }
            else { echo '<small>' . esc_html__( 'Tracked email', 'wc-product-view-reminder-lite' ) . '</small>'; }
            echo '</div></div></td><td><div class="wc-pvr-product-cell">';
            if ( $product ) { echo wp_kses_post( $product->get_image( array( 48, 48 ) ) ) . '<div><a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a><small>' . wp_kses_post( wc_price( $product->get_price() ) ) . '</small></div>'; }
            else { echo esc_html__( 'Product unavailable', 'wc-product-view-reminder-lite' ); }
            echo '</div></td><td><time datetime="' . esc_attr( $this->machine_datetime( $row->view_time ) ) . '">' . esc_html( $this->display_datetime( $row->view_time ) ) . '</time></td><td>' . $this->status_badge( $consent_label, $consent_tone ) . '</td><td>' . $this->status_badge( $eligibility_label, $eligibility_tone, $opted_out ? __( 'This customer opted out, so they won\'t receive reminders.', 'wc-product-view-reminder-lite' ) : '' ) . '</td><td>' . ( $scheduled ? '<time datetime="' . esc_attr( $this->machine_datetime( $scheduled ) ) . '">' . esc_html( $this->display_datetime( $scheduled ) ) . '</time>' : esc_html__( 'Unavailable', 'wc-product-view-reminder-lite' ) ) . '</td><td>' . $this->status_badge( $status, $status_tone ) . '</td><td>' . $this->status_badge( $row->purchased ? __( 'Purchased', 'wc-product-view-reminder-lite' ) : __( 'No purchase recorded', 'wc-product-view-reminder-lite' ), $row->purchased ? 'danger' : 'neutral' ) . '</td><td><div class="wc-pvr-row-actions">';
            echo '<details class="wc-pvr-row-details"><summary class="button button-secondary">' . esc_html__( 'View details', 'wc-product-view-reminder-lite' ) . '</summary><div><p><strong>' . esc_html__( 'Current eligibility:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( is_wp_error( $manual_allowed ) ? $manual_allowed->get_error_message() : __( 'Ready for a manual send, subject to a final check.', 'wc-product-view-reminder-lite' ) ) . '</p><p><strong>' . esc_html__( 'Consent:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( $consent_label ) . '</p><p><strong>' . esc_html__( 'Reminder type:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( ! empty( $row->added_to_cart ) ? __( 'Cart reminder', 'wc-product-view-reminder-lite' ) : __( 'Product view reminder', 'wc-product-view-reminder-lite' ) ) . '</p></div></details>';
            if ( ! $row->view_reminder_sent && ! $row->cart_reminder_sent && ! is_wp_error( $manual_allowed ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wc-pvr-manual-send">';
                echo '<input type="hidden" name="action" value="wc_pvr_send_reminder"><input type="hidden" name="view_id" value="' . esc_attr( absint( $row->id ) ) . '"><input type="hidden" name="confirmed" value="0">';
                wp_nonce_field( 'wc_pvr_send_reminder_' . absint( $row->id ) );
                echo '<button type="submit" class="button button-primary" data-confirm="' . esc_attr__( 'Send this reminder now? We\'ll check permission and purchases again first.', 'wc-product-view-reminder-lite' ) . '" data-sending-label="' . esc_attr__( 'Sending…', 'wc-product-view-reminder-lite' ) . '">' . esc_html__( 'Send Reminder', 'wc-product-view-reminder-lite' ) . '</button></form>';
            } else {
                $reason = is_wp_error( $manual_allowed ) ? $manual_allowed->get_error_message() : __( 'This reminder was already sent.', 'wc-product-view-reminder-lite' );
                echo '<button type="button" class="button" disabled aria-describedby="wc-pvr-block-' . esc_attr( absint( $row->id ) ) . '">' . esc_html__( 'Send Reminder', 'wc-product-view-reminder-lite' ) . '</button><small class="wc-pvr-action-reason" id="wc-pvr-block-' . esc_attr( absint( $row->id ) ) . '">' . esc_html( $reason ) . '</small>';
            }
            echo '</div></td></tr>';
        }
        echo '</tbody></table></div>';
        $pages = (int) ceil( $total / $per_page );
        if ( $pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'page' => 'wc-pvr-reminders', 'paged' => '%#%', 's' => $search, 'view_status' => $view_filter ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>'; }
        echo '<p class="wc-pvr-note">' . esc_html__( 'A manual send can skip the waiting period, but consent, opt-outs, product availability, purchase checks, duplicate prevention and frequency limits still apply. "Sent" doesn\'t confirm the email reached the inbox.', 'wc-product-view-reminder-lite' ) . '</p></div>';
    }
    private function history_table_exists() {
        global $wpdb;
        $table = WC_PVR_Consent::history_table_name();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function safe_date_filter( $key, $fallback ) {
        $value = isset( $_GET[ $key ] ) && is_scalar( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return $fallback; }
        $parts = array_map( 'intval', explode( '-', $value ) );
        return checkdate( $parts[1], $parts[2], $parts[0] ) ? $value : $fallback;
    }

    public function history_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $history = WC_PVR_Consent::history_table_name();
        $views = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'Reminder History', 'wc-product-view-reminder-lite' ), __( 'See every reminder attempt, what happened, and why one was blocked.', 'wc-product-view-reminder-lite' ), array( array( 'page' => 'wc-pvr-reminders', 'label' => __( 'Customer Reminders', 'wc-product-view-reminder-lite' ) ), array( 'page' => 'wc-pvr-analytics', 'label' => __( 'Analytics', 'wc-product-view-reminder-lite' ) ) ) );
        if ( ! $this->history_table_exists() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Reminder history isn\'t set up yet. Finish the RecoverFlow storage setup in Settings.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        $today = current_datetime()->format( 'Y-m-d' );
        $from = $this->safe_date_filter( 'date_from', current_datetime()->modify( '-30 days' )->format( 'Y-m-d' ) );
        $to = $this->safe_date_filter( 'date_to', $today );
        if ( $from > $to ) { $from = $to; }
        $status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        if ( ! in_array( $status, array( '', 'accepted', 'failed', 'blocked', 'pending' ), true ) ) { $status = ''; }
        $trigger = isset( $_GET['trigger'] ) && is_scalar( $_GET['trigger'] ) ? sanitize_key( wp_unslash( $_GET['trigger'] ) ) : '';
        if ( ! in_array( $trigger, array( '', 'automatic', 'manual' ), true ) ) { $trigger = ''; }
        $search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $where = array( 'h.attempted_at >= %s', 'h.attempted_at < %s' );
        $to_exclusive = ( new DateTimeImmutable( $to . ' 00:00:00', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
        $args = array( $from . ' 00:00:00', $to_exclusive );
        if ( $status ) { $where[] = 'h.result = %s'; $args[] = $status; }
        if ( $trigger ) { $where[] = 'h.trigger_type = %s'; $args[] = $trigger; }
        if ( '' !== $search ) { $where[] = '(v.email LIKE %s OR CAST(h.user_id AS CHAR) = %s)'; $args[] = '%' . $wpdb->esc_like( $search ) . '%'; $args[] = $search; }
        $where_sql = ' WHERE ' . implode( ' AND ', $where );
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} h LEFT JOIN {$views} v ON v.id = h.view_id{$where_sql}", $args ) );
        $per_page = 25;
        $paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
        $offset = ( $paged - 1 ) * $per_page;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT h.*,v.email FROM {$history} h LEFT JOIN {$views} v ON v.id = h.view_id{$where_sql} ORDER BY h.attempted_at DESC,h.id DESC LIMIT %d OFFSET %d", $query_args ) );
        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-history"><label>' . esc_html__( 'Search email or user ID', 'wc-product-view-reminder-lite' ) . ' <input type="search" name="s" value="' . esc_attr( $search ) . '"></label> <label>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option>';
        foreach ( array( 'accepted' => __( 'Reminder sent', 'wc-product-view-reminder-lite' ), 'failed' => __( 'Failed', 'wc-product-view-reminder-lite' ), 'blocked' => __( 'Blocked before send', 'wc-product-view-reminder-lite' ), 'pending' => __( 'Outcome unknown', 'wc-product-view-reminder-lite' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label> <label>' . esc_html__( 'Trigger', 'wc-product-view-reminder-lite' ) . ' <select name="trigger"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option><option value="automatic" ' . selected( $trigger, 'automatic', false ) . '>' . esc_html__( 'Automatic', 'wc-product-view-reminder-lite' ) . '</option><option value="manual" ' . selected( $trigger, 'manual', false ) . '>' . esc_html__( 'Manual', 'wc-product-view-reminder-lite' ) . '</option></select></label> <label>' . esc_html__( 'From', 'wc-product-view-reminder-lite' ) . ' <input type="date" name="date_from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'To', 'wc-product-view-reminder-lite' ) . ' <input type="date" name="date_to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Filter', 'wc-product-view-reminder-lite' ) . '</button></form>';
        echo '<p class="description">' . esc_html__( '“Sent” means your site handed the email to its mail service. It doesn\'t guarantee the customer received it. “Blocked” rows explain why a reminder wasn\'t sent.', 'wc-product-view-reminder-lite' ) . '</p>';
        if ( empty( $rows ) ) { echo '<p>' . esc_html( $search || $status || $trigger ? __( 'No reminder attempts match these filters. Try changing or clearing them.', 'wc-product-view-reminder-lite' ) : __( 'No reminders have been sent in this date range yet.', 'wc-product-view-reminder-lite' ) ) . '</p>'; }
        else {
            echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attempt time', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Customer', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Product', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Type', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Trigger', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Result', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Details', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Retry count', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $product = wc_get_product( absint( $row->product_id ) );
                $result_label = 'accepted' === $row->result ? __( 'Reminder sent', 'wc-product-view-reminder-lite' ) : ( 'pending' === $row->result ? __( 'Outcome unknown', 'wc-product-view-reminder-lite' ) : ( 'blocked' === $row->result ? __( 'Blocked', 'wc-product-view-reminder-lite' ) : __( 'Failed', 'wc-product-view-reminder-lite' ) ) );
                $customer_reference = $row->email ? $row->email : ( absint( $row->user_id ) ? sprintf( __( 'Customer #%d', 'wc-product-view-reminder-lite' ), absint( $row->user_id ) ) : sprintf( __( 'Deleted view #%d', 'wc-product-view-reminder-lite' ), absint( $row->view_id ) ) );
                $failure_reason = 'pending' === $row->result ? __( 'The send was interrupted before a result was saved, so we don\'t know whether the email went out.', 'wc-product-view-reminder-lite' ) : $this->failure_reason_label( $row->failure_code );
                $result_tone = 'accepted' === $row->result ? 'success' : ( 'failed' === $row->result ? 'danger' : ( 'blocked' === $row->result ? 'warning' : 'info' ) );
                $type_label = 'cart' === $row->reminder_type ? __( 'Cart reminder', 'wc-product-view-reminder-lite' ) : __( 'Product view', 'wc-product-view-reminder-lite' );
                $trigger_label = 'manual' === $row->trigger_type ? __( 'Manual', 'wc-product-view-reminder-lite' ) : __( 'Automatic', 'wc-product-view-reminder-lite' );
                echo '<tr><td><time datetime="' . esc_attr( $this->machine_datetime( $row->attempted_at ) ) . '">' . esc_html( $this->display_datetime( $row->attempted_at ) ) . '</time></td><td>' . esc_html( $customer_reference ) . '</td><td>' . esc_html( $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $row->product_id ) ) ) . '</td><td>' . $this->status_badge( $type_label, 'neutral' ) . '</td><td>' . $this->status_badge( $trigger_label, 'info' ) . '</td><td>' . $this->status_badge( $result_label, $result_tone, 'accepted' === $row->result ? __( 'Handed to your mail service. Delivery isn\'t confirmed.', 'wc-product-view-reminder-lite' ) : '' ) . '</td><td><details class="wc-pvr-history-details"><summary>' . esc_html__( 'Details', 'wc-product-view-reminder-lite' ) . '</summary><p>' . esc_html( $failure_reason ) . '</p></details></td><td>' . esc_html( absint( $row->retry_count ) ) . '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        $pages = (int) ceil( $total / $per_page );
        if ( $pages > 1 ) {
            $page_base = add_query_arg( array( 'page' => 'wc-pvr-history', 'paged' => '%#%', 's' => $search, 'status' => $status, 'trigger' => $trigger, 'date_from' => $from, 'date_to' => $to ), admin_url( 'admin.php' ) );
            echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $page_base, 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
        }
        echo '</div>';
    }

    private function failure_reason_label( $code ) {
        $labels = array(
            'wp_mail_returned_false' => __( 'The email couldn\'t be sent', 'wc-product-view-reminder-lite' ),
            'customer_opted_out' => __( 'Customer opted out', 'wc-product-view-reminder-lite' ),
            'consent_required' => __( 'Marketing consent is required', 'wc-product-view-reminder-lite' ),
            'purchase_suppressed' => __( 'Matching paid purchase found', 'wc-product-view-reminder-lite' ),
            'frequency_limit' => __( 'Frequency limit reached', 'wc-product-view-reminder-lite' ),
            'retry_backoff' => __( 'Retry window has not elapsed', 'wc-product-view-reminder-lite' ),
            'send_in_progress' => __( 'Another send is in progress', 'wc-product-view-reminder-lite' ),
            'invalid_product' => __( 'Product is unavailable', 'wc-product-view-reminder-lite' ),
            'invalid_email' => __( 'Customer email is invalid', 'wc-product-view-reminder-lite' ),
        );
        if ( ! $code ) { return '—'; }
        $label = isset( $labels[ $code ] ) ? $labels[ $code ] : sprintf( __( 'Couldn\'t be sent (%s)', 'wc-product-view-reminder-lite' ), sanitize_key( $code ) );
        return $label;
    }

    public function analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $views = $wpdb->prefix . 'wc_pvr_views';
        $history = WC_PVR_Consent::history_table_name();
        $days = isset( $_GET['days'] ) && is_scalar( $_GET['days'] ) ? absint( wp_unslash( $_GET['days'] ) ) : 30;
        if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) { $days = 30; }
        $from_dt = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
        $from = $from_dt->format( 'Y-m-d H:i:s' );
        $to = current_datetime()->modify( '+1 day' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'RecoverFlow Analytics', 'wc-product-view-reminder-lite' ), __( 'See product views and reminder results over a period of your choice.', 'wc-product-view-reminder-lite' ), array( array( 'page' => 'wc-pvr-history', 'label' => __( 'Reminder History', 'wc-product-view-reminder-lite' ) ) ) );
        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-analytics"><label for="wc-pvr-analytics-days">' . esc_html__( 'Date range', 'wc-product-view-reminder-lite' ) . ' <select id="wc-pvr-analytics-days" name="days"><option value="7" ' . selected( $days, 7, false ) . '>7 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option><option value="30" ' . selected( $days, 30, false ) . '>30 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option><option value="90" ' . selected( $days, 90, false ) . '>90 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option></select></label><button class="button button-primary">' . esc_html__( 'Apply', 'wc-product-view-reminder-lite' ) . '</button></form>';
        if ( ! $this->views_table_exists() ) { echo '<p>' . esc_html__( 'Tracking data is not available.', 'wc-product-view-reminder-lite' ) . '</p></div>'; return; }
        if ( ! $this->history_table_exists() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Reminder history storage is not available. Run RecoverFlow storage setup from Settings.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        $views_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$views} WHERE view_time >= %s AND view_time < %s", $from, $to ) );
        $unique_customers = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT CASE WHEN user_id > 0 THEN CONCAT('u:',user_id) WHEN email IS NOT NULL AND email <> '' THEN CONCAT('e:',LOWER(email)) ELSE NULL END) FROM {$views} WHERE view_time >= %s AND view_time < %s", $from, $to ) );
        $pending_cutoff = wp_date( 'Y-m-d H:i:s', current_datetime()->getTimestamp() - max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) ) * HOUR_IN_SECONDS );
        $cart = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
        $pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$views} WHERE purchased = 0 AND ((added_to_cart = 0 AND view_time >= %s AND view_time < %s AND view_time <= %s AND view_reminder_sent = 0) OR (added_to_cart = 1 AND %d = 1 AND COALESCE(cart_added_at,view_time) >= %s AND COALESCE(cart_added_at,view_time) < %s AND COALESCE(cart_added_at,view_time) <= %s AND cart_reminder_sent = 0))", $from, $to, $pending_cutoff, $cart, $from, $to, $pending_cutoff ) );
        $purchased = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$views} WHERE view_time >= %s AND view_time < %s AND purchased = 1", $from, $to ) );
        $accepted = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s AND result = 'accepted'", $from, $to ) );
        $failed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s AND result = 'failed'", $from, $to ) );
        $suppressed = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s AND result = 'blocked' AND failure_code = 'purchase_suppressed'", $from, $to ) );
        $eligible = 0;
        $checked = 0;
        $candidates = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$views} WHERE purchased = 0 AND ((added_to_cart = 0 AND view_time >= %s AND view_time < %s AND view_time <= %s AND view_reminder_sent = 0) OR (added_to_cart = 1 AND %d = 1 AND COALESCE(cart_added_at,view_time) >= %s AND COALESCE(cart_added_at,view_time) < %s AND COALESCE(cart_added_at,view_time) <= %s AND cart_reminder_sent = 0)) ORDER BY id ASC LIMIT 100", $from, $to, $pending_cutoff, $cart, $from, $to, $pending_cutoff ) );
        $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
        foreach ( (array) $candidates as $candidate ) { $checked++; if ( $service instanceof WC_PVR_Reminder && ! is_wp_error( $service->can_send( $candidate, false, false ) ) ) { $eligible++; } }
        $cards = array( __( 'Product views', 'wc-product-view-reminder-lite' ) => $views_count, __( 'Unique identified customers', 'wc-product-view-reminder-lite' ) => $unique_customers, __( 'Waiting to send', 'wc-product-view-reminder-lite' ) => $pending, __( 'Confirmed ready', 'wc-product-view-reminder-lite' ) => $eligible, __( 'Reminders sent', 'wc-product-view-reminder-lite' ) => $accepted, __( 'Failed to send', 'wc-product-view-reminder-lite' ) => $failed, __( 'Viewed products later purchased', 'wc-product-view-reminder-lite' ) => $purchased, __( 'Skipped because already purchased', 'wc-product-view-reminder-lite' ) => $suppressed );
        echo '<div class="wc-pvr-dashboard-grid">'; foreach ( $cards as $label => $value ) { echo '<section class="wc-pvr-stat-card"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( number_format_i18n( $value ) ) . '</p></section>'; } echo '</div><p class="description">' . esc_html( sprintf( __( '"Confirmed ready" is based on the first %1$d reminders waiting to send, so it may be lower than the real total. "Sent" means your site handed the email to its mail service, not that it reached the inbox. These figures don\'t include revenue or conversion estimates.', 'wc-product-view-reminder-lite' ), $checked ) ) . '</p>';
        $daily_views = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(view_time) AS day,COUNT(*) AS total FROM {$views} WHERE view_time >= %s AND view_time < %s GROUP BY DATE(view_time) ORDER BY day ASC", $from, $to ) );
        $view_map = array();
        foreach ( (array) $daily_views as $daily ) { $view_map[ $daily->day ] = absint( $daily->total ); }
        $view_chart_points = array();
        for ( $index = 0; $index < $days; $index++ ) {
            $day = $from_dt->modify( '+' . $index . ' days' )->format( 'Y-m-d' );
            $count = isset( $view_map[ $day ] ) ? $view_map[ $day ] : 0;
            $view_chart_points[] = array( 'day' => $day, 'total' => $count, 'segments' => array( 'views' => $count ) );
        }
        list( $view_chart_render, $views_weekly ) = $this->maybe_bucket_chart_points( $view_chart_points, $days );
        echo '<section class="wc-pvr-card wc-pvr-chart-card"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Product interest', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( $views_weekly ? sprintf( __( 'Product page views by week over the selected %d days.', 'wc-product-view-reminder-lite' ), $days ) : sprintf( __( 'Product page views over the selected %d days.', 'wc-product-view-reminder-lite' ), $days ) ) . '</p></div></div>';
        $this->render_activity_chart(
            $view_chart_render,
            array( 'views' => __( 'Views', 'wc-product-view-reminder-lite' ) ),
            __( 'No product views in this date range yet.', 'wc-product-view-reminder-lite' ),
            __( 'Product views by day', 'wc-product-view-reminder-lite' )
        );
        echo '</section>';
        $top_views = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,COUNT(*) AS total FROM {$views} WHERE view_time >= %s AND view_time < %s GROUP BY product_id ORDER BY total DESC LIMIT 10", $from, $to ) );
        $top_reminded = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,COUNT(*) AS total FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s AND result = 'accepted' AND trigger_type IN ('automatic','manual') GROUP BY product_id ORDER BY total DESC LIMIT 10", $from, $to ) );
        echo '<div class="wc-pvr-dashboard-grid"><section class="wc-pvr-card"><h2>' . esc_html__( 'Most viewed products', 'wc-product-view-reminder-lite' ) . '</h2>' . $this->render_product_ranking( $top_views ) . '</section><section class="wc-pvr-card"><h2>' . esc_html__( 'Most reminded products', 'wc-product-view-reminder-lite' ) . '</h2>' . $this->render_product_ranking( $top_reminded ) . '</section></div>';
        $daily_attempts = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(attempted_at) AS day,SUM(result = 'accepted') AS accepted,SUM(result = 'failed') AS failed,SUM(result = 'blocked') AS blocked,SUM(result = 'pending') AS pending FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s GROUP BY DATE(attempted_at) ORDER BY day ASC", $from, $to ) );
        $activity_map = array();
        foreach ( (array) $daily_attempts as $attempt ) { $activity_map[ $attempt->day ] = array( 'accepted' => absint( $attempt->accepted ), 'failed' => absint( $attempt->failed ), 'blocked' => absint( $attempt->blocked ), 'pending' => absint( $attempt->pending ) ); }
        $activity_points = array();
        for ( $index = 0; $index < $days; $index++ ) {
            $day = $from_dt->modify( '+' . $index . ' days' )->format( 'Y-m-d' );
            $values = isset( $activity_map[ $day ] ) ? $activity_map[ $day ] : array( 'accepted' => 0, 'failed' => 0, 'blocked' => 0, 'pending' => 0 );
            $activity_points[] = array( 'day' => $day, 'total' => array_sum( $values ), 'segments' => $values );
        }
        list( $activity_render, $activity_weekly ) = $this->maybe_bucket_chart_points( $activity_points, $days );
        echo '<section class="wc-pvr-card wc-pvr-chart-card"><div class="wc-pvr-card-heading"><div><h2>' . esc_html__( 'Reminder results', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( $activity_weekly ? sprintf( __( 'How reminder emails went by week over the selected %d days.', 'wc-product-view-reminder-lite' ), $days ) : sprintf( __( 'How reminder emails went over the selected %d days.', 'wc-product-view-reminder-lite' ), $days ) ) . '</p></div></div>';
        $this->render_activity_chart(
            $activity_render,
            array(
                'accepted' => __( 'Sent', 'wc-product-view-reminder-lite' ),
                'failed' => __( 'Failed', 'wc-product-view-reminder-lite' ),
                'blocked' => __( 'Blocked', 'wc-product-view-reminder-lite' ),
                'pending' => __( 'Needs review', 'wc-product-view-reminder-lite' ),
            ),
            __( 'No reminders were tried in this date range yet.', 'wc-product-view-reminder-lite' ),
            __( 'Reminder results by day', 'wc-product-view-reminder-lite' )
        );
        echo '</section></div>';
    }

    private function render_product_ranking( $items ) {
        if ( empty( $items ) ) { return '<div class="wc-pvr-chart-empty wc-pvr-chart-empty--compact"><p>' . esc_html__( 'Nothing to show here for this date range yet.', 'wc-product-view-reminder-lite' ) . '</p></div>'; }
        $html = '<ol class="wc-pvr-ranking">';
        $max = 1;
        foreach ( $items as $item ) { $max = max( $max, absint( $item->total ) ); }
        foreach ( $items as $item ) {
            $product = wc_get_product( absint( $item->product_id ) );
            $label = $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $item->product_id ) );
            $width = max( 8, (int) round( 100 * absint( $item->total ) / $max ) );
            $html .= '<li><div class="wc-pvr-ranking-main"><span class="wc-pvr-ranking-name">' . esc_html( $label ) . '</span><span class="wc-pvr-ranking-track"><span class="wc-pvr-ranking-fill" style="width:' . esc_attr( $width ) . '%"></span></span></div><strong>' . esc_html( number_format_i18n( absint( $item->total ) ) ) . '</strong></li>';
        }
        return $html . '</ol>';
    }

    public function handle_record_revocation() {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to record a consent revocation.', 'wc-product-view-reminder-lite' ) ); }
        $record_id = isset( $_POST['record_id'] ) && is_scalar( $_POST['record_id'] ) ? absint( wp_unslash( $_POST['record_id'] ) ) : 0;
        check_admin_referer( 'wc_pvr_record_revocation_' . $record_id );
        global $wpdb;
        $table = WC_PVR_Consent::table_name();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT c.* FROM {$table} c WHERE c.id = %d AND c.status = 'opted_in' AND NOT EXISTS (SELECT 1 FROM {$table} newer WHERE newer.email_hash = c.email_hash AND newer.id > c.id) LIMIT 1",
            $record_id
        ) );
        $saved = false;
        if ( $row && ! empty( $_POST['verified_request'] ) && '1' === (string) wp_unslash( $_POST['verified_request'] ) ) {
            $now = current_time( 'mysql' );
            $saved = false !== $wpdb->insert( $table, array(
                'user_id' => $row->user_id ? absint( $row->user_id ) : null,
                'email_hash' => $row->email_hash,
                'status' => 'opted_out',
                'source' => 'admin_verified_revocation',
                'channel' => 'admin',
                'wording_version' => 'verified-request',
                'consented_at' => null,
                'revoked_at' => $now,
                'created_at' => $now,
            ) );
        }
        $url = add_query_arg( array( 'page' => 'wc-pvr-consent', 'wc_pvr_revocation' => $saved ? 'saved' : 'failed' ), admin_url( 'admin.php' ) );
        wp_safe_redirect( $url );
        exit;
    }

    private function consent_source_label( $source ) {
        $labels = array(
            'account_preferences' => __( 'My Account', 'wc-product-view-reminder-lite' ),
            'classic_checkout' => __( 'Checkout', 'wc-product-view-reminder-lite' ),
            'checkout_blocks' => __( 'Checkout (blocks)', 'wc-product-view-reminder-lite' ),
            'registration' => __( 'Registration', 'wc-product-view-reminder-lite' ),
            'unsubscribe_link' => __( 'Unsubscribe link', 'wc-product-view-reminder-lite' ),
            'admin_verified_revocation' => __( 'Store admin', 'wc-product-view-reminder-lite' ),
            'privacy_eraser' => __( 'Privacy erasure', 'wc-product-view-reminder-lite' ),
        );
        $source = is_scalar( $source ) ? (string) $source : '';
        if ( isset( $labels[ $source ] ) ) { return $labels[ $source ]; }
        return '' !== $source ? ucfirst( str_replace( '_', ' ', sanitize_key( $source ) ) ) : __( 'Unknown', 'wc-product-view-reminder-lite' );
    }

    private function mask_email( $email ) {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) { return ''; }
        list( $local, $domain ) = explode( '@', $email, 2 );
        return substr( $local, 0, 1 ) . str_repeat( '*', max( 2, min( 8, strlen( $local ) - 1 ) ) ) . '@' . $domain;
    }

    public function consent_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'Consent Management', 'wc-product-view-reminder-lite' ), __( 'See what customers have chosen about marketing emails and whether a consent tool is connected. You can\'t sign customers up from this page.', 'wc-product-view-reminder-lite' ) );
        if ( ! WC_PVR_Consent::is_installed() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Consent records aren\'t set up yet. Finish the storage setup in Settings first.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        if ( isset( $_GET['wc_pvr_revocation'] ) && is_scalar( $_GET['wc_pvr_revocation'] ) ) {
            $saved = 'saved' === sanitize_key( wp_unslash( $_GET['wc_pvr_revocation'] ) );
            echo '<div class="notice ' . ( $saved ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $saved ? __( 'The opt-out was saved. This customer won\'t receive reminders.', 'wc-product-view-reminder-lite' ) : __( 'The opt-out couldn\'t be saved. Refresh the page and try again.', 'wc-product-view-reminder-lite' ) ) . '</p></div>';
        }
        global $wpdb;
        $table = WC_PVR_Consent::table_name();
        $latest = " FROM {$table} c WHERE NOT EXISTS (SELECT 1 FROM {$table} newer WHERE newer.email_hash = c.email_hash AND newer.id > c.id)";
        $opted_in = absint( $wpdb->get_var( "SELECT COUNT(*){$latest} AND c.status = 'opted_in'" ) );
        $opted_out = absint( $wpdb->get_var( "SELECT COUNT(*){$latest} AND c.status = 'opted_out'" ) );
        echo '<div class="wc-pvr-dashboard-grid"><section class="wc-pvr-stat-card"><h2>' . esc_html__( 'Currently opted in', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( number_format_i18n( $opted_in ) ) . '</p></section><section class="wc-pvr-stat-card"><h2>' . esc_html__( 'Currently opted out', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( number_format_i18n( $opted_out ) ) . '</p></section></div>';
        $eligibility_filter = has_filter( 'wc_pvr_customer_is_eligible_for_marketing' );
        $optout_filter = has_filter( 'wc_pvr_customer_has_marketing_opted_out' );
        echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Reminder eligibility', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'Viewing a product doesn\'t count as consent. A customer only gets reminders after they\'ve agreed to marketing emails, either here or through a connected consent tool. An opt-out always wins.', 'wc-product-view-reminder-lite' ) . '</p>' . $this->status_badge( $eligibility_filter ? __( 'Consent tool connected', 'wc-product-view-reminder-lite' ) : __( 'No consent tool connected', 'wc-product-view-reminder-lite' ), $eligibility_filter ? 'info' : 'warning' ) . ' ' . $this->status_badge( $optout_filter ? __( 'Opt-out tool connected', 'wc-product-view-reminder-lite' ) : __( 'No opt-out tool connected', 'wc-product-view-reminder-lite' ), $optout_filter ? 'info' : 'neutral' ) . '<details class="wc-pvr-integration-details"><summary>' . esc_html__( 'Connection details', 'wc-product-view-reminder-lite' ) . '</summary><p>' . esc_html__( 'A connected tool means your site has code linked in, not that a particular customer has agreed. The connection should check each customer\'s current consent, including any they have withdrawn.', 'wc-product-view-reminder-lite' ) . '</p></details></section>';
        $search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status = isset( $_GET['status'] ) && is_scalar( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        if ( ! in_array( $status, array( '', 'opted_in', 'opted_out' ), true ) ) { $status = ''; }
        $where = array( "NOT EXISTS (SELECT 1 FROM {$table} newer WHERE newer.email_hash = c.email_hash AND newer.id > c.id)" );
        $args = array();
        if ( $status ) { $where[] = 'c.status = %s'; $args[] = $status; }
        if ( '' !== $search ) {
            $hash = WC_PVR_Consent::email_hash( $search );
            if ( $hash ) { $where[] = '(c.email_hash = %s OR CAST(c.user_id AS CHAR) = %s)'; $args[] = $hash; $args[] = $search; }
            elseif ( ctype_digit( $search ) ) { $where[] = 'c.user_id = %d'; $args[] = absint( $search ); }
            else { $where[] = '1 = 0'; }
        }
        $where_sql = ' WHERE ' . implode( ' AND ', $where );
        $total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$table} c{$where_sql}", $args ) : "SELECT COUNT(*) FROM {$table} c{$where_sql}" );
        $per_page = 25;
        $paged = isset( $_GET['paged'] ) && is_scalar( $_GET['paged'] ) ? max( 1, absint( wp_unslash( $_GET['paged'] ) ) ) : 1;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = ( $paged - 1 ) * $per_page;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$table} c{$where_sql} ORDER BY c.id DESC LIMIT %d OFFSET %d", $query_args ) );
        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-consent"><label>' . esc_html__( 'Search email or customer ID', 'wc-product-view-reminder-lite' ) . ' <input type="search" name="s" value="' . esc_attr( $search ) . '"></label><label>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option><option value="opted_in" ' . selected( $status, 'opted_in', false ) . '>' . esc_html__( 'Opted in', 'wc-product-view-reminder-lite' ) . '</option><option value="opted_out" ' . selected( $status, 'opted_out', false ) . '>' . esc_html__( 'Opted out', 'wc-product-view-reminder-lite' ) . '</option></select></label><button class="button">' . esc_html__( 'Filter records', 'wc-product-view-reminder-lite' ) . '</button></form>';
        if ( empty( $rows ) ) { echo '<div class="wc-pvr-empty"><p>' . esc_html__( 'No consent records match this search. Without a record, a customer won\'t get reminders unless a connected consent tool confirms they agreed.', 'wc-product-view-reminder-lite' ) . '</p></div>'; }
        else {
            echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer reference', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Source', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Channel', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Last updated', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'What this means', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Opt-out request', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $user = $row->user_id ? get_userdata( absint( $row->user_id ) ) : false;
                $reference = $user ? sprintf( __( '%1$s (customer #%2$d)', 'wc-product-view-reminder-lite' ), $this->mask_email( $user->user_email ), absint( $row->user_id ) ) : sprintf( __( 'Guest email reference …%s', 'wc-product-view-reminder-lite' ), substr( $row->email_hash, -8 ) );
                $status_label = 'opted_in' === $row->status ? __( 'Opted in', 'wc-product-view-reminder-lite' ) : __( 'Opted out', 'wc-product-view-reminder-lite' );
                if ( 'opted_out' === $row->status && 'privacy_eraser' === $row->source ) {
                    $reason = __( 'Won\'t receive reminders: data was erased on request. We keep an anonymous marker so reminders can\'t be turned back on by mistake.', 'wc-product-view-reminder-lite' );
                } else {
                    $reason = 'opted_out' === $row->status ? __( 'Won\'t receive reminders: the customer opted out. This can\'t be overridden by a connected consent tool.', 'wc-product-view-reminder-lite' ) : __( 'Agreed to marketing emails. Product, purchase, frequency and opt-out checks still apply when a reminder is sent.', 'wc-product-view-reminder-lite' );
                }
                echo '<tr><td>' . esc_html( $reference ) . '</td><td><span class="wc-pvr-status wc-pvr-status-' . esc_attr( $row->status ) . '">' . esc_html( $status_label ) . '</span></td><td>' . esc_html( $this->consent_source_label( $row->source ) ) . '</td><td>' . esc_html( $row->channel ) . '</td><td>' . esc_html( $this->display_datetime( $row->created_at ) ) . '</td><td>' . esc_html( $reason ) . '</td><td>';
                if ( 'opted_in' === $row->status ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(\'' . esc_js( __( 'Only confirm after you have verified that this customer asked to stop receiving marketing emails. This records an opt-out.', 'wc-product-view-reminder-lite' ) ) . '\');"><input type="hidden" name="action" value="wc_pvr_record_revocation"><input type="hidden" name="record_id" value="' . esc_attr( absint( $row->id ) ) . '"><input type="hidden" name="verified_request" value="1">' . wp_nonce_field( 'wc_pvr_record_revocation_' . absint( $row->id ), '_wpnonce', true, false ) . '<button class="button button-secondary">' . esc_html__( 'Record verified opt-out', 'wc-product-view-reminder-lite' ) . '</button></form>';
                } else { echo '&mdash;'; }
                echo '</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        $pages = (int) ceil( $total / $per_page );
        if ( $pages > 1 ) { $base = add_query_arg( array( 'page' => 'wc-pvr-consent', 'paged' => '%#%', 's' => $search, 'status' => $status ), admin_url( 'admin.php' ) ); echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => $base, 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>'; }
        echo '</div>';
    }

    public function system_status_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $views = $wpdb->prefix . 'wc_pvr_views';
        $history = WC_PVR_Consent::history_table_name();
        $next_cron = wp_next_scheduled( 'wc_pvr_cron' );
        $next_cleanup = wp_next_scheduled( 'wc_pvr_retention_cleanup' );
        $last_cron = get_option( 'wc_pvr_last_cron_completed_at', '' );
        $last_cleanup = get_option( 'wc_pvr_last_retention_cleanup_at', '' );
        $hpos = __( 'Unavailable', 'wc-product-view-reminder-lite' );
        if ( class_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil' ) && method_exists( 'Automattic\\WooCommerce\\Utilities\\OrderUtil', 'custom_orders_table_usage_is_enabled' ) ) {
            $order_util = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
            $hpos = call_user_func( array( $order_util, 'custom_orders_table_usage_is_enabled' ) ) ? __( 'Enabled', 'wc-product-view-reminder-lite' ) : __( 'Disabled (posts storage)', 'wc-product-view-reminder-lite' );
        }
        $view_exists = $this->views_table_exists();
        $cart_enabled = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
        $delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
        $due_cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $delay * HOUR_IN_SECONDS ) );
        $pending = $view_exists ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$views} WHERE purchased = 0 AND ((added_to_cart = 0 AND view_reminder_sent = 0 AND view_time <= %s) OR (added_to_cart = 1 AND cart_reminder_sent = 0 AND %d = 1 AND COALESCE(cart_added_at,view_time) <= %s))", $due_cutoff, $cart_enabled, $due_cutoff ) ) ) : 0;
        $failures = WC_PVR_Consent::is_installed() ? absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE result = 'failed' AND attempted_at >= %s", current_datetime()->modify( '-7 days' )->format( 'Y-m-d H:i:s' ) ) ) ) : 0;
        $sender = sanitize_email( get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) ) );
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'System Status', 'wc-product-view-reminder-lite' ), __( 'Check that storage, purchase lookup, scheduled checks and email settings are working.', 'wc-product-view-reminder-lite' ) );
        echo '<p class="wc-pvr-note">' . esc_html__( 'A scheduled check doesn\'t prove it has run. The "last completed" time shows when RecoverFlow last finished checking for reminders.', 'wc-product-view-reminder-lite' ) . '</p><div class="wc-pvr-table-wrap"><table class="widefat striped"><tbody>';
        $rows = array(
            __( 'WordPress', 'wc-product-view-reminder-lite' ) => get_bloginfo( 'version' ),
            __( 'PHP', 'wc-product-view-reminder-lite' ) => PHP_VERSION,
            __( 'WooCommerce', 'wc-product-view-reminder-lite' ) => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Not detected', 'wc-product-view-reminder-lite' ),
            __( 'HPOS order storage', 'wc-product-view-reminder-lite' ) => $hpos,
            __( 'RecoverFlow schema', 'wc-product-view-reminder-lite' ) => get_option( 'wc_pvr_consent_db_version', __( 'Not installed', 'wc-product-view-reminder-lite' ) ) . ( WC_PVR_Consent::is_installed() ? ' (' . __( 'ready', 'wc-product-view-reminder-lite' ) . ')' : ' (' . __( 'not ready', 'wc-product-view-reminder-lite' ) . ')' ),
            __( 'Consent records', 'wc-product-view-reminder-lite' ) => WC_PVR_Consent::is_installed() ? __( 'Ready. Customers without recorded consent won\'t be emailed.', 'wc-product-view-reminder-lite' ) : __( 'Not ready', 'wc-product-view-reminder-lite' ),
            __( 'Purchase lookup', 'wc-product-view-reminder-lite' ) => get_option( 'wc_pvr_orders_backfill_complete', 0 ) ? __( 'Complete', 'wc-product-view-reminder-lite' ) : sprintf( __( 'Still building (batch %d)', 'wc-product-view-reminder-lite' ), max( 1, absint( get_option( 'wc_pvr_orders_backfill_page', 1 ) ) ) ),
            __( 'Next reminder check', 'wc-product-view-reminder-lite' ) => $next_cron ? wp_date( 'Y-m-d H:i:s T', $next_cron ) : __( 'Nothing scheduled', 'wc-product-view-reminder-lite' ),
            __( 'Last reminder check', 'wc-product-view-reminder-lite' ) => $last_cron ? $last_cron : __( 'No completed check yet', 'wc-product-view-reminder-lite' ),
            __( 'Next data cleanup', 'wc-product-view-reminder-lite' ) => $next_cleanup ? wp_date( 'Y-m-d H:i:s T', $next_cleanup ) : __( 'Nothing scheduled', 'wc-product-view-reminder-lite' ),
            __( 'Last data cleanup', 'wc-product-view-reminder-lite' ) => $last_cleanup ? $last_cleanup : __( 'No cleanup yet', 'wc-product-view-reminder-lite' ),
            __( 'Ready to send', 'wc-product-view-reminder-lite' ) => sprintf( __( '%s (before consent and purchase checks)', 'wc-product-view-reminder-lite' ), number_format_i18n( $pending ) ),
            __( 'Failed to send in last 7 days', 'wc-product-view-reminder-lite' ) => number_format_i18n( $failures ),
            __( 'Email sending', 'wc-product-view-reminder-lite' ) => function_exists( 'wp_mail' ) && is_email( $sender ) ? sprintf( __( 'Sender address %s looks valid. We can\'t check whether your mail service is connected or whether emails reach inboxes.', 'wc-product-view-reminder-lite' ), $sender ) : __( 'Email sending isn\'t available, or the sender address is invalid.', 'wc-product-view-reminder-lite' ),
        );
        foreach ( $rows as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
        echo '</tbody></table></div><section class="wc-pvr-card"><h2>' . esc_html__( 'Troubleshooting', 'wc-product-view-reminder-lite' ) . '</h2><ul><li>' . esc_html__( 'If the purchase lookup isn\'t finished, open Settings and choose Build purchase lookup. Reminders stay paused until it completes.', 'wc-product-view-reminder-lite' ) . '</li><li>' . esc_html__( 'If a check is scheduled but the last check time is empty or old, your site may not get enough visitors to trigger it. Ask your host to set up a server cron job that calls wp-cron.php.', 'wc-product-view-reminder-lite' ) . '</li><li>' . esc_html__( 'If a reminder shows as sent but never arrives, check your site\'s mail service. "Sent" means your site handed the email over, not that it was delivered.', 'wc-product-view-reminder-lite' ) . '</li></ul><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-pvr-templates' ) ) . '">' . esc_html__( 'Open Email Templates', 'wc-product-view-reminder-lite' ) . '</a></section></div>';
    }

    public function templates_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap wc-pvr-admin">';
        $this->render_page_header( __( 'Email Templates', 'wc-product-view-reminder-lite' ), __( 'Edit the wording of your reminder emails and preview how they look with sample data.', 'wc-product-view-reminder-lite' ) );
        settings_errors();
        if ( isset( $_GET['wc_pvr_test_result'] ) && is_scalar( $_GET['wc_pvr_test_result'] ) ) { $ok = 'accepted' === sanitize_key( wp_unslash( $_GET['wc_pvr_test_result'] ) ); echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $ok ? __( 'Test email sent. Check your local mail inbox (for example Mailpit or MailHog) to see it.', 'wc-product-view-reminder-lite' ) : __( 'The test email wasn\'t sent. Test emails only work on a local site and only to an address ending in .test.', 'wc-product-view-reminder-lite' ) ) . '</p></div>'; }
        echo '<p class="description">' . esc_html__( 'Use the tags listed below, like {customer_name}, to fill in each customer\'s details automatically. The previews use sample data. You can edit both the product view email and the cart email here.', 'wc-product-view-reminder-lite' ) . '</p><div class="wc-pvr-card"><form method="post" action="options.php">';
        settings_fields( 'wc_pvr_templates_group' );
        echo '<table class="form-table"><tr><th><label for="wc-pvr-subject">' . esc_html__( 'Subject', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-subject" type="text" name="wc_pvr_email_subject" value="' . esc_attr( get_option( 'wc_pvr_email_subject', 'You viewed {product_name}' ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-heading">' . esc_html__( 'Email heading', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-heading" type="text" name="wc_pvr_email_heading" value="' . esc_attr( get_option( 'wc_pvr_email_heading', __( 'A product you viewed is waiting', 'wc-product-view-reminder-lite' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-sender-name">' . esc_html__( 'Sender name', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-sender-name" type="text" name="wc_pvr_email_sender_name" value="' . esc_attr( get_option( 'wc_pvr_email_sender_name', get_bloginfo( 'name' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-sender-email">' . esc_html__( 'Sender email', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-sender-email" type="email" name="wc_pvr_email_sender_email" value="' . esc_attr( get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-button-text">' . esc_html__( 'Call-to-action text', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-button-text" type="text" name="wc_pvr_email_button_text" value="' . esc_attr( get_option( 'wc_pvr_email_button_text', __( 'View product', 'wc-product-view-reminder-lite' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc_pvr_template_message">' . esc_html__( 'Email body', 'wc-product-view-reminder-lite' ) . '</label></th><td>';
        $default_view_body = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::default_view_message() : "Hi {customer_name},\n\nYou viewed {product_name}.\n\n{product_image}\n\n{cta_button}\n\n{unsubscribe_url}";
        $default_cart_body = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::default_cart_message() : "Hi {customer_name},\n\nYou added {product_name} to your cart.\n\n{product_image}\n\n{cta_button}\n\n{unsubscribe_url}";
        wp_editor( get_option( 'wc_pvr_email_message', $default_view_body ), 'wc_pvr_template_message', array( 'textarea_name' => 'wc_pvr_email_message', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true ) );
        echo '<p class="description">' . esc_html__( 'Available tags: {customer_name}, {product_name}, {product_image}, {product_link}, {product_url}, {product_price}, {store_name}, {email_heading}, {cta_button}, {unsubscribe_url}.', 'wc-product-view-reminder-lite' ) . '</p></td></tr>';
        echo '<tr><th><label for="wc_pvr_cart_template_message">' . esc_html__( 'Cart reminder body', 'wc-product-view-reminder-lite' ) . '</label></th><td>';
        wp_editor( get_option( 'wc_pvr_cart_email_message', $default_cart_body ), 'wc_pvr_cart_template_message', array( 'textarea_name' => 'wc_pvr_cart_email_message', 'textarea_rows' => 8, 'media_buttons' => false, 'teeny' => true ) );
        echo '<p class="description">' . esc_html__( 'The cart email supports the same tags listed above.', 'wc-product-view-reminder-lite' ) . '</p>';
        echo '</td></tr></table>'; submit_button( __( 'Save Templates', 'wc-product-view-reminder-lite' ) ); echo '</form></div>';
        $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
        $preview_kses = array_merge(
            wp_kses_allowed_html( 'post' ),
            array(
                'img' => array(
                    'src' => true,
                    'alt' => true,
                    'width' => true,
                    'height' => true,
                    'style' => true,
                    'border' => true,
                ),
            )
        );
        if ( $service instanceof WC_PVR_Reminder ) { $preview = $service->render_template( null, 'Sample Customer', 'synthetic@example.test', 'view', 0, null, true ); echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Email preview (sample data)', 'wc-product-view-reminder-lite' ) . '</h2><p><strong>' . esc_html__( 'Subject:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( $preview['subject'] ) . '</p><div class="wc-pvr-email-preview">' . wp_kses( $preview['message'], $preview_kses ) . '</div></section>'; }
        if ( $service instanceof WC_PVR_Reminder ) { $cart_preview = $service->render_template( null, 'Sample Customer', 'synthetic@example.test', 'cart', 0, null, true ); echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Cart email preview (sample data)', 'wc-product-view-reminder-lite' ) . '</h2><p><strong>' . esc_html__( 'Subject:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( $cart_preview['subject'] ) . '</p><div class="wc-pvr-email-preview">' . wp_kses( $cart_preview['message'], $preview_kses ) . '</div></section>'; }
        if ( 'local' === wp_get_environment_type() ) {
            echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Local test email', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'This is only available on a local development site. Send the test to an address ending in .test, then check your local mail inbox (for example Mailpit or MailHog).', 'wc-product-view-reminder-lite' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wc_pvr_send_test_email">' . wp_nonce_field( 'wc_pvr_send_test_email', '_wpnonce', true, false ) . '<label for="wc-pvr-test-recipient">' . esc_html__( 'Send test to', 'wc-product-view-reminder-lite' ) . '</label> <input type="email" required id="wc-pvr-test-recipient" name="test_recipient" value="recoverflow@example.test"> <button class="button button-secondary">' . esc_html__( 'Send test email', 'wc-product-view-reminder-lite' ) . '</button></form></section>';
        }
        echo '</div>';
    }

    public function settings() {

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_delay',
            array( 'sanitize_callback' => array( $this, 'sanitize_tracking_delay' ) )
        );

        register_setting( 'wc_pvr_settings_group', 'wc_pvr_reminder_delay', array( 'sanitize_callback' => array( $this, 'sanitize_reminder_delay' ) ) );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_cron_interval',
            array(
                'sanitize_callback' => array( $this, 'sanitize_cron_interval' )
            )
        );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_cart_reminder',
            array(
                'sanitize_callback' => array( $this, 'sanitize_checkbox' )
            )
        );

        register_setting(
            'wc_pvr_templates_group',
            'wc_pvr_cart_email_message',
            array(
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting(
            'wc_pvr_templates_group',
            'wc_pvr_email_subject',
            array(
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'wc_pvr_templates_group',
            'wc_pvr_email_message',
            array(
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting( 'wc_pvr_settings_group', 'wc_pvr_frequency_days', array( 'sanitize_callback' => array( $this, 'sanitize_frequency_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_quiet_hours_enabled', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_quiet_hours_start', array( 'sanitize_callback' => array( $this, 'sanitize_hour' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_quiet_hours_end', array( 'sanitize_callback' => array( $this, 'sanitize_hour' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_exclude_product_ids', array( 'sanitize_callback' => array( $this, 'sanitize_id_list_option' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_exclude_category_ids', array( 'sanitize_callback' => array( $this, 'sanitize_id_list_option' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_enabled', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_registration', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_checkout', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_wording', array( 'sanitize_callback' => array( $this, 'sanitize_consent_wording' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_views_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_views_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_history_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_history_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_consent_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_consent_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_preserve_optout_after_erasure', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_templates_group', 'wc_pvr_email_sender_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wc_pvr_templates_group', 'wc_pvr_email_sender_email', array( 'sanitize_callback' => array( $this, 'sanitize_sender_email' ) ) );
        register_setting( 'wc_pvr_templates_group', 'wc_pvr_email_button_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wc_pvr_templates_group', 'wc_pvr_email_heading', array( 'sanitize_callback' => 'sanitize_text_field' ) );

    }

    public function sanitize_tracking_delay( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 3600 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_tracking_delay', __( 'Enter a whole number from 0 to 3600 seconds for when a product view is counted.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( 'wc_pvr_delay', 5 ) );
        }
        return absint( $value );
    }

    public function sanitize_reminder_delay( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 720 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_reminder_delay', __( 'Enter a whole number from 0 to 720 hours for when reminders are sent.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( 'wc_pvr_reminder_delay', 24 ) );
        }
        return absint( $value );
    }

    public function sanitize_sender_email( $value ) {
        $email = is_scalar( $value ) ? sanitize_email( $value ) : '';
        if ( ! is_email( $email ) ) {
            add_settings_error( 'wc_pvr_templates_group', 'wc_pvr_invalid_sender_email', __( 'Enter a valid email address for the sender.', 'wc-product-view-reminder-lite' ), 'error' );
            return get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) );
        }
        return $email;
    }

    public function sanitize_checkbox( $value ) {
        return is_scalar( $value ) && '1' === (string) $value ? 1 : 0;
    }

    public function sanitize_consent_wording( $value ) {
        $wording = is_scalar( $value ) ? sanitize_text_field( $value ) : '';
        if ( '' === trim( $wording ) ) {
            return class_exists( 'WC_PVR_Consent' ) ? WC_PVR_Consent::default_wording() : __( 'I agree to receive product reminders and promotional emails from this store. I can unsubscribe at any time.', 'wc-product-view-reminder-lite' );
        }
        return $wording;
    }

    public function sanitize_frequency_days( $value ) {
        $min = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::FREQUENCY_DAYS_MIN : 3;
        $max = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::FREQUENCY_DAYS_MAX : 30;
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) < $min || absint( $value ) > $max ) {
            add_settings_error(
                'wc_pvr_settings_group',
                'wc_pvr_invalid_frequency_days',
                sprintf(
                    /* translators: 1: min days, 2: max days */
                    __( 'Enter a whole number from %1$d to %2$d for the reminder cooldown.', 'wc-product-view-reminder-lite' ),
                    $min,
                    $max
                ),
                'error'
            );
            return absint( get_option( 'wc_pvr_frequency_days', 7 ) );
        }
        return absint( $value );
    }

    public function sanitize_hour( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 23 ) {
            return 9;
        }
        return absint( $value );
    }

    public function sanitize_id_list_option( $value ) {
        $ids = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::parse_id_list( $value ) : array();
        return implode( ',', $ids );
    }

    private function sanitize_retention_days( $value, $option, $error_code, $error_message ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 3650 ) {
            add_settings_error( 'wc_pvr_settings_group', $error_code, $error_message, 'error' );
            return absint( get_option( $option, 0 ) );
        }
        return absint( $value );
    }

    public function sanitize_retention_views_days( $value ) {
        return $this->sanitize_retention_days( $value, 'wc_pvr_retention_views_days', 'wc_pvr_invalid_retention_views', __( 'Enter a whole number from 0 to 3650 for product view retention.', 'wc-product-view-reminder-lite' ) );
    }
    public function sanitize_retention_history_days( $value ) {
        $min_history = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::frequency_days() : 7;
        if ( is_scalar( $value ) && preg_match( '/^\d+$/', (string) $value ) && absint( $value ) > 0 && absint( $value ) < $min_history ) {
            add_settings_error(
                'wc_pvr_settings_group',
                'wc_pvr_short_history_retention',
                sprintf(
                    /* translators: %d: minimum retention days matching cooldown */
                    __( 'Reminder history must be kept for at least %d days (or enter 0 to keep it), so the product cooldown keeps working.', 'wc-product-view-reminder-lite' ),
                    $min_history
                ),
                'error'
            );
            return absint( get_option( 'wc_pvr_retention_history_days', 0 ) );
        }
        return $this->sanitize_retention_days( $value, 'wc_pvr_retention_history_days', 'wc_pvr_invalid_retention_history', __( 'Enter a whole number from 0 to 3650 for reminder history retention.', 'wc-product-view-reminder-lite' ) );
    }
    public function sanitize_retention_consent_days( $value ) {
        return $this->sanitize_retention_days( $value, 'wc_pvr_retention_consent_days', 'wc_pvr_invalid_retention_consent', __( 'Enter a whole number from 0 to 3650 for consent history retention.', 'wc-product-view-reminder-lite' ) );
    }

    public function sanitize_cron_interval( $value ) {
        $allowed = array( '5min', '15min', '30min', 'hourly', 'twicedaily', 'daily' );
        return in_array( $value, $allowed, true ) ? $value : 'hourly';
    }

    public function help_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }

        $link = static function ( $page, $label ) {
            return '<a href="' . esc_url( admin_url( 'admin.php?page=' . $page ) ) . '">' . esc_html( $label ) . '</a>';
        };

        echo '<div class="wrap wc-pvr-admin wc-pvr-help">';
        echo '<h1>' . esc_html__( 'RecoverFlow Help', 'wc-product-view-reminder-lite' ) . '</h1>';
        echo '<p class="wc-pvr-help-intro">' . esc_html__( 'A practical guide to setting up reminders, understanding customer eligibility, reading reports, and solving common problems. RecoverFlow tracks product views for signed-in customers only; it does not identify anonymous visitors.', 'wc-product-view-reminder-lite' ) . '</p>';
        echo '<nav class="wc-pvr-help-nav" aria-label="' . esc_attr__( 'Help topics', 'wc-product-view-reminder-lite' ) . '"><a href="#wc-pvr-help-start">' . esc_html__( 'Getting started', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-timing">' . esc_html__( 'Tracking and timing', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-reminders">' . esc_html__( 'Customer reminders', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-consent">' . esc_html__( 'Consent', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-email">' . esc_html__( 'Email and history', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-reports">' . esc_html__( 'Dashboard and analytics', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-privacy">' . esc_html__( 'Privacy and retention', 'wc-product-view-reminder-lite' ) . '</a><a href="#wc-pvr-help-troubleshooting">' . esc_html__( 'Troubleshooting', 'wc-product-view-reminder-lite' ) . '</a></nav>';
        echo '<div class="wc-pvr-help-grid">';

        echo '<section id="wc-pvr-help-start" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '1. Getting started', 'wc-product-view-reminder-lite' ) . '</h2><ol>';
        echo '<li>' . sprintf( esc_html__( 'Open %s. Choose how long someone must stay on a product page before tracking, how long to wait before a reminder, and how often to check for due reminders.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr', __( 'Settings', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li>' . esc_html__( 'Choose how your store establishes email marketing eligibility. Built-in consent choices are optional. Do not treat a product view, registration, or checkout alone as permission.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . sprintf( esc_html__( 'Review %s and confirm the purchase lookup is complete before relying on purchase suppression.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-status', __( 'System Status', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li>' . sprintf( esc_html__( 'Configure and preview messages in %s. While testing on a local site, only send to an address ending in .test.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-templates', __( 'Email Templates', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '</ol><p>' . esc_html__( 'Reminders only go to customers who have agreed to marketing emails, either through RecoverFlow\'s checkbox or a consent tool you\'ve connected.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-timing" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '2. Tracking, waiting period, and schedule', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li><strong>' . esc_html__( 'Count a product view after (seconds):', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How long a logged-in customer must stay on a product page before the view is counted. This doesn\'t control when an email is sent.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Send reminders after (hours):', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How long to wait after a product view, or after a product is added to the cart for cart reminders, before a reminder can be sent. The default is 24 hours.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'How often to check for reminders:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How often your site looks for reminders that are ready to send. Timing is approximate, because WordPress runs these checks when people visit your site. Your host can also set up a scheduled job to run them on time.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Reminder cooldown (days):', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How long to wait before the same customer can get another reminder for the same product (3–30 days). Other products stay eligible.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Quiet hours:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Optional send window in your site timezone (for example 09:00–20:00). Automatic cron reminders pause outside that window; manual Send Reminder still works.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Excluded products and categories:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Skip tracking and reminders for specific product IDs or whole product categories (for example gift cards or donations).', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Cart reminders are optional. Enable them in Settings. Consent, purchase, duplicate, and customer-frequency checks still apply.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul></section>';

        echo '<section id="wc-pvr-help-reminders" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '3. Customer Reminders and manual sends', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( 'Use %s to search tracked customer and product records. The eligibility column explains blocks such as missing consent, an opt-out, a recent purchase, a deleted product, a waiting period, or a frequency limit.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-reminders', __( 'Customer Reminders', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . esc_html__( 'A manual send asks for confirmation and requires administrator permission. It can bypass the waiting period only. It still checks consent, opt-outs, product validity, purchase state, duplicate attempts, retry rules, and frequency limits. Read the eligibility explanation when the action is unavailable.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-consent" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '4. Consent and customer choices', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li>' . esc_html__( 'Registration and checkout consent checkboxes are optional and unchecked by default. Customers can register and purchase without selecting them.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Customers can manage preferences at My Account > Email preferences. Unsubscribe links require confirmation. A later opt-out or revoked consent blocks future reminders.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Existing customers are not opted in automatically. Views, account registration, and checkout do not imply marketing consent.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . sprintf( esc_html__( '%s shows each customer\'s latest choice and whether a consent tool is connected. You can record an opt-out a customer asked for, but you can\'t sign customers up from this page.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-consent', __( 'Consent Management', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li>' . esc_html__( 'If your store already uses a consent or email preferences tool, a developer can connect it to RecoverFlow. WooCommerce doesn\'t have a built-in marketing consent field, so the connection depends on the tool you use.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul></section>';

        echo '<section id="wc-pvr-help-email" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '5. Email Templates and Reminder History', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( 'In %s you can edit subjects, headings, sender details, call-to-action text, and product-view or cart message bodies. Placeholders include {customer_name}, {product_name}, {product_image}, {product_link}, {product_url}, {product_price}, {store_name}, {email_heading}, {cta_button}, and {unsubscribe_url}. Previews use sample data.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-templates', __( 'Email Templates', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . esc_html__( 'The test email option only appears on a local development site and only sends to addresses ending in .test. Check your local mail inbox (for example Mailpit). Never test with real customer addresses.', 'wc-product-view-reminder-lite' ) . '</p><p>' . sprintf( esc_html__( '%s records every attempt and failure. “Sent” means your site handed the email to its mail service; it doesn\'t confirm the email reached the inbox. Check unknown results before trying again.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-history', __( 'Reminder History', 'wc-product-view-reminder-lite' ) ) ) . '</p></section>';

        echo '<section id="wc-pvr-help-reports" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '6. Dashboard and Analytics', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( '%s summarizes views and reminder activity. “Ready to send” counts customers who have passed the waiting period but may still be blocked by consent, purchase or other checks.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-dashboard', __( 'Dashboard', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . sprintf( esc_html__( '%s shows date-range activity, top viewed and reminded products, and send outcomes based on RecoverFlow records. It does not invent conversion rates or revenue.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-analytics', __( 'Analytics', 'wc-product-view-reminder-lite' ) ) ) . '</p></section>';

        echo '<section id="wc-pvr-help-privacy" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '7. Privacy, data export, and retention', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'RecoverFlow records are included in WordPress Tools > Export Personal Data and Erase Personal Data. Erasure removes RecoverFlow views, reminder history, and purchase lookup references. WooCommerce orders are not deleted.', 'wc-product-view-reminder-lite' ) . '</p><p>' . esc_html__( 'Settings > Privacy and retention controls how long views, reminder history, and older consent records are kept. A value of 0 means retain until manual erasure. Cleanup runs in bounded daily batches and retains the latest consent choice.', 'wc-product-view-reminder-lite' ) . '</p><p>' . esc_html__( 'By default, erasure may keep one keyed opt-out suppression hash so another consent integration cannot re-enable reminders for someone who opted out. Review this setting and your retention periods against your privacy obligations.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-troubleshooting" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '8. Troubleshooting', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li><strong>' . esc_html__( 'No reminder is eligible:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Read the customer row’s eligibility explanation. Check current consent, opt-out status, purchase state, product status, waiting period, and frequency limit.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'No product view appears:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Tracking identifies signed-in customers only. Confirm the product is published and the customer stayed on its page for at least the configured tracking delay.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'A reminder is late:', 'wc-product-view-reminder-lite' ) . '</strong> ' . sprintf( esc_html__( 'Check the next and last reminder check times on %s. Checks can run late on sites with little traffic.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-status', __( 'System Status', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li><strong>' . esc_html__( 'No email appears in the inbox:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Check Reminder History and your site\'s mail service. “Sent” isn\'t proof of delivery. On a local site, check Mailpit; otherwise check your email provider\'s logs.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Purchase suppression seems incomplete:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Check System Status for order lookup progress. Existing orders must be indexed; new orders are synchronized through WooCommerce lifecycle hooks.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul><p><strong>' . esc_html__( 'Limitations:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'RecoverFlow does not track anonymous guests, guarantee exactly-once sending, or confirm inbox delivery. It does not provide SMS or WhatsApp reminders.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '</div></div>';
    }

    public function settings_page() {

        $next_run = wp_next_scheduled( 'wc_pvr_cron' );

        ?>

        <div class="wrap wc-pvr-admin">

            <h1 class="wc-pvr-title">
                <?php esc_html_e( 'RecoverFlow Settings', 'wc-product-view-reminder-lite' ); ?>
            </h1>
            <p class="wc-pvr-settings-intro"><?php esc_html_e( 'The tracking delay decides when a product view is counted. The reminder waiting period decides how long to wait before a reminder can go out. They are separate settings.', 'wc-product-view-reminder-lite' ); ?></p>

            <?php settings_errors(); ?>

            <?php
            $schema_notice = isset( $_GET['wc_pvr_consent_schema'] ) && is_scalar( $_GET['wc_pvr_consent_schema'] ) ? sanitize_key( wp_unslash( $_GET['wc_pvr_consent_schema'] ) ) : '';
            $backfill_notice = isset( $_GET['wc_pvr_backfill'] ) && is_scalar( $_GET['wc_pvr_backfill'] ) ? sanitize_key( wp_unslash( $_GET['wc_pvr_backfill'] ) ) : '';
            ?>

            <?php if ( 'failed' === $schema_notice ) : ?>
                <div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Setup didn\'t finish. Please try again, and contact your host if the problem continues.', 'wc-product-view-reminder-lite' ); ?></p></div>
            <?php elseif ( 'ready' === $schema_notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'RecoverFlow storage is set up and ready to use.', 'wc-product-view-reminder-lite' ); ?></p></div>
            <?php endif; ?>

            <?php if ( 'started' === $backfill_notice ) : ?>
                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Purchase lookup has started. It runs in the background and may take a little while on stores with many orders.', 'wc-product-view-reminder-lite' ); ?></p></div>
            <?php endif; ?>

            <?php if ( class_exists( 'WC_PVR_Consent' ) && ! WC_PVR_Consent::is_installed() ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'RecoverFlow needs a one-time setup before it can send reminders. Setup adds storage for consent, reminder history and purchase lookup. Your existing customer and product-view records are kept.', 'wc-product-view-reminder-lite' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wc_pvr_install_consent_schema">
                        <?php wp_nonce_field( 'wc_pvr_install_consent_schema' ); ?>
                        <?php submit_button( __( 'Set up RecoverFlow storage', 'wc-product-view-reminder-lite' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ( class_exists( 'WC_PVR_Consent' ) && WC_PVR_Consent::is_installed() && ! get_option( 'wc_pvr_orders_backfill_complete', 0 ) ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'Reminders are paused until RecoverFlow has checked your past orders, so it never emails someone who already bought the product. This reads your existing WooCommerce orders in small batches of up to 100 and doesn\'t change them.', 'wc-product-view-reminder-lite' ); ?></p>
                    <?php if ( ! wp_next_scheduled( 'wc_pvr_order_backfill' ) ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wc_pvr_start_order_backfill"><?php wp_nonce_field( 'wc_pvr_start_order_backfill' ); ?><?php submit_button( __( 'Build purchase lookup', 'wc-product-view-reminder-lite' ), 'secondary', 'submit', false ); ?></form>
                    <?php else : ?>
                        <p><strong><?php esc_html_e( 'Purchase lookup is queued or processing.', 'wc-product-view-reminder-lite' ); ?></strong></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ( ! get_option( 'wc_pvr_consent_enabled' ) ) : ?>
                <div class="notice notice-info inline"><p><?php esc_html_e( 'Reminders only go to customers who have agreed to marketing emails. Turn on the checkbox below, or connect your existing consent tool.', 'wc-product-view-reminder-lite' ); ?></p></div>
            <?php endif; ?>

            <div class="wc-pvr-card">

                <h2><?php esc_html_e( 'General Settings', 'wc-product-view-reminder-lite' ); ?></h2>

                <form method="post" action="options.php">

                    <?php settings_fields( 'wc_pvr_settings_group' ); ?>

                    <table class="form-table">

                        <tr>
                            <th><?php esc_html_e( 'Send reminders after (hours)', 'wc-product-view-reminder-lite' ); ?></th>
                            <td><input type="number" min="0" max="720" name="wc_pvr_reminder_delay" value="<?php echo esc_attr( get_option( 'wc_pvr_reminder_delay', 24 ) ); ?>"><p class="description"><?php esc_html_e( 'How long to wait after a customer views a product or adds it to their cart before a reminder can be sent.', 'wc-product-view-reminder-lite' ); ?></p></td>
                        </tr>

                        <tr>
                            <th><?php esc_html_e( 'Count a product view after (seconds)', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <input type="number"
                                       min="0"
                                       max="3600"
                                       name="wc_pvr_delay"
                                       value="<?php echo esc_attr( get_option( 'wc_pvr_delay', 5 ) ); ?>">

                                <p class="description">
                                    <?php esc_html_e( 'How long a customer must stay on a product page before the view is counted. This is separate from when reminders are sent.', 'wc-product-view-reminder-lite' ); ?>
                                </p>

                            </td>
                        </tr>


                        <tr>
                            <th><?php esc_html_e( 'How often to check for reminders', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <select name="wc_pvr_cron_interval">

                                    <option value="5min" <?php selected( get_option( 'wc_pvr_cron_interval' ), '5min' ); ?>>
                                        <?php esc_html_e( 'Every 5 Minutes', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                    <option value="15min" <?php selected( get_option( 'wc_pvr_cron_interval' ), '15min' ); ?>>
                                        <?php esc_html_e( 'Every 15 Minutes', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                    <option value="30min" <?php selected( get_option( 'wc_pvr_cron_interval' ), '30min' ); ?>>
                                        <?php esc_html_e( 'Every 30 Minutes', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                    <option value="hourly" <?php selected( get_option( 'wc_pvr_cron_interval' ), 'hourly' ); ?>>
                                        <?php esc_html_e( 'Hourly', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                    <option value="twicedaily" <?php selected( get_option( 'wc_pvr_cron_interval' ), 'twicedaily' ); ?>>
                                        <?php esc_html_e( 'Twice Daily', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                    <option value="daily" <?php selected( get_option( 'wc_pvr_cron_interval' ), 'daily' ); ?>>
                                        <?php esc_html_e( 'Daily', 'wc-product-view-reminder-lite' ); ?>
                                    </option>

                                </select>

                                <p class="description">
                                    <?php esc_html_e( 'How often your site looks for reminders that are ready to send. Timing is approximate.', 'wc-product-view-reminder-lite' ); ?>
                                </p>

                                <?php

                                if ( $next_run ) {

                                    $remaining = $next_run - time();
                                    $minutes   = floor( $remaining / 60 );
                                    $seconds   = $remaining % 60;

                                    echo '<p style="color:#2271b1;font-weight:600;">'
                                         . esc_html__( 'Next Reminder Check:', 'wc-product-view-reminder-lite' )
                                         . ' '
                                         . esc_html( wp_date( 'Y-m-d H:i:s', $next_run ) )
                                         . '</p>';

                                    echo '<p id="wc-pvr-countdown" style="color:#d63638;font-weight:600;">'
                                         . esc_html__( 'Next check in:', 'wc-product-view-reminder-lite' )
                                         . ' '
                                         . esc_html( $minutes )
                                         . ' '
                                         . esc_html__( 'minutes', 'wc-product-view-reminder-lite' )
                                         . ' '
                                         . esc_html( $seconds )
                                         . ' '
                                         . esc_html__( 'seconds', 'wc-product-view-reminder-lite' )
                                         . '</p>';

                                } else {

                                    echo '<p style="color:#999;">'
                                         . esc_html__( 'The next check hasn\'t been scheduled yet.', 'wc-product-view-reminder-lite' )
                                         . '</p>';

                                }

                                ?>

                            </td>
                        </tr>


                        <tr>

                            <th><?php esc_html_e( 'Cart reminders', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <label>

                                    <input type="hidden" name="wc_pvr_cart_reminder" value="0">

                                    <input type="checkbox"
                                           name="wc_pvr_cart_reminder"
                                           value="1"
                                           <?php checked( get_option( 'wc_pvr_cart_reminder' ), 1 ); ?>>

                                    <?php esc_html_e( 'Also remind customers who added a product to their cart but didn\'t buy it', 'wc-product-view-reminder-lite' ); ?>

                                </label>

                            </td>

                        </tr>

                        <tr>
                            <th><label for="wc-pvr-frequency-days"><?php esc_html_e( 'Reminder cooldown (days)', 'wc-product-view-reminder-lite' ); ?></label></th>
                            <td>
                                <input type="number" id="wc-pvr-frequency-days" min="3" max="30" name="wc_pvr_frequency_days" value="<?php echo esc_attr( class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::frequency_days() : 7 ); ?>">
                                <p class="description"><?php esc_html_e( 'How long to wait before the same customer can get another reminder for the same product. Other products are not affected. Choose 3–30 days.', 'wc-product-view-reminder-lite' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th><?php esc_html_e( 'Quiet hours', 'wc-product-view-reminder-lite' ); ?></th>
                            <td>
                                <label>
                                    <input type="hidden" name="wc_pvr_quiet_hours_enabled" value="0">
                                    <input type="checkbox" name="wc_pvr_quiet_hours_enabled" value="1" <?php checked( get_option( 'wc_pvr_quiet_hours_enabled', 0 ), 1 ); ?>>
                                    <?php esc_html_e( 'Only send automatic reminders between these hours', 'wc-product-view-reminder-lite' ); ?>
                                </label>
                                <?php
                                $quiet_start = min( 23, absint( get_option( 'wc_pvr_quiet_hours_start', 9 ) ) );
                                $quiet_end   = min( 23, absint( get_option( 'wc_pvr_quiet_hours_end', 20 ) ) );
                                ?>
                                <p style="margin-top:10px;">
                                    <label for="wc-pvr-quiet-start"><?php esc_html_e( 'From', 'wc-product-view-reminder-lite' ); ?></label>
                                    <select id="wc-pvr-quiet-start" name="wc_pvr_quiet_hours_start">
                                        <?php for ( $hour = 0; $hour <= 23; $hour++ ) : ?>
                                            <option value="<?php echo esc_attr( $hour ); ?>" <?php selected( $quiet_start, $hour ); ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                    <label for="wc-pvr-quiet-end" style="margin-left:8px;"><?php esc_html_e( 'To', 'wc-product-view-reminder-lite' ); ?></label>
                                    <select id="wc-pvr-quiet-end" name="wc_pvr_quiet_hours_end">
                                        <?php for ( $hour = 0; $hour <= 23; $hour++ ) : ?>
                                            <option value="<?php echo esc_attr( $hour ); ?>" <?php selected( $quiet_end, $hour ); ?>><?php echo esc_html( sprintf( '%02d:00', $hour ) ); ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </p>
                                <p class="description"><?php esc_html_e( 'Uses your WordPress site timezone. Manual “Send Reminder” from the admin still works outside these hours. Example: 09:00 to 20:00 means automatic emails only go out during the day.', 'wc-product-view-reminder-lite' ); ?></p>
                            </td>
                        </tr>

                        <tr>
                            <th colspan="2"><h2><?php esc_html_e( 'Excluded products', 'wc-product-view-reminder-lite' ); ?></h2></th>
                        </tr>
                        <tr>
                            <th><label for="wc-pvr-exclude-products"><?php esc_html_e( 'Exclude product IDs', 'wc-product-view-reminder-lite' ); ?></label></th>
                            <td>
                                <input type="text" class="large-text" id="wc-pvr-exclude-products" name="wc_pvr_exclude_product_ids" value="<?php echo esc_attr( (string) get_option( 'wc_pvr_exclude_product_ids', '' ) ); ?>" placeholder="<?php echo esc_attr__( 'e.g. 12, 45, 108', 'wc-product-view-reminder-lite' ); ?>">
                                <p class="description"><?php esc_html_e( 'Comma-separated product IDs that should never be tracked or reminded (for example gift cards or donations).', 'wc-product-view-reminder-lite' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="wc-pvr-exclude-categories"><?php esc_html_e( 'Exclude categories', 'wc-product-view-reminder-lite' ); ?></label></th>
                            <td>
                                <?php
                                $excluded_cats = class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::parse_id_list( get_option( 'wc_pvr_exclude_category_ids', '' ) ) : array();
                                $product_cats  = get_terms(
                                    array(
                                        'taxonomy'   => 'product_cat',
                                        'hide_empty' => false,
                                    )
                                );
                                ?>
                                <input type="hidden" name="wc_pvr_exclude_category_ids" value="">
                                <select id="wc-pvr-exclude-categories" name="wc_pvr_exclude_category_ids[]" multiple size="6" style="min-width:280px;max-width:100%;">
                                    <?php if ( ! is_wp_error( $product_cats ) ) : ?>
                                        <?php foreach ( $product_cats as $term ) : ?>
                                            <option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( in_array( absint( $term->term_id ), $excluded_cats, true ) ); ?>>
                                                <?php echo esc_html( $term->name ); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </select>
                                <p class="description"><?php esc_html_e( 'Hold Ctrl (Windows) or Command (Mac) to select more than one category. Products in these categories are skipped.', 'wc-product-view-reminder-lite' ); ?></p>
                            </td>
                        </tr>

<tr>
    <th><?php esc_html_e( 'Email templates', 'wc-product-view-reminder-lite' ); ?></th>
    <td>
        <p><?php esc_html_e( 'Manage reminder subjects, message bodies, sender details, and previews from the dedicated Email Templates page.', 'wc-product-view-reminder-lite' ); ?></p>
        <a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=wc-pvr-templates' ) ); ?>"><?php esc_html_e( 'Open Email Templates', 'wc-product-view-reminder-lite' ); ?></a>
    </td>
</tr>


                        <tr><th colspan="2"><h2><?php esc_html_e( 'Marketing Consent', 'wc-product-view-reminder-lite' ); ?></h2></th></tr>
                        <tr><th><?php esc_html_e( 'Built-in Consent', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_enabled" value="0"><input type="checkbox" name="wc_pvr_consent_enabled" value="1" <?php checked( get_option( 'wc_pvr_consent_enabled' ), 1 ); ?>> <?php esc_html_e( 'Ask customers if they\'d like to receive reminder emails', 'wc-product-view-reminder-lite' ); ?></label><p class="description"><?php esc_html_e( 'Existing customers are not signed up automatically. The checkbox is optional, so it never blocks registration or checkout.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Registration', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_registration" value="0"><input type="checkbox" name="wc_pvr_consent_registration" value="1" <?php checked( get_option( 'wc_pvr_consent_registration' ), 1 ); ?>> <?php esc_html_e( 'Show the checkbox when customers create an account', 'wc-product-view-reminder-lite' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Checkout', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_checkout" value="0"><input type="checkbox" name="wc_pvr_consent_checkout" value="1" <?php checked( get_option( 'wc_pvr_consent_checkout' ), 1 ); ?>> <?php esc_html_e( 'Show the checkbox at checkout (works with both classic and block checkout)', 'wc-product-view-reminder-lite' ); ?></label></td></tr>
                        <tr><th><label for="wc-pvr-consent-wording"><?php esc_html_e( 'Consent field text', 'wc-product-view-reminder-lite' ); ?></label></th><td><?php
                            $consent_wording = get_option( 'wc_pvr_consent_wording', '' );
                            if ( ! is_string( $consent_wording ) || '' === trim( $consent_wording ) ) {
                                $consent_wording = class_exists( 'WC_PVR_Consent' ) ? WC_PVR_Consent::default_wording() : __( 'I agree to receive product reminders and promotional emails from this store. I can unsubscribe at any time.', 'wc-product-view-reminder-lite' );
                            }
                            ?><input type="text" id="wc-pvr-consent-wording" name="wc_pvr_consent_wording" value="<?php echo esc_attr( $consent_wording ); ?>" class="large-text" maxlength="500"><p class="description"><?php esc_html_e( 'This is the consent text customers see next to the checkbox. Make it clear who the emails are from and what they will receive. Customers can still register or buy without ticking the box.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th colspan="2"><h2><?php esc_html_e( 'Privacy and retention', 'wc-product-view-reminder-lite' ); ?></h2></th></tr>
                        <tr><th><?php esc_html_e( 'Product view retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_views_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_views_days', 0 ) ); ?>"><p class="description"><?php esc_html_e( 'Delete product view records after this many days. Enter 0 to keep them until you erase them yourself.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Reminder history retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_history_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_history_days', 0 ) ); ?>"><p class="description"><?php echo esc_html( sprintf( __( 'Delete reminder history after this many days. Enter 0 to keep it, or use at least %d days so the product cooldown keeps working.', 'wc-product-view-reminder-lite' ), class_exists( 'WC_PVR_Reminder' ) ? WC_PVR_Reminder::frequency_days() : 7 ) ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Consent history retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_consent_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_consent_days', 0 ) ); ?>"><p class="description"><?php esc_html_e( 'Delete older consent records after this many days. Enter 0 to keep them. Each customer\'s latest choice is always kept.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'After privacy erasure', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_preserve_optout_after_erasure" value="0"><input type="checkbox" name="wc_pvr_preserve_optout_after_erasure" value="1" <?php checked( get_option( 'wc_pvr_preserve_optout_after_erasure', 1 ), 1 ); ?>> <?php esc_html_e( 'Remember that the customer opted out, without keeping their email address', 'wc-product-view-reminder-lite' ); ?></label><p class="description"><?php esc_html_e( 'If someone opted out and then asked to be erased, we keep an anonymous marker so they don\'t start receiving reminders again. Check this against your privacy obligations. RecoverFlow never deletes WooCommerce orders.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                    </table>

                    <?php submit_button(); ?>

                </form>

            </div>

            <hr>

            <div class="wc-pvr-pro-box">

                <h2><?php esc_html_e( 'Upgrade to PRO', 'wc-product-view-reminder-lite' ); ?></h2>

                <p><?php esc_html_e( 'Unlock powerful marketing automation features.', 'wc-product-view-reminder-lite' ); ?></p>

                <ul>

                    <li>✔ <?php echo esc_html__( 'WhatsApp reminders', 'wc-product-view-reminder-lite' ); ?></li>
                    <li>✔ <?php echo esc_html__( 'Multi-day reminder campaigns', 'wc-product-view-reminder-lite' ); ?></li>
                    <li>✔ <?php echo esc_html__( 'Guest visitor tracking', 'wc-product-view-reminder-lite' ); ?></li>
                    <li>✔ <?php echo esc_html__( 'Automatic coupon generator', 'wc-product-view-reminder-lite' ); ?></li>
                    <li>✔ <?php echo esc_html__( 'Advanced reminder analytics', 'wc-product-view-reminder-lite' ); ?></li>
                    <li>✔ <?php echo esc_html__( 'Exit intent detection', 'wc-product-view-reminder-lite' ); ?></li>

                </ul>

                <a class="wc-pvr-upgrade-btn"
                   href="<?php echo esc_url( '#' ); ?>"
                   target="_blank"
                   rel="noopener">

                    <?php esc_html_e( 'Upgrade to Pro', 'wc-product-view-reminder-lite' ); ?>

                </a>

            </div>

        </div>

        <?php

    }

}
