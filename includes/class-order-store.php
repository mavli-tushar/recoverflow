<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** HPOS-safe product purchase index maintained through WooCommerce CRUD/hooks. */
class WC_PVR_Order_Store {

	public function __construct() {
		add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 4 );
		add_action( 'woocommerce_payment_complete', array( $this, 'sync_order' ), 10, 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'sync_order' ), 20, 1 );
		add_action( 'wc_pvr_order_backfill', array( $this, 'backfill_batch' ) );
	}

	public function order_status_changed( $order_id, $old_status, $new_status, $order = null ) {
		unset( $old_status, $new_status, $order );
		$this->sync_order( $order_id );
	}

	public function sync_order( $order_id ) {
		$order = $order_id instanceof WC_Order ? $order_id : wc_get_order( absint( $order_id ) );
		if ( ! $order ) { return false; }
		global $wpdb;
		$table = WC_PVR_Consent::purchases_table_name();
		$email_hash = WC_PVR_Consent::email_hash( $order->get_billing_email() );
		$status = sanitize_key( $order->get_status() );
		$user_id = absint( $order->get_customer_id() );
		$current_lines = array();
		$sync_ok = true;
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product_id = absint( $item->get_product_id() );
			if ( ! $product_id ) { continue; }
			$variation_id = absint( $item->get_variation_id() );
			$current_lines[ $product_id . ':' . $variation_id ] = true;
			$result = $wpdb->replace(
				$table,
				array(
					'order_id' => absint( $order->get_id() ),
					'product_id' => $product_id,
					'variation_id' => $variation_id,
					'user_id' => $user_id ?: null,
					'email_hash' => $email_hash,
					'order_status' => $status,
					'updated_at' => current_time( 'mysql' ),
				),
				array( '%d', '%d', '%d', '%d', '%s', '%s', '%s' )
			);
			if ( false === $result ) { $sync_ok = false; }

			if ( in_array( $status, array( 'processing', 'completed' ), true ) && $order->is_paid() ) {
				$this->mark_matching_views_purchased( $order, $product_id );
			} elseif ( in_array( $status, array( 'refunded', 'cancelled', 'failed', 'trash' ), true ) ) {
				$this->clear_matching_views_purchased( $order, $product_id );
			}
		}
		if ( $sync_ok ) {
			$existing_lines = $wpdb->get_results( $wpdb->prepare( "SELECT id,product_id,variation_id FROM {$table} WHERE order_id = %d", absint( $order->get_id() ) ) );
			foreach ( (array) $existing_lines as $existing_line ) {
				$key = absint( $existing_line->product_id ) . ':' . absint( $existing_line->variation_id );
				if ( ! isset( $current_lines[ $key ] ) && false === $wpdb->delete( $table, array( 'id' => absint( $existing_line->id ) ), array( '%d' ) ) ) { $sync_ok = false; }
			}
		}
		return $sync_ok;
	}

	private function matching_view_product_ids( $order, $product_id ) {
		$line_item_ids = array( absint( $product_id ) );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( absint( $item->get_product_id() ) === absint( $product_id ) && $item->get_variation_id() ) {
				$line_item_ids[] = absint( $item->get_variation_id() );
			}
		}
		$product = wc_get_product( absint( $product_id ) );
		return array_unique( array_filter( array_merge( $line_item_ids, array( $product ? absint( $product->get_parent_id() ) : 0 ) ) ) );
	}

	private function mark_matching_views_purchased( $order, $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_pvr_views';
		foreach ( $this->matching_view_product_ids( $order, $product_id ) as $view_product_id ) {
			if ( $order->get_customer_id() ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET purchased = 1 WHERE product_id = %d AND user_id = %d", $view_product_id, absint( $order->get_customer_id() ) ) );
			}
			$email = sanitize_email( $order->get_billing_email() );
			if ( is_email( $email ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET purchased = 1 WHERE product_id = %d AND email = %s", $view_product_id, $email ) );
			}
		}
	}

	/** Clears the purchased flag so refunded/cancelled orders can re-enter the reminder queue. */
	private function clear_matching_views_purchased( $order, $product_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'wc_pvr_views';
		foreach ( $this->matching_view_product_ids( $order, $product_id ) as $view_product_id ) {
			if ( $order->get_customer_id() ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET purchased = 0 WHERE product_id = %d AND user_id = %d AND purchased = 1", $view_product_id, absint( $order->get_customer_id() ) ) );
			}
			$email = sanitize_email( $order->get_billing_email() );
			if ( is_email( $email ) ) {
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET purchased = 0 WHERE product_id = %d AND email = %s AND purchased = 1", $view_product_id, $email ) );
			}
		}
	}

	/** One hundred CRUD orders per invocation; no direct order-table SQL. */
	public function backfill_batch() {
		if ( ! WC_PVR_Consent::is_installed() ) { return; }
		$page = max( 1, absint( get_option( 'wc_pvr_orders_backfill_page', 1 ) ) );
		$batch = wc_get_orders( array(
			'limit' => 100,
			'page' => $page,
			'paginate' => true,
			'orderby' => 'ID',
			'order' => 'ASC',
			'return' => 'objects',
			'status' => array_keys( wc_get_order_statuses() ),
		) );
		if ( ! is_object( $batch ) || ! isset( $batch->orders, $batch->max_num_pages ) || ! is_array( $batch->orders ) ) {
			$this->schedule_backfill_retry();
			return;
		}
		foreach ( $batch->orders as $order ) {
			if ( ! $this->sync_order( $order ) ) { $this->schedule_backfill_retry(); return; }
		}
		if ( $page < absint( $batch->max_num_pages ) ) {
			update_option( 'wc_pvr_orders_backfill_page', $page + 1, false );
			$this->schedule_backfill_retry( 30 );
			return;
		}
		update_option( 'wc_pvr_orders_backfill_complete', 1, false );
	}

	private function schedule_backfill_retry( $delay = 300 ) {
		if ( ! wp_next_scheduled( 'wc_pvr_order_backfill' ) ) { wp_schedule_single_event( time() + absint( $delay ), 'wc_pvr_order_backfill' ); }
	}

	public function has_paid_purchase( $view, $live_check = true ) {
		global $wpdb;
		$table = WC_PVR_Consent::purchases_table_name();
		$product_id = absint( $view->product_id );
		$user_id = absint( $view->user_id );
		$email_hash = WC_PVR_Consent::email_hash( $view->email );
		if ( ! $product_id || ! $email_hash ) { return false; }
		$product = wc_get_product( $product_id );
		$is_variation = $product && $product->is_type( 'variation' );
		/* A tracked variation matches that exact variation; a parent product matches any of its variations. */
		if ( $is_variation ) {
			$match_sql = '(variation_id = %d OR product_id = %d)';
			$match_args = array( $product_id, $product_id );
		} else {
			$match_sql = 'product_id = %d';
			$match_args = array( $product_id );
		}
		$args = $match_args;
		if ( $user_id ) {
			$args[] = $user_id;
			$args[] = $email_hash;
			$indexed = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_status IN ('processing','completed') AND {$match_sql} AND (user_id = %d OR email_hash = %s) ORDER BY updated_at DESC LIMIT 20", $args ) );
		} else {
			$args[] = $email_hash;
			$indexed = $wpdb->get_col( $wpdb->prepare( "SELECT order_id FROM {$table} WHERE order_status IN ('processing','completed') AND {$match_sql} AND email_hash = %s ORDER BY updated_at DESC LIMIT 20", $args ) );
		}
		if ( ! $live_check ) { return ! empty( $indexed ); }
		foreach ( array_unique( array_map( 'absint', (array) $indexed ) ) as $indexed_order_id ) {
			$indexed_order = wc_get_order( $indexed_order_id );
			if ( $this->order_matches_view( $indexed_order, $view, $is_variation ) ) { return true; }
			if ( $indexed_order ) { $this->sync_order( $indexed_order ); }
		}

		/*
		 * The local product index is maintained from order lifecycle hooks, but a
		 * bounded CRUD lookup catches a newly paid order before its hook has synced.
		 * The index remains the source for older orders, avoiding a full-order scan.
		 */
		$query = array(
			'limit'   => 20,
			'orderby' => 'date',
			'order'   => 'DESC',
			'status'  => array( 'processing', 'completed' ),
			'return'  => 'objects',
		);
		$customer_keys = $user_id ? array( $user_id, sanitize_email( $view->email ) ) : array( sanitize_email( $view->email ) );
		$seen_order_ids = array();
		foreach ( $customer_keys as $customer_key ) {
			if ( ! $customer_key ) { continue; }
			$query['customer'] = $customer_key;
			$orders = wc_get_orders( $query );
			foreach ( (array) $orders as $order ) {
				if ( ! $order instanceof WC_Order || isset( $seen_order_ids[ $order->get_id() ] ) ) { continue; }
				$seen_order_ids[ $order->get_id() ] = true;
				if ( $this->order_matches_view( $order, $view, $is_variation ) ) { $this->sync_order( $order ); return true; }
			}
		}
		return false;
	}

	private function order_matches_view( $order, $view, $is_variation ) {
		if ( ! $order instanceof WC_Order || ! $order->is_paid() || ! in_array( $order->get_status(), array( 'processing', 'completed' ), true ) ) { return false; }
		$user_id = absint( $view->user_id );
		$email = strtolower( sanitize_email( $view->email ) );
		$order_email = strtolower( sanitize_email( $order->get_billing_email() ) );
		if ( $user_id && absint( $order->get_customer_id() ) !== $user_id && $order_email !== $email ) { return false; }
		if ( ! $user_id && $order_email !== $email ) { return false; }
		$product_id = absint( $view->product_id );
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			if ( $is_variation ? absint( $item->get_variation_id() ) === $product_id : absint( $item->get_product_id() ) === $product_id ) { return true; }
		}
		return false;
	}
}
