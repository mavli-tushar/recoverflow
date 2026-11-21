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
                'remaining' => (int) $remaining
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
        if ( ! absint( get_option( 'wc_pvr_orders_backfill_page', 0 ) ) ) { update_option( 'wc_pvr_orders_backfill_page', 1, false ); }
        if ( ! wp_next_scheduled( 'wc_pvr_order_backfill' ) ) { wp_schedule_single_event( time() + 5, 'wc_pvr_order_backfill' ); }
        wp_safe_redirect( add_query_arg( 'wc_pvr_backfill', 'started', admin_url( 'admin.php?page=wc-pvr' ) ) );
        exit;
    }

    private function views_table_exists() {
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    public function dashboard_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'RecoverFlow Dashboard', 'wc-product-view-reminder-lite' ) . '</h1>';
        if ( ! $this->views_table_exists() ) {
            echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tracking storage is not available. Activate RecoverFlow to create its view table.', 'wc-product-view-reminder-lite' ) . '</p></div></div>';
            return;
        }
        $stats = $wpdb->get_row( "SELECT COUNT(*) AS total_views, COUNT(DISTINCT CASE WHEN user_id > 0 THEN user_id END) AS identified_customers, SUM(view_reminder_sent = 1 OR cart_reminder_sent = 1) AS sent, SUM(purchased = 1) AS purchased FROM {$table}" );
        $delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
        $cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $delay * HOUR_IN_SECONDS ) );
        $cart_enabled = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
        $pending = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE purchased = 0 AND ((added_to_cart = 0 AND view_reminder_sent = 0 AND view_time <= %s) OR (added_to_cart = 1 AND cart_reminder_sent = 0 AND %d = 1 AND COALESCE(cart_added_at,view_time) <= %s))", $cutoff, $cart_enabled, $cutoff ) );
        $cards = array(
            __( 'Product views', 'wc-product-view-reminder-lite' ) => absint( $stats->total_views ),
            __( 'Identified customers', 'wc-product-view-reminder-lite' ) => absint( $stats->identified_customers ),
            __( 'Age-qualified queue rows', 'wc-product-view-reminder-lite' ) => $pending,
            __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ) => absint( $stats->sent ),
            __( 'Marked purchased', 'wc-product-view-reminder-lite' ) => absint( $stats->purchased ),
        );
        echo '<div class="wc-pvr-dashboard-grid">';
        foreach ( $cards as $label => $value ) {
            echo '<section class="wc-pvr-stat-card"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( number_format_i18n( $value ) ) . '</p></section>';
        }
        echo '</div><p class="description">' . esc_html__( 'A wp_mail accepted result means WordPress accepted the message, not that it reached an inbox. Consent is checked per customer. See Analytics and Reminder History for recorded results. Revenue and conversion are not inferred.', 'wc-product-view-reminder-lite' ) . '</p>';
        echo '</div>';
    }

    public function reminders_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $table = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'Customer Reminders', 'wc-product-view-reminder-lite' ) . '</h1>';
        if ( ! $this->views_table_exists() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Tracking storage is not available.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        if ( isset( $_GET['wc_pvr_send_result'] ) ) {
            $send_result = sanitize_key( wp_unslash( $_GET['wc_pvr_send_result'] ) );
            $accepted = 'accepted' === $send_result;
            $unknown = 'unknown' === $send_result;
            $notice_class = $accepted ? 'notice-success' : ( $unknown ? 'notice-warning' : 'notice-error' );
            $notice_text = $accepted ? __( 'wp_mail accepted the reminder. Inbox delivery is not confirmed.', 'wc-product-view-reminder-lite' ) : ( $unknown ? __( 'The mailer was called but the result could not be saved. Check Reminder History and do not retry while the outcome is unknown.', 'wc-product-view-reminder-lite' ) : __( 'The reminder was not accepted. See Reminder History for the recorded result or blocking reason.', 'wc-product-view-reminder-lite' ) );
            echo '<div class="notice ' . esc_attr( $notice_class ) . ' is-dismissible"><p>' . esc_html( $notice_text ) . '</p></div>';
        }
        $per_page = 20;
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $where = '';
        $args = array();
        if ( '' !== $search ) { $where = ' WHERE email LIKE %s'; $args[] = '%' . $wpdb->esc_like( $search ) . '%'; }
        $total = (int) ( $args ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table}{$where}", $args ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) );
        $offset = ( $paged - 1 ) * $per_page;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table}{$where} ORDER BY id DESC LIMIT %d OFFSET %d", $query_args ) );
        if ( empty( $rows ) ) { echo '<p>' . esc_html( $search ? __( 'No customers matched your search.', 'wc-product-view-reminder-lite' ) : __( 'No product views have been recorded yet.', 'wc-product-view-reminder-lite' ) ) . '</p></div>'; return; }
        echo '<form method="get"><input type="hidden" name="page" value="wc-pvr-reminders"><label for="wc-pvr-customer-search">' . esc_html__( 'Search customer email', 'wc-product-view-reminder-lite' ) . '</label> <input id="wc-pvr-customer-search" type="search" name="s" value="' . esc_attr( $search ) . '"><button class="button">' . esc_html__( 'Search', 'wc-product-view-reminder-lite' ) . '</button></form>';
        echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Product', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Last viewed', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Consent', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Eligibility', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Scheduled', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Reminder status', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Purchase', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Action', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
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
                $scheduled = __( 'Unavailable', 'wc-product-view-reminder-lite' );
                $is_waiting = false;
            }
            if ( $row->purchased ) { $status = __( 'Purchased', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Suppressed', 'wc-product-view-reminder-lite' ); }
            elseif ( ! $product ) { $status = __( 'Invalid product', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Unavailable', 'wc-product-view-reminder-lite' ); }
            elseif ( ! is_email( $email ) ) { $status = __( 'Invalid email', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $opted_out ) { $status = __( 'Suppressed: opted out', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( ! $eligible ) { $status = __( 'Consent required', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Ineligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $row->view_reminder_sent || $row->cart_reminder_sent ) { $status = __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible', 'wc-product-view-reminder-lite' ); }
            elseif ( $is_waiting ) { $status = __( 'Waiting period', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible; waiting', 'wc-product-view-reminder-lite' ); }
            else { $status = __( 'Pending', 'wc-product-view-reminder-lite' ); $eligibility_label = __( 'Eligible', 'wc-product-view-reminder-lite' ); }
            $customer = $user ? trim( $user->first_name . ' ' . $user->last_name ) : '';
            if ( '' === $customer ) { $customer = $email; }
            $consent_label = 'opted_in' === $consent ? __( 'Opted in', 'wc-product-view-reminder-lite' ) : ( 'opted_out' === $consent || $opted_out ? __( 'Opted out', 'wc-product-view-reminder-lite' ) : ( $external_ok ? __( 'Integration eligible', 'wc-product-view-reminder-lite' ) : __( 'No consent', 'wc-product-view-reminder-lite' ) ) );
            echo '<tr><td>' . esc_html( $customer ) . '<br><code>' . esc_html( $email ) . '</code></td><td>';
            if ( $product ) { echo wp_kses_post( $product->get_image( array( 40, 40 ) ) ) . ' <a href="' . esc_url( get_edit_post_link( $product->get_id() ) ) . '">' . esc_html( $product->get_name() ) . '</a><br>' . wp_kses_post( wc_price( $product->get_price() ) ); }
            else { echo esc_html__( 'Product unavailable', 'wc-product-view-reminder-lite' ); }
            echo '</td><td>' . esc_html( $row->view_time ) . '</td><td>' . esc_html( $consent_label ) . '</td><td>' . esc_html( $eligibility_label ) . '</td><td>' . esc_html( $scheduled ) . '</td><td>' . esc_html( $status ) . '</td><td>' . ( $row->purchased ? esc_html__( 'Yes', 'wc-product-view-reminder-lite' ) : esc_html__( 'No', 'wc-product-view-reminder-lite' ) ) . '</td><td>';
            $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
            $manual_allowed = $service instanceof WC_PVR_Reminder ? $service->can_send( $row, true, false ) : new WP_Error( 'service_unavailable' );
            if ( ! $row->view_reminder_sent && ! $row->cart_reminder_sent && ! is_wp_error( $manual_allowed ) ) {
                echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wc-pvr-manual-send">';
                echo '<input type="hidden" name="action" value="wc_pvr_send_reminder"><input type="hidden" name="view_id" value="' . esc_attr( absint( $row->id ) ) . '"><input type="hidden" name="confirmed" value="0">';
                wp_nonce_field( 'wc_pvr_send_reminder_' . absint( $row->id ) );
                echo '<button type="submit" class="button button-primary" data-confirm="' . esc_attr__( 'Send this reminder now? Consent, opt-out, purchase, and frequency checks will run again.', 'wc-product-view-reminder-lite' ) . '">' . esc_html__( 'Send Reminder', 'wc-product-view-reminder-lite' ) . '</button></form>';
            } else {
                $reason = is_wp_error( $manual_allowed ) ? $manual_allowed->get_error_message() : __( 'A reminder has already been accepted.', 'wc-product-view-reminder-lite' );
                echo '<span class="description">' . esc_html( $reason ) . '</span>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        $pages = (int) ceil( $total / $per_page );
        if ( $pages > 1 ) { echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array( 'paged' => '%#%', 's' => $search ) ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>'; }
        echo '<p class="description">' . esc_html__( 'Manual sends bypass only the waiting period. Consent, opt-outs, product availability, purchase suppression, duplicate prevention, and frequency limits remain enforced. “Accepted” means wp_mail accepted the message, not confirmed delivery.', 'wc-product-view-reminder-lite' ) . '</p></div>';
    }

    private function history_table_exists() {
        global $wpdb;
        $table = WC_PVR_Consent::history_table_name();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    private function safe_date_filter( $key, $fallback ) {
        $value = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $fallback;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return $fallback; }
        $parts = array_map( 'intval', explode( '-', $value ) );
        return checkdate( $parts[1], $parts[2], $parts[0] ) ? $value : $fallback;
    }

    public function history_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $history = WC_PVR_Consent::history_table_name();
        $views = $wpdb->prefix . 'wc_pvr_views';
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'Reminder History', 'wc-product-view-reminder-lite' ) . '</h1>';
        if ( ! $this->history_table_exists() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Reminder history storage is not available. Run the RecoverFlow storage setup from Settings.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        $today = current_datetime()->format( 'Y-m-d' );
        $from = $this->safe_date_filter( 'date_from', current_datetime()->modify( '-30 days' )->format( 'Y-m-d' ) );
        $to = $this->safe_date_filter( 'date_to', $today );
        if ( $from > $to ) { $from = $to; }
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
        if ( ! in_array( $status, array( '', 'accepted', 'failed', 'blocked', 'pending' ), true ) ) { $status = ''; }
        $trigger = isset( $_GET['trigger'] ) ? sanitize_key( wp_unslash( $_GET['trigger'] ) ) : '';
        if ( ! in_array( $trigger, array( '', 'automatic', 'manual' ), true ) ) { $trigger = ''; }
        $search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $where = array( 'h.attempted_at >= %s', 'h.attempted_at < %s' );
        $to_exclusive = ( new DateTimeImmutable( $to . ' 00:00:00', wp_timezone() ) )->modify( '+1 day' )->format( 'Y-m-d H:i:s' );
        $args = array( $from . ' 00:00:00', $to_exclusive );
        if ( $status ) { $where[] = 'h.result = %s'; $args[] = $status; }
        if ( $trigger ) { $where[] = 'h.trigger_type = %s'; $args[] = $trigger; }
        if ( '' !== $search ) { $where[] = '(v.email LIKE %s OR CAST(h.user_id AS CHAR) = %s)'; $args[] = '%' . $wpdb->esc_like( $search ) . '%'; $args[] = $search; }
        $where_sql = ' WHERE ' . implode( ' AND ', $where );
        $total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} h LEFT JOIN {$views} v ON v.id = h.view_id{$where_sql}", $args ) );
        $per_page = 25;
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $offset = ( $paged - 1 ) * $per_page;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = $offset;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT h.*,v.email FROM {$history} h LEFT JOIN {$views} v ON v.id = h.view_id{$where_sql} ORDER BY h.attempted_at DESC,h.id DESC LIMIT %d OFFSET %d", $query_args ) );
        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-history"><label>' . esc_html__( 'Search email or user ID', 'wc-product-view-reminder-lite' ) . ' <input type="search" name="s" value="' . esc_attr( $search ) . '"></label> <label>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option>';
        foreach ( array( 'accepted' => __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ), 'failed' => __( 'Failed', 'wc-product-view-reminder-lite' ), 'blocked' => __( 'Blocked before send', 'wc-product-view-reminder-lite' ), 'pending' => __( 'Outcome unknown', 'wc-product-view-reminder-lite' ) ) as $value => $label ) { echo '<option value="' . esc_attr( $value ) . '" ' . selected( $status, $value, false ) . '>' . esc_html( $label ) . '</option>'; }
        echo '</select></label> <label>' . esc_html__( 'Trigger', 'wc-product-view-reminder-lite' ) . ' <select name="trigger"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option><option value="automatic" ' . selected( $trigger, 'automatic', false ) . '>' . esc_html__( 'Automatic', 'wc-product-view-reminder-lite' ) . '</option><option value="manual" ' . selected( $trigger, 'manual', false ) . '>' . esc_html__( 'Manual', 'wc-product-view-reminder-lite' ) . '</option></select></label> <label>' . esc_html__( 'From', 'wc-product-view-reminder-lite' ) . ' <input type="date" name="date_from" value="' . esc_attr( $from ) . '"></label> <label>' . esc_html__( 'To', 'wc-product-view-reminder-lite' ) . ' <input type="date" name="date_to" value="' . esc_attr( $to ) . '"></label> <button class="button">' . esc_html__( 'Filter', 'wc-product-view-reminder-lite' ) . '</button></form>';
        echo '<p class="description">' . esc_html__( '“wp_mail accepted” means WordPress accepted the message for sending. It does not confirm delivery to an inbox. Failure codes are technical identifiers; blocked rows explain why a manual request did not reach the mailer.', 'wc-product-view-reminder-lite' ) . '</p>';
        if ( empty( $rows ) ) { echo '<p>' . esc_html__( 'No reminder attempts match these filters.', 'wc-product-view-reminder-lite' ) . '</p>'; }
        else {
            echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Attempt time', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Customer', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Product', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Type', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Trigger', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Result', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Reason', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Retry count', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $product = wc_get_product( absint( $row->product_id ) );
                $result_label = 'accepted' === $row->result ? __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ) : ( 'pending' === $row->result ? __( 'Outcome unknown', 'wc-product-view-reminder-lite' ) : ( 'blocked' === $row->result ? __( 'Blocked', 'wc-product-view-reminder-lite' ) : __( 'Failed', 'wc-product-view-reminder-lite' ) ) );
                $customer_reference = $row->email ? $row->email : ( absint( $row->user_id ) ? sprintf( __( 'Customer #%d', 'wc-product-view-reminder-lite' ), absint( $row->user_id ) ) : sprintf( __( 'Deleted view #%d', 'wc-product-view-reminder-lite' ), absint( $row->view_id ) ) );
                $failure_reason = 'pending' === $row->result ? __( 'Worker stopped before recording a result; delivery status is unknown.', 'wc-product-view-reminder-lite' ) : $this->failure_reason_label( $row->failure_code );
                echo '<tr><td>' . esc_html( $row->attempted_at ) . '</td><td>' . esc_html( $customer_reference ) . '</td><td>' . esc_html( $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $row->product_id ) ) ) . '</td><td>' . esc_html( ucfirst( $row->reminder_type ) ) . '</td><td>' . esc_html( ucfirst( $row->trigger_type ) ) . '</td><td>' . esc_html( $result_label ) . '</td><td>' . esc_html( $failure_reason ) . '</td><td>' . esc_html( absint( $row->retry_count ) ) . '</td></tr>';
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
            'wp_mail_returned_false' => __( 'wp_mail() returned false', 'wc-product-view-reminder-lite' ),
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
        $label = isset( $labels[ $code ] ) ? $labels[ $code ] : sprintf( __( 'Mailer or validation error (%s)', 'wc-product-view-reminder-lite' ), sanitize_key( $code ) );
        return $label;
    }

    public function analytics_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $views = $wpdb->prefix . 'wc_pvr_views';
        $history = WC_PVR_Consent::history_table_name();
        $days = isset( $_GET['days'] ) ? absint( $_GET['days'] ) : 30;
        if ( ! in_array( $days, array( 7, 30, 90 ), true ) ) { $days = 30; }
        $from_dt = current_datetime()->modify( '-' . ( $days - 1 ) . ' days' )->setTime( 0, 0, 0 );
        $from = $from_dt->format( 'Y-m-d H:i:s' );
        $to = current_datetime()->modify( '+1 day' )->setTime( 0, 0, 0 )->format( 'Y-m-d H:i:s' );
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'RecoverFlow Analytics', 'wc-product-view-reminder-lite' ) . '</h1><form method="get"><input type="hidden" name="page" value="wc-pvr-analytics"><label for="wc-pvr-analytics-days">' . esc_html__( 'Date range', 'wc-product-view-reminder-lite' ) . '</label> <select id="wc-pvr-analytics-days" name="days"><option value="7" ' . selected( $days, 7, false ) . '>7 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option><option value="30" ' . selected( $days, 30, false ) . '>30 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option><option value="90" ' . selected( $days, 90, false ) . '>90 ' . esc_html__( 'days', 'wc-product-view-reminder-lite' ) . '</option></select> <button class="button">' . esc_html__( 'Apply', 'wc-product-view-reminder-lite' ) . '</button></form>';
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
        $cards = array( __( 'Product views', 'wc-product-view-reminder-lite' ) => $views_count, __( 'Unique identified customers', 'wc-product-view-reminder-lite' ) => $unique_customers, __( 'Pending due candidates', 'wc-product-view-reminder-lite' ) => $pending, __( 'Verified eligible (scanned)', 'wc-product-view-reminder-lite' ) => $eligible, __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ) => $accepted, __( 'Failed mail attempts', 'wc-product-view-reminder-lite' ) => $failed, __( 'Purchased view records', 'wc-product-view-reminder-lite' ) => $purchased, __( 'Purchase suppressions (recorded)', 'wc-product-view-reminder-lite' ) => $suppressed );
        echo '<div class="wc-pvr-dashboard-grid">'; foreach ( $cards as $label => $value ) { echo '<section class="wc-pvr-stat-card"><h2>' . esc_html( $label ) . '</h2><p>' . esc_html( number_format_i18n( $value ) ) . '</p></section>'; } echo '</div><p class="description">' . esc_html( sprintf( __( 'Eligibility checked against up to %1$d due records; the displayed eligible count is for this bounded scan. Reminder acceptance is not inbox delivery. No revenue or conversion estimate is inferred.', 'wc-product-view-reminder-lite' ), $checked ) ) . '</p>';
        $daily_views = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(view_time) AS day,COUNT(*) AS total FROM {$views} WHERE view_time >= %s AND view_time < %s GROUP BY DATE(view_time) ORDER BY day ASC", $from, $to ) );
        echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Product views by day', 'wc-product-view-reminder-lite' ) . '</h2>';
        if ( empty( $daily_views ) ) { echo '<p>' . esc_html__( 'No product views in this date range.', 'wc-product-view-reminder-lite' ) . '</p>'; }
        else { $max = max( 1, max( array_map( static function( $row ) { return absint( $row->total ); }, $daily_views ) ) ); echo '<ul class="wc-pvr-bars">'; foreach ( $daily_views as $daily ) { $width = (int) round( 100 * absint( $daily->total ) / $max ); echo '<li><span>' . esc_html( $daily->day ) . '</span><span class="wc-pvr-bar-track"><span class="wc-pvr-bar" style="width:' . esc_attr( $width ) . '%"></span></span><strong>' . esc_html( number_format_i18n( absint( $daily->total ) ) ) . '</strong></li>'; } echo '</ul>'; }
        echo '</section>';
        $top_views = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,COUNT(*) AS total FROM {$views} WHERE view_time >= %s AND view_time < %s GROUP BY product_id ORDER BY total DESC LIMIT 10", $from, $to ) );
        $top_reminded = $wpdb->get_results( $wpdb->prepare( "SELECT product_id,COUNT(*) AS total FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s AND result = 'accepted' AND trigger_type IN ('automatic','manual') GROUP BY product_id ORDER BY total DESC LIMIT 10", $from, $to ) );
        echo '<div class="wc-pvr-dashboard-grid"><section class="wc-pvr-card"><h2>' . esc_html__( 'Top viewed products', 'wc-product-view-reminder-lite' ) . '</h2>' . $this->render_product_ranking( $top_views ) . '</section><section class="wc-pvr-card"><h2>' . esc_html__( 'Top reminded products', 'wc-product-view-reminder-lite' ) . '</h2>' . $this->render_product_ranking( $top_reminded ) . '</section></div>';
        $daily_attempts = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(attempted_at) AS day,result,COUNT(*) AS total FROM {$history} WHERE attempted_at >= %s AND attempted_at < %s GROUP BY DATE(attempted_at),result ORDER BY day ASC", $from, $to ) );
        echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Reminder activity', 'wc-product-view-reminder-lite' ) . '</h2>';
        if ( empty( $daily_attempts ) ) { echo '<p>' . esc_html__( 'No reminder attempts in this date range.', 'wc-product-view-reminder-lite' ) . '</p>'; }
        else { echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Result', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Attempts', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>'; foreach ( $daily_attempts as $attempt ) { echo '<tr><td>' . esc_html( $attempt->day ) . '</td><td>' . esc_html( 'accepted' === $attempt->result ? __( 'wp_mail accepted', 'wc-product-view-reminder-lite' ) : ( 'failed' === $attempt->result ? __( 'Failed', 'wc-product-view-reminder-lite' ) : ( 'pending' === $attempt->result ? __( 'Outcome unknown', 'wc-product-view-reminder-lite' ) : __( 'Blocked', 'wc-product-view-reminder-lite' ) ) ) ) . '</td><td>' . esc_html( absint( $attempt->total ) ) . '</td></tr>'; } echo '</tbody></table></div>'; }
        echo '</section></div>';
    }

    private function render_product_ranking( $items ) {
        if ( empty( $items ) ) { return '<p>' . esc_html__( 'No records in this date range.', 'wc-product-view-reminder-lite' ) . '</p>'; }
        $html = '<ol class="wc-pvr-ranking">';
        foreach ( $items as $item ) { $product = wc_get_product( absint( $item->product_id ) ); $label = $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $item->product_id ) ); $html .= '<li><span>' . esc_html( $label ) . '</span><strong>' . esc_html( number_format_i18n( absint( $item->total ) ) ) . '</strong></li>'; }
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

    private function mask_email( $email ) {
        $email = sanitize_email( $email );
        if ( ! is_email( $email ) ) { return ''; }
        list( $local, $domain ) = explode( '@', $email, 2 );
        return substr( $local, 0, 1 ) . str_repeat( '*', max( 2, min( 8, strlen( $local ) - 1 ) ) ) . '@' . $domain;
    }

    public function consent_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'Consent Management', 'wc-product-view-reminder-lite' ) . '</h1>';
        if ( ! WC_PVR_Consent::is_installed() ) { echo '<div class="notice notice-warning"><p>' . esc_html__( 'Consent ledger storage is not ready. Complete storage setup from Settings first.', 'wc-product-view-reminder-lite' ) . '</p></div></div>'; return; }
        if ( isset( $_GET['wc_pvr_revocation'] ) ) {
            $saved = 'saved' === sanitize_key( wp_unslash( $_GET['wc_pvr_revocation'] ) );
            echo '<div class="notice ' . ( $saved ? 'notice-success' : 'notice-error' ) . ' is-dismissible"><p>' . esc_html( $saved ? __( 'The verified revocation was recorded. This customer is now opted out.', 'wc-product-view-reminder-lite' ) : __( 'The revocation could not be recorded. Refresh the consent record and try again.', 'wc-product-view-reminder-lite' ) ) . '</p></div>';
        }
        global $wpdb;
        $table = WC_PVR_Consent::table_name();
        $latest = " FROM {$table} c WHERE NOT EXISTS (SELECT 1 FROM {$table} newer WHERE newer.email_hash = c.email_hash AND newer.id > c.id)";
        $opted_in = absint( $wpdb->get_var( "SELECT COUNT(*){$latest} AND c.status = 'opted_in'" ) );
        $opted_out = absint( $wpdb->get_var( "SELECT COUNT(*){$latest} AND c.status = 'opted_out'" ) );
        echo '<div class="wc-pvr-dashboard-grid"><section class="wc-pvr-stat-card"><h2>' . esc_html__( 'Currently opted in', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( number_format_i18n( $opted_in ) ) . '</p></section><section class="wc-pvr-stat-card"><h2>' . esc_html__( 'Currently opted out', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html( number_format_i18n( $opted_out ) ) . '</p></section></div>';
        $eligibility_filter = has_filter( 'wc_pvr_customer_is_eligible_for_marketing' );
        $optout_filter = has_filter( 'wc_pvr_customer_has_marketing_opted_out' );
        echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Eligibility and integration status', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'Product views are not consent. Customers without a current explicit opt-in or an approved store adapter are denied by default. A RecoverFlow opt-out always overrides positive signals.', 'wc-product-view-reminder-lite' ) . '</p><ul><li>' . esc_html__( 'Eligibility adapter:', 'wc-product-view-reminder-lite' ) . ' <strong>' . esc_html( $eligibility_filter ? __( 'registered', 'wc-product-view-reminder-lite' ) : __( 'not registered; default deny', 'wc-product-view-reminder-lite' ) ) . '</strong></li><li>' . esc_html__( 'Additional opt-out adapter:', 'wc-product-view-reminder-lite' ) . ' <strong>' . esc_html( $optout_filter ? __( 'registered', 'wc-product-view-reminder-lite' ) : __( 'not registered', 'wc-product-view-reminder-lite' ) ) . '</strong></li></ul><p class="description">' . esc_html__( 'The plugin can confirm a recorded opt-out or internal opt-in here. An external consent adapter must provide its own customer-level status and explanation. A filter can be registered but still deny every customer.', 'wc-product-view-reminder-lite' ) . '</p></section>';
        $search = isset( $_GET['s'] ) && is_scalar( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
        $status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
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
        $paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
        $query_args = $args;
        $query_args[] = $per_page;
        $query_args[] = ( $paged - 1 ) * $per_page;
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM {$table} c{$where_sql} ORDER BY c.id DESC LIMIT %d OFFSET %d", $query_args ) );
        echo '<form method="get" class="wc-pvr-filters"><input type="hidden" name="page" value="wc-pvr-consent"><label>' . esc_html__( 'Search email or customer ID', 'wc-product-view-reminder-lite' ) . ' <input type="search" name="s" value="' . esc_attr( $search ) . '"></label><label>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . ' <select name="status"><option value="">' . esc_html__( 'All', 'wc-product-view-reminder-lite' ) . '</option><option value="opted_in" ' . selected( $status, 'opted_in', false ) . '>' . esc_html__( 'Opted in', 'wc-product-view-reminder-lite' ) . '</option><option value="opted_out" ' . selected( $status, 'opted_out', false ) . '>' . esc_html__( 'Opted out', 'wc-product-view-reminder-lite' ) . '</option></select></label><button class="button">' . esc_html__( 'Filter records', 'wc-product-view-reminder-lite' ) . '</button></form>';
        if ( empty( $rows ) ) { echo '<div class="wc-pvr-empty"><p>' . esc_html__( 'No consent records match this search. No record means marketing eligibility is denied unless a registered adapter confirms permission.', 'wc-product-view-reminder-lite' ) . '</p></div>'; }
        else {
            echo '<div class="wc-pvr-table-wrap"><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Customer reference', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Status', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Source', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Channel', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Last updated', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Eligibility explanation', 'wc-product-view-reminder-lite' ) . '</th><th>' . esc_html__( 'Verified revocation', 'wc-product-view-reminder-lite' ) . '</th></tr></thead><tbody>';
            foreach ( $rows as $row ) {
                $user = $row->user_id ? get_userdata( absint( $row->user_id ) ) : false;
                $reference = $user ? sprintf( __( '%1$s (customer #%2$d)', 'wc-product-view-reminder-lite' ), $this->mask_email( $user->user_email ), absint( $row->user_id ) ) : sprintf( __( 'Guest email reference …%s', 'wc-product-view-reminder-lite' ), substr( $row->email_hash, -8 ) );
                $status_label = 'opted_in' === $row->status ? __( 'Opted in', 'wc-product-view-reminder-lite' ) : __( 'Opted out', 'wc-product-view-reminder-lite' );
                if ( 'opted_out' === $row->status && 'privacy_eraser' === $row->source ) {
                    $reason = __( 'Ineligible: suppressed after privacy erasure. This minimal hash prevents an external adapter from re-enabling reminders.', 'wc-product-view-reminder-lite' );
                } else {
                    $reason = 'opted_out' === $row->status ? __( 'Ineligible: explicit opt-out. Positive adapter signals cannot override this.', 'wc-product-view-reminder-lite' ) : __( 'Internal opt-in recorded. Product, purchase, frequency, and external opt-out checks still apply at send time.', 'wc-product-view-reminder-lite' );
                }
                echo '<tr><td>' . esc_html( $reference ) . '</td><td><span class="wc-pvr-status wc-pvr-status-' . esc_attr( $row->status ) . '">' . esc_html( $status_label ) . '</span></td><td>' . esc_html( $row->source ) . '</td><td>' . esc_html( $row->channel ) . '</td><td>' . esc_html( $row->created_at ) . '</td><td>' . esc_html( $reason ) . '</td><td>';
                if ( 'opted_in' === $row->status ) {
                    echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" onsubmit="return window.confirm(\'' . esc_js( __( 'Only confirm this action after you have verified a customer request to revoke consent. This records an opt-out and cannot opt anyone in.', 'wc-product-view-reminder-lite' ) ) . '\');"><input type="hidden" name="action" value="wc_pvr_record_revocation"><input type="hidden" name="record_id" value="' . esc_attr( absint( $row->id ) ) . '"><input type="hidden" name="verified_request" value="1">' . wp_nonce_field( 'wc_pvr_record_revocation_' . absint( $row->id ), '_wpnonce', true, false ) . '<button class="button button-secondary">' . esc_html__( 'Record verified opt-out', 'wc-product-view-reminder-lite' ) . '</button></form>';
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
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'System Status', 'wc-product-view-reminder-lite' ) . '</h1><p class="description">' . esc_html__( 'A scheduled event indicates WP-Cron has a pending event. Only the last completed timestamp below confirms that the RecoverFlow callback ran to completion.', 'wc-product-view-reminder-lite' ) . '</p><div class="wc-pvr-table-wrap"><table class="widefat striped"><tbody>';
        $rows = array(
            __( 'WordPress', 'wc-product-view-reminder-lite' ) => get_bloginfo( 'version' ),
            __( 'PHP', 'wc-product-view-reminder-lite' ) => PHP_VERSION,
            __( 'WooCommerce', 'wc-product-view-reminder-lite' ) => defined( 'WC_VERSION' ) ? WC_VERSION : __( 'Not detected', 'wc-product-view-reminder-lite' ),
            __( 'HPOS order storage', 'wc-product-view-reminder-lite' ) => $hpos,
            __( 'RecoverFlow schema', 'wc-product-view-reminder-lite' ) => get_option( 'wc_pvr_consent_db_version', __( 'Not installed', 'wc-product-view-reminder-lite' ) ) . ( WC_PVR_Consent::is_installed() ? ' (' . __( 'ready', 'wc-product-view-reminder-lite' ) . ')' : ' (' . __( 'not ready', 'wc-product-view-reminder-lite' ) . ')' ),
            __( 'Consent ledger', 'wc-product-view-reminder-lite' ) => WC_PVR_Consent::is_installed() ? __( 'Available; default deny remains active', 'wc-product-view-reminder-lite' ) : __( 'Unavailable', 'wc-product-view-reminder-lite' ),
            __( 'Purchase lookup', 'wc-product-view-reminder-lite' ) => get_option( 'wc_pvr_orders_backfill_complete', 0 ) ? __( 'Complete', 'wc-product-view-reminder-lite' ) : sprintf( __( 'Incomplete; current batch page %d', 'wc-product-view-reminder-lite' ), max( 1, absint( get_option( 'wc_pvr_orders_backfill_page', 1 ) ) ) ),
            __( 'WP-Cron event scheduled', 'wc-product-view-reminder-lite' ) => $next_cron ? wp_date( 'Y-m-d H:i:s T', $next_cron ) : __( 'No event scheduled', 'wc-product-view-reminder-lite' ),
            __( 'Last completed cron run', 'wc-product-view-reminder-lite' ) => $last_cron ? $last_cron : __( 'No completed run recorded', 'wc-product-view-reminder-lite' ),
            __( 'Retention cleanup scheduled', 'wc-product-view-reminder-lite' ) => $next_cleanup ? wp_date( 'Y-m-d H:i:s T', $next_cleanup ) : __( 'No cleanup event scheduled', 'wc-product-view-reminder-lite' ),
            __( 'Last retention cleanup', 'wc-product-view-reminder-lite' ) => $last_cleanup ? $last_cleanup : __( 'No cleanup run recorded', 'wc-product-view-reminder-lite' ),
            __( 'Age-qualified pending rows', 'wc-product-view-reminder-lite' ) => sprintf( __( '%s (before consent and purchase checks)', 'wc-product-view-reminder-lite' ), number_format_i18n( $pending ) ),
            __( 'Failed attempts in last 7 days', 'wc-product-view-reminder-lite' ) => number_format_i18n( $failures ),
            __( 'Email configuration', 'wc-product-view-reminder-lite' ) => function_exists( 'wp_mail' ) && is_email( $sender ) ? sprintf( __( 'wp_mail() available; sender address %s is syntactically valid. Connection and inbox delivery are not verified.', 'wc-product-view-reminder-lite' ), $sender ) : __( 'wp_mail() unavailable or sender address is invalid.', 'wc-product-view-reminder-lite' ),
        );
        foreach ( $rows as $label => $value ) { echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>'; }
        echo '</tbody></table></div><section class="wc-pvr-card"><h2>' . esc_html__( 'Troubleshooting', 'wc-product-view-reminder-lite' ) . '</h2><ul><li>' . esc_html__( 'If purchase lookup is incomplete, open Settings and start Build purchase lookup. Reminder sending remains blocked until all bounded WooCommerce CRUD batches finish.', 'wc-product-view-reminder-lite' ) . '</li><li>' . esc_html__( 'If the cron event is scheduled but the completion time is empty or stale, visit the site regularly or configure a system cron to call wp-cron.php.', 'wc-product-view-reminder-lite' ) . '</li><li>' . esc_html__( 'If wp_mail() is accepted but no message appears, check your configured mail transport. Accepted means handed to WordPress mail transport, not delivered.', 'wc-product-view-reminder-lite' ) . '</li></ul><a class="button" href="' . esc_url( admin_url( 'admin.php?page=wc-pvr-templates' ) ) . '">' . esc_html__( 'Open Email Templates and local test', 'wc-product-view-reminder-lite' ) . '</a></section></div>';
    }

    public function templates_page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        echo '<div class="wrap wc-pvr-admin"><h1>' . esc_html__( 'Email Templates', 'wc-product-view-reminder-lite' ) . '</h1>';
        settings_errors();
        if ( isset( $_GET['wc_pvr_test_result'] ) ) { $ok = 'accepted' === sanitize_key( wp_unslash( $_GET['wc_pvr_test_result'] ) ); echo '<div class="notice ' . ( $ok ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( $ok ? __( 'Local test message was accepted by wp_mail(). Check LocalWP Mailpit/MailHog; acceptance does not prove delivery.', 'wc-product-view-reminder-lite' ) : __( 'Test send blocked or failed. Local testing requires a local environment and an address ending in .test.', 'wc-product-view-reminder-lite' ) ) . '</p></div>'; }
        echo '<p class="description">' . esc_html__( 'Placeholders are escaped before insertion. The preview and local test use synthetic data only. Edit and preview both product-view and cart reminder messages here.', 'wc-product-view-reminder-lite' ) . '</p><div class="wc-pvr-card"><form method="post" action="options.php">';
        settings_fields( 'wc_pvr_settings_group' );
        echo '<table class="form-table"><tr><th><label for="wc-pvr-subject">' . esc_html__( 'Subject', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-subject" type="text" name="wc_pvr_email_subject" value="' . esc_attr( get_option( 'wc_pvr_email_subject', 'You viewed {product_name}' ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-heading">' . esc_html__( 'Email heading', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-heading" type="text" name="wc_pvr_email_heading" value="' . esc_attr( get_option( 'wc_pvr_email_heading', __( 'A product you viewed is waiting', 'wc-product-view-reminder-lite' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-sender-name">' . esc_html__( 'Sender name', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-sender-name" type="text" name="wc_pvr_email_sender_name" value="' . esc_attr( get_option( 'wc_pvr_email_sender_name', get_bloginfo( 'name' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-sender-email">' . esc_html__( 'Sender email', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-sender-email" type="email" name="wc_pvr_email_sender_email" value="' . esc_attr( get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc-pvr-button-text">' . esc_html__( 'Call-to-action text', 'wc-product-view-reminder-lite' ) . '</label></th><td><input class="regular-text" id="wc-pvr-button-text" type="text" name="wc_pvr_email_button_text" value="' . esc_attr( get_option( 'wc_pvr_email_button_text', __( 'View product', 'wc-product-view-reminder-lite' ) ) ) . '"></td></tr>';
        echo '<tr><th><label for="wc_pvr_template_message">' . esc_html__( 'Email body', 'wc-product-view-reminder-lite' ) . '</label></th><td>';
        wp_editor( get_option( 'wc_pvr_email_message', "Hi {customer_name},\n\nYou viewed {product_name}.\n\n{cta_button}\n\n{unsubscribe_url}" ), 'wc_pvr_template_message', array( 'textarea_name' => 'wc_pvr_email_message', 'textarea_rows' => 10, 'media_buttons' => false, 'teeny' => true ) );
        echo '<p class="description">' . esc_html__( 'Available placeholders: {customer_name}, {product_name}, {product_link}, {product_url}, {product_price}, {store_name}, {email_heading}, {cta_button}, {unsubscribe_url}.', 'wc-product-view-reminder-lite' ) . '</p></td></tr>';
        echo '<tr><th><label for="wc_pvr_cart_template_message">' . esc_html__( 'Cart reminder body', 'wc-product-view-reminder-lite' ) . '</label></th><td>';
        wp_editor( get_option( 'wc_pvr_cart_email_message', "Hi {customer_name},\n\nYou added {product_name} to your cart.\n\n{cta_button}\n\n{unsubscribe_url}" ), 'wc_pvr_cart_template_message', array( 'textarea_name' => 'wc_pvr_cart_email_message', 'textarea_rows' => 8, 'media_buttons' => false, 'teeny' => true ) );
        echo '<p class="description">' . esc_html__( 'The cart reminder supports the same product, customer, call-to-action, and unsubscribe placeholders listed above.', 'wc-product-view-reminder-lite' ) . '</p>';
        echo '</td></tr></table>'; submit_button( __( 'Save Templates', 'wc-product-view-reminder-lite' ) ); echo '</form></div>';
        $service = isset( $GLOBALS['wc_pvr_reminder'] ) ? $GLOBALS['wc_pvr_reminder'] : null;
        if ( $service instanceof WC_PVR_Reminder ) { $preview = $service->render_template( null, 'Sample Customer', 'synthetic@example.test', 'view', 0, null, true ); echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Synthetic preview', 'wc-product-view-reminder-lite' ) . '</h2><p><strong>' . esc_html__( 'Subject:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( $preview['subject'] ) . '</p><div class="wc-pvr-email-preview">' . wp_kses_post( $preview['message'] ) . '</div></section>'; }
        if ( $service instanceof WC_PVR_Reminder ) { $cart_preview = $service->render_template( null, 'Sample Customer', 'synthetic@example.test', 'cart', 0, null, true ); echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Synthetic cart reminder preview', 'wc-product-view-reminder-lite' ) . '</h2><p><strong>' . esc_html__( 'Subject:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html( $cart_preview['subject'] ) . '</p><div class="wc-pvr-email-preview">' . wp_kses_post( $cart_preview['message'] ) . '</div></section>'; }
        echo '<section class="wc-pvr-card"><h2>' . esc_html__( 'Local test email', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'Available only when WordPress reports its environment as local. Send only to a synthetic address on the reserved .test domain. Verify the message in LocalWP Mailpit/MailHog.', 'wc-product-view-reminder-lite' ) . '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="wc_pvr_send_test_email">' . wp_nonce_field( 'wc_pvr_send_test_email', '_wpnonce', true, false ) . '<label for="wc-pvr-test-recipient">' . esc_html__( 'Synthetic recipient', 'wc-product-view-reminder-lite' ) . '</label> <input type="email" required id="wc-pvr-test-recipient" name="test_recipient" value="recoverflow@example.test"> <button class="button button-secondary">' . esc_html__( 'Send local test', 'wc-product-view-reminder-lite' ) . '</button></form></section></div>';
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
            'wc_pvr_settings_group',
            'wc_pvr_cart_email_message',
            array(
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_email_subject',
            array(
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_email_message',
            array(
                'sanitize_callback' => 'wp_kses_post'
            )
        );

        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_enabled', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_registration', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_checkout', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_consent_wording', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_views_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_views_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_history_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_history_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_retention_consent_days', array( 'sanitize_callback' => array( $this, 'sanitize_retention_consent_days' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_preserve_optout_after_erasure', array( 'sanitize_callback' => array( $this, 'sanitize_checkbox' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_email_sender_name', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_email_sender_email', array( 'sanitize_callback' => array( $this, 'sanitize_sender_email' ) ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_email_button_text', array( 'sanitize_callback' => 'sanitize_text_field' ) );
        register_setting( 'wc_pvr_settings_group', 'wc_pvr_email_heading', array( 'sanitize_callback' => 'sanitize_text_field' ) );

    }

    public function sanitize_tracking_delay( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 3600 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_tracking_delay', __( 'Tracking delay must be a whole number between 0 and 3600 seconds. The previous value was kept.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( 'wc_pvr_delay', 5 ) );
        }
        return absint( $value );
    }

    public function sanitize_reminder_delay( $value ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > DAY_IN_SECONDS * 30 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_reminder_delay', __( 'Reminder waiting period must be a whole number between 0 and 720 hours. The previous value was kept.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( 'wc_pvr_reminder_delay', 24 ) );
        }
        return absint( $value );
    }

    public function sanitize_sender_email( $value ) {
        $email = is_scalar( $value ) ? sanitize_email( $value ) : '';
        if ( ! is_email( $email ) ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_sender_email', __( 'Enter a valid sender email address. The previous value was kept.', 'wc-product-view-reminder-lite' ), 'error' );
            return get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) );
        }
        return $email;
    }

    public function sanitize_checkbox( $value ) {
        return is_scalar( $value ) && '1' === (string) $value ? 1 : 0;
    }

    private function sanitize_retention_days( $value, $option ) {
        if ( ! is_scalar( $value ) || ! preg_match( '/^\d+$/', (string) $value ) || absint( $value ) > 3650 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_invalid_retention', __( 'Retention must be a whole number between 0 and 3650 days. The previous value was kept.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( $option, 0 ) );
        }
        return absint( $value );
    }

    public function sanitize_retention_views_days( $value ) { return $this->sanitize_retention_days( $value, 'wc_pvr_retention_views_days' ); }
    public function sanitize_retention_history_days( $value ) {
        if ( is_scalar( $value ) && preg_match( '/^\d+$/', (string) $value ) && absint( $value ) > 0 && absint( $value ) < 7 ) {
            add_settings_error( 'wc_pvr_settings_group', 'wc_pvr_short_history_retention', __( 'Reminder history retention must be 0 (keep indefinitely) or at least 7 days so the weekly customer frequency cap remains effective.', 'wc-product-view-reminder-lite' ), 'error' );
            return absint( get_option( 'wc_pvr_retention_history_days', 0 ) );
        }
        return $this->sanitize_retention_days( $value, 'wc_pvr_retention_history_days' );
    }
    public function sanitize_retention_consent_days( $value ) { return $this->sanitize_retention_days( $value, 'wc_pvr_retention_consent_days' ); }

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
        echo '<li>' . sprintf( esc_html__( 'Configure and preview messages in %s. During local testing, only send to a synthetic address ending in .test.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-templates', __( 'Email Templates', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '</ol><p>' . esc_html__( 'Marketing reminders are default-deny. A customer needs a current RecoverFlow opt-in or a trusted store integration must confirm eligibility.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-timing" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '2. Tracking, waiting period, and schedule', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li><strong>' . esc_html__( 'Tracking delay (seconds):', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How long a signed-in customer must stay on a product page before RecoverFlow records a view. This does not control when an email is sent.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Reminder waiting period (hours):', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How long after a tracked view, or after adding a product to the cart for cart reminders, a reminder becomes time-eligible. The default is 24 hours.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Cron frequency:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'How often WordPress checks for due reminders. The scheduled time is approximate. WP-Cron normally runs when the site receives traffic; a hosting cron can call wp-cron.php on a schedule.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Cart reminders are optional. Enable them in Settings. Consent, purchase, duplicate, and customer-frequency checks still apply.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul></section>';

        echo '<section id="wc-pvr-help-reminders" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '3. Customer Reminders and manual sends', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( 'Use %s to search tracked customer and product records. The eligibility column explains blocks such as missing consent, an opt-out, a recent purchase, a deleted product, a waiting period, or a frequency limit.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-reminders', __( 'Customer Reminders', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . esc_html__( 'A manual send asks for confirmation and requires administrator permission. It can bypass the waiting period only. It still checks consent, opt-outs, product validity, purchase state, duplicate attempts, retry rules, and frequency limits. Read the eligibility explanation when the action is unavailable.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-consent" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '4. Consent and customer choices', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li>' . esc_html__( 'Registration and checkout consent checkboxes are optional and unchecked by default. Customers can register and purchase without selecting them.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Customers can manage preferences at My Account > Email preferences. Unsubscribe links require confirmation. A later opt-out or revoked consent blocks future reminders.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . esc_html__( 'Existing customers are not opted in automatically. Views, account registration, and checkout do not imply marketing consent.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li>' . sprintf( esc_html__( '%s shows the latest recorded choice and whether integration filters are registered. Administrators can record a verified revocation, but cannot opt customers in from this page.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-consent', __( 'Consent Management', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li>' . esc_html__( 'A developer can connect your store’s existing consent provider using the documented eligibility filters. Follow that provider’s documented API. The README example is illustrative; WooCommerce does not have a universal marketing-consent field.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul></section>';

        echo '<section id="wc-pvr-help-email" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '5. Email Templates and Reminder History', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( 'In %s you can edit subjects, headings, sender details, call-to-action text, and product-view or cart message bodies. Placeholders include {customer_name}, {product_name}, {product_link}, {product_url}, {product_price}, {store_name}, {email_heading}, {cta_button}, and {unsubscribe_url}. Previews use synthetic data.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-templates', __( 'Email Templates', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . esc_html__( 'Local test email is limited to a local WordPress environment and a synthetic .test address. Check Mailpit or the local mail catcher. Do not test with real customer addresses.', 'wc-product-view-reminder-lite' ) . '</p><p>' . sprintf( esc_html__( '%s records attempts and failures. “Accepted” means wp_mail() handed the message to the configured mail transport; it does not confirm inbox delivery. Review pending or unknown attempts before retrying.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-history', __( 'Reminder History', 'wc-product-view-reminder-lite' ) ) ) . '</p></section>';

        echo '<section id="wc-pvr-help-reports" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '6. Dashboard and Analytics', 'wc-product-view-reminder-lite' ) . '</h2><p>' . sprintf( esc_html__( '%s summarizes views and reminder activity. Age-qualified records have passed the waiting period but may still be blocked by consent, purchase, or other checks.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-dashboard', __( 'Dashboard', 'wc-product-view-reminder-lite' ) ) ) . '</p><p>' . sprintf( esc_html__( '%s shows date-range activity, top viewed and reminded products, and send outcomes based on RecoverFlow records. It does not invent conversion rates or revenue.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-analytics', __( 'Analytics', 'wc-product-view-reminder-lite' ) ) ) . '</p></section>';

        echo '<section id="wc-pvr-help-privacy" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '7. Privacy, data export, and retention', 'wc-product-view-reminder-lite' ) . '</h2><p>' . esc_html__( 'RecoverFlow records are included in WordPress Tools > Export Personal Data and Erase Personal Data. Erasure removes RecoverFlow views, reminder history, and purchase lookup references. WooCommerce orders are not deleted.', 'wc-product-view-reminder-lite' ) . '</p><p>' . esc_html__( 'Settings > Privacy and retention controls how long views, reminder history, and older consent records are kept. A value of 0 means retain until manual erasure. Cleanup runs in bounded daily batches and retains the latest consent choice.', 'wc-product-view-reminder-lite' ) . '</p><p>' . esc_html__( 'By default, erasure may keep one keyed opt-out suppression hash so another consent integration cannot re-enable reminders for someone who opted out. Review this setting and your retention periods against your privacy obligations.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '<section id="wc-pvr-help-troubleshooting" class="wc-pvr-card wc-pvr-help-card"><h2>' . esc_html__( '8. Troubleshooting', 'wc-product-view-reminder-lite' ) . '</h2><ul>';
        echo '<li><strong>' . esc_html__( 'No reminder is eligible:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Read the customer row’s eligibility explanation. Check current consent, opt-out status, purchase state, product status, waiting period, and frequency limit.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'No product view appears:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Tracking identifies signed-in customers only. Confirm the product is published and the customer stayed on its page for at least the configured tracking delay.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'A reminder is late:', 'wc-product-view-reminder-lite' ) . '</strong> ' . sprintf( esc_html__( 'Check the scheduled event and last completed run on %s. WP-Cron may be delayed on low-traffic sites.', 'wc-product-view-reminder-lite' ), $link( 'wc-pvr-status', __( 'System Status', 'wc-product-view-reminder-lite' ) ) ) . '</li>';
        echo '<li><strong>' . esc_html__( 'No email appears in the inbox:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Check Reminder History and the site mail transport. Accepted is not proof of delivery. Inspect Mailpit during local testing or your configured mail provider.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '<li><strong>' . esc_html__( 'Purchase suppression seems incomplete:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'Check System Status for order lookup progress. Existing orders must be indexed; new orders are synchronized through WooCommerce lifecycle hooks.', 'wc-product-view-reminder-lite' ) . '</li>';
        echo '</ul><p><strong>' . esc_html__( 'Limitations:', 'wc-product-view-reminder-lite' ) . '</strong> ' . esc_html__( 'RecoverFlow does not track anonymous guests, guarantee exactly-once sending, or confirm inbox delivery. It does not provide SMS or WhatsApp reminders.', 'wc-product-view-reminder-lite' ) . '</p></section>';

        echo '</div></div>';
    }

    public function settings_page() {

        $next_run = wp_next_scheduled( 'wc_pvr_cron' );

        ?>

        <div class="wrap wc-pvr-admin">

            <h1 class="wc-pvr-title">
                <?php esc_html_e( 'WooCommerce Product View Reminder', 'wc-product-view-reminder-lite' ); ?>
            </h1>

            <?php settings_errors(); ?>

            <?php if ( isset( $_GET['wc_pvr_consent_schema'] ) ) : ?>
                <div class="notice notice-info"><p><?php esc_html_e( 'Consent storage setup request completed. Reload the page to confirm table availability.', 'wc-product-view-reminder-lite' ); ?></p></div>
            <?php endif; ?>

            <?php if ( class_exists( 'WC_PVR_Consent' ) && ! WC_PVR_Consent::is_installed() ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'RecoverFlow storage is incomplete. Setup adds consent, reminder history, and product purchase lookup tables; existing customer and product-view records are preserved. Reminder sending remains disabled until setup completes and the bounded order index is built.', 'wc-product-view-reminder-lite' ); ?></p>
                    <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="wc_pvr_install_consent_schema">
                        <?php wp_nonce_field( 'wc_pvr_install_consent_schema' ); ?>
                        <?php submit_button( __( 'Set up RecoverFlow storage', 'wc-product-view-reminder-lite' ), 'secondary', 'submit', false ); ?>
                    </form>
                </div>
            <?php endif; ?>

            <?php if ( class_exists( 'WC_PVR_Consent' ) && WC_PVR_Consent::is_installed() && ! get_option( 'wc_pvr_orders_backfill_complete', 0 ) ) : ?>
                <div class="notice notice-warning inline"><p><?php esc_html_e( 'Historical purchase verification is not ready, so reminders remain paused. Starting the index reads WooCommerce orders through CRUD APIs and stores only product/order references plus a keyed email hash in RecoverFlow. It processes up to 100 orders per scheduled batch and does not alter WooCommerce orders.', 'wc-product-view-reminder-lite' ); ?></p>
                    <?php if ( ! wp_next_scheduled( 'wc_pvr_order_backfill' ) ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="wc_pvr_start_order_backfill"><?php wp_nonce_field( 'wc_pvr_start_order_backfill' ); ?><?php submit_button( __( 'Build purchase lookup', 'wc-product-view-reminder-lite' ), 'secondary', 'submit', false ); ?></form>
                    <?php else : ?>
                        <p><strong><?php esc_html_e( 'Purchase lookup is queued or processing.', 'wc-product-view-reminder-lite' ); ?></strong></p>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="notice notice-info inline"><p><?php esc_html_e( 'Marketing reminders are disabled unless your store explicitly confirms customer eligibility. Connect your consent or communication preference system with the wc_pvr_customer_is_eligible_for_marketing filter. A product view is not consent.', 'wc-product-view-reminder-lite' ); ?></p></div>

            <div class="wc-pvr-card">

                <h2><?php esc_html_e( 'General Settings', 'wc-product-view-reminder-lite' ); ?></h2>

                <form method="post" action="options.php">

                    <?php settings_fields( 'wc_pvr_settings_group' ); ?>

                    <table class="form-table">

                        <tr>
                            <th><?php esc_html_e( 'Reminder Waiting Period (hours)', 'wc-product-view-reminder-lite' ); ?></th>
                            <td><input type="number" min="0" max="720" name="wc_pvr_reminder_delay" value="<?php echo esc_attr( get_option( 'wc_pvr_reminder_delay', 24 ) ); ?>"><p class="description"><?php esc_html_e( 'Minimum time after a tracked product view or cart addition before a reminder can be sent.', 'wc-product-view-reminder-lite' ); ?></p></td>
                        </tr>

                        <tr>
                            <th><?php esc_html_e( 'Tracking Delay (seconds)', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <input type="number"
                                       name="wc_pvr_delay"
                                       value="<?php echo esc_attr( get_option( 'wc_pvr_delay', 5 ) ); ?>">

                                <p class="description">
                                    <?php esc_html_e( 'How long a customer must stay on the product page before a view is recorded. This is separate from the reminder waiting period.', 'wc-product-view-reminder-lite' ); ?>
                                </p>

                            </td>
                        </tr>


                        <tr>
                            <th><?php esc_html_e( 'Cron Frequency', 'wc-product-view-reminder-lite' ); ?></th>

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
                                    <?php esc_html_e( 'How often the system checks and sends reminder emails.', 'wc-product-view-reminder-lite' ); ?>
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
                                         . esc_html__( 'Cron will run in:', 'wc-product-view-reminder-lite' )
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
                                         . esc_html__( 'Cron not scheduled yet.', 'wc-product-view-reminder-lite' )
                                         . '</p>';

                                }

                                ?>

                            </td>
                        </tr>


                        <tr>

                            <th><?php esc_html_e( 'Send Reminder for Cart Products', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <label>

                                    <input type="hidden" name="wc_pvr_cart_reminder" value="0">

                                    <input type="checkbox"
                                           name="wc_pvr_cart_reminder"
                                           value="1"
                                           <?php checked( get_option( 'wc_pvr_cart_reminder' ), 1 ); ?>>

                                    <?php esc_html_e( 'Enable reminders if product is added to cart but not purchased.', 'wc-product-view-reminder-lite' ); ?>

                                </label>

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
                        <tr><th><?php esc_html_e( 'Built-in Consent', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_enabled" value="0"><input type="checkbox" name="wc_pvr_consent_enabled" value="1" <?php checked( get_option( 'wc_pvr_consent_enabled' ), 1 ); ?>> <?php esc_html_e( 'Enable optional consent collection', 'wc-product-view-reminder-lite' ); ?></label><p class="description"><?php esc_html_e( 'Existing customers are not opted in. Consent is optional and does not affect registration or purchase.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Registration', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_registration" value="0"><input type="checkbox" name="wc_pvr_consent_registration" value="1" <?php checked( get_option( 'wc_pvr_consent_registration' ), 1 ); ?>> <?php esc_html_e( 'Show the optional checkbox at registration', 'wc-product-view-reminder-lite' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Checkout', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_consent_checkout" value="0"><input type="checkbox" name="wc_pvr_consent_checkout" value="1" <?php checked( get_option( 'wc_pvr_consent_checkout' ), 1 ); ?>> <?php esc_html_e( 'Show the optional checkbox at classic and block checkout', 'wc-product-view-reminder-lite' ); ?></label></td></tr>
                        <tr><th><?php esc_html_e( 'Checkbox wording', 'wc-product-view-reminder-lite' ); ?></th><td><input type="text" name="wc_pvr_consent_wording" value="<?php echo esc_attr( get_option( 'wc_pvr_consent_wording', __( 'I agree to receive product reminders and promotional emails from this store. I can unsubscribe at any time.', 'wc-product-view-reminder-lite' ) ) ); ?>" class="regular-text"><p class="description"><?php esc_html_e( 'Identify this store and the email channel. Customers can register or purchase without opting in.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th colspan="2"><h2><?php esc_html_e( 'Privacy and retention', 'wc-product-view-reminder-lite' ); ?></h2></th></tr>
                        <tr><th><?php esc_html_e( 'Product view retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_views_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_views_days', 0 ) ); ?>"><p class="description"><?php esc_html_e( 'Set to 0 to retain existing view records. Expired rows are deleted in batches of 250 per daily cleanup run.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Reminder history retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_history_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_history_days', 0 ) ); ?>"><p class="description"><?php esc_html_e( 'Set to 0 to retain history indefinitely, or use at least 7 days to preserve the weekly frequency cap. Cleanup deletes at most 250 expired attempts per run.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'Consent history retention (days)', 'wc-product-view-reminder-lite' ); ?></th><td><input type="number" min="0" max="3650" name="wc_pvr_retention_consent_days" value="<?php echo esc_attr( get_option( 'wc_pvr_retention_consent_days', 0 ) ); ?>"><p class="description"><?php esc_html_e( 'Set to 0 to retain consent history. Older choices may be removed only when a newer choice exists; the latest opt-in or opt-out is always preserved.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                        <tr><th><?php esc_html_e( 'After privacy erasure', 'wc-product-view-reminder-lite' ); ?></th><td><label><input type="hidden" name="wc_pvr_preserve_optout_after_erasure" value="0"><input type="checkbox" name="wc_pvr_preserve_optout_after_erasure" value="1" <?php checked( get_option( 'wc_pvr_preserve_optout_after_erasure', 1 ), 1 ); ?>> <?php esc_html_e( 'Keep one keyed email hash as an opt-out suppression record', 'wc-product-view-reminder-lite' ); ?></label><p class="description"><?php esc_html_e( 'This minimizes data while preventing another consent adapter from re-enabling a person who requested erasure after opting out. Review this choice against your privacy obligations. WooCommerce order data is never erased by RecoverFlow.', 'wc-product-view-reminder-lite' ); ?></p></td></tr>
                    </table>

            </div>

            <?php submit_button(); ?>

            </form>

            <hr>

            <div class="wc-pvr-pro-box">

                <h2><?php esc_html_e( 'Upgrade to PRO', 'wc-product-view-reminder-lite' ); ?></h2>

                <p><?php esc_html_e( 'Unlock powerful marketing automation features.', 'wc-product-view-reminder-lite' ); ?></p>

                <ul>

                    <li>✔ WhatsApp reminders</li>
                    <li>✔ Multi-day reminder campaigns</li>
                    <li>✔ Guest visitor tracking</li>
                    <li>✔ Automatic coupon generator</li>
                    <li>✔ Reminder analytics dashboard</li>
                    <li>✔ Exit intent detection</li>

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
