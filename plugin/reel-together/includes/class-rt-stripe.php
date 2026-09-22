<?php
defined( 'ABSPATH' ) || exit;

final class RT_Stripe {
    const API_VERSION = '2025-03-31.basil';
    const EVENTS = array( 'checkout.session.completed', 'customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted', 'invoice.paid', 'invoice.payment_failed' );

    public static function init() {
        add_filter( 'rt_membership_status', function ( $status, $uid ) { return self::status( $uid, 'live' ); }, 10, 2 );
        add_action( 'rt_membership_controls', array( self::class, 'controls' ) );
        add_action( 'rest_api_init', function () {
            foreach ( array( 'checkout', 'portal' ) as $action ) {
                register_rest_route( 'reel-together/v1', '/billing/' . $action, array( 'methods' => 'POST',
                    'callback' => array( self::class, $action ), 'permission_callback' => array( 'RT_API', 'signed_in' ) ) );
            }
            register_rest_route( 'reel-together/v1', '/billing/webhook/(?P<mode>test|live)', array( 'methods' => 'POST',
                'callback' => array( self::class, 'webhook' ), 'permission_callback' => '__return_true' ) );
        } );
        add_action( 'template_redirect', array( self::class, 'checkout_return' ) );
    }

    public static function config( $mode ) {
        return in_array( $mode, array( 'test', 'live' ), true ) ? get_option( 'rt_stripe_' . $mode, array() ) : array();
    }

    public static function ready( $mode ) {
        $config = self::config( $mode );
        return ! empty( $config['ready'] ) && ! empty( $config['key'] ) && ! empty( $config['account'] ) && ! empty( $config['price'] ) && ! empty( $config['portal'] ) && ! empty( $config['webhook_secret'] );
    }

    public static function site_id() { return substr( hash( 'sha256', untrailingslashit( home_url() ) ), 0, 24 ); }
    private static function error( $message = 'Stripe is temporarily unavailable. Please try again.', $status = 503 ) {
        return new WP_Error( 'rt_billing', $message, array( 'status' => $status ) );
    }

    public static function api( $mode, $method, $path, $data = array(), $idempotency = '', $key = null ) {
        $config = self::config( $mode );
        $key = $key ?? ( $config['key'] ?? '' );
        if ( ! in_array( $mode, array( 'test', 'live' ), true ) || ! preg_match( '/^sk_' . $mode . '_[A-Za-z0-9]+$/', $key ) ) { return self::error(); }
        $headers = array( 'Authorization' => 'Bearer ' . $key, 'Stripe-Version' => self::API_VERSION );
        if ( $idempotency ) { $headers['Idempotency-Key'] = 'reel-' . self::site_id() . '-' . $mode . '-' . $idempotency; }
        $url = 'https://api.stripe.com/v1/' . $path;
        $args = array( 'method' => $method, 'headers' => $headers, 'timeout' => 20, 'redirection' => 0, 'limit_response_size' => 2097152 );
        if ( 'GET' === $method && $data ) { $url .= '?' . http_build_query( $data ); }
        elseif ( $data ) { $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded'; $args['body'] = http_build_query( $data ); }
        $response = wp_remote_request( $url, $args );
        if ( is_wp_error( $response ) ) { return self::error(); }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        // Do not expose provider responses: they may contain customer data or configuration details.
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || isset( $body['error'] ) ) { return self::error(); }
        return $body;
    }

    public static function row( $uid, $mode ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RT_Store::table( 'billing_accounts' ) . ' WHERE user_id=%d AND mode=%s', $uid, $mode ), ARRAY_A );
    }

    private static function put( $uid, $mode, $data ) {
        global $wpdb;
        return false !== $wpdb->update( RT_Store::table( 'billing_accounts' ), $data, array( 'user_id' => $uid, 'mode' => $mode ) );
    }

    public static function status( $uid, $mode ) {
        if ( ! self::ready( $mode ) ) { return array( 'access' => false, 'state' => 'unavailable' ); }
        $row = self::row( $uid, $mode );
        if ( ! $row || ! $row['customer_id'] ) { return array( 'access' => false, 'state' => 'inactive' ); }
        if ( $row['account_id'] !== self::config( $mode )['account'] ) { return array( 'access' => false, 'state' => 'unavailable' ); }
        if ( (int) $row['checked_at'] < time() - 300 || ( in_array( $row['state'], array( 'active', 'ending' ), true ) && (int) $row['paid_until'] <= time() ) ) {
            $result = self::sync( $uid, $mode );
            if ( is_wp_error( $result ) ) { return array( 'access' => false, 'state' => 'unavailable' ); }
            $row = self::row( $uid, $mode );
        }
        $access = in_array( $row['state'], array( 'active', 'ending' ), true ) && (int) $row['paid_until'] > time();
        return array( 'access' => $access, 'state' => $row['state'], 'paid_until' => $access ? (int) $row['paid_until'] : 0 );
    }

    public static function sync( $uid, $mode, $already_locked = false ) {
        global $wpdb;
        $lease = time() + 300;
        if ( ! $already_locked ) {
            $locked = $wpdb->query( $wpdb->prepare( 'UPDATE ' . RT_Store::table( 'billing_accounts' ) . ' SET lock_until=%d WHERE user_id=%d AND mode=%s AND lock_until<%d', $lease, $uid, $mode, time() ) );
            if ( 1 !== $locked ) { return self::error(); }
        }
        try { return self::fetch_status( $uid, $mode ); }
        finally {
            if ( ! $already_locked ) { $wpdb->query( $wpdb->prepare( 'UPDATE ' . RT_Store::table( 'billing_accounts' ) . ' SET lock_until=0 WHERE user_id=%d AND mode=%s AND lock_until=%d', $uid, $mode, $lease ) ); }
        }
    }

    private static function fetch_status( $uid, $mode ) {
        $row = self::row( $uid, $mode ); $config = self::config( $mode );
        if ( ! $row || ! $row['customer_id'] || $row['account_id'] !== ( $config['account'] ?? '' ) ) { return self::error(); }
        $data = array( 'customer' => $row['customer_id'], 'price' => $config['price'], 'status' => 'all', 'limit' => 100, 'expand' => array( 'data.latest_invoice' ) );
        $state = 'inactive'; $paid_until = 0; $has_subscription = false;
        // Refetch current state, so duplicated or out-of-order webhooks cannot restore old access.
        for ( $page = 0; $page < 10; $page++ ) {
            $subscriptions = self::api( $mode, 'GET', 'subscriptions', $data );
            if ( is_wp_error( $subscriptions ) || ! isset( $subscriptions['data'] ) || ! is_array( $subscriptions['data'] ) ) { return self::error(); }
            foreach ( $subscriptions['data'] as $sub ) {
                if ( ( $sub['customer'] ?? '' ) !== $row['customer_id'] || ( $sub['livemode'] ?? null ) !== ( 'live' === $mode ) ) { continue; }
                $items = $sub['items']['data'] ?? array();
                if ( ! is_array( $items ) ) { return self::error(); }
                $raw = $sub['status'] ?? '';
                // A misconfigured existing subscription still blocks a second charge.
                foreach ( $items as $candidate ) {
                    if ( ( $candidate['price']['id'] ?? '' ) === $config['price'] && ! in_array( $raw, array( 'canceled', 'incomplete_expired' ), true ) ) { $has_subscription = true; }
                }
                if ( ( $sub['metadata']['rt_site'] ?? '' ) !== self::site_id() || (string) ( $sub['metadata']['rt_user'] ?? '' ) !== (string) $uid ) { continue; }
                if ( count( $items ) !== 1 ) { continue; }
                $item = $items[0]; $price = $item['price'] ?? array();
                if ( ( $price['id'] ?? '' ) !== $config['price'] || ( $price['currency'] ?? '' ) !== 'eur' || ( $price['unit_amount'] ?? 0 ) !== 100 ||
                    ( $price['recurring']['interval'] ?? '' ) !== 'month' || ( $price['recurring']['interval_count'] ?? 0 ) !== 1 || ( $item['quantity'] ?? 0 ) !== 1 ) { continue; }
                $end = (int) ( $item['current_period_end'] ?? 0 );
                if ( 'active' === $raw && empty( $sub['pause_collection'] ) && ( $sub['latest_invoice']['status'] ?? '' ) === 'paid' && $end > time() && $end > $paid_until ) {
                    $state = ! empty( $sub['cancel_at_period_end'] ) || ! empty( $sub['cancel_at'] ) ? 'ending' : 'active';
                    $paid_until = ! empty( $sub['cancel_at'] ) ? min( $end, (int) $sub['cancel_at'] ) : $end;
                } elseif ( ! $paid_until && in_array( $raw, array( 'past_due', 'unpaid', 'paused', 'incomplete', 'active' ), true ) ) { $state = 'past_due'; }
            }
            if ( empty( $subscriptions['has_more'] ) ) { break; }
            $last = end( $subscriptions['data'] );
            if ( ! $last || $page === 9 ) { return self::error(); }
            $data['starting_after'] = $last['id'];
        }
        if ( ! self::put( $uid, $mode, array( 'state' => $state, 'paid_until' => $paid_until, 'has_subscription' => (int) $has_subscription, 'checked_at' => time() ) ) ) { return self::error(); }
        if ( 'test' === $mode && $paid_until > time() ) { update_option( 'rt_stripe_test_passed', self::config( $mode )['account'], false ); }
        return true;
    }

    private static function request_mode( $request ) {
        $mode = $request->get_param( 'mode' ) ?? 'live';
        if ( ! in_array( $mode, array( 'test', 'live' ), true ) || ( 'test' === $mode && ! current_user_can( 'manage_options' ) ) ) { return self::error( 'This billing mode is unavailable.', 403 ); }
        if ( ! self::ready( $mode ) || ( 'live' === $mode && ! RT_Membership::enabled() ) ) { return self::error( 'Subscriptions are not open yet.' ); }
        return $mode;
    }

    public static function checkout( $request ) {
        global $wpdb;
        $mode = self::request_mode( $request ); if ( is_wp_error( $mode ) ) { return $mode; }
        $uid = get_current_user_id(); $config = self::config( $mode );
        if ( 'live' === $mode && ( user_can( $uid, 'manage_options' ) || 'yes' === get_user_meta( $uid, '_rt_complimentary_access', true ) ) ) { return self::error( 'You already have complimentary access.', 409 ); }
        $row = self::row( $uid, $mode );
        if ( ! $row ) {
            $wpdb->insert( RT_Store::table( 'billing_accounts' ), array( 'user_id' => $uid, 'mode' => $mode, 'account_id' => $config['account'] ) );
            $row = self::row( $uid, $mode );
        }
        if ( ! $row || $row['account_id'] !== $config['account'] ) { return self::error(); }
        $lease = time() + 300;
        $locked = $wpdb->query( $wpdb->prepare( 'UPDATE ' . RT_Store::table( 'billing_accounts' ) . ' SET lock_until=%d WHERE user_id=%d AND mode=%s AND lock_until<%d', $lease, $uid, $mode, time() ) );
        if ( 1 !== $locked ) { return self::error( 'A checkout is already being prepared. Please try again in a moment.', 409 ); }
        try {
            if ( ! $row['customer_id'] ) {
                $customer = self::api( $mode, 'POST', 'customers', array( 'email' => wp_get_current_user()->user_email,
                    'metadata' => array( 'rt_site' => self::site_id(), 'rt_user' => (string) $uid ) ), 'customer-' . $uid );
                if ( is_wp_error( $customer ) || ! preg_match( '/^cus_[A-Za-z0-9]+$/', $customer['id'] ?? '' ) ) { return self::error(); }
                if ( ! self::put( $uid, $mode, array( 'customer_id' => $customer['id'] ) ) ) { return self::error(); }
                $row = self::row( $uid, $mode );
            }
            $synced = self::sync( $uid, $mode, true ); if ( is_wp_error( $synced ) ) { return $synced; }
            if ( self::row( $uid, $mode )['has_subscription'] ) { return self::error( 'You already have a subscription. Use Manage billing to update it.', 409 ); }
            if ( $row['checkout_id'] ) {
                $session = self::api( $mode, 'GET', 'checkout/sessions/' . rawurlencode( $row['checkout_id'] ) );
                if ( is_wp_error( $session ) ) { return $session; }
                if ( 'open' === ( $session['status'] ?? '' ) && (int) ( $session['expires_at'] ?? 0 ) > time() ) { return self::session_url( $session, 'checkout.stripe.com' ); }
                if ( 'expired' !== ( $session['status'] ?? '' ) && 'complete' !== ( $session['status'] ?? '' ) ) { return self::error(); }
                if ( ! self::put( $uid, $mode, array( 'checkout_id' => '', 'checkout_key' => '' ) ) ) { return self::error(); }
                $row = self::row( $uid, $mode );
            }
            $key = $row['checkout_key'] ?: wp_generate_uuid4();
            if ( ! self::put( $uid, $mode, array( 'checkout_key' => $key ) ) ) { return self::error(); }
            $return_url = add_query_arg( array( 'billing_mode' => $mode, 'checkout_session' => '{CHECKOUT_SESSION_ID}' ), RT_Membership::url() );
            $return_url = str_replace( array( '%7B', '%7D' ), array( '{', '}' ), $return_url );
            $session = self::api( $mode, 'POST', 'checkout/sessions', array(
                'mode' => 'subscription', 'customer' => $row['customer_id'], 'client_reference_id' => (string) $uid,
                'line_items' => array( array( 'price' => $config['price'], 'quantity' => 1 ) ), 'payment_method_types' => array( 'card' ),
                'allow_promotion_codes' => 'false', 'billing_address_collection' => 'required',
                'customer_update' => array( 'address' => 'auto' ), 'automatic_tax' => array( 'enabled' => empty( $config['automatic_tax'] ) ? 'false' : 'true' ),
                'subscription_data' => array( 'metadata' => array( 'rt_site' => self::site_id(), 'rt_user' => (string) $uid ) ),
                'success_url' => $return_url, 'cancel_url' => add_query_arg( 'billing_mode', $mode, RT_Membership::url() ),
            ), 'checkout-' . $uid . '-' . $key );
            if ( is_wp_error( $session ) || ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $session['id'] ?? '' ) ) { return self::error(); }
            if ( ! self::put( $uid, $mode, array( 'checkout_id' => $session['id'] ) ) ) { return self::error(); }
            return self::session_url( $session, 'checkout.stripe.com' );
        } finally {
            $wpdb->query( $wpdb->prepare( 'UPDATE ' . RT_Store::table( 'billing_accounts' ) . ' SET lock_until=0 WHERE user_id=%d AND mode=%s AND lock_until=%d', $uid, $mode, $lease ) );
        }
    }

    private static function session_url( $session, $host ) {
        $url = $session['url'] ?? ''; $parts = is_string( $url ) ? wp_parse_url( $url ) : false;
        return $parts && 'https' === ( $parts['scheme'] ?? '' ) && $host === ( $parts['host'] ?? '' ) && ! isset( $parts['user'] ) && ! isset( $parts['pass'] ) && ! isset( $parts['port'] )
            ? array( 'url' => $url ) : self::error();
    }

    public static function portal( $request ) {
        // Portal remains available when new subscriptions are closed, including for complimentary users.
        $mode = $request->get_param( 'mode' ) ?? 'live';
        if ( ! in_array( $mode, array( 'test', 'live' ), true ) || ( 'test' === $mode && ! current_user_can( 'manage_options' ) ) ) { return self::error( 'This billing mode is unavailable.', 403 ); }
        $row = self::row( get_current_user_id(), $mode ); $config = self::config( $mode );
        if ( ! self::ready( $mode ) || ! $row || ! $row['customer_id'] || $row['account_id'] !== $config['account'] ) { return self::error( 'There is no billing account to manage.', 400 ); }
        $session = self::api( $mode, 'POST', 'billing_portal/sessions', array( 'customer' => $row['customer_id'], 'configuration' => $config['portal'],
            'return_url' => add_query_arg( 'billing_mode', $mode, RT_Membership::url() ) ) );
        return is_wp_error( $session ) ? $session : self::session_url( $session, 'billing.stripe.com' );
    }

    public static function verify_signature( $body, $header, $secret ) {
        if ( ! $secret || strlen( $body ) > 1048576 || ! is_string( $header ) ) { return false; }
        $timestamp = 0; $signatures = array();
        foreach ( explode( ',', $header ) as $part ) {
            $bits = explode( '=', trim( $part ), 2 );
            if ( count( $bits ) !== 2 ) { continue; }
            if ( 't' === $bits[0] && ctype_digit( $bits[1] ) ) { $timestamp = (int) $bits[1]; }
            if ( 'v1' === $bits[0] && preg_match( '/^[a-f0-9]{64}$/', $bits[1] ) ) { $signatures[] = $bits[1]; }
        }
        if ( ! $timestamp || abs( time() - $timestamp ) > 300 ) { return false; }
        $expected = hash_hmac( 'sha256', $timestamp . '.' . $body, $secret );
        foreach ( $signatures as $signature ) { if ( hash_equals( $expected, $signature ) ) { return true; } }
        return false;
    }

    public static function webhook( $request ) {
        global $wpdb;
        $mode = $request['mode']; $config = self::config( $mode );
        if ( ! self::verify_signature( $request->get_body(), $request->get_header( 'stripe-signature' ), $config['webhook_secret'] ?? '' ) ) { return self::error( 'Invalid webhook signature.', 400 ); }
        $event = json_decode( $request->get_body(), true );
        if ( ! is_array( $event ) || ( $event['livemode'] ?? null ) !== ( 'live' === $mode ) || ! is_string( $event['type'] ?? null ) ) { return self::error( 'Invalid billing event.', 400 ); }
        if ( ! in_array( $event['type'], self::EVENTS, true ) ) { return array( 'received' => true ); }
        $customer = $event['data']['object']['customer'] ?? '';
        if ( ! is_string( $customer ) || ! preg_match( '/^cus_[A-Za-z0-9]+$/', $customer ) ) { return self::error( 'Invalid billing event.', 400 ); }
        $uid = $wpdb->get_var( $wpdb->prepare( 'SELECT user_id FROM ' . RT_Store::table( 'billing_accounts' ) . ' WHERE customer_id=%s AND mode=%s AND account_id=%s', $customer, $mode, $config['account'] ?? '' ) );
        if ( ! $uid ) { return array( 'received' => true ); }
        $result = self::sync( (int) $uid, $mode );
        if ( ! is_wp_error( $result ) && 'test' === $mode ) { update_option( 'rt_stripe_test_webhook_seen', $config['account'], false ); }
        return is_wp_error( $result ) ? $result : array( 'received' => true );
    }

    public static function reconcile_return( $uid, $mode, $id ) {
        if ( ! is_string( $id ) || ! preg_match( '/^cs_[A-Za-z0-9_]+$/', $id ) || ! in_array( $mode, array( 'test', 'live' ), true ) ) { return self::error( 'Invalid checkout return.', 400 ); }
        if ( 'test' === $mode && ! user_can( $uid, 'manage_options' ) ) { return self::error( 'This billing mode is unavailable.', 403 ); }
        $row = self::row( $uid, $mode );
        if ( ! $row || $row['checkout_id'] !== $id ) { return self::error( 'This checkout does not belong to your account.', 403 ); }
        $session = self::api( $mode, 'GET', 'checkout/sessions/' . rawurlencode( $id ) );
        if ( is_wp_error( $session ) ) { return $session; }
        if ( ( $session['customer'] ?? '' ) !== $row['customer_id'] || (string) ( $session['client_reference_id'] ?? '' ) !== (string) $uid ||
            ( $session['livemode'] ?? null ) !== ( 'live' === $mode ) || 'subscription' !== ( $session['mode'] ?? '' ) ||
            'complete' !== ( $session['status'] ?? '' ) || 'paid' !== ( $session['payment_status'] ?? '' ) ) { return self::error( 'Payment has not been confirmed yet.', 409 ); }
        return self::sync( $uid, $mode );
    }

    public static function checkout_return() {
        if ( ! RT_App::is_app() || ! is_user_logged_in() || ! isset( $_GET['checkout_session'] ) ) { return; }
        $mode = isset( $_GET['billing_mode'] ) && is_string( $_GET['billing_mode'] ) ? sanitize_key( $_GET['billing_mode'] ) : 'live';
        $result = self::reconcile_return( get_current_user_id(), $mode, wp_unslash( $_GET['checkout_session'] ) );
        wp_safe_redirect( add_query_arg( array( 'billing_mode' => $mode, 'billing_result' => is_wp_error( $result ) ? 'pending' : 'checked' ), RT_Membership::url() ) );
        exit;
    }

    public static function controls( $status ) {
        $test = current_user_can( 'manage_options' ) && isset( $_GET['billing_mode'] ) && 'test' === $_GET['billing_mode'];
        $mode = $test ? 'test' : 'live'; $row = self::row( get_current_user_id(), $mode );
        if ( $test ) {
            echo '<p><strong>Test mode — no real payment</strong></p>';
            $test_status = self::status( get_current_user_id(), 'test' );
            if ( $test_status['access'] ) { echo '<p>Test payment verified successfully.</p>'; }
        }
        if ( isset( $_GET['billing_result'] ) && 'pending' === $_GET['billing_result'] ) { echo '<p role="status">Payment is still being checked. Reload this page in a moment or use Manage billing.</p>'; }
        if ( self::ready( $mode ) && ( $test || RT_Membership::enabled() ) && ( $test || ! $status['access'] ) && ( ! $row || ! $row['has_subscription'] ) ) {
            echo '<button class="button primary" data-billing-action="checkout" data-mode="' . esc_attr( $mode ) . '">' . ( $test ? 'Try test checkout' : 'Subscribe for €1/month' ) . '</button>';
        }
        if ( $row && $row['customer_id'] && self::ready( $mode ) ) {
            echo '<p><button class="button secondary" data-billing-action="portal" data-mode="' . esc_attr( $mode ) . '">Manage billing</button></p>';
        }
        echo '<p id="billing-error" class="form-error" role="alert"></p>';
    }
}
