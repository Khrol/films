<?php
defined( 'ABSPATH' ) || exit;

final class RT_API {
    public static function register() {
        $routes = array(
            '/bootstrap' => array( 'GET', 'bootstrap' ),
            '/entries' => array( array( 'GET', 'entries' ), array( 'POST', 'save_entry' ) ),
            '/entries/(?P<id>\d+)' => array( array( 'PUT', 'save_entry' ), array( 'DELETE', 'delete_entry' ) ),
            '/household' => array( 'POST', 'create_household' ),
            '/household/join' => array( 'POST', 'join_household' ),
            '/household/invite' => array( 'POST', 'invite' ),
            '/household/membership' => array( 'DELETE', 'leave_household' ),
            '/household/members/(?P<id>\d+)' => array( 'DELETE', 'remove_member' ),
            '/search' => array( 'GET', 'search' ),
            '/lookup' => array( 'GET', 'lookup' ),
            '/companions' => array( 'POST', 'save_companion' ),
            '/companions/(?P<id>\d+)' => array( 'PUT', 'save_companion' ),
        );
        foreach ( $routes as $path => $handlers ) {
            if ( is_string( $handlers[0] ) ) {
                $handlers = array( $handlers );
            }
            $endpoints = array();
            foreach ( $handlers as $handler ) {
                $endpoints[] = array(
                    'methods' => $handler[0], 'callback' => array( self::class, $handler[1] ),
                    'permission_callback' => array( self::class, 'authenticated' ),
                );
            }
            register_rest_route( 'reel-together/v1', $path, $endpoints );
        }
        add_filter( 'rest_post_dispatch', function ( $response, $server, $request ) {
            if ( str_starts_with( $request->get_route(), '/reel-together/v1/' ) ) {
                $response->header( 'Cache-Control', 'private, no-store, max-age=0' );
            }
            return $response;
        }, 10, 3 );
    }

    public static function authenticated() {
        return is_user_logged_in() && current_user_can( 'read' )
            ? true : self::error( 'Please sign in to use your diary.', 401 );
    }

    private static function error( $message, $status = 400 ) {
        return new WP_Error( 'rt_error', $message, array( 'status' => $status ) );
    }

    private static function text( $value, $max, $multiline = false ) {
        if ( ! is_string( $value ) || false === RT_Store::length( $value ) || RT_Store::length( $value ) > $max ) {
            return null;
        }
        return $multiline ? sanitize_textarea_field( $value ) : sanitize_text_field( $value );
    }

    private static function rate_limit( $action, $limit, $seconds ) {
        $key = 'rt_rate_' . $action . '_' . get_current_user_id();
        $count = (int) get_transient( $key );
        if ( $count >= $limit ) {
            return false;
        }
        set_transient( $key, $count + 1, $seconds );
        return true;
    }

    public static function bootstrap() {
        global $wpdb;
        $user = wp_get_current_user();
        $household = RT_Store::household();
        $members = array();
        if ( $household ) {
            $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT user_id FROM ' . RT_Store::table( 'members' ) . ' WHERE household_id=%d', $household['id'] ) );
            foreach ( $ids as $id ) {
                $members[] = array( 'id' => (int) $id, 'name' => get_the_author_meta( 'display_name', $id ) );
            }
        }
        $counts = $wpdb->get_results( 'SELECT e.status, COUNT(*) total FROM ' . RT_Store::table( 'entries' ) . ' e WHERE ' . RT_Store::visibility() . ' GROUP BY e.status', OBJECT_K );
        return array(
            'user' => array( 'id' => $user->ID, 'name' => $user->display_name ),
            'household' => $household ? array(
                'id' => (int) $household['id'], 'name' => $household['name'],
                'is_owner' => (int) $household['owner_id'] === $user->ID, 'members' => $members,
            ) : null,
            'counts' => array( 'watched' => (int) ( $counts['watched']->total ?? 0 ), 'watchlist' => (int) ( $counts['watchlist']->total ?? 0 ) ),
            'catalog_enabled' => count( RT_Catalog::providers() ) > 0,
            'catalog_providers' => RT_Catalog::providers(),
            'today' => current_time( 'Y-m-d' ),
            'companions' => RT_Companions::visible(),
        );
    }

    public static function entries( $request ) {
        global $wpdb;
        $status = $request->get_param( 'status' ) ?? 'watched';
        $scope = $request->get_param( 'scope' ) ?? 'all';
        $query = self::text( $request->get_param( 'q' ) ?? '', 200 );
        $page = filter_var( $request->get_param( 'page' ) ?? 1, FILTER_VALIDATE_INT );
        $companion = $request->get_param( 'with' ) ?? 'all';
        if ( ! in_array( $companion, array( 'all', 'alone', 'unspecified', 'others' ), true ) &&
            ( false === filter_var( $companion, FILTER_VALIDATE_INT ) || $companion < 1 ) ) {
            return self::error( 'Choose a valid viewing companion filter.' );
        }
        if ( ! in_array( $status, array( 'watched', 'watchlist' ), true ) || ! in_array( $scope, array( 'all', 'personal', 'household' ), true ) || null === $query || ! $page || $page < 1 || $page > 100000 ) {
            return self::error( 'Invalid diary filter.' );
        }
        $where = RT_Store::visibility( $scope ) . $wpdb->prepare( ' AND e.status=%s', $status );
        if ( 'alone' === $companion || 'unspecified' === $companion || 'others' === $companion ) {
            $where .= $wpdb->prepare( ' AND e.watch_company=%s', 'others' === $companion ? 'companions' : $companion );
        } elseif ( 'all' !== $companion ) {
            $where .= $wpdb->prepare( " AND e.watch_company='companions' AND e.companion_ids LIKE %s", '%|' . (int) $companion . '|%' );
        }
        if ( '' !== $query ) {
            $where .= $wpdb->prepare( ' AND m.title LIKE %s', '%' . $wpdb->esc_like( $query ) . '%' );
        }
        $from = ' FROM ' . RT_Store::table( 'entries' ) . ' e JOIN ' . RT_Store::table( 'movies' ) . ' m ON m.id=e.movie_id WHERE ' . $where;
        $total = (int) $wpdb->get_var( 'SELECT COUNT(*)' . $from );
        $rows = $wpdb->get_results( 'SELECT e.*, m.title, m.release_year, m.tmdb_id, m.poster_path, m.kinopoisk_id, m.kinopoisk_rating, m.imdb_id, m.imdb_rating, m.linked_kinopoisk_id, m.linked_imdb_id, m.poster_url, m.catalog_checked_at' . $from .
            $wpdb->prepare( ' ORDER BY e.watched_on DESC, e.id DESC LIMIT 24 OFFSET %d', ( $page - 1 ) * 24 ), ARRAY_A );
        return array( 'items' => array_map( array( 'RT_Store', 'format_entry' ), $rows ), 'total' => $total, 'pages' => (int) ceil( $total / 24 ), 'page' => $page );
    }

    public static function save_entry( $request ) {
        global $wpdb;
        $id = (int) $request->get_param( 'id' );
        $existing = $id ? RT_Store::entry( $id ) : null;
        if ( $id && ( ! $existing || (int) $existing['user_id'] !== get_current_user_id() ) ) {
            return self::error( 'This entry is unavailable or belongs to another member.', 403 );
        }
        $body = $request->get_json_params();
        if ( ! is_array( $body ) ) {
            return self::error( 'Send a valid entry.' );
        }
        $status = $body['status'] ?? '';
        $scope = $body['scope'] ?? '';
        if ( ! in_array( $status, array( 'watched', 'watchlist' ), true ) || ! in_array( $scope, array( 'personal', 'household' ), true ) ) {
            return self::error( 'Choose a list and who can see this entry.' );
        }
        $household = RT_Store::household();
        if ( 'household' === $scope && ! $household ) {
            return self::error( 'Create or join a household first.' );
        }
        $notes = self::text( $body['notes'] ?? '', 2000, true );
        $attendees = self::text( $body['attendees'] ?? '', 200 );
        if ( null === $notes || null === $attendees ) {
            return self::error( 'Keep notes under 2,000 characters and names under 200 characters.' );
        }
        $date = null;
        $rating = null;
        if ( 'watched' === $status ) {
            $date = $body['watched_on'] ?? '';
            $parsed = is_string( $date ) ? DateTimeImmutable::createFromFormat( '!Y-m-d', $date ) : false;
            if ( ! $parsed || $parsed->format( 'Y-m-d' ) !== $date || $date > current_time( 'Y-m-d' ) || $date < '1870-01-01' ) {
                return self::error( 'Choose a valid viewing date, today or earlier.' );
            }
            if ( isset( $body['rating'] ) && '' !== $body['rating'] ) {
                $rating = filter_var( $body['rating'], FILTER_VALIDATE_INT );
                if ( false === $rating || $rating < 1 || $rating > 5 ) {
                    return self::error( 'Choose a rating from 1 to 5.' );
                }
            }
        }
        $movie = RT_Store::movie( $body['movie'] ?? null );
        if ( is_wp_error( $movie ) ) {
            return $movie;
        }
        $company = RT_Companions::selection( $body, $existing, $status );
        if ( is_wp_error( $company ) ) { return $company; }
        $data = array(
            'user_id' => get_current_user_id(), 'household_id' => 'household' === $scope ? (int) $household['id'] : 0,
            'movie_id' => $movie, 'status' => $status, 'watched_on' => $date,
            'rating' => $rating, 'attendees' => $attendees, 'notes' => $notes,
        );
        $data = array_merge( $data, $company );
        // Only watchlists deduplicate. A diary deliberately allows repeated viewings.
        if ( 'watchlist' === $status ) {
            $duplicate = $wpdb->get_var( $wpdb->prepare(
                'SELECT e.id FROM ' . RT_Store::table( 'entries' ) . ' e WHERE ' . RT_Store::visibility( $scope ) .
                " AND e.movie_id=%d AND e.status='watchlist' AND e.id<>%d", $movie, $id
            ) );
            if ( $duplicate ) {
                return self::error( 'That movie is already on this watchlist.', 409 );
            }
        }
        if ( $id ) {
            $result = $wpdb->update( RT_Store::table( 'entries' ), $data, array( 'id' => $id, 'user_id' => get_current_user_id() ) );
        } else {
            $data['created_at'] = current_time( 'mysql', true );
            $result = $wpdb->insert( RT_Store::table( 'entries' ), $data );
            $id = (int) $wpdb->insert_id;
        }
        return false === $result ? self::error( 'Could not save your entry. Please try again.', 500 ) : new WP_REST_Response( array( 'id' => $id ), $existing ? 200 : 201 );
    }

    public static function delete_entry( $request ) {
        global $wpdb;
        $entry = RT_Store::entry( (int) $request['id'] );
        if ( ! $entry || (int) $entry['user_id'] !== get_current_user_id() ) {
            return self::error( 'This entry is unavailable or belongs to another member.', 403 );
        }
        $ok = $wpdb->delete( RT_Store::table( 'entries' ), array( 'id' => $entry['id'], 'user_id' => get_current_user_id() ) );
        return false === $ok ? self::error( 'Could not delete your entry.', 500 ) : array( 'deleted' => true );
    }

    public static function create_household( $request ) {
        global $wpdb;
        if ( RT_Store::household() ) {
            return self::error( 'You already belong to a household.', 409 );
        }
        $name = self::text( $request->get_param( 'name' ), 100 );
        if ( ! $name ) {
            return self::error( 'Give your household a name (up to 100 characters).' );
        }
        if ( ! $wpdb->insert( RT_Store::table( 'households' ), array( 'owner_id' => get_current_user_id(), 'name' => $name ) ) ) {
            return self::error( 'Could not create your household.', 500 );
        }
        $id = (int) $wpdb->insert_id;
        if ( ! $wpdb->insert( RT_Store::table( 'members' ), array( 'household_id' => $id, 'user_id' => get_current_user_id() ) ) ) {
            $wpdb->delete( RT_Store::table( 'households' ), array( 'id' => $id ) );
            return self::error( 'Could not join your household. Please try again.', 409 );
        }
        return self::bootstrap();
    }

    public static function invite() {
        global $wpdb;
        $household = RT_Store::household();
        if ( ! $household || (int) $household['owner_id'] !== get_current_user_id() ) {
            return self::error( 'Only the household owner can create invitations.', 403 );
        }
        $code = bin2hex( random_bytes( 16 ) );
        $ok = $wpdb->update( RT_Store::table( 'households' ), array(
            'invite_hash' => hash( 'sha256', $code ), 'invite_expires' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ),
        ), array( 'id' => $household['id'] ) );
        return false === $ok ? self::error( 'Could not create an invitation.', 500 ) : array( 'code' => $code );
    }

    public static function join_household( $request ) {
        global $wpdb;
        if ( RT_Store::household() ) {
            return self::error( 'You already belong to a household.', 409 );
        }
        if ( ! self::rate_limit( 'join', 10, 10 * MINUTE_IN_SECONDS ) ) {
            return self::error( 'Too many attempts. Try again in 10 minutes.', 429 );
        }
        $code = self::text( $request->get_param( 'code' ), 64 );
        if ( ! $code || ! preg_match( '/^[a-f0-9]{32}$/', $code ) ) {
            return self::error( 'That invitation is invalid or has expired.' );
        }
        $id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . RT_Store::table( 'households' ) . ' WHERE invite_hash=%s AND invite_expires>%s', hash( 'sha256', $code ), current_time( 'mysql', true ) ) );
        if ( ! $id ) {
            return self::error( 'That invitation is invalid or has expired.' );
        }
        $ok = $wpdb->insert( RT_Store::table( 'members' ), array( 'household_id' => $id, 'user_id' => get_current_user_id() ) );
        return $ok ? self::bootstrap() : self::error( 'Could not join this household.', 409 );
    }

    public static function leave_household() {
        global $wpdb;
        $household = RT_Store::household();
        if ( ! $household || (int) $household['owner_id'] === get_current_user_id() ) {
            return self::error( 'The household owner cannot leave the household.', 403 );
        }
        $ok = $wpdb->delete( RT_Store::table( 'members' ), array( 'user_id' => get_current_user_id() ) );
        return false === $ok ? self::error( 'Could not leave the household.', 500 ) : self::bootstrap();
    }

    public static function remove_member( $request ) {
        global $wpdb;
        $household = RT_Store::household();
        $id = (int) $request['id'];
        if ( ! $household || (int) $household['owner_id'] !== get_current_user_id() || $id === get_current_user_id() ) {
            return self::error( 'Only the owner can remove other members.', 403 );
        }
        $ok = $wpdb->delete( RT_Store::table( 'members' ), array( 'household_id' => $household['id'], 'user_id' => $id ) );
        // Rotate away any previous invitation when revoking a member's access.
        $wpdb->update( RT_Store::table( 'households' ), array( 'invite_hash' => '', 'invite_expires' => null ), array( 'id' => $household['id'] ) );
        return false === $ok ? self::error( 'Could not remove this member.', 500 ) : self::bootstrap();
    }

    public static function save_companion( $request ) {
        return RT_Companions::save( $request );
    }

    public static function lookup( $request ) {
        if ( ! self::rate_limit( 'search', 40, MINUTE_IN_SECONDS ) ) {
            return self::error( 'Please wait a minute before searching again.', 429 );
        }
        return RT_Catalog::lookup( $request->get_param( 'id' ), $request->get_param( 'provider' ) );
    }

    public static function search( $request ) {
        $query = self::text( $request->get_param( 'q' ), 100 );
        if ( ! $query || RT_Store::length( $query ) < 2 ) {
            return self::error( 'Enter at least two characters.' );
        }
        if ( ! self::rate_limit( 'search', 40, MINUTE_IN_SECONDS ) ) {
            return self::error( 'Please wait a minute before searching again.', 429 );
        }
        $provider = $request->get_param( 'provider' ) ?? ( RT_App::token( 'kinopoisk' ) ? 'kinopoisk' : 'tmdb' );
        return RT_Catalog::search( $query, $provider );
    }
}
