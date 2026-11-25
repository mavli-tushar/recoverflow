<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_PVR_Reminder {

	const MAX_ATTEMPTS = 3;
	const FREQUENCY_DAYS = 7;
	const FREQUENCY_DAYS_MIN = 3;
	const FREQUENCY_DAYS_MAX = 30;

	public function __construct() {
		add_action( 'wc_pvr_cron', array( $this, 'send_pvr_reminders' ) );
		$this->schedule();
	}

	public function schedule() {
		$interval = sanitize_key( get_option( 'wc_pvr_cron_interval', 'hourly' ) );
		if ( ! wp_next_scheduled( 'wc_pvr_cron' ) ) {
			wp_schedule_event( time(), $interval, 'wc_pvr_cron' );
		}
	}

	/** Reminder cooldown in days for the same customer + product (3–30). */
	public static function frequency_days() {
		$days = absint( get_option( 'wc_pvr_frequency_days', self::FREQUENCY_DAYS ) );
		if ( $days < self::FREQUENCY_DAYS_MIN || $days > self::FREQUENCY_DAYS_MAX ) {
			$days = self::FREQUENCY_DAYS;
		}
		return $days;
	}

	/** Parse a comma/space/newline separated or array list of IDs. */
	public static function parse_id_list( $value ) {
		if ( is_array( $value ) ) {
			return array_values( array_unique( array_filter( array_map( 'absint', $value ) ) ) );
		}
		if ( ! is_scalar( $value ) || '' === trim( (string) $value ) ) {
			return array();
		}
		$parts = preg_split( '/[\s,]+/', (string) $value, -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_unique( array_filter( array_map( 'absint', (array) $parts ) ) ) );
	}

	/** Whether automatic sends are allowed at the current site-local hour. */
	public static function is_within_send_window() {
		if ( ! absint( get_option( 'wc_pvr_quiet_hours_enabled', 0 ) ) ) {
			return true;
		}
		$start = min( 23, absint( get_option( 'wc_pvr_quiet_hours_start', 9 ) ) );
		$end   = min( 23, absint( get_option( 'wc_pvr_quiet_hours_end', 20 ) ) );
		$hour  = absint( wp_date( 'G' ) );
		if ( $start === $end ) {
			return true;
		}
		if ( $start < $end ) {
			return ( $hour >= $start && $hour < $end );
		}
		return ( $hour >= $start || $hour < $end );
	}

	/** Whether a product (or its category / parent) is excluded from reminders. */
	public static function is_product_excluded( $product_id ) {
		$product_id = absint( $product_id );
		if ( ! $product_id ) {
			return true;
		}
		$excluded_products = self::parse_id_list( get_option( 'wc_pvr_exclude_product_ids', '' ) );
		if ( in_array( $product_id, $excluded_products, true ) ) {
			return true;
		}
		$excluded_cats = self::parse_id_list( get_option( 'wc_pvr_exclude_category_ids', '' ) );
		if ( empty( $excluded_cats ) || ! function_exists( 'wc_get_product' ) ) {
			return false;
		}
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return false;
		}
		$check_ids = array( $product_id );
		if ( is_callable( array( $product, 'is_type' ) ) && $product->is_type( 'variation' ) && is_callable( array( $product, 'get_parent_id' ) ) ) {
			$parent_id = absint( $product->get_parent_id() );
			if ( $parent_id ) {
				$check_ids[] = $parent_id;
				if ( in_array( $parent_id, $excluded_products, true ) ) {
					return true;
				}
			}
		}
		foreach ( $check_ids as $check_id ) {
			$terms = wp_get_post_terms( $check_id, 'product_cat', array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			if ( array_intersect( $excluded_cats, array_map( 'absint', $terms ) ) ) {
				return true;
			}
		}
		return false;
	}

	public function send_pvr_reminders() {
		if ( ! self::is_within_send_window() ) {
			update_option( 'wc_pvr_last_cron_completed_at', current_time( 'mysql' ), false );
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_pvr_views';
		$cart_enabled = absint( get_option( 'wc_pvr_cart_reminder', 0 ) );
		$delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
		$cutoff = wp_date( 'Y-m-d H:i:s', time() - ( $delay * HOUR_IN_SECONDS ) );
		$cursor = absint( get_transient( 'wc_pvr_queue_cursor' ) );
		$scan_limit = 1000;
		$scanned = 0;
		$views = array();
		while ( $scanned < $scan_limit && count( $views ) < 100 ) {
			$page = $wpdb->get_results( $wpdb->prepare(
				"SELECT * FROM {$table} WHERE id > %d AND purchased = 0 AND ((added_to_cart = 0 AND view_reminder_sent = 0 AND view_time <= %s) OR (added_to_cart = 1 AND cart_reminder_sent = 0 AND %d = 1 AND COALESCE(cart_added_at,view_time) <= %s)) ORDER BY id ASC LIMIT 100",
				$cursor,
				$cutoff,
				$cart_enabled,
				$cutoff
			) );
			if ( empty( $page ) ) {
				$cursor = 0;
				if ( empty( $views ) ) {
					$page = $wpdb->get_results( $wpdb->prepare(
						"SELECT * FROM {$table} WHERE id > 0 AND purchased = 0 AND ((added_to_cart = 0 AND view_reminder_sent = 0 AND view_time <= %s) OR (added_to_cart = 1 AND cart_reminder_sent = 0 AND %d = 1 AND COALESCE(cart_added_at,view_time) <= %s)) ORDER BY id ASC LIMIT 100",
						$cutoff,
						$cart_enabled,
						$cutoff
					) );
				}
				if ( empty( $page ) ) { break; }
			}
			foreach ( $page as $view ) {
				$views[] = $view;
				$cursor = absint( $view->id );
			}
			$scanned += count( $page );
		}
		set_transient( 'wc_pvr_queue_cursor', $cursor, DAY_IN_SECONDS );
		foreach ( $views as $view ) {
			$this->send_view( $view, 'automatic', 0, false );
		}
		update_option( 'wc_pvr_last_cron_completed_at', current_time( 'mysql' ), false );
	}

	/** Return a WP_Error when a view cannot currently receive a reminder. */
	public function can_send( $view, $bypass_wait = false, $live_purchase_check = true ) {
		global $wpdb;
		if ( ! $view || ! WC_PVR_Consent::is_installed() ) {
			return new WP_Error( 'storage_unavailable', __( 'RecoverFlow setup is not finished yet.', 'wc-product-view-reminder-lite' ) );
		}
		if ( ! get_option( 'wc_pvr_orders_backfill_complete', 0 ) ) {
			return new WP_Error( 'purchase_index_building', __( 'Still scanning past orders. Try again once the scan finishes.', 'wc-product-view-reminder-lite' ) );
		}
		$product_id = absint( $view->product_id );
		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) || ! $product->get_permalink() ) {
			return new WP_Error( 'invalid_product', __( 'The product is no longer available.', 'wc-product-view-reminder-lite' ) );
		}
		if ( self::is_product_excluded( $product_id ) ) {
			return new WP_Error( 'product_excluded', __( 'This product is excluded from reminders.', 'wc-product-view-reminder-lite' ) );
		}
		$email = sanitize_email( $view->email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'invalid_email', __( 'The customer email address is invalid.', 'wc-product-view-reminder-lite' ) );
		}
		$user_id = absint( $view->user_id );
		$external_eligible = (bool) apply_filters( 'wc_pvr_customer_is_eligible_for_marketing', false, $user_id, $email, $view );
		$consent_status = WC_PVR_Consent::status_for_email( $email );
		$ledger_opted_out = 'opted_out' === $consent_status;
		$integration_opted_out = (bool) apply_filters( 'wc_pvr_customer_has_marketing_opted_out', $ledger_opted_out, $user_id, $email, $view );
		$explicit_opt_out = $ledger_opted_out || $integration_opted_out;
		if ( $explicit_opt_out || ( ! $external_eligible && 'opted_in' !== $consent_status ) ) {
			return new WP_Error( $explicit_opt_out ? 'customer_opted_out' : 'consent_required', __( 'This customer has not agreed to marketing emails.', 'wc-product-view-reminder-lite' ) );
		}
		$type = $this->reminder_type( $view );
		if ( ! $type ) { return new WP_Error( 'cart_reminder_disabled', __( 'Cart reminders are turned off.', 'wc-product-view-reminder-lite' ) ); }
		if ( $this->was_sent( $view, $type ) ) { return new WP_Error( 'already_sent', __( 'This reminder was already sent.', 'wc-product-view-reminder-lite' ) ); }
		$email_hash = WC_PVR_Consent::email_hash( $email );
		$history_table = WC_PVR_Consent::history_table_name();
		$pending_cutoff = current_datetime()->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$history_table} WHERE email_hash = %s AND result = 'pending' AND attempted_at >= %s LIMIT 1", $email_hash, $pending_cutoff ) ) ) {
			return new WP_Error( 'send_outcome_unknown', __( 'We do not know whether the last email to this customer went out. Check Reminder History before trying again.', 'wc-product-view-reminder-lite' ) );
		}
		$frequency_days = self::frequency_days();
		$weekly_cutoff = current_datetime()->modify( '-' . $frequency_days . ' days' )->format( 'Y-m-d H:i:s' );
		/* One accepted reminder per customer + product within the cooldown; other products stay eligible. */
		if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$history_table} WHERE email_hash = %s AND product_id = %d AND result = 'accepted' AND attempted_at >= %s LIMIT 1", $email_hash, $product_id, $weekly_cutoff ) ) ) {
			return new WP_Error(
				'customer_frequency_limit',
				sprintf(
					/* translators: %d: cooldown days */
					__( 'This customer already received a reminder for this product in the last %d days.', 'wc-product-view-reminder-lite' ),
					$frequency_days
				)
			);
		}
		$daily_cutoff = current_datetime()->modify( '-1 day' )->format( 'Y-m-d H:i:s' );
		$daily_attempts = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history_table} WHERE email_hash = %s AND result IN ('accepted','failed') AND attempted_at >= %s", $email_hash, $daily_cutoff ) ) );
		if ( $daily_attempts >= self::MAX_ATTEMPTS ) {
			return new WP_Error( 'customer_rate_limit', __( 'Too many attempts for this customer today. Try again tomorrow.', 'wc-product-view-reminder-lite' ) );
		}
		$store = isset( $GLOBALS['wc_pvr_order_store'] ) ? $GLOBALS['wc_pvr_order_store'] : null;
		if ( ! $store instanceof WC_PVR_Order_Store ) {
			return new WP_Error( 'purchase_check_unavailable', __( 'Purchase checks are unavailable right now.', 'wc-product-view-reminder-lite' ) );
		}
		if ( $store->has_paid_purchase( $view, $live_purchase_check ) ) {
			$wpdb->update( $wpdb->prefix . 'wc_pvr_views', array( 'purchased' => 1 ), array( 'id' => absint( $view->id ) ), array( '%d' ), array( '%d' ) );
			return new WP_Error( 'purchase_suppressed', __( 'This customer already bought this product.', 'wc-product-view-reminder-lite' ) );
		}
		if ( ! $live_purchase_check && $view->purchased ) {
			return new WP_Error( 'already_purchased', __( 'This customer already bought this product.', 'wc-product-view-reminder-lite' ) );
		}
		if ( $live_purchase_check && $view->purchased ) {
			$wpdb->update( $wpdb->prefix . 'wc_pvr_views', array( 'purchased' => 0 ), array( 'id' => absint( $view->id ) ), array( '%d' ), array( '%d' ) );
		}
		$state = $this->attempt_state( $view, $type );
		if ( $state['pending_recent'] ) {
			return new WP_Error( 'send_outcome_unknown', __( 'We do not know whether the last attempt was sent. Check Reminder History before trying again.', 'wc-product-view-reminder-lite' ) );
		}
		if ( $state['failed_count'] >= self::MAX_ATTEMPTS ) {
			return new WP_Error( 'retry_limit_reached', __( 'Gave up after 3 tries for this reminder.', 'wc-product-view-reminder-lite' ) );
		}
		if ( $state['next_retry_at'] ) {
			try { $retry_at = new DateTimeImmutable( $state['next_retry_at'], wp_timezone() ); }
			catch ( Exception $exception ) { $retry_at = null; }
			if ( $retry_at && $retry_at > current_datetime() ) { return new WP_Error( 'retry_backoff', __( 'This reminder will be tried again shortly.', 'wc-product-view-reminder-lite' ) ); }
		}
		if ( $state['recent_accepted'] ) {
			return new WP_Error(
				'frequency_limit',
				sprintf(
					/* translators: %d: cooldown days */
					__( 'This customer already received a reminder for this product in the last %d days.', 'wc-product-view-reminder-lite' ),
					self::frequency_days()
				)
			);
		}
		if ( ! $bypass_wait ) {
			$delay = max( 0, absint( get_option( 'wc_pvr_reminder_delay', 24 ) ) );
			try {
				$waiting_start = 'cart' === $type && ! empty( $view->cart_added_at ) ? $view->cart_added_at : $view->view_time;
				$eligible_at = ( new DateTimeImmutable( $waiting_start, wp_timezone() ) )->modify( '+' . $delay . ' hours' );
			} catch ( Exception $exception ) {
				return new WP_Error( 'invalid_view_time', __( 'The tracked view time is invalid.', 'wc-product-view-reminder-lite' ) );
			}
			if ( $eligible_at > current_datetime() ) {
				return new WP_Error( 'waiting_period', __( 'The reminder waiting period has not elapsed.', 'wc-product-view-reminder-lite' ) );
			}
		}
		return true;
	}

	public function send_manual_reminder( $view_id, $actor_user_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_pvr_views';
		$view = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $view_id ) ) );
		if ( ! $view ) {
			return new WP_Error( 'view_not_found', __( 'The reminder record no longer exists.', 'wc-product-view-reminder-lite' ) );
		}
		return $this->send_view( $view, 'manual', absint( $actor_user_id ), true );
	}

	private function reminder_type( $view ) {
		if ( $view->added_to_cart ) {
			return absint( get_option( 'wc_pvr_cart_reminder', 0 ) ) ? 'cart' : '';
		}
		return 'view';
	}

	private function was_sent( $view, $type ) {
		return ( 'cart' === $type && ! empty( $view->cart_reminder_sent ) ) || ( 'view' === $type && ! empty( $view->view_reminder_sent ) );
	}

	private function attempt_state( $view, $type ) {
		global $wpdb;
		$table = WC_PVR_Consent::history_table_name();
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT result,retry_count,next_retry_at,attempted_at FROM {$table} WHERE view_id = %d AND reminder_type = %s ORDER BY id DESC LIMIT 10", absint( $view->id ), $type ) );
		$failed = 0;
		$recent_accepted = false;
		$pending_recent = false;
		$next_retry = null;
		$retry_time_found = false;
		$now = current_datetime();
		foreach ( (array) $rows as $row ) {
			if ( 'pending' === $row->result ) {
				try { $pending_at = new DateTimeImmutable( $row->attempted_at, wp_timezone() ); }
				catch ( Exception $exception ) { $pending_at = null; }
				if ( $pending_at && $pending_at >= $now->modify( '-1 day' ) ) { $pending_recent = true; }
			}
			if ( 'failed' === $row->result ) {
				$failed++;
				if ( ! $retry_time_found ) { $next_retry = $row->next_retry_at; $retry_time_found = true; }
			}
			if ( 'accepted' === $row->result ) {
				try { $attempted_at = new DateTimeImmutable( $row->attempted_at, wp_timezone() ); }
				catch ( Exception $exception ) { $attempted_at = null; }
				if ( $attempted_at && $attempted_at >= $now->modify( '-' . self::frequency_days() . ' days' ) ) { $recent_accepted = true; }
			}
		}
		return array( 'failed_count' => $failed, 'recent_accepted' => $recent_accepted, 'pending_recent' => $pending_recent, 'next_retry_at' => $next_retry );
	}

	private function send_view( $view, $trigger, $actor_user_id, $bypass_wait ) {
		if ( ! $view ) { return new WP_Error( 'view_not_found', __( 'The reminder record no longer exists.', 'wc-product-view-reminder-lite' ) ); }
		$initial_check = $this->can_send( $view, $bypass_wait );
		if ( is_wp_error( $initial_check ) ) {
			if ( 'manual' === $trigger && WC_PVR_Consent::is_installed() ) {
				$this->record_attempt( $view, $this->reminder_type( $view ) ?: 'view', $trigger, $actor_user_id, 'blocked', $initial_check->get_error_code(), 0, null );
			}
			return $initial_check;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wc_pvr_views';
		$type = $this->reminder_type( $view );
		$email_hash = WC_PVR_Consent::email_hash( $view->email );
		$lock = 'wc_pvr_send_' . md5( $wpdb->prefix . $email_hash );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock ) ) ) {
			if ( 'manual' === $trigger ) { $this->record_attempt( $view, $type ?: 'view', $trigger, $actor_user_id, 'blocked', 'send_in_progress', 0, null ); }
			return new WP_Error( 'send_in_progress', __( 'Another reminder attempt is already in progress.', 'wc-product-view-reminder-lite' ) );
		}
		try {
			$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $view->id ) ) );
			if ( ! $fresh || $this->reminder_type( $fresh ) !== $type ) {
				if ( 'manual' === $trigger && $fresh ) { $this->record_attempt( $fresh, $type ?: 'view', $trigger, $actor_user_id, 'blocked', 'record_changed', 0, null ); }
				return new WP_Error( 'record_changed', __( 'The reminder record changed. Refresh the page and review its current eligibility.', 'wc-product-view-reminder-lite' ) );
			}
			$allowed = $this->can_send( $fresh, $bypass_wait );
			if ( is_wp_error( $allowed ) ) {
				if ( 'manual' === $trigger ) { $this->record_attempt( $fresh, $type, $trigger, $actor_user_id, 'blocked', $allowed->get_error_code(), 0, null ); }
				return $allowed;
			}
			$product = wc_get_product( absint( $fresh->product_id ) );
			$email = sanitize_email( $fresh->email );
			$user_id = absint( $fresh->user_id );
			$customer = $user_id ? get_userdata( $user_id ) : false;
			$customer_name = $customer ? trim( $customer->first_name . ' ' . $customer->last_name ) : '';
			$template = $this->render_template( $product, $customer_name, $email, $type, $user_id, $fresh, false );
			$subject = $template['subject'];
			$message = $template['message'];
			$unsubscribe_url = isset( $template['unsubscribe_url'] ) ? $template['unsubscribe_url'] : '';
			$failure_code = '';
			$capture_error = function ( $error ) use ( &$failure_code ) {
				if ( is_wp_error( $error ) ) { $failure_code = sanitize_key( $error->get_error_code() ); }
			};
			add_action( 'wp_mail_failed', $capture_error, 10, 1 );
			try {
				/* Re-read and revalidate consent, opt-out and purchase state at the send boundary. */
				$fresh = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $fresh->id ) ) );
				if ( ! $fresh || $this->reminder_type( $fresh ) !== $type || strtolower( sanitize_email( $fresh->email ) ) !== strtolower( $email ) ) {
					$final_check = new WP_Error( 'record_changed', __( 'The customer or reminder record changed while preparing the email. No email was sent.', 'wc-product-view-reminder-lite' ) );
				} else {
				$final_check = $this->can_send( $fresh, $bypass_wait );
				}
				if ( is_wp_error( $final_check ) ) {
					if ( 'manual' === $trigger && $fresh ) { $this->record_attempt( $fresh, $type, $trigger, $actor_user_id, 'blocked', $final_check->get_error_code(), 0, null ); }
					return $final_check;
				}
				$state = $this->attempt_state( $fresh, $type );
				$retry_count = $state['failed_count'];
				$attempt_id = $this->record_attempt( $fresh, $type, $trigger, $actor_user_id, 'pending', '', $retry_count, null );
				if ( ! $attempt_id ) {
					return new WP_Error( 'history_write_failed', __( 'The send attempt could not be recorded, so no email was sent.', 'wc-product-view-reminder-lite' ) );
				}
				try {
					$sent = wp_mail( $email, $subject, $message, $this->mail_headers( $unsubscribe_url ) );
				} catch ( Throwable $exception ) {
					$sent = false;
					$failure_code = 'mailer_exception';
				}
			} finally {
				remove_action( 'wp_mail_failed', $capture_error, 10 );
			}
			$result = $sent ? 'accepted' : 'failed';
			$retry_number = $state['failed_count'] + 1;
			$retry_minutes = $sent ? 0 : ( 1 === $retry_number ? 5 : ( 2 === $retry_number ? 60 : 0 ) );
			$next_retry = $retry_minutes ? wp_date( 'Y-m-d H:i:s', time() + ( $retry_minutes * MINUTE_IN_SECONDS ) ) : null;
			$history_updated = $wpdb->update(
				WC_PVR_Consent::history_table_name(),
				array(
					'result' => $result,
					'failure_code' => $failure_code ?: ( $sent ? null : 'wp_mail_returned_false' ),
					'retry_count' => $retry_count,
					'next_retry_at' => $next_retry,
				),
				array( 'id' => $attempt_id ),
				array( '%s', '%s', '%d', '%s' ),
				array( '%d' )
			);
			if ( false === $history_updated ) {
				return new WP_Error( 'send_outcome_unknown', __( 'We tried to send this reminder but could not record whether it worked. Check Reminder History before trying again.', 'wc-product-view-reminder-lite' ) );
			}
			if ( $sent ) {
				$sent_column = 'cart' === $type ? 'cart_reminder_sent' : 'view_reminder_sent';
				$wpdb->update( $table, array( $sent_column => 1 ), array( 'id' => absint( $fresh->id ) ), array( '%d' ), array( '%d' ) );
				return array( 'accepted' => true, 'message' => __( 'Reminder sent. We cannot confirm it reached the inbox.', 'wc-product-view-reminder-lite' ) );
			}
			return new WP_Error( 'mail_failed', __( 'The email could not be sent. It will be tried again automatically (up to 3 attempts).', 'wc-product-view-reminder-lite' ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
		}
	}

	private function record_attempt( $view, $type, $trigger, $actor_user_id, $result, $failure_code, $retry_count, $next_retry_at ) {
		global $wpdb;
		$inserted = $wpdb->insert(
			WC_PVR_Consent::history_table_name(),
			array(
				'view_id' => absint( $view->id ),
				'user_id' => absint( $view->user_id ) ?: null,
				'email_hash' => WC_PVR_Consent::email_hash( $view->email ),
				'product_id' => absint( $view->product_id ),
				'reminder_type' => $type,
				'trigger_type' => $trigger,
				'actor_user_id' => absint( $actor_user_id ) ?: null,
				'attempted_at' => current_time( 'mysql' ),
				'result' => $result,
				'failure_code' => $failure_code ?: null,
				'retry_count' => absint( $retry_count ),
				'next_retry_at' => $next_retry_at,
			),
			array( '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%s' )
		);
		return false === $inserted ? false : absint( $wpdb->insert_id );
	}

	/** Default product-view reminder body used when the saved option is empty. */
	public static function default_view_message() {
		return "Hi {customer_name},\n\nYou viewed {product_name}.\n\n{product_image}\n\n{cta_button}\n\n{unsubscribe_url}";
	}

	/** Default cart reminder body used when the saved option is empty. */
	public static function default_cart_message() {
		return "Hi {customer_name},\n\nYou added {product_name} to your cart.\n\n{product_image}\n\n{cta_button}\n\n{unsubscribe_url}";
	}

	/** Shared renderer for reminders, preview, and local test messages. */
	public function render_template( $product, $customer_name = '', $email = '', $type = 'view', $user_id = 0, $view = null, $synthetic = false ) {
		$is_real_product = $product && is_object( $product ) && is_callable( array( $product, 'get_name' ) );
		$product_name = $is_real_product ? $product->get_name() : __( 'Sample Product', 'wc-product-view-reminder-lite' );
		$product_url = $is_real_product ? $product->get_permalink() : 'https://example.test/sample-product';
		$price = $is_real_product ? wp_strip_all_tags( wc_price( $product->get_price() ) ) : '$0.00';
		$product_image = '';
		if ( $is_real_product && is_callable( array( $product, 'get_image_id' ) ) ) {
			$image_id = absint( $product->get_image_id() );
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';
			if ( ! $image_url && function_exists( 'wc_placeholder_img_src' ) ) {
				$image_url = wc_placeholder_img_src( 'woocommerce_thumbnail' );
			}
			if ( $image_url ) {
				$product_image = '<a href="' . esc_url( $product_url ) . '" style="display:inline-block"><img src="' . esc_url( $image_url ) . '" alt="' . esc_attr( wp_strip_all_tags( $product_name ) ) . '" width="200" style="max-width:200px;width:100%;height:auto;border:0;display:block;border-radius:4px;"></a>';
			}
		} elseif ( $synthetic ) {
			$product_image = '<div style="width:200px;height:140px;background:#f0f0f1;border-radius:4px;color:#646970;font:14px/140px sans-serif;text-align:center;">' . esc_html__( 'Product image', 'wc-product-view-reminder-lite' ) . '</div>';
		}
		$email = sanitize_email( $email );
		$consent = isset( $GLOBALS['wc_pvr_consent'] ) ? $GLOBALS['wc_pvr_consent'] : null;
		$unsubscribe_url = $synthetic ? 'https://example.test/unsubscribe-preview' : ( $consent instanceof WC_PVR_Consent ? $consent->create_unsubscribe_url( $email ) : '' );
		if ( ! $synthetic ) { $unsubscribe_url = apply_filters( 'wc_pvr_unsubscribe_url', $unsubscribe_url, absint( $user_id ), $email, $view ); }
		$unsubscribe_link = $unsubscribe_url ? '<a href="' . esc_url( $unsubscribe_url ) . '">' . esc_html__( 'Unsubscribe', 'wc-product-view-reminder-lite' ) . '</a>' : '';
		$subject_template = get_option( 'wc_pvr_email_subject', __( 'You viewed {product_name}', 'wc-product-view-reminder-lite' ) );
		if ( ! is_string( $subject_template ) || '' === trim( $subject_template ) ) {
			$subject_template = __( 'You viewed {product_name}', 'wc-product-view-reminder-lite' );
		}
		$default_message = 'cart' === $type ? self::default_cart_message() : self::default_view_message();
		$message_template = 'cart' === $type ? get_option( 'wc_pvr_cart_email_message', $default_message ) : get_option( 'wc_pvr_email_message', $default_message );
		if ( ! is_string( $message_template ) || '' === trim( wp_strip_all_tags( $message_template ) ) ) {
			$message_template = $default_message;
		}
		$email_heading = get_option( 'wc_pvr_email_heading', __( 'A product you viewed is waiting', 'wc-product-view-reminder-lite' ) );
		$subject = str_replace( array( '{product_name}', '{store_name}' ), array( wp_strip_all_tags( $product_name ), wp_strip_all_tags( get_bloginfo( 'name' ) ) ), sanitize_text_field( $subject_template ) );
		$product_link = '<a href="' . esc_url( $product_url ) . '">' . esc_html__( 'View product', 'wc-product-view-reminder-lite' ) . '</a>';
		$button_text = sanitize_text_field( get_option( 'wc_pvr_email_button_text', __( 'View product', 'wc-product-view-reminder-lite' ) ) );
		$cta_button = '<a href="' . esc_url( $product_url ) . '" style="display:inline-block;padding:10px 16px;text-decoration:none;background:#2271b1;color:#fff;border-radius:3px">' . esc_html( $button_text ) . '</a>';
		$message = str_replace(
			array( '{customer_name}', '{product_name}', '{product_link}', '{product_url}', '{product_price}', '{product_image}', '{store_name}', '{email_heading}', '{unsubscribe_url}', '{cta_button}' ),
			array( esc_html( $customer_name ), esc_html( $product_name ), $product_link, esc_url( $product_url ), esc_html( $price ), $product_image, esc_html( get_bloginfo( 'name' ) ), esc_html( $email_heading ), $unsubscribe_link, $cta_button ),
			wp_kses_post( $message_template )
		);
		if ( false === strpos( $message, '<p' ) && false === strpos( $message, '<br' ) && function_exists( 'wpautop' ) ) {
			$message = wpautop( $message );
		}
		if ( $unsubscribe_link && false === strpos( $message_template, '{unsubscribe_url}' ) ) { $message .= '<p>' . esc_html__( 'To stop future product reminders,', 'wc-product-view-reminder-lite' ) . ' ' . $unsubscribe_link . '.</p>'; }
		return array( 'subject' => $subject, 'message' => $message, 'unsubscribe_url' => $unsubscribe_url );
	}

	public function send_local_test_email( $recipient ) {
		$recipient = sanitize_email( $recipient );
		$domain = is_email( $recipient ) ? substr( strrchr( $recipient, '@' ), 1 ) : '';
		if ( 'local' !== wp_get_environment_type() || ! $domain || ! preg_match( '/\.test$/i', $domain ) ) {
			return false;
		}
		$template = $this->render_template( null, 'Sample Customer', 'synthetic@example.test', 'view', 0, null, true );
		return wp_mail( $recipient, '[Local test] ' . $template['subject'], $template['message'], $this->mail_headers() );
	}

	private function mail_headers( $unsubscribe_url = '' ) {
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );
		$name = sanitize_text_field( get_option( 'wc_pvr_email_sender_name', get_bloginfo( 'name' ) ) );
		$email = sanitize_email( get_option( 'wc_pvr_email_sender_email', get_option( 'admin_email' ) ) );
		if ( $name && is_email( $email ) ) {
			$safe_name = str_replace( array( "\r", "\n", '"' ), '', $name );
			$headers[] = 'From: "' . $safe_name . '" <' . $email . '>';
		}
		if ( $unsubscribe_url ) {
			$safe_unsubscribe = function_exists( 'esc_url_raw' ) ? esc_url_raw( $unsubscribe_url ) : esc_url( $unsubscribe_url );
			if ( $safe_unsubscribe ) {
				$headers[] = 'List-Unsubscribe: <' . $safe_unsubscribe . '>';
				$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
			}
		}
		return $headers;
	}
}
