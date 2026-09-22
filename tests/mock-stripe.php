<?php
// Local test fixture only. This file is never packaged or deployed.
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
    if ( ! str_starts_with( $url, 'https://api.stripe.com/v1/' ) ) { return $preempt; }
    $path = substr( parse_url( $url, PHP_URL_PATH ), 4 );
    $mode = str_contains( $args['headers']['Authorization'] ?? '', 'sk_live_' ) ? 'live' : 'test';
    $data = array(); parse_str( 'GET' === $args['method'] ? ( parse_url( $url, PHP_URL_QUERY ) ?? '' ) : ( $args['body'] ?? '' ), $data );
    $calls = get_option( 'rt_mock_stripe_calls', array() );
    $calls[] = array( 'path' => $path, 'method' => $args['method'], 'mode' => $mode, 'data' => $data, 'idempotency' => $args['headers']['Idempotency-Key'] ?? '', 'version' => $args['headers']['Stripe-Version'] ?? '' );
    update_option( 'rt_mock_stripe_calls', $calls, false );
    if ( get_option( 'rt_mock_stripe_failure' ) ) { return new WP_Error( 'mock_outage', 'Simulated Stripe outage' ); }
    $price = array( 'id' => 'price_Reel' . $mode, 'active' => true, 'currency' => 'eur', 'unit_amount' => 100,
        'recurring' => array( 'interval' => 'month', 'interval_count' => 1 ), 'tax_behavior' => 'inclusive' );
    if ( 'account' === $path ) { $body = array( 'id' => 'acct_Reel' . $mode, 'charges_enabled' => ! get_option( 'rt_mock_charges_disabled' ) ); }
    elseif ( 'products' === $path ) { $body = array( 'id' => 'prod_Reel' . $mode ); }
    elseif ( 'prices' === $path || str_starts_with( $path, 'prices/' ) ) { $body = $price; if ( get_option( 'rt_mock_wrong_price' ) ) { $body['unit_amount'] = 200; } }
    elseif ( 'billing_portal/configurations' === $path ) { $body = array( 'id' => 'bpc_Reel' . $mode ); }
    elseif ( 'webhook_endpoints' === $path ) { $body = array( 'id' => 'we_Reel' . $mode, 'secret' => 'whsec_Reel' . $mode ); }
    elseif ( 'customers' === $path ) { $body = array( 'id' => 'cus_Reel' . $data['metadata']['rt_user'] . $mode ); }
    elseif ( 'subscriptions' === $path ) {
        $all = get_option( 'rt_mock_subscriptions', array() );
        $body = array( 'data' => $all[ $data['customer'] ] ?? array(), 'has_more' => false );
    } elseif ( 'checkout/sessions' === $path ) {
        $sessions = get_option( 'rt_mock_sessions', array() );
        $id = 'cs_' . $mode . '_' . $data['client_reference_id'] . '_' . count( $sessions );
        $body = array( 'id' => $id, 'url' => 'https://checkout.stripe.com/c/pay/' . $id, 'mode' => 'subscription', 'livemode' => 'live' === $mode,
            'customer' => $data['customer'], 'client_reference_id' => $data['client_reference_id'], 'status' => 'open', 'payment_status' => 'unpaid', 'expires_at' => time() + 3600 );
        $sessions[ $id ] = $body; update_option( 'rt_mock_sessions', $sessions, false );
    } elseif ( str_starts_with( $path, 'checkout/sessions/' ) ) {
        $body = get_option( 'rt_mock_sessions', array() )[ substr( $path, strlen( 'checkout/sessions/' ) ) ] ?? array();
    } elseif ( 'billing_portal/sessions' === $path ) { $body = array( 'url' => 'https://billing.stripe.com/p/session/testportal' ); }
    else { return new WP_Error( 'mock_unexpected', 'Unexpected Stripe request' ); }
    return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( $body ) );
}, 10, 3 );

function rt_mock_subscription( $uid, $mode = 'live', $changes = array() ) {
    $row = RT_Stripe::row( $uid, $mode );
    $sub = array( 'id' => 'sub_Reel' . $uid . $mode, 'customer' => $row['customer_id'], 'livemode' => 'live' === $mode,
        'metadata' => array( 'rt_site' => RT_Stripe::site_id(), 'rt_user' => (string) $uid ), 'status' => 'active', 'cancel_at_period_end' => false,
        'latest_invoice' => array( 'status' => 'paid' ), 'items' => array( 'data' => array( array( 'quantity' => 1, 'current_period_end' => time() + 30 * DAY_IN_SECONDS,
            'price' => array( 'id' => RT_Stripe::config( $mode )['price'], 'currency' => 'eur', 'unit_amount' => 100, 'recurring' => array( 'interval' => 'month', 'interval_count' => 1 ) ) ) ) ) );
    $sub = array_replace_recursive( $sub, $changes );
    $all = get_option( 'rt_mock_subscriptions', array() ); $all[ $row['customer_id'] ] = array( $sub ); update_option( 'rt_mock_subscriptions', $all, false );
    return $sub;
}

function rt_mock_complete_checkout( $uid, $mode = 'live' ) {
    rt_mock_subscription( $uid, $mode );
    $row = RT_Stripe::row( $uid, $mode ); $sessions = get_option( 'rt_mock_sessions', array() );
    $sessions[ $row['checkout_id'] ]['status'] = 'complete'; $sessions[ $row['checkout_id'] ]['payment_status'] = 'paid';
    update_option( 'rt_mock_sessions', $sessions, false );
    return $row['checkout_id'];
}
