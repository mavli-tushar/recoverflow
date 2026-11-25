<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Minimal consent ledger. It stores no raw email address; identity is a hash
 * of the normalized address already held by the existing view record.
 */
class WC_PVR_Consent {

	const DB_VERSION = '1.4';
	const CHECKOUT_FIELD = 'wc-pvr/marketing-consent';
	const WORDING_VERSION = '1';
	private static $installed_cache = null;

	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_pvr_consent';
	}

	public static function history_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_pvr_email_history';
	}

	public static function purchases_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wc_pvr_order_products';
	}

	public static function maybe_install() {
		global $wpdb;
		self::$installed_cache = null;
		$table = self::table_name();
		$history_table = self::history_table_name();
		$purchases_table = self::purchases_table_name();
		$purchases_table_existed = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $purchases_table ) ) === $purchases_table;
		$views_table = $wpdb->prefix . 'wc_pvr_views';
		$tables_exist = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history_table ) ) === $history_table
			&& $purchases_table_existed
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $views_table ) ) === $views_table;
		if ( self::DB_VERSION === get_option( 'wc_pvr_consent_db_version' ) && $tables_exist ) {
			if ( get_option( 'wc_pvr_consent_indexes_verified', 0 ) ) { return; }
			$view_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$views_table}", 0 );
			$view_indexes = $wpdb->get_col( "SHOW INDEX FROM {$views_table}", 2 );
			$consent_indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
			$history_indexes = $wpdb->get_col( "SHOW INDEX FROM {$history_table}", 2 );
			if ( in_array( 'cart_added_at', (array) $view_columns, true )
				&& ! array_diff( array( 'view_time', 'user_id', 'customer_email' ), (array) $view_indexes )
				&& ! array_diff( array( 'email_latest', 'token_expiry', 'consent_created' ), (array) $consent_indexes )
				&& ! array_diff( array( 'user_attempted' ), (array) $history_indexes ) ) {
				update_option( 'wc_pvr_consent_indexes_verified', 1, false );
				return;
			}
		}
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		if ( function_exists( 'wc_pvr_create_table' ) ) {
			wc_pvr_create_table();
		}
		$charset_collate = $wpdb->get_charset_collate();
		$sql             = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			email_hash CHAR(64) NOT NULL,
			status VARCHAR(12) NOT NULL,
			source VARCHAR(64) NOT NULL,
			channel VARCHAR(32) NOT NULL,
			wording_version VARCHAR(32) NOT NULL,
			consented_at DATETIME DEFAULT NULL,
			revoked_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			unsubscribe_token_hash CHAR(64) DEFAULT NULL,
			token_expires_at DATETIME DEFAULT NULL,
			token_used_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY email_status (email_hash,status),
			KEY email_latest (email_hash,id),
			KEY user_status (user_id,status),
			KEY unsubscribe_token_hash (unsubscribe_token_hash),
			KEY token_expiry (token_expires_at),
			KEY consent_created (created_at)
		) {$charset_collate};";
		dbDelta( $sql );
		$history_sql = "CREATE TABLE {$history_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			view_id BIGINT(20) UNSIGNED NOT NULL,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			email_hash CHAR(64) NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			reminder_type VARCHAR(12) NOT NULL,
			trigger_type VARCHAR(12) NOT NULL,
			actor_user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			attempted_at DATETIME NOT NULL,
			result VARCHAR(12) NOT NULL,
			failure_code VARCHAR(64) DEFAULT NULL,
			retry_count SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
			next_retry_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY view_type_result (view_id,reminder_type,result),
			KEY attempted_result (attempted_at,result),
			KEY user_attempted (user_id,attempted_at),
			KEY customer_email (email_hash,attempted_at),
			KEY product_attempted (product_id,attempted_at)
		) {$charset_collate};";
		dbDelta( $history_sql );
		$purchases_sql = "CREATE TABLE {$purchases_table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			product_id BIGINT(20) UNSIGNED NOT NULL,
			variation_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT(20) UNSIGNED DEFAULT NULL,
			email_hash CHAR(64) NOT NULL,
			order_status VARCHAR(20) NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_product_variation (order_id,product_id,variation_id),
			KEY product_user_status (product_id,user_id,order_status),
			KEY variation_user_status (variation_id,user_id,order_status),
			KEY email_product_status (email_hash,product_id,order_status),
			KEY email_variation_status (email_hash,variation_id,order_status)
		) {$charset_collate};";
		dbDelta( $purchases_sql );
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
			return;
		}
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history_table ) ) !== $history_table || $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $purchases_table ) ) !== $purchases_table ) { return; }
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 );
		if ( ! is_array( $columns ) || array_diff( array( 'email_hash', 'status', 'source', 'channel', 'wording_version', 'consented_at', 'revoked_at', 'unsubscribe_token_hash', 'token_expires_at', 'token_used_at' ), $columns ) ) {
			return;
		}
		$view_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}wc_pvr_views", 0 );
		if ( ! is_array( $view_columns ) || ! in_array( 'cart_added_at', $view_columns, true ) ) { return; }
		$history_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$history_table}", 0 );
		$purchases_columns = $wpdb->get_col( "SHOW COLUMNS FROM {$purchases_table}", 0 );
		if ( ! is_array( $history_columns ) || array_diff( array( 'view_id', 'user_id', 'email_hash', 'product_id', 'reminder_type', 'trigger_type', 'actor_user_id', 'attempted_at', 'result', 'failure_code', 'retry_count', 'next_retry_at' ), $history_columns ) ) { return; }
		if ( ! is_array( $purchases_columns ) || array_diff( array( 'order_id', 'product_id', 'variation_id', 'user_id', 'email_hash', 'order_status', 'updated_at' ), $purchases_columns ) ) { return; }
		$view_indexes = $wpdb->get_col( "SHOW INDEX FROM {$wpdb->prefix}wc_pvr_views", 2 );
		$consent_indexes = $wpdb->get_col( "SHOW INDEX FROM {$table}", 2 );
		$history_indexes = $wpdb->get_col( "SHOW INDEX FROM {$history_table}", 2 );
		if ( array_diff( array( 'view_time', 'user_id', 'customer_email' ), (array) $view_indexes )
			|| array_diff( array( 'email_latest', 'token_expiry', 'consent_created' ), (array) $consent_indexes )
			|| array_diff( array( 'user_attempted' ), (array) $history_indexes ) ) { return; }
		update_option( 'wc_pvr_consent_indexes_verified', 1, false );
		if ( ! get_option( 'wc_pvr_email_hash_key' ) ) {
			update_option( 'wc_pvr_email_hash_key', bin2hex( random_bytes( 32 ) ), false );
		}
		update_option( 'wc_pvr_consent_db_version', self::DB_VERSION, false );
		if ( false === get_option( 'wc_pvr_orders_backfill_complete', false ) ) {
			update_option( 'wc_pvr_orders_backfill_complete', 0, false );
		}
		if ( ! $purchases_table_existed || false === get_option( 'wc_pvr_orders_backfill_page', false ) ) {
			update_option( 'wc_pvr_orders_backfill_page', 1, false );
		}
	}

	/** Run only additive schema upgrades for an already-installed ledger. */
	public static function maybe_upgrade_existing_schema() {
		if ( in_array( get_option( 'wc_pvr_consent_db_version' ), array( '1.2', '1.3', self::DB_VERSION ), true ) ) {
			self::maybe_install();
		}
	}

	public static function is_installed() {
		if ( null !== self::$installed_cache ) { return self::$installed_cache; }
		global $wpdb;
		$table = self::table_name();
		$history = self::history_table_name();
		$purchases = self::purchases_table_name();
		self::$installed_cache = self::DB_VERSION === get_option( 'wc_pvr_consent_db_version' ) && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $history ) ) === $history && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $purchases ) ) === $purchases;
		return self::$installed_cache;
	}

	public function __construct() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_upgrade_existing_schema' ), 1 );
		add_action( 'woocommerce_register_form', array( $this, 'registration_checkbox' ) );
		add_action( 'woocommerce_created_customer', array( $this, 'capture_registration' ), 20, 3 );
		add_action( 'woocommerce_after_order_notes', array( $this, 'classic_checkout_checkbox' ) );
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_classic_checkout' ), 20, 3 );
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			$this->register_blocks_field();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( $this, 'register_blocks_field' ) );
		}
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( $this, 'capture_blocks_checkout' ), 20, 2 );
		add_action( 'init', array( $this, 'register_account_endpoint' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'account_menu_item' ) );
		add_action( 'woocommerce_account_wc-pvr-email-preferences_endpoint', array( $this, 'render_account_preferences' ) );
		add_action( 'template_redirect', array( $this, 'save_account_preferences' ) );
		add_action( 'admin_post_wc_pvr_unsubscribe', array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_nopriv_wc_pvr_unsubscribe', array( $this, 'handle_unsubscribe' ) );
		add_action( 'admin_post_wc_pvr_install_consent_schema', array( $this, 'install_schema_from_admin' ) );
	}

	public function install_schema_from_admin() {
		if ( ! current_user_can( 'manage_options' ) ) { wp_die( esc_html__( 'You are not allowed to run this setup.', 'wc-product-view-reminder-lite' ) ); }
		check_admin_referer( 'wc_pvr_install_consent_schema' );
		self::maybe_install();
		$url = add_query_arg( 'wc_pvr_consent_schema', self::is_installed() ? 'ready' : 'failed', admin_url( 'admin.php?page=wc-pvr' ) );
		wp_safe_redirect( $url );
		exit;
	}

	private function collection_enabled( $context ) {
		return self::is_installed() && (bool) get_option( 'wc_pvr_consent_enabled', 0 ) && (bool) get_option( 'wc_pvr_consent_' . $context, 0 );
	}

	/** Default checkbox / consent field text shown to customers. */
	public static function default_wording() {
		return __( 'I agree to receive product reminders and promotional emails from this store. I can unsubscribe at any time.', 'wc-product-view-reminder-lite' );
	}

	private function wording() {
		$wording = get_option( 'wc_pvr_consent_wording', '' );
		if ( ! is_string( $wording ) || '' === trim( $wording ) ) {
			$wording = self::default_wording();
		}
		return sanitize_text_field( $wording );
	}

	private function wording_version() {
		return substr( hash( 'sha256', $this->wording() ), 0, 16 );
	}

	public function registration_checkbox() {
		if ( ! $this->collection_enabled( 'registration' ) ) { return; }
		printf( '<p class="form-row"><label><input type="checkbox" name="wc_pvr_marketing_consent" value="1"> %s</label></p>', esc_html( $this->wording() ) );
	}

	public function capture_registration( $user_id, $new_customer_data = array(), $password_generated = false ) {
		unset( $new_customer_data, $password_generated );
		if ( $this->collection_enabled( 'registration' ) && $this->posted_consent() ) {
			$user = get_userdata( $user_id );
			if ( $user ) { self::record_choice( $user->user_email, $user_id, true, 'registration', $this->wording_version() ); }
		}
	}

	public function classic_checkout_checkbox( $checkout ) {
		if ( ! $this->collection_enabled( 'checkout' ) ) { return; }
		woocommerce_form_field( 'wc_pvr_marketing_consent', array(
			'type' => 'checkbox',
			'class' => array( 'form-row-wide' ),
			'label' => $this->wording(),
			'required' => false,
			'default' => 0,
		), $checkout->get_value( 'wc_pvr_marketing_consent' ) );
	}

	public function capture_classic_checkout( $order_id, $posted_data, $order ) {
		unset( $posted_data );
		if ( $this->collection_enabled( 'checkout' ) && $this->posted_consent() && $order instanceof WC_Order ) {
			self::record_choice( $order->get_billing_email(), $order->get_customer_id(), true, 'classic_checkout', $this->wording_version() );
		}
	}

	public function register_blocks_field() {
		if ( ! $this->collection_enabled( 'checkout' ) || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) { return; }
		woocommerce_register_additional_checkout_field( array(
			'id' => self::CHECKOUT_FIELD,
			'label' => $this->wording(),
			'location' => 'contact',
			'type' => 'checkbox',
			'required' => false,
			'hidden' => false,
			'show_in_order_confirmation' => false,
		) );
	}

	public function capture_blocks_checkout( $order, $request ) {
		unset( $request );
		if ( ! $this->collection_enabled( 'checkout' ) || ! $order instanceof WC_Order ) { return; }
		if ( '1' === (string) $order->get_meta( '_wc_other/' . self::CHECKOUT_FIELD, true ) ) {
			self::record_choice( $order->get_billing_email(), $order->get_customer_id(), true, 'checkout_blocks', $this->wording_version() );
		}
	}

	private function posted_consent() {
		return isset( $_POST['wc_pvr_marketing_consent'] ) && is_scalar( $_POST['wc_pvr_marketing_consent'] ) && '1' === sanitize_text_field( wp_unslash( $_POST['wc_pvr_marketing_consent'] ) );
	}

	public function register_account_endpoint() { add_rewrite_endpoint( 'wc-pvr-email-preferences', EP_ROOT | EP_PAGES ); }

	public function account_menu_item( $items ) {
		if ( ! self::is_installed() ) { return $items; }
		$items['wc-pvr-email-preferences'] = __( 'Email preferences', 'wc-product-view-reminder-lite' );
		return $items;
	}

	public function render_account_preferences() {
		if ( ! is_user_logged_in() ) { return; }
		$user = wp_get_current_user();
		$status = self::status_for_email( $user->user_email );
		$active = 'opted_in' === $status;
		?>
		<h3><?php esc_html_e( 'Product reminder emails', 'wc-product-view-reminder-lite' ); ?></h3>
		<?php if ( isset( $_GET['wc_pvr_preferences'] ) && 'saved' === sanitize_key( wp_unslash( $_GET['wc_pvr_preferences'] ) ) ) : ?>
			<div class="woocommerce-message" role="status"><?php esc_html_e( 'Your email preferences have been saved.', 'wc-product-view-reminder-lite' ); ?></div>
		<?php elseif ( isset( $_GET['wc_pvr_preferences'] ) && 'failed' === sanitize_key( wp_unslash( $_GET['wc_pvr_preferences'] ) ) ) : ?>
			<div class="woocommerce-error" role="alert"><?php esc_html_e( 'Your preferences could not be saved. Please try again.', 'wc-product-view-reminder-lite' ); ?></div>
		<?php endif; ?>
		<p><?php
		if ( 'opted_in' === $status ) {
			esc_html_e( 'Current status: opted in to product reminder emails.', 'wc-product-view-reminder-lite' );
		} elseif ( 'opted_out' === $status ) {
			esc_html_e( 'Current status: opted out of product reminder emails.', 'wc-product-view-reminder-lite' );
		} else {
			esc_html_e( 'Current status: you have not chosen yet.', 'wc-product-view-reminder-lite' );
		}
		?></p>
		<form method="post">
			<?php wp_nonce_field( 'wc_pvr_save_preferences', 'wc_pvr_preferences_nonce' ); ?>
			<input type="hidden" name="wc_pvr_marketing_consent" value="0">
			<label><input type="checkbox" name="wc_pvr_marketing_consent" value="1" <?php checked( $active ); ?>> <?php echo esc_html( $this->wording() ); ?></label>
			<input type="hidden" name="wc_pvr_save_preferences" value="1">
			<p class="form-row"><button type="submit" class="woocommerce-Button button"><?php esc_html_e( 'Save email preferences', 'wc-product-view-reminder-lite' ); ?></button></p>
		</form>
		<?php
	}

	public function save_account_preferences() {
		if ( ! is_user_logged_in() || empty( $_POST['wc_pvr_save_preferences'] ) ) { return; }
		if ( empty( $_POST['wc_pvr_preferences_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wc_pvr_preferences_nonce'] ) ), 'wc_pvr_save_preferences' ) ) { return; }
		$user = wp_get_current_user();
		$accepted = $this->posted_consent();
		$saved = self::record_choice( $user->user_email, $user->ID, $accepted, 'account_preferences', $this->wording_version() );
		$redirect = add_query_arg( 'wc_pvr_preferences', $saved ? 'saved' : 'failed', wc_get_account_endpoint_url( 'wc-pvr-email-preferences' ) );
		wp_safe_redirect( $redirect );
		exit;
	}

	public function create_unsubscribe_url( $email ) {
		if ( ! self::is_installed() ) { return ''; }
		global $wpdb;
		$hash = self::email_hash( $email );
		if ( ! $hash ) { return ''; }
		$token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
		$table = self::table_name();
		$token_hash = hash_hmac( 'sha256', $token, get_option( 'wc_pvr_email_hash_key' ) );
		$expires_at = gmdate( 'Y-m-d H:i:s', time() + ( 30 * DAY_IN_SECONDS ) );
		/* Prefer an opted-in row, then any latest row, so adapter-eligible customers still get a working link. */
		$updated = $wpdb->query( $wpdb->prepare(
			"UPDATE {$table} SET unsubscribe_token_hash = %s, token_expires_at = %s, token_used_at = NULL WHERE id = (SELECT id FROM (SELECT id FROM {$table} WHERE email_hash = %s AND status = 'opted_in' ORDER BY id DESC LIMIT 1) AS latest)",
			$token_hash,
			$expires_at,
			$hash
		) );
		if ( ! $updated ) {
			$updated = $wpdb->query( $wpdb->prepare(
				"UPDATE {$table} SET unsubscribe_token_hash = %s, token_expires_at = %s, token_used_at = NULL WHERE id = (SELECT id FROM (SELECT id FROM {$table} WHERE email_hash = %s ORDER BY id DESC LIMIT 1) AS latest)",
				$token_hash,
				$expires_at,
				$hash
			) );
		}
		if ( ! $updated ) {
			$now = current_time( 'mysql' );
			$wpdb->insert(
				$table,
				array(
					'user_id' => null,
					'email_hash' => $hash,
					'status' => 'opted_in',
					'source' => 'reminder_delivery',
					'channel' => 'email',
					'wording_version' => self::WORDING_VERSION,
					'consented_at' => $now,
					'revoked_at' => null,
					'created_at' => $now,
					'unsubscribe_token_hash' => $token_hash,
					'token_expires_at' => $expires_at,
					'token_used_at' => null,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
		return add_query_arg( array( 'action' => 'wc_pvr_unsubscribe', 'token' => $token ), admin_url( 'admin-post.php' ) );
	}

	public function handle_unsubscribe() {
		if ( ! self::is_installed() ) { wp_die( esc_html__( 'Unsubscribe is temporarily unavailable. Please contact the store.', 'wc-product-view-reminder-lite' ) ); }
		nocache_headers();
		if ( ! headers_sent() ) { header( 'Referrer-Policy: no-referrer' ); }
		$token = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['token'] ) ) : '';
		if ( ! preg_match( '/^[A-Za-z0-9_-]{40,50}$/', $token ) ) { wp_die( esc_html__( 'This unsubscribe link is invalid or expired.', 'wc-product-view-reminder-lite' ) ); }
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		if ( 'POST' !== $request_method ) {
			$action_url = esc_url( admin_url( 'admin-post.php' ) );
			$store_name = esc_html( get_bloginfo( 'name' ) );
			printf(
				'<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>%1$s</title></head><body style="font-family:sans-serif;max-width:32rem;margin:3rem auto;padding:0 1rem;"><h1>%1$s</h1><p>%2$s</p><form method="post" action="%3$s"><input type="hidden" name="action" value="wc_pvr_unsubscribe"><input type="hidden" name="token" value="%4$s"><button type="submit" style="padding:0.6rem 1rem;">%5$s</button></form><p><a href="%6$s">%7$s</a></p></body></html>',
				esc_html__( 'Unsubscribe from product reminders', 'wc-product-view-reminder-lite' ),
				esc_html__( 'Confirm to stop future product reminder emails.', 'wc-product-view-reminder-lite' ),
				$action_url,
				esc_attr( $token ),
				esc_html__( 'Unsubscribe', 'wc-product-view-reminder-lite' ),
				esc_url( home_url( '/' ) ),
				/* translators: %s: store name */
				esc_html( sprintf( __( 'Return to %s', 'wc-product-view-reminder-lite' ), $store_name ) )
			);
			exit;
		}
		global $wpdb;
		$table = self::table_name();
		$token_hash = hash_hmac( 'sha256', $token, get_option( 'wc_pvr_email_hash_key' ) );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE unsubscribe_token_hash = %s AND token_used_at IS NULL AND token_expires_at >= %s ORDER BY id DESC LIMIT 1", $token_hash, gmdate( 'Y-m-d H:i:s' ) ) );
		if ( ! $row ) { wp_die( esc_html__( 'This unsubscribe link is invalid or expired.', 'wc-product-view-reminder-lite' ) ); }
		$now = current_time( 'mysql' );
		$inserted = $wpdb->insert( $table, array(
			'user_id' => $row->user_id ? absint( $row->user_id ) : null,
			'email_hash' => $row->email_hash,
			'status' => 'opted_out',
			'source' => 'unsubscribe_link',
			'channel' => 'email',
			'wording_version' => self::WORDING_VERSION,
			'consented_at' => null,
			'revoked_at' => $now,
			'created_at' => $now,
		), array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
		if ( false === $inserted ) {
			wp_die( esc_html__( 'We could not save your unsubscribe request. Please contact the store.', 'wc-product-view-reminder-lite' ) );
		}
		$wpdb->update( $table, array( 'token_used_at' => $now ), array( 'id' => absint( $row->id ) ), array( '%s' ), array( '%d' ) );
		wp_die( esc_html__( 'You have been unsubscribed from product reminder emails.', 'wc-product-view-reminder-lite' ) );
	}

	public static function email_hash( $email ) {
		$email = strtolower( trim( sanitize_email( $email ) ) );
		$key = get_option( 'wc_pvr_email_hash_key' );
		return is_email( $email ) && $key ? hash_hmac( 'sha256', $email, $key ) : '';
	}

	/** Returns whether the most recent consent record is an active opt-in. */
	public static function is_opted_in( $email, $user_id = 0 ) {
		if ( ! self::is_installed() ) { return false; }
		global $wpdb;
		$hash = self::email_hash( $email );
		if ( ! $hash ) {
			return false;
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE email_hash = %s ORDER BY id DESC LIMIT 1", $hash ) );
		return $row && 'opted_in' === $row->status;
	}

	/** Adds an explicit customer choice without storing the raw email. */
	public static function record_choice( $email, $user_id, $accepted, $source, $wording_version ) {
		if ( ! self::is_installed() ) { return false; }
		global $wpdb;
		$hash = self::email_hash( $email );
		if ( ! $hash ) {
			return false;
		}
		$table  = self::table_name();
		$now    = current_time( 'mysql' );
		$status = $accepted ? 'opted_in' : 'opted_out';
		return false !== $wpdb->insert(
			$table,
			array(
				'user_id' => absint( $user_id ) ?: null,
				'email_hash' => $hash,
				'status' => $status,
				'source' => sanitize_key( $source ),
				'channel' => 'email',
				'wording_version' => sanitize_text_field( $wording_version ),
				'consented_at' => $accepted ? $now : null,
				'revoked_at' => $accepted ? null : $now,
				'created_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/** Returns whether the latest stored choice is a revocation. */
	public static function is_opted_out( $email ) {
		return 'opted_out' === self::status_for_email( $email );
	}

	public static function status_for_email( $email ) {
		if ( ! self::is_installed() ) { return ''; }
		global $wpdb;
		$hash = self::email_hash( $email );
		if ( ! $hash ) {
			return '';
		}
		$table = self::table_name();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT status FROM {$table} WHERE email_hash = %s ORDER BY id DESC LIMIT 1", $hash ) );
		return $row ? $row->status : '';
	}
}
