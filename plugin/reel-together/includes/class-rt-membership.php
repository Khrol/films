<?php
defined( 'ABSPATH' ) || exit;

final class RT_Membership {
    public static function enabled() {
        return 'yes' === get_option( 'rt_membership_enabled', 'no' );
    }

    public static function status( $user_id = null ) {
        $user_id = $user_id ?? get_current_user_id();
        if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
            return array( 'access' => false, 'state' => 'signed_out' );
        }
        if ( ! self::enabled() ) { return array( 'access' => true, 'state' => 'disabled' ); }
        if ( user_can( $user_id, 'manage_options' ) ) { return array( 'access' => true, 'state' => 'administrator' ); }
        if ( 'yes' === get_user_meta( $user_id, '_rt_complimentary_access', true ) ) {
            return array( 'access' => true, 'state' => 'complimentary' );
        }
        // Only a server-side billing provider can supply payment entitlement.
        return apply_filters( 'rt_membership_status', array( 'access' => false, 'state' => 'unavailable' ), $user_id );
    }

    public static function authorize() {
        return self::status()['access'] ? true : new WP_Error( 'rt_subscription_required',
            'An active membership is required. Open Membership to subscribe or manage your payment.', array( 'status' => 402 ) );
    }

    public static function url() {
        return add_query_arg( 'membership', '1', RT_App::url() );
    }

    public static function is_account_page() {
        return isset( $_GET['membership'] ) && '1' === $_GET['membership'];
    }

    public static function init() {
        add_action( 'show_user_profile', array( self::class, 'user_profile' ) );
        add_action( 'edit_user_profile', array( self::class, 'user_profile' ) );
        add_action( 'personal_options_update', array( self::class, 'save_user_profile' ) );
        add_action( 'edit_user_profile_update', array( self::class, 'save_user_profile' ) );
    }

    public static function render() {
        $status = self::status();
        $messages = array(
            'disabled' => 'Subscriptions are not open yet.',
            'administrator' => 'You have administrator access.',
            'complimentary' => 'The site owner has given you complimentary access.',
            'active' => 'Your membership is active.',
            'ending' => 'Your membership will end after the period you have paid for.',
            'past_due' => 'Your payment needs attention. Update it to reopen your diary.',
            'inactive' => 'Subscribe to open your diary and join your household’s movie nights.',
            'unavailable' => 'Subscription payments are temporarily unavailable. Please try again later.',
        );
        ob_start();
        ?>
        <main id="main" class="welcome">
            <a class="brand" href="<?php echo esc_url( RT_App::url() ); ?>"><span class="brand-mark" aria-hidden="true">r.</span><span>reel together.</span></a>
            <div class="welcome-grid"><div><p class="eyebrow">YOUR LITTLE CINEMA</p><h1>Good films.<br>Better <em>company.</em></h1><p class="intro">Your diary, watchlist, and shared movie nights — one membership for your personal account.</p></div>
                <section class="login-card membership-card"><p class="eyebrow">REEL TOGETHER MEMBERSHIP</p><h2>€1 <span class="membership-interval">/ month</span></h2>
                    <p>Per person. Renews monthly. Cancel anytime; access continues until the end of your paid period.</p>
                    <p><strong><?php echo esc_html( wp_get_current_user()->display_name ); ?></strong></p>
                    <p role="status"><?php echo esc_html( $messages[ $status['state'] ] ?? $messages['inactive'] ); ?></p>
                    <?php if ( ! empty( $status['paid_until'] ) ) : ?><p>Paid through <?php echo esc_html( wp_date( get_option( 'date_format' ), (int) $status['paid_until'] ) ); ?>.</p><?php endif; ?>
                    <?php do_action( 'rt_membership_controls', $status ); ?>
                    <?php if ( $status['access'] ) : ?><p><a class="button secondary" href="<?php echo esc_url( RT_App::url() ); ?>">Open my diary</a></p><?php endif; ?>
                    <?php if ( current_user_can( 'manage_options' ) ) : ?><p><a href="<?php echo esc_url( admin_url( 'options-general.php?page=reel-together' ) ); ?>">Membership settings</a></p><?php endif; ?>
                    <p class="helper">If membership ends, your saved films stay stored in your account.</p>
                    <p><a href="<?php echo esc_url( wp_logout_url( RT_App::url() ) ); ?>">Sign out</a></p>
                </section>
            </div>
        </main>
        <?php
        return ob_get_clean();
    }

    public static function user_profile( $user ) {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        ?>
        <h2>Reel Together membership</h2>
        <?php wp_nonce_field( 'rt_membership_access_' . $user->ID, 'rt_membership_access_nonce' ); ?>
        <p><label><input type="checkbox" name="rt_complimentary_access" value="yes" <?php checked( 'yes', get_user_meta( $user->ID, '_rt_complimentary_access', true ) ); ?>> Complimentary access — this person does not need a paid subscription.</label></p>
        <p>This does not cancel or refund an existing subscription. Manage any billing separately.</p>
        <?php
    }

    public static function save_user_profile( $user_id ) {
        if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'edit_user', $user_id ) ||
            ! isset( $_POST['rt_membership_access_nonce'] ) || ! is_string( $_POST['rt_membership_access_nonce'] ) ||
            ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rt_membership_access_nonce'] ) ), 'rt_membership_access_' . $user_id ) ) { return; }
        if ( isset( $_POST['rt_complimentary_access'] ) && 'yes' === $_POST['rt_complimentary_access'] ) {
            update_user_meta( $user_id, '_rt_complimentary_access', 'yes' );
        } else { delete_user_meta( $user_id, '_rt_complimentary_access' ); }
    }
}
