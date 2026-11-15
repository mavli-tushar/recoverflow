<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class WC_PVR_Admin_Settings {

    public function __construct() {

        add_action( 'admin_menu', array( $this, 'menu' ) );
        add_action( 'admin_init', array( $this, 'settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'admin_styles_or_js' ) );

    }

    public function admin_styles_or_js( $hook ) {

        if ( $hook !== 'woocommerce_page_wc-pvr' ) {
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

        add_submenu_page(
            'woocommerce',
            __( 'Product View Reminder', 'wc-product-view-reminder-lite' ),
            __( 'Product View Reminder', 'wc-product-view-reminder-lite' ),
            'manage_options',
            'wc-pvr',
            array( $this, 'settings_page' )
        );

    }

    public function settings() {

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_delay',
            array(
                'sanitize_callback' => 'absint'
            )
        );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_cron_interval',
            array(
                'sanitize_callback' => 'sanitize_text_field'
            )
        );

        register_setting(
            'wc_pvr_settings_group',
            'wc_pvr_cart_reminder',
            array(
                'sanitize_callback' => 'absint'
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

    }

    public function settings_page() {

        $next_run = wp_next_scheduled( 'wc_pvr_cron' );

        ?>

        <div class="wrap wc-pvr-admin">

            <h1 class="wc-pvr-title">
                <?php esc_html_e( 'WooCommerce Product View Reminder', 'wc-product-view-reminder-lite' ); ?>
            </h1>

            <div class="wc-pvr-card">

                <h2><?php esc_html_e( 'General Settings', 'wc-product-view-reminder-lite' ); ?></h2>

                <form method="post" action="options.php">

                    <?php settings_fields( 'wc_pvr_settings_group' ); ?>

                    <table class="form-table">

                        <tr>
                            <th><?php esc_html_e( 'Trigger Delay (seconds)', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <input type="number"
                                       name="wc_pvr_delay"
                                       value="<?php echo esc_attr( get_option( 'wc_pvr_delay', 5 ) ); ?>">

                                <p class="description">
                                    <?php esc_html_e( 'Time user must stay on product page before tracking starts.', 'wc-product-view-reminder-lite' ); ?>
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
                                         . esc_html( date( 'Y-m-d H:i:s', $next_run ) )
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

                                    <input type="checkbox"
                                           name="wc_pvr_cart_reminder"
                                           value="1"
                                           <?php checked( get_option( 'wc_pvr_cart_reminder' ), 1 ); ?>>

                                    <?php esc_html_e( 'Enable reminders if product is added to cart but not purchased.', 'wc-product-view-reminder-lite' ); ?>

                                </label>

                            </td>

                        </tr>


                        <tr>

                            <th><?php esc_html_e( 'Email Subject', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <input type="text"
                                       name="wc_pvr_email_subject"
                                       value="<?php echo esc_attr( get_option( 'wc_pvr_email_subject', 'You viewed {product_name}' ) ); ?>"
                                       style="width:400px">

                            </td>

                        </tr>


                        <tr>

                            <th><?php esc_html_e( 'Email Message', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <?php 
                                $content = get_option(
                                        'wc_pvr_email_message',
                                        "Hi {customer_name},

                                    You viewed {product_name}. but didn’t purchase.

                                    View again: {product_link}"
                                    );

                                    wp_editor(
                                        $content,
                                        'wc_pvr_email_message',
                                        array(
                                            'textarea_name' => 'wc_pvr_email_message',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true,
                                        )
                                    );
                                ?>
                                <p class="description">
                                    <?php esc_html_e(
                                        'Tags:',
                                        'wc-product-view-reminder-lite'
                                    ); ?>
                                    <code>{product_name}</code>
                                    <code>{product_link}</code>
                                    <code>{customer_name}</code>
                                </p>

                            </td>

                        </tr>

                        <tr id="wc-pvr-cart-message-row">

                            <th><?php esc_html_e( 'Cart Reminder Message', 'wc-product-view-reminder-lite' ); ?></th>

                            <td>

                                <?php

                                    $content = get_option(
                                        'wc_pvr_cart_email_message',
                                        "Hi {customer_name},

                                    You added {product_name} to cart but didn’t complete the purchase.

                                    {product_link}"
                                    );

                                    wp_editor(
                                        $content,
                                        'wc_pvr_cart_email_message',
                                        array(
                                            'textarea_name' => 'wc_pvr_cart_email_message',
                                            'textarea_rows' => 8,
                                            'media_buttons' => false,
                                            'teeny' => true,
                                        )
                                    );

                                    ?>

                                <p class="description">
                                    <?php esc_html_e(
                                        'Used when cart reminder is enabled. Tags:',
                                        'wc-product-view-reminder-lite'
                                    ); ?>
                                    <code>{product_name}</code>
                                    <code>{product_link}</code>
                                    <code>{customer_name}</code>
                                </p>

                            </td>

                        </tr>

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