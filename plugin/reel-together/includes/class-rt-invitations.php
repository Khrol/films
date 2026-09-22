<?php
defined( 'ABSPATH' ) || exit;

final class RT_Invitations {
    public static function url() {
        return admin_url( 'users.php?page=rt-invitations' );
    }

    public static function pending_count() {
        global $wpdb;
        return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . RT_Store::table( 'invitation_requests' ) . " WHERE status='pending'" );
    }

    public static function init() {
        add_action( 'admin_menu', function () {
            $count = self::pending_count();
            $label = 'Invitation requests' . ( $count ? ' <span class="awaiting-mod"><span class="pending-count">' . $count . '</span></span>' : '' );
            add_users_page( 'Invitation requests', $label, 'manage_options', 'rt-invitations', array( self::class, 'render_admin' ) );
        } );
        foreach ( array( 'admin_post_nopriv_rt_request_invitation', 'admin_post_rt_request_invitation' ) as $hook ) {
            add_action( $hook, function () {
                $result = self::submit( wp_unslash( $_POST ), $_SERVER['REMOTE_ADDR'] ?? 'unknown' );
                $status = is_wp_error( $result ) ? $result->get_error_code() : 'received';
                wp_safe_redirect( add_query_arg( 'invitation', $status, RT_App::url() ) . '#request-invitation', 303 );
                exit;
            } );
        }
        add_action( 'admin_post_rt_review_invitation', function () {
            $result = self::review( absint( $_POST['request_id'] ?? 0 ), sanitize_key( $_POST['review_action'] ?? '' ), $_POST['_wpnonce'] ?? '' );
            if ( is_wp_error( $result ) ) {
                wp_die( esc_html( $result->get_error_message() ), 'Invitation request', array( 'response' => $result->get_error_data()['status'] ?? 400 ) );
            }
            wp_safe_redirect( add_query_arg( 'updated', '1', self::url() ), 303 );
            exit;
        } );
    }

    private static function limited( $bucket, $limit ) {
        $key = 'rt_invite_rate_' . hash_hmac( 'sha256', $bucket, wp_salt() );
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) { return true; }
        set_transient( $key, $count + 1, HOUR_IN_SECONDS );
        return false;
    }

    public static function submit( $data, $ip ) {
        global $wpdb;
        if ( ! is_array( $data ) || ! is_string( $data['_wpnonce'] ?? null ) || ! wp_verify_nonce( $data['_wpnonce'], 'rt_request_invitation' ) ) {
            return new WP_Error( 'expired', 'Refresh this page and try again.', array( 'status' => 403 ) );
        }
        // Give bots the same confirmation without saving their submissions.
        if ( ! empty( $data['website'] ) ) { return true; }
        if ( self::limited( 'ip:' . $ip, 5 ) || self::limited( 'site', 100 ) ) {
            return new WP_Error( 'limited', 'Too many requests. Please try again in an hour.', array( 'status' => 429 ) );
        }
        $name = is_string( $data['request_name'] ?? null ) ? sanitize_text_field( $data['request_name'] ) : '';
        $email = is_string( $data['request_email'] ?? null ) ? strtolower( trim( $data['request_email'] ) ) : '';
        if ( '' === $name || RT_Store::length( $name ) > 100 || ! is_email( $email ) || strlen( $email ) > 100 ) {
            return new WP_Error( 'invalid', 'Enter your name and a valid email address.', array( 'status' => 400 ) );
        }
        $table = RT_Store::table( 'invitation_requests' );
        $key = hash( 'sha256', $email );
        // Duplicate requests do not expose membership or overwrite a prior decision.
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email_key=%s", $key ) ) ) { return true; }
        $suppress = $wpdb->suppress_errors();
        $saved = $wpdb->insert( $table, array(
            'email_key' => $key, 'name' => $name, 'email' => $email, 'status' => 'pending',
            'created_at' => current_time( 'mysql', true ), 'updated_at' => current_time( 'mysql', true ),
        ) );
        $wpdb->suppress_errors( $suppress );
        if ( ! $saved && ! $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE email_key=%s", $key ) ) ) {
            return new WP_Error( 'unavailable', 'We could not save your request. Please try again later.', array( 'status' => 503 ) );
        }
        return true;
    }

    public static function review( $id, $action, $nonce ) {
        global $wpdb;
        if ( ! current_user_can( 'manage_options' ) || ! is_string( $nonce ) || ! wp_verify_nonce( $nonce, 'rt_review_invitation_' . $id ) ) {
            return new WP_Error( 'rt_forbidden', 'You cannot manage invitation requests.', array( 'status' => 403 ) );
        }
        if ( ! in_array( $action, array( 'handled', 'declined', 'delete' ), true ) || $id < 1 ) {
            return new WP_Error( 'rt_invalid', 'Choose a valid request and action.', array( 'status' => 400 ) );
        }
        $table = RT_Store::table( 'invitation_requests' );
        $changed = 'delete' === $action ? $wpdb->delete( $table, array( 'id' => $id ) ) : $wpdb->update( $table,
            array( 'status' => $action, 'updated_at' => current_time( 'mysql', true ) ), array( 'id' => $id, 'status' => 'pending' ) );
        if ( false === $changed ) { return new WP_Error( 'rt_save', 'The request could not be updated.', array( 'status' => 500 ) ); }
        if ( ! $changed ) { return new WP_Error( 'rt_changed', 'This request has already been handled or removed.', array( 'status' => 409 ) ); }
        return true;
    }

    public static function render_form() {
        $status = is_string( $_GET['invitation'] ?? null ) ? sanitize_key( $_GET['invitation'] ) : '';
        $messages = array(
            'received' => 'Thank you. Your request has been received for review. If you have already requested an invitation, there is no need to send another.',
            'expired' => 'The form expired. Please try sending your request again.',
            'limited' => 'Too many requests. Please try again in an hour.',
            'invalid' => 'Enter your name and a valid email address.',
            'unavailable' => 'We could not save your request. Please try again later.',
        );
        ?>
        <details class="signup invitation-request" id="request-invitation" <?php echo isset( $messages[ $status ] ) ? 'open' : ''; ?>>
            <summary>New here? Request an invitation</summary>
            <p class="helper">Leave your name and email for the site owner to review. They’ll contact you if an invitation is available.</p>
            <?php if ( isset( $messages[ $status ] ) ) : ?>
            <p class="invitation-message <?php echo 'received' === $status ? '' : 'form-error'; ?>" role="<?php echo 'received' === $status ? 'status' : 'alert'; ?>"><?php echo esc_html( $messages[ $status ] ); ?></p>
            <?php endif; ?>
            <?php if ( 'received' !== $status ) : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="rt_request_invitation">
                <?php wp_nonce_field( 'rt_request_invitation', '_wpnonce', false ); ?>
                <label for="request-name">Your name</label><input id="request-name" name="request_name" required maxlength="100" autocomplete="name">
                <label for="request-email">Email address</label><input id="request-email" name="request_email" type="email" required maxlength="100" autocomplete="email">
                <div class="invitation-trap" aria-hidden="true"><label for="request-website">Leave this field empty</label><input id="request-website" name="website" tabindex="-1" autocomplete="off"></div>
                <button type="submit" class="button primary">Request an invitation</button>
            </form>
            <?php endif; ?>
        </details>
        <?php
    }

    public static function render_admin() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        global $wpdb;
        $status = is_string( $_GET['status'] ?? null ) ? sanitize_key( $_GET['status'] ) : 'pending';
        if ( ! in_array( $status, array( 'pending', 'handled', 'declined', 'all' ), true ) ) { $status = 'pending'; }
        $page = max( 1, min( 100000, absint( $_GET['paged'] ?? 1 ) ) );
        $table = RT_Store::table( 'invitation_requests' );
        $where = 'all' === $status ? '1=1' : $wpdb->prepare( 'status=%s', $status );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table WHERE $where" );
        $rows = $wpdb->get_results( "SELECT * FROM $table WHERE $where" . $wpdb->prepare( ' ORDER BY created_at DESC, id DESC LIMIT 20 OFFSET %d', ( $page - 1 ) * 20 ), ARRAY_A );
        ?>
        <div class="wrap"><h1>Invitation requests</h1>
            <p>Review requests here and arrange accounts yourself. These actions do not create accounts or send emails. Once you have arranged an account, mark the request as handled.</p>
            <p><a href="<?php echo esc_url( admin_url( 'user-new.php' ) ); ?>">Manage new accounts</a> · <a href="<?php echo esc_url( RT_App::url() ); ?>">Open your movie diary</a></p>
            <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p>Request updated.</p></div><?php endif; ?>
            <form method="get"><input type="hidden" name="page" value="rt-invitations"><label for="request-status">Show requests</label>
                <select id="request-status" name="status"><?php foreach ( array( 'pending' => 'Pending', 'handled' => 'Handled', 'declined' => 'Declined', 'all' => 'All requests' ) as $value => $label ) : ?>
                    <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $value, $status ); ?>><?php echo esc_html( $label ); ?></option>
                <?php endforeach; ?></select> <?php submit_button( 'Filter', 'secondary', '', false ); ?>
            </form><p><?php echo esc_html( $total ); ?> request(s)</p>
            <table class="widefat striped"><thead><tr><th>Name</th><th>Email</th><th>Requested (UTC)</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            <?php if ( ! $rows ) : ?><tr><td colspan="5">No requests in this view.</td></tr><?php endif; ?>
            <?php foreach ( $rows as $row ) : ?>
                <tr><td><?php echo esc_html( $row['name'] ); ?></td><td><?php echo esc_html( $row['email'] ); ?></td><td><?php echo esc_html( $row['created_at'] ); ?></td><td><?php echo esc_html( ucfirst( $row['status'] ) ); ?></td>
                    <td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                        <input type="hidden" name="action" value="rt_review_invitation"><input type="hidden" name="request_id" value="<?php echo (int) $row['id']; ?>">
                        <?php wp_nonce_field( 'rt_review_invitation_' . $row['id'] ); ?>
                        <?php if ( 'pending' === $row['status'] ) : ?>
                            <button class="button" name="review_action" value="handled">Mark handled</button>
                            <button class="button" name="review_action" value="declined">Decline</button>
                        <?php endif; ?>
                        <button class="button" name="review_action" value="delete">Delete request</button>
                    </form></td>
                </tr>
            <?php endforeach; ?></tbody></table>
            <p><?php if ( $page > 1 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'status' => $status, 'paged' => $page - 1 ), self::url() ) ); ?>">Previous</a><?php endif; ?>
            <?php if ( $total > $page * 20 ) : ?><a class="button" href="<?php echo esc_url( add_query_arg( array( 'status' => $status, 'paged' => $page + 1 ), self::url() ) ); ?>">Next</a><?php endif; ?></p>
        </div>
        <?php
    }
}
