<?php
defined( 'ABSPATH' ) || exit;

final class RT_Stripe_Settings {
    public static function init() {
        add_action( 'admin_post_rt_stripe_connect', function () {
            self::guard( 'rt_stripe_connect' );
            $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? sanitize_key( $_POST['mode'] ) : '';
            $key = isset( $_POST['stripe_key'] ) && is_string( $_POST['stripe_key'] ) ? trim( wp_unslash( $_POST['stripe_key'] ) ) : '';
            $result = self::connect( $mode, $key, isset( $_POST['automatic_tax'] ) && 'yes' === $_POST['automatic_tax'] );
            self::redirect( is_wp_error( $result ) ? $result->get_error_message() : 'Stripe connected. The €1/month plan and customer portal are ready in ' . $mode . ' mode.' );
        } );
        add_action( 'admin_post_rt_membership_enable', function () {
            self::guard( 'rt_membership_enable' );
            $enable = isset( $_POST['enabled'] ) && 'yes' === $_POST['enabled'];
            $result = self::set_enabled( $enable );
            self::redirect( is_wp_error( $result ) ? $result->get_error_message() : ( $enable ? 'Paid membership is now open. Complimentary users retain free access.' : 'Paid access requirements are off. Existing Stripe subscriptions are unchanged.' ) );
        } );
    }

    private static function guard( $action ) {
        if ( ! current_user_can( 'manage_options' ) ) { wp_die( 'Access denied.', '', array( 'response' => 403 ) ); }
        check_admin_referer( $action );
    }

    private static function redirect( $message ) {
        set_transient( 'rt_billing_notice_' . get_current_user_id(), $message, 60 );
        wp_safe_redirect( admin_url( 'options-general.php?page=reel-together#membership' ) ); exit;
    }

    private static function fail( $message ) { return new WP_Error( 'rt_stripe_setup', $message ); }

    public static function connect( $mode, $key, $automatic_tax = false ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) ) { return self::fail( 'Only the site administrator can configure billing.' ); }
        if ( ! in_array( $mode, array( 'test', 'live' ), true ) ) { return self::fail( 'Choose test or live mode.' ); }
        $config = RT_Stripe::config( $mode );
        $key = $key ?: ( $config['key'] ?? '' );
        if ( ! is_string( $key ) || ! preg_match( '/^sk_' . $mode . '_[A-Za-z0-9]{12,}$/', $key ) ) { return self::fail( 'Enter a Stripe secret key for the selected mode. Keys stay on your server.' ); }
        $account = RT_Stripe::api( $mode, 'GET', 'account', array(), '', $key );
        if ( is_wp_error( $account ) || ! preg_match( '/^acct_[A-Za-z0-9]+$/', $account['id'] ?? '' ) ) { return self::fail( 'Stripe could not verify this key. Check its mode and permissions.' ); }
        if ( 'live' === $mode && empty( $account['charges_enabled'] ) ) { return self::fail( 'Finish your Stripe account setup before connecting live payments.' ); }
        if ( ! empty( $config['account'] ) && $config['account'] !== $account['id'] ) {
            $customers = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RT_Store::table( 'billing_accounts' ) . ' WHERE mode=%s AND customer_id IS NOT NULL', $mode ) );
            if ( $customers ) { return self::fail( 'This mode already has customer accounts. Keep the same Stripe account so their billing remains manageable.' ); }
            $config = array();
        }
        $config = array_merge( $config, array( 'account' => $account['id'], 'key' => $key, 'automatic_tax' => (bool) $automatic_tax ) );
        update_option( 'rt_stripe_' . $mode, $config, false );
        $metadata = array( 'rt_site' => RT_Stripe::site_id() );
        if ( empty( $config['product'] ) ) {
            $product = RT_Stripe::api( $mode, 'POST', 'products', array( 'name' => 'Reel Together — personal membership', 'metadata' => $metadata ), 'product-v1' );
            if ( is_wp_error( $product ) || ! preg_match( '/^prod_[A-Za-z0-9]+$/', $product['id'] ?? '' ) ) { return self::fail( 'Stripe could not create the membership product. Retry the connection.' ); }
            $config['product'] = $product['id']; update_option( 'rt_stripe_' . $mode, $config, false );
        }
        if ( empty( $config['price'] ) ) {
            $price = RT_Stripe::api( $mode, 'POST', 'prices', array( 'product' => $config['product'], 'currency' => 'eur', 'unit_amount' => 100,
                'recurring' => array( 'interval' => 'month', 'interval_count' => 1 ), 'tax_behavior' => 'inclusive', 'metadata' => $metadata ), 'price-eur100-month-v1' );
            if ( is_wp_error( $price ) || ! preg_match( '/^price_[A-Za-z0-9]+$/', $price['id'] ?? '' ) ) { return self::fail( 'Stripe could not create the €1/month price. Retry the connection.' ); }
            $config['price'] = $price['id']; update_option( 'rt_stripe_' . $mode, $config, false );
        }
        $price = RT_Stripe::api( $mode, 'GET', 'prices/' . rawurlencode( $config['price'] ) );
        if ( is_wp_error( $price ) || empty( $price['active'] ) || ( $price['currency'] ?? '' ) !== 'eur' || ( $price['unit_amount'] ?? 0 ) !== 100 ||
            ( $price['recurring']['interval'] ?? '' ) !== 'month' || ( $price['recurring']['interval_count'] ?? 0 ) !== 1 || ( $price['tax_behavior'] ?? '' ) !== 'inclusive' ) {
            return self::fail( 'The configured Stripe price must be active, €1 per month, with inclusive tax behavior.' );
        }
        if ( empty( $config['portal'] ) ) {
            $portal = RT_Stripe::api( $mode, 'POST', 'billing_portal/configurations', array( 'business_profile' => array( 'headline' => 'Manage your Reel Together membership' ),
                'features' => array( 'invoice_history' => array( 'enabled' => 'true' ), 'payment_method_update' => array( 'enabled' => 'true' ),
                    'subscription_cancel' => array( 'enabled' => 'true', 'mode' => 'at_period_end' ) ) ), 'portal-v1' );
            if ( is_wp_error( $portal ) || ! preg_match( '/^bpc_[A-Za-z0-9]+$/', $portal['id'] ?? '' ) ) { return self::fail( 'Stripe could not prepare the customer portal. Retry the connection.' ); }
            $config['portal'] = $portal['id']; update_option( 'rt_stripe_' . $mode, $config, false );
        }
        if ( empty( $config['webhook_secret'] ) ) {
            $endpoint = RT_Stripe::api( $mode, 'POST', 'webhook_endpoints', array( 'url' => rest_url( 'reel-together/v1/billing/webhook/' . $mode ),
                'enabled_events' => RT_Stripe::EVENTS, 'api_version' => RT_Stripe::API_VERSION, 'description' => 'Reel Together membership access' ), 'webhook-v1' );
            if ( is_wp_error( $endpoint ) || ! preg_match( '/^whsec_[A-Za-z0-9]+$/', $endpoint['secret'] ?? '' ) || ! preg_match( '/^we_[A-Za-z0-9]+$/', $endpoint['id'] ?? '' ) ) { return self::fail( 'Stripe could not register the webhook. Your site must be reachable over HTTPS. Retry the connection.' ); }
            $config['webhook'] = $endpoint['id']; $config['webhook_secret'] = $endpoint['secret'];
        }
        $config['ready'] = true; update_option( 'rt_stripe_' . $mode, $config, false );
        return true;
    }

    public static function set_enabled( $enabled ) {
        if ( ! current_user_can( 'manage_options' ) ) { return self::fail( 'Only the site administrator can open subscriptions.' ); }
        if ( $enabled ) {
            if ( ! RT_Stripe::ready( 'live' ) ) { return self::fail( 'Connect live Stripe payments first.' ); }
            // General sandboxes have their own account identity, separate from live mode.
            $test = RT_Stripe::config( 'test' );
            if ( ! RT_Stripe::ready( 'test' ) || get_option( 'rt_stripe_test_passed' ) !== $test['account'] || get_option( 'rt_stripe_test_webhook_seen' ) !== $test['account'] ) {
                return self::fail( 'Complete a Stripe test checkout and verify webhook delivery for the connected test environment before opening paid membership.' );
            }
        }
        update_option( 'rt_membership_enabled', $enabled ? 'yes' : 'no', false );
        return true;
    }

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $notice = get_transient( 'rt_billing_notice_' . get_current_user_id() );
        if ( $notice ) { delete_transient( 'rt_billing_notice_' . get_current_user_id() ); echo '<div class="notice notice-info"><p>' . esc_html( $notice ) . '</p></div>'; }
        ?>
        <h2 id="membership">Membership — €1 per person, per month</h2>
        <p>Choose who gets free access under <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Users → Edit → Reel Together membership</a>. Administrators always retain access. Other accounts need a subscription once paid membership is opened.</p>
        <p>Stripe handles checkout, monthly payments, receipts, payment updates, and cancellation. The plan is €1/month, inclusive of any taxes configured for collection. Stripe processing and billing fees apply.</p>
        <p>Create your account at <a href="https://dashboard.stripe.com/register" target="_blank" rel="noopener noreferrer">Stripe</a>. Start with a test secret key, then connect a live key after completing Stripe’s business and bank setup.</p>
        <p>Test connection: <strong><?php echo RT_Stripe::ready( 'test' ) ? 'Ready' : 'Not connected'; ?></strong>. Live connection: <strong><?php echo RT_Stripe::ready( 'live' ) ? 'Ready' : 'Not connected'; ?></strong>.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="rt_stripe_connect"><?php wp_nonce_field( 'rt_stripe_connect' ); ?>
            <p><label for="stripe-mode">Connection mode</label><br><select id="stripe-mode" name="mode"><option value="test">Test — no real payments</option><option value="live">Live</option></select></p>
            <p><label for="stripe-key">Stripe secret key</label><br><input type="password" id="stripe-key" name="stripe_key" class="large-text" value="" autocomplete="new-password" placeholder="sk_test_… or sk_live_…"></p>
            <p class="description">Leave blank to reuse the key for that mode. Keys are stored on the server and never included in app responses.</p>
            <p><label><input type="checkbox" name="automatic_tax" value="yes"> Calculate taxes with Stripe Tax (configure your tax settings and registrations in Stripe first).</label></p>
            <p>This creates the €1/month product, price, customer portal, and webhook in the selected mode. It does not charge anyone.</p>
            <?php submit_button( 'Connect and prepare Stripe', 'secondary' ); ?>
        </form>
        <?php if ( RT_Stripe::ready( 'test' ) ) : ?>
            <p><a class="button" href="<?php echo esc_url( add_query_arg( 'billing_mode', 'test', RT_Membership::url() ) ); ?>">Run test checkout</a></p>
            <p>Use Stripe’s test card 4242 4242 4242 4242 with a future expiry and any three-digit CVC. A successful checkout and verified webhook are required before opening paid membership.</p>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="rt_membership_enable"><?php wp_nonce_field( 'rt_membership_enable' ); ?>
            <p><label><input type="checkbox" name="enabled" value="yes" <?php checked( RT_Membership::enabled() ); ?>> Open €1/month subscriptions and require paid or complimentary access.</label></p>
            <p>Choose complimentary users before opening subscriptions. Turning this off opens app access again; it does not cancel existing Stripe subscriptions.</p>
            <?php submit_button( 'Save membership access' ); ?>
        </form>
        <?php
    }
}
