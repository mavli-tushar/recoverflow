<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WordPress privacy tools and bounded RecoverFlow retention cleanup. */
class WC_PVR_Privacy {

	const CLEANUP_BATCH = 250;

	public function __construct() {
		add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( $this, 'register_eraser' ) );
		add_action( 'admin_init', array( $this, 'register_privacy_policy_content' ) );
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( 'wc_pvr_retention_cleanup', array( $this, 'run_retention_cleanup' ) );
	}

	public function register_privacy_policy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) { return; }
		$content = '<p>' . esc_html__( 'RecoverFlow records identified product views for logged-in customers and stores their email in its existing view table. When enabled, the consent ledger stores a keyed email hash, consent status, source, channel, wording version, and timestamps. Reminder history and purchase lookup store keyed email hashes and product, order, and attempt references. Product views are not treated as consent.', 'wc-product-view-reminder-lite' ) . '</p>';
		$content .= '<p>' . esc_html__( 'WordPress privacy export and erasure tools include RecoverFlow records. Erasure removes RecoverFlow view, history, and purchase lookup references but does not change WooCommerce orders. By default, one keyed opt-out suppression hash may remain so another consent integration cannot re-enable marketing after erasure. This can be disabled in RecoverFlow retention settings if your privacy obligations require complete deletion.', 'wc-product-view-reminder-lite' ) . '</p>';
		$content .= '<p>' . esc_html__( 'View, reminder-history, and older consent-choice retention are configurable in RecoverFlow settings. A value of 0 retains these records until manual erasure. The latest consent choice is always kept by automatic retention, and expired unsubscribe token data is cleared in bounded batches.', 'wc-product-view-reminder-lite' ) . '</p>';
		wp_add_privacy_policy_content( __( 'RecoverFlow', 'wc-product-view-reminder-lite' ), wp_kses_post( $content ) );
	}

	public function register_exporter( $exporters ) {
		$exporters['wc-pvr-reminders'] = array(
			'exporter_friendly_name' => __( 'RecoverFlow product reminders', 'wc-product-view-reminder-lite' ),
			'callback' => array( $this, 'export_personal_data' ),
		);
		return $exporters;
	}

	public function register_eraser( $erasers ) {
		$erasers['wc-pvr-reminders'] = array(
			'eraser_friendly_name' => __( 'RecoverFlow product reminders', 'wc-product-view-reminder-lite' ),
			'callback' => array( $this, 'erase_personal_data' ),
		);
		return $erasers;
	}

	private function identity( $email_address ) {
		$user = get_user_by( 'email', sanitize_email( $email_address ) );
		$user_id = $user ? absint( $user->ID ) : 0;
		$email_hash = class_exists( 'WC_PVR_Consent' ) ? WC_PVR_Consent::email_hash( $email_address ) : '';
		return array( $user_id, $email_hash, sanitize_email( $email_address ) );
	}

	private function hash_placeholders( $hashes ) {
		return implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
	}

	/** WordPress personal-data exporter callback. */
	public function export_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		list( $user_id, $email_hash, $email ) = $this->identity( $email_address );
		if ( ! is_email( $email ) ) { return array( 'data' => array(), 'done' => true ); }
		$hashes = $email_hash ? array( $email_hash ) : array();
		$consent = WC_PVR_Consent::table_name();
		$views = $wpdb->prefix . 'wc_pvr_views';
		$history = WC_PVR_Consent::history_table_name();
		$purchases = WC_PVR_Consent::purchases_table_name();
		if ( $user_id && WC_PVR_Consent::is_installed() ) {
			$old_hashes = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT email_hash FROM {$consent} WHERE user_id = %d LIMIT 1000", $user_id ) );
			$hashes = array_values( array_unique( array_filter( array_merge( $hashes, (array) $old_hashes ) ) ) );
		}
		$offset = ( max( 1, absint( $page ) ) - 1 ) * 100;
		$data = array();
		$more = false;
		$groups = array();

		$view_where = $user_id ? $wpdb->prepare( 'user_id = %d OR email = %s', $user_id, $email ) : $wpdb->prepare( 'email = %s', $email );
		$view_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,product_id,added_to_cart,purchased,view_time,view_reminder_sent,cart_reminder_sent FROM {$views} WHERE {$view_where} ORDER BY id ASC LIMIT 100 OFFSET %d", $offset ) );
		$view_count = absint( $wpdb->get_var( "SELECT COUNT(*) FROM {$views} WHERE {$view_where}" ) );
		$more = $more || $view_count > $offset + count( $view_rows );
		foreach ( (array) $view_rows as $row ) {
			$product = wc_get_product( absint( $row->product_id ) );
			$groups[] = $this->export_item( 'view-' . absint( $row->id ), __( 'Product view', 'wc-product-view-reminder-lite' ), array(
				'Product' => $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $row->product_id ) ),
				'Viewed at' => $row->view_time,
				'Added to cart' => $row->added_to_cart ? __( 'Yes', 'wc-product-view-reminder-lite' ) : __( 'No', 'wc-product-view-reminder-lite' ),
				__( 'Bought this product', 'wc-product-view-reminder-lite' ) => $row->purchased ? __( 'Yes', 'wc-product-view-reminder-lite' ) : __( 'No', 'wc-product-view-reminder-lite' ),
				__( 'Product reminder sent', 'wc-product-view-reminder-lite' ) => $row->view_reminder_sent ? __( 'Yes', 'wc-product-view-reminder-lite' ) : __( 'No', 'wc-product-view-reminder-lite' ),
				__( 'Cart reminder sent', 'wc-product-view-reminder-lite' ) => $row->cart_reminder_sent ? __( 'Yes', 'wc-product-view-reminder-lite' ) : __( 'No', 'wc-product-view-reminder-lite' ),
			) );
		}

		if ( WC_PVR_Consent::is_installed() ) {
			$identity_where = $user_id ? '(user_id = %d OR email_hash = %s)' : 'email_hash = %s';
			$identity_args = $user_id ? array( $user_id, $email_hash ) : array( $email_hash );
			$page_args = array_merge( $identity_args, array( $offset ) );
			$consent_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,status,source,channel,wording_version,consented_at,revoked_at,created_at FROM {$consent} WHERE {$identity_where} ORDER BY id ASC LIMIT 100 OFFSET %d", $page_args ) );
			$consent_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$consent} WHERE {$identity_where}", $identity_args ) ) );
			$more = $more || $consent_count > $offset + count( $consent_rows );
			foreach ( (array) $consent_rows as $row ) {
				$groups[] = $this->export_item( 'consent-' . absint( $row->id ), __( 'Consent choice', 'wc-product-view-reminder-lite' ), array(
					'Status' => $row->status, 'Source' => $row->source, 'Channel' => $row->channel,
					'Wording version' => $row->wording_version, 'Recorded at' => $row->created_at,
					'Consented at' => $row->consented_at, 'Revoked at' => $row->revoked_at,
				) );
			}

			if ( $hashes ) {
				$placeholders = $this->hash_placeholders( $hashes );
				$hash_args = $hashes;
				if ( $user_id ) { $history_where = '(user_id = %d OR email_hash IN (' . $placeholders . '))'; $history_args = array_merge( array( $user_id ), $hash_args ); }
				else { $history_where = 'email_hash IN (' . $placeholders . ')'; $history_args = $hash_args; }
				$history_args[] = $offset;
				$history_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,product_id,reminder_type,trigger_type,attempted_at,result,failure_code,retry_count FROM {$history} WHERE {$history_where} ORDER BY id ASC LIMIT 100 OFFSET %d", $history_args ) );
				$count_args = $user_id ? array_merge( array( $user_id ), $hashes ) : $hashes;
				$history_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$history} WHERE {$history_where}", $count_args ) ) );
				$more = $more || $history_count > $offset + count( $history_rows );
				foreach ( (array) $history_rows as $row ) {
					$product = wc_get_product( absint( $row->product_id ) );
					$groups[] = $this->export_item( 'attempt-' . absint( $row->id ), __( 'Reminder attempt', 'wc-product-view-reminder-lite' ), array(
						'Product' => $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $row->product_id ) ),
						'Reminder type' => $row->reminder_type, 'Trigger' => $row->trigger_type,
						'Attempted at' => $row->attempted_at, 'Result' => $row->result,
						'Failure code' => $row->failure_code, 'Retry count' => absint( $row->retry_count ),
					) );
				}

				$purchase_where = $user_id ? '(user_id = %d OR email_hash IN (' . $placeholders . '))' : 'email_hash IN (' . $placeholders . ')';
				$purchase_args = $user_id ? array_merge( array( $user_id ), $hashes ) : $hashes;
				$purchase_page_args = array_merge( $purchase_args, array( $offset ) );
				$purchase_rows = $wpdb->get_results( $wpdb->prepare( "SELECT id,order_id,product_id,variation_id,order_status,updated_at FROM {$purchases} WHERE {$purchase_where} ORDER BY id ASC LIMIT 100 OFFSET %d", $purchase_page_args ) );
				$purchase_count = absint( $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$purchases} WHERE {$purchase_where}", $purchase_args ) ) );
				$more = $more || $purchase_count > $offset + count( $purchase_rows );
				foreach ( (array) $purchase_rows as $row ) {
					$product = wc_get_product( absint( $row->product_id ) );
					$groups[] = $this->export_item( 'purchase-' . absint( $row->id ), __( 'Order reference', 'wc-product-view-reminder-lite' ), array(
						'WooCommerce order ID' => absint( $row->order_id ),
						'Product' => $product ? $product->get_name() : sprintf( __( 'Deleted product #%d', 'wc-product-view-reminder-lite' ), absint( $row->product_id ) ),
						'Variation ID' => absint( $row->variation_id ), 'Order status' => $row->order_status,
						'Index updated' => $row->updated_at,
					) );
				}
			}
		}
		$data = array_merge( $data, $groups );
		return array( 'data' => $data, 'done' => ! $more );
	}

	private function export_item( $id, $label, $fields ) {
		$data = array();
		foreach ( $fields as $name => $value ) {
			$data[] = array( 'name' => $name, 'value' => null === $value ? '' : (string) $value );
		}
		return array( 'group_id' => 'wc-pvr-reminders', 'group_label' => __( 'RecoverFlow product reminders', 'wc-product-view-reminder-lite' ), 'item_id' => $id, 'data' => $data );
	}

	/** WordPress personal-data eraser callback; at most 100 rows per data set per pass. */
	public function erase_personal_data( $email_address, $page = 1 ) {
		global $wpdb;
		list( $user_id, $email_hash, $email ) = $this->identity( $email_address );
		if ( ! is_email( $email ) ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }
		$views = $wpdb->prefix . 'wc_pvr_views';
		$history = WC_PVR_Consent::history_table_name();
		$purchases = WC_PVR_Consent::purchases_table_name();
		$consent = WC_PVR_Consent::table_name();
		$has_data = (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$views} WHERE " . ( $user_id ? '(user_id = %d OR email = %s)' : 'email = %s' ) . ' LIMIT 1', $user_id ? array( $user_id, $email ) : array( $email ) ) );
		if ( WC_PVR_Consent::is_installed() && $email_hash ) {
			$history_match = $user_id ? $wpdb->prepare( '(user_id = %d OR email_hash = %s)', $user_id, $email_hash ) : $wpdb->prepare( 'email_hash = %s', $email_hash );
			$consent_match = $user_id ? $wpdb->prepare( '(email_hash = %s OR user_id = %d)', $email_hash, $user_id ) : $wpdb->prepare( 'email_hash = %s', $email_hash );
			$has_data = $has_data || (bool) $wpdb->get_var( "SELECT id FROM {$consent} WHERE {$consent_match} LIMIT 1" );
			$has_data = $has_data || (bool) $wpdb->get_var( "SELECT id FROM {$history} WHERE {$history_match} LIMIT 1" );
			$has_data = $has_data || (bool) $wpdb->get_var( "SELECT id FROM {$purchases} WHERE {$history_match} LIMIT 1" );
		}
		if ( ! $has_data ) { return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true ); }

		$suppression_id = 0;
		$preserve = (bool) get_option( 'wc_pvr_preserve_optout_after_erasure', 1 );
		if ( WC_PVR_Consent::is_installed() && $preserve && $email_hash ) {
			$current = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$consent} WHERE email_hash = %s ORDER BY id DESC LIMIT 1", $email_hash ) );
			$now = current_time( 'mysql' );
			if ( $current ) {
				$suppression_id = absint( $current->id );
				$wpdb->update( $consent, array( 'user_id' => null, 'status' => 'opted_out', 'source' => 'privacy_eraser', 'channel' => 'email', 'wording_version' => '', 'consented_at' => null, 'revoked_at' => $now, 'unsubscribe_token_hash' => null, 'token_expires_at' => null, 'token_used_at' => null ), array( 'id' => $suppression_id ) );
			} else {
				$wpdb->insert( $consent, array( 'user_id' => null, 'email_hash' => $email_hash, 'status' => 'opted_out', 'source' => 'privacy_eraser', 'channel' => 'email', 'wording_version' => '', 'consented_at' => null, 'revoked_at' => $now, 'created_at' => $now ) );
				$suppression_id = absint( $wpdb->insert_id );
			}
		}

		$removed = 0;
		$view_where = $user_id ? $wpdb->prepare( '(user_id = %d OR email = %s)', $user_id, $email ) : $wpdb->prepare( 'email = %s', $email );
		$removed += $this->delete_batch( $views, $view_where );
		if ( WC_PVR_Consent::is_installed() && $email_hash ) {
			$identity_where = $user_id ? $wpdb->prepare( '(user_id = %d OR email_hash = %s)', $user_id, $email_hash ) : $wpdb->prepare( 'email_hash = %s', $email_hash );
			$removed += $this->delete_batch( $history, $identity_where );
			$removed += $this->delete_batch( $purchases, $identity_where );
			$consent_where = $identity_where . ( $suppression_id ? $wpdb->prepare( ' AND id <> %d', $suppression_id ) : '' );
			$removed += $this->delete_batch( $consent, $consent_where );
		}
		$remaining = (bool) $wpdb->get_var( "SELECT id FROM {$views} WHERE {$view_where} LIMIT 1" );
		if ( WC_PVR_Consent::is_installed() && $email_hash ) {
			$identity_where = $user_id ? $wpdb->prepare( '(user_id = %d OR email_hash = %s)', $user_id, $email_hash ) : $wpdb->prepare( 'email_hash = %s', $email_hash );
			$remaining = $remaining || (bool) $wpdb->get_var( "SELECT id FROM {$history} WHERE {$identity_where} LIMIT 1" ) || (bool) $wpdb->get_var( "SELECT id FROM {$purchases} WHERE {$identity_where} LIMIT 1" );
			$consent_remaining = $identity_where . ( $suppression_id ? $wpdb->prepare( ' AND id <> %d', $suppression_id ) : '' );
			$remaining = $remaining || (bool) $wpdb->get_var( "SELECT id FROM {$consent} WHERE {$consent_remaining} LIMIT 1" );
		}
		$messages = array();
		if ( $suppression_id ) { $messages[] = __( 'We kept a minimal, unreadable record that this customer opted out, so they are not emailed again. It does not contain their email address.', 'wc-product-view-reminder-lite' ); }
		$messages[] = __( 'RecoverFlow removed matching product views, reminder history, and order references. WooCommerce orders were not changed.', 'wc-product-view-reminder-lite' );
		return array( 'items_removed' => $removed > 0, 'items_retained' => (bool) $suppression_id, 'messages' => $messages, 'done' => ! $remaining );
	}

	private function delete_batch( $table, $where ) {
		global $wpdb;
		$ids = $wpdb->get_col( "SELECT id FROM {$table} WHERE {$where} ORDER BY id ASC LIMIT 100" );
		if ( empty( $ids ) ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", array_map( 'absint', $ids ) ) );
	}

	public function schedule_cleanup() {
		if ( ! wp_next_scheduled( 'wc_pvr_retention_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'wc_pvr_retention_cleanup' );
		}
	}

	public function run_retention_cleanup() {
		global $wpdb;
		$views = $wpdb->prefix . 'wc_pvr_views';
		$history = WC_PVR_Consent::history_table_name();
		$consent = WC_PVR_Consent::table_name();
		$now = current_datetime();
		$deleted = array( 'views' => 0, 'history' => 0, 'consent_history' => 0, 'tokens' => 0 );
		$view_days = absint( get_option( 'wc_pvr_retention_views_days', 0 ) );
		if ( $view_days > 0 ) { $cutoff = $now->modify( '-' . $view_days . ' days' )->format( 'Y-m-d H:i:s' ); $deleted['views'] = $this->delete_expired( $views, 'view_time', $cutoff ); }
		$history_days = absint( get_option( 'wc_pvr_retention_history_days', 0 ) );
		if ( $history_days > 0 && WC_PVR_Consent::is_installed() ) { $cutoff = $now->modify( '-' . $history_days . ' days' )->format( 'Y-m-d H:i:s' ); $deleted['history'] = $this->delete_expired( $history, 'attempted_at', $cutoff ); }
		$consent_days = absint( get_option( 'wc_pvr_retention_consent_days', 0 ) );
		if ( $consent_days > 0 && WC_PVR_Consent::is_installed() ) {
			$cutoff = $now->modify( '-' . $consent_days . ' days' )->format( 'Y-m-d H:i:s' );
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT old.id FROM {$consent} old INNER JOIN {$consent} newer ON newer.email_hash = old.email_hash AND newer.id > old.id WHERE old.created_at < %s ORDER BY old.created_at ASC,old.id ASC LIMIT %d", $cutoff, self::CLEANUP_BATCH ) );
			$deleted['consent_history'] = $this->delete_ids( $consent, $ids );
		}
		if ( WC_PVR_Consent::is_installed() ) {
			$expired_tokens = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$consent} WHERE token_expires_at IS NOT NULL AND token_expires_at < %s ORDER BY token_expires_at ASC LIMIT %d", gmdate( 'Y-m-d H:i:s' ), self::CLEANUP_BATCH ) );
			if ( $expired_tokens ) {
				$placeholders = implode( ',', array_fill( 0, count( $expired_tokens ), '%d' ) );
				$deleted['tokens'] = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$consent} SET unsubscribe_token_hash = NULL,token_expires_at = NULL,token_used_at = NULL WHERE id IN ({$placeholders})", array_map( 'absint', $expired_tokens ) ) );
			}
		}
		update_option( 'wc_pvr_last_retention_cleanup_at', $now->format( 'Y-m-d H:i:s' ), false );
		update_option( 'wc_pvr_last_retention_cleanup_counts', $deleted, false );
	}

	private function delete_expired( $table, $column, $cutoff ) {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$column} < %s ORDER BY {$column} ASC,id ASC LIMIT %d", $cutoff, self::CLEANUP_BATCH ) );
		return $this->delete_ids( $table, $ids );
	}

	private function delete_ids( $table, $ids ) {
		global $wpdb;
		if ( empty( $ids ) ) { return 0; }
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ({$placeholders})", array_map( 'absint', $ids ) ) );
	}
}
