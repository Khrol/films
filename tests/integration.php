<?php
require '/wordpress/wp-load.php';

$passed = array();
function check( $condition, $description ) {
    global $passed;
    if ( ! $condition ) { throw new Exception( $description ); }
    $passed[] = $description;
}
function call_api( $user, $method, $path, $body = null, $query = array() ) {
    wp_set_current_user( $user );
    $request = new WP_REST_Request( $method, '/reel-together/v1/' . $path );
    $request->set_query_params( $query );
    if ( null !== $body ) {
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( $body ) );
    }
    return rest_do_request( $request );
}
function viewing( $title, $scope = 'personal', $status = 'watched' ) {
    return array( 'movie' => array( 'title' => $title, 'year' => 2020 ), 'scope' => $scope,
        'status' => $status, 'watched_on' => '2025-03-14', 'rating' => 4, 'attendees' => 'Me and Alex', 'notes' => 'Worth another watch.' );
}
function new_user( $login ) {
    return wp_insert_user( array( 'user_login' => $login, 'user_pass' => 'test-movie-night', 'user_email' => "$login@example.test", 'display_name' => ucfirst( $login ), 'role' => 'subscriber' ) );
}

try {
    $alice = new_user( 'alice' ); $bob = new_user( 'bob' ); $eve = new_user( 'eve' );
    check( ! is_wp_error( $alice ) && ! is_wp_error( $bob ) && ! is_wp_error( $eve ), 'Creates independent subscriber accounts' );
    check( call_api( 0, 'GET', 'bootstrap' )->get_status() === 401, 'Anonymous callers cannot read diaries' );
    check( call_api( 0, 'POST', 'entries', viewing( 'No access' ) )->get_status() === 401, 'Anonymous callers cannot write entries' );

    $personal = call_api( $alice, 'POST', 'entries', viewing( 'Alice private film' ) );
    check( $personal->get_status() === 201, 'Saves a personal viewing' );
    $private_id = $personal->get_data()['id'];
    check( call_api( $bob, 'GET', 'entries' )->get_data()['total'] === 0, 'Other accounts cannot list personal entries' );
    check( call_api( $bob, 'PUT', "entries/$private_id", viewing( 'Hijacked' ) )->get_status() === 403, 'Another account cannot edit a personal entry by ID' );
    check( call_api( $bob, 'DELETE', "entries/$private_id" )->get_status() === 403, 'Another account cannot delete a personal entry by ID' );
    check( call_api( $alice, 'POST', 'entries', viewing( 'No household', 'household' ) )->get_status() === 400, 'Shared entries require household membership' );

    check( call_api( $alice, 'POST', 'household', array( 'name' => 'Friday Film Club' ) )->get_status() === 200, 'Creates a household' );
    $old_code = call_api( $alice, 'POST', 'household/invite', array() )->get_data()['code'];
    $code = call_api( $alice, 'POST', 'household/invite', array() )->get_data()['code'];
    check( call_api( $bob, 'POST', 'household/join', array( 'code' => $old_code ) )->get_status() === 400, 'Rotating invitations invalidates the old code' );
    check( call_api( $bob, 'POST', 'household/join', array( 'code' => $code ) )->get_status() === 200, 'A valid invitation joins the household' );
    check( call_api( $bob, 'POST', 'household', array( 'name' => 'Second household' ) )->get_status() === 409, 'One account cannot join two households' );
    check( call_api( $bob, 'POST', 'household/invite', array() )->get_status() === 403, 'Only the owner can generate invitations' );
    check( call_api( $bob, 'GET', 'entries' )->get_data()['total'] === 0, 'Joining a household does not expose personal entries' );

    $shared = call_api( $alice, 'POST', 'entries', viewing( 'Family favorite', 'household' ) );
    $shared_id = $shared->get_data()['id'];
    check( call_api( $bob, 'GET', 'entries' )->get_data()['total'] === 1, 'Household members can read shared viewings' );
    check( call_api( $eve, 'GET', 'entries' )->get_data()['total'] === 0, 'Nonmembers cannot read household viewings' );
    check( call_api( $bob, 'PUT', "entries/$shared_id", viewing( 'Changed title', 'household' ) )->get_status() === 403, 'Members cannot change another author’s entry' );
    check( call_api( $bob, 'DELETE', "entries/$shared_id" )->get_status() === 403, 'Members cannot delete another author’s entry' );
    check( call_api( $bob, 'GET', 'bootstrap' )->get_data()['counts']['watched'] === 1, 'Counts obey personal visibility rules' );
    $bootstrap = call_api( $alice, 'GET', 'bootstrap' )->get_data();
    check( ! isset( $bootstrap['household']['invite_hash'] ) && ! isset( $bootstrap['household']['invite_expires'] ), 'Bootstrap never exposes invitation secrets' );

    $rewatch = call_api( $alice, 'POST', 'entries', viewing( 'Family favorite', 'household' ) );
    check( $rewatch->get_status() === 201 && $rewatch->get_data()['id'] !== $shared_id, 'Repeated viewings are separate diary entries' );
    $rows = call_api( $alice, 'GET', 'entries', null, array( 'scope' => 'household' ) )->get_data()['items'];
    check( count( $rows ) === 2 && $rows[0]['movie_id'] === $rows[1]['movie_id'], 'Repeated viewings share one movie record' );

    $watchlist = call_api( $alice, 'POST', 'entries', viewing( 'Watch this later', 'personal', 'watchlist' ) );
    check( $watchlist->get_status() === 201, 'Creates a watchlist entry' );
    check( call_api( $alice, 'POST', 'entries', viewing( 'Watch this later', 'personal', 'watchlist' ) )->get_status() === 409, 'Duplicate personal watchlist entries are rejected' );
    $watch_id = $watchlist->get_data()['id'];
    check( call_api( $alice, 'PUT', "entries/$watch_id", viewing( 'Watch this later' ) )->get_status() === 200, 'Moves a watchlist entry into the diary' );
    check( call_api( $alice, 'GET', 'entries', null, array( 'status' => 'watchlist' ) )->get_data()['total'] === 0, 'Watched movies leave the watchlist' );

    foreach ( array(
        array( 'watched_on', '2025-02-30' ), array( 'watched_on', '2999-01-01' ), array( 'watched_on', array() ),
        array( 'rating', 6 ), array( 'rating', 3.5 ), array( 'scope', 'public' ), array( 'notes', str_repeat( 'x', 2001 ) ),
    ) as $invalid ) {
        $body = viewing( 'Invalid entry' ); $body[ $invalid[0] ] = $invalid[1];
        check( call_api( $alice, 'POST', 'entries', $body )->get_status() === 400, 'Rejects invalid ' . $invalid[0] . ': ' . wp_json_encode( $invalid[1] ) );
    }
    $invalid = viewing( 'Invalid movie' ); $invalid['movie']['title'] = array( 'x' );
    check( call_api( $alice, 'POST', 'entries', $invalid )->get_status() === 400, 'Rejects malformed movie data without a PHP error' );
    check( call_api( $alice, 'GET', 'entries', null, array( 'scope' => 'anything' ) )->get_status() === 400, 'Rejects unsupported filters' );
    check( call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Alice private' ) )->get_data()['total'] === 1, 'Search filters the accessible collection' );
    check( call_api( $bob, 'GET', 'entries', null, array( 'q' => 'Alice private' ) )->get_data()['total'] === 0, 'Search never reveals another member’s private titles' );
    check( call_api( $alice, 'GET', 'search', null, array( 'q' => 'Film' ) )->get_status() === 503, 'Manual entry works without a catalog connection' );

    update_option( 'rt_tmdb_token', 'secret-server-token' );
    $mock = function ( $preempt, $args, $url ) {
        if ( str_starts_with( $url, 'https://api.themoviedb.org/3/search/movie' ) ) {
            check( $args['headers']['Authorization'] === 'Bearer secret-server-token', 'TMDB receives a server-side bearer token' );
            return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'results' => array( array( 'id' => 12, 'title' => 'Catalog result', 'release_date' => '2001-01-01', 'poster_path' => '/test.jpg' ) ) ) ) );
        }
        return $preempt;
    };
    add_filter( 'pre_http_request', $mock, 10, 3 );
    $search = call_api( $alice, 'GET', 'search', null, array( 'q' => 'Catalog mock' ) );
    check( $search->get_status() === 200 && $search->get_data()[0]['year'] === 2001, 'Maps catalog metadata into movie search results' );
    check( ! str_contains( wp_json_encode( call_api( $alice, 'GET', 'bootstrap' )->get_data() ), 'secret-server-token' ), 'The API does not expose the TMDB token' );
    remove_filter( 'pre_http_request', $mock, 10 ); delete_option( 'rt_tmdb_token' );

    update_option( 'rt_kinopoisk_token', 'test-kinopoisk-key' );
    $search = call_api( $alice, 'GET', 'search', null, array( 'q' => 'Матрица', 'provider' => 'kinopoisk' ) );
    $catalog = $search->get_data();
    check( $search->get_status() === 200 && $catalog[0]['title'] === 'Матрица' && $catalog[0]['kinopoisk_rating'] === 8.5, 'Kinopoisk search returns Russian titles and community ratings' );
    check( $GLOBALS['rt_test_kinopoisk_request']['headers']['X-API-KEY'] === 'test-kinopoisk-key', 'Kinopoisk authentication stays on the server' );
    parse_str( wp_parse_url( $GLOBALS['rt_test_kinopoisk_request']['url'], PHP_URL_QUERY ), $kp_query );
    check( $kp_query['keyword'] === 'Матрица' && $kp_query['type'] === 'FILM', 'Search encodes Cyrillic keywords and requests movies' );
    $requests = $GLOBALS['rt_test_kinopoisk_calls'];
    call_api( $alice, 'GET', 'search', null, array( 'q' => 'Матрица', 'provider' => 'kinopoisk' ) );
    check( $GLOBALS['rt_test_kinopoisk_calls'] === $requests, 'Search caching avoids repeated provider calls' );
    check( null === $catalog[1]['kinopoisk_rating'], 'Missing Kinopoisk ratings remain unknown instead of becoming zero' );
    check( $catalog[2]['title'] === 'Original title' && $catalog[2]['poster_url'] === '', 'Falls back to an original title and rejects unknown poster hosts' );
    check( RT_Catalog::safe_poster( 'https://kinopoiskapiunofficial.tech.evil.example/images/posters/301.jpg' ) === '', 'Poster allowlist rejects lookalike domains' );
    check( RT_Catalog::safe_poster( 'http://kinopoiskapiunofficial.tech/images/posters/kp/301.jpg' ) === 'https://kinopoiskapiunofficial.tech/images/posters/kp/301.jpg', 'Legacy provider poster URLs are upgraded to HTTPS' );
    $body = viewing( 'Ignored client title' );
    $body['movie'] = $catalog[0]; $body['movie']['kinopoisk_rating'] = 1.0; $body['movie']['title'] = 'Forged metadata';
    $saved = call_api( $alice, 'POST', 'entries', $body );
    check( $saved->get_status() === 201, 'Saves a selected Kinopoisk film' );
    $kp_entry_id = $saved->get_data()['id'];
    $saved_rows = call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Матрица' ) )->get_data()['items'];
    check( $saved_rows[0]['kinopoisk_rating'] === 8.5 && $saved_rows[0]['rating'] === 4, 'Verified Kinopoisk rating is separate from personal rating and cannot be forged' );
    check( $saved_rows[0]['kinopoisk_id'] === 301 && str_contains( $saved_rows[0]['poster_url'], '301.jpg' ), 'Kinopoisk identity and poster survive persistence' );
    $body['movie']['kinopoisk_id'] = 99999999;
    check( call_api( $alice, 'POST', 'entries', $body )->get_status() === 400, 'Invented catalog IDs cannot poison shared movie metadata' );
    $body = viewing( str_repeat( 'я', 150 ) );
    check( call_api( $alice, 'POST', 'entries', $body )->get_status() === 201, 'Cyrillic titles use character limits rather than byte limits' );
    check( call_api( $alice, 'GET', 'search', null, array( 'q' => 'Я', 'provider' => 'kinopoisk' ) )->get_status() === 400, 'One Cyrillic character is not treated as a two-character query' );
    foreach ( array( 'quota' => 429, 'invalid-key' => 502, 'malformed-json' => 502 ) as $query => $status ) {
        check( call_api( $alice, 'GET', 'search', null, array( 'q' => $query, 'provider' => 'kinopoisk' ) )->get_status() === $status, 'Handles Kinopoisk ' . $query . ' without losing the diary' );
    }
    check( call_api( $alice, 'GET', 'search', null, array( 'q' => 'empty-results', 'provider' => 'kinopoisk' ) )->get_data() === array(), 'Empty catalog results are a successful empty list' );
    check( ! str_contains( wp_json_encode( call_api( $alice, 'GET', 'bootstrap' )->get_data() ), 'test-kinopoisk-key' ), 'Bootstrap never exposes the Kinopoisk key' );
    check( call_api( $alice, 'GET', 'search', null, array( 'q' => 'test', 'provider' => 'http://example.com' ) )->get_status() === 400, 'Rejects unrecognized search providers' );
    $by_kp = call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'kinopoisk', 'id' => 'https://www.kinopoisk.ru/film/430/?utm_source=test' ) );
    check( $by_kp->get_status() === 200 && $by_kp->get_data()['imdb_id'] === 'tt0126029', 'Exact Kinopoisk URL lookup maps IMDb identity from the provider' );
    check( $GLOBALS['rt_test_kinopoisk_request']['url'] === 'https://kinopoiskapiunofficial.tech/api/v2.2/films/430', 'Looks up Kinopoisk by ID at a fixed API endpoint' );
    $kp_calls = $GLOBALS['rt_test_kinopoisk_calls'];
    call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'kinopoisk', 'id' => '430' ) );
    check( $GLOBALS['rt_test_kinopoisk_calls'] === $kp_calls, 'IDs and equivalent URLs share a lookup cache' );
    $by_imdb = call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'imdb', 'id' => 'https://www.imdb.com/title/tt0126029/' ) );
    check( $by_imdb->get_status() === 200 && $by_imdb->get_data()['kinopoisk_id'] === 430 && $by_imdb->get_data()['imdb_rating'] === 7.9, 'IMDb lookup returns the exact cross-reference and community rating' );
    parse_str( wp_parse_url( $GLOBALS['rt_test_kinopoisk_request']['url'], PHP_URL_QUERY ), $lookup_query );
    check( $lookup_query['imdbId'] === 'tt0126029' && ! isset( $lookup_query['keyword'] ), 'IMDb lookup uses the exact external ID filter, never a title guess' );
    $body = viewing( 'ignored' ); $body['movie'] = $by_kp->get_data();
    $body['movie']['imdb_id'] = 'tt9999999'; $body['movie']['imdb_rating'] = 10;
    $from_kp = call_api( $alice, 'POST', 'entries', $body );
    $body['movie'] = $by_imdb->get_data();
    $from_imdb = call_api( $alice, 'POST', 'entries', $body );
    $rows = call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Шрэк' ) )->get_data()['items'];
    check( $from_kp->get_status() === 201 && $from_imdb->get_status() === 201 && $rows[0]['movie_id'] === $rows[1]['movie_id'], 'Both verified IDs reuse the same movie while retaining separate viewings' );
    check( $rows[1]['imdb_id'] === 'tt0126029' && $rows[1]['imdb_rating'] === 7.9, 'Client edits cannot forge the stored IMDb identity or rating' );
    foreach ( array( array( 'kinopoisk', '999999' ), array( 'kinopoisk', '777' ), array( 'imdb', 'tt9999999' ) ) as $missing ) {
        check( call_api( $alice, 'GET', 'lookup', null, array( 'provider' => $missing[0], 'id' => $missing[1] ) )->get_status() === 404, 'Rejects missing or mismatched exact catalog ID ' . $missing[1] );
    }
    $kp_calls = $GLOBALS['rt_test_kinopoisk_calls'];
    foreach ( array( 'https://kinopoisk.ru.evil.example/film/430/', 'https://kinopoisk.ru@evil.example/film/430/', 'https://www.kinopoisk.ru/name/430/', 'https://www.kinopoisk.ru:443/film/430/', 'https://127.0.0.1/film/430/', '430/../301', '0', array( 430 ) ) as $invalid ) {
        check( call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'kinopoisk', 'id' => $invalid ) )->get_status() === 400, 'Rejects malformed Kinopoisk identities before making a request' );
    }
    check( $GLOBALS['rt_test_kinopoisk_calls'] === $kp_calls, 'Invalid URLs never trigger outgoing requests' );
    check( is_wp_error( RT_Catalog::parse_id( 'nm0126029', 'imdb' ) ) && is_wp_error( RT_Catalog::parse_id( 'https://www.imdb.com/name/nm0126029/', 'imdb' ) ), 'IMDb IDs must identify titles, not people' );
    check( RT_Catalog::parse_id( 'https://m.imdb.com/title/tt0126029/?ref_=test', 'imdb' ) === 'tt0126029', 'IMDb mobile URLs retain leading zeros and discard tracking parameters' );
    delete_option( 'rt_kinopoisk_token' ); delete_transient( 'rt_catalog_movie_kinopoisk_301' );
    delete_transient( 'rt_rate_search_' . $alice );
    check( call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'kinopoisk', 'id' => '430' ) )->get_status() === 503, 'Missing API connection leaves exact links available without promising metadata' );
    $body = viewing( 'Shrek manual links' );
    $body['movie']['links'] = array( 'kinopoisk' => 'https://www.kinopoisk.ru/film/430/', 'imdb' => 'tt0126029' );
    $body['movie']['imdb_rating'] = 10; $body['movie']['kinopoisk_rating'] = 10;
    $manual = call_api( $alice, 'POST', 'entries', $body );
    $row = call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Shrek manual links' ) )->get_data()['items'][0];
    check( $manual->get_status() === 201 && $row['linked_kinopoisk_id'] === 430 && $row['linked_imdb_id'] === 'tt0126029', 'Both manual ID links persist without an API key' );
    check( $row['kinopoisk_rating'] === null && $row['imdb_rating'] === null && $row['kinopoisk_id'] === 0, 'Manual links cannot impersonate verified catalog metadata or ratings' );
    $alice_movie = $row['movie_id'];
    $other_manual = call_api( $bob, 'POST', 'entries', $body );
    $bob_movie = call_api( $bob, 'GET', 'entries', null, array( 'q' => 'Shrek manual links' ) )->get_data()['items'][0]['movie_id'];
    check( $other_manual->get_status() === 201 && $alice_movie !== $bob_movie, 'Unverified movie links are isolated per author' );
    call_api( $bob, 'DELETE', 'entries/' . $other_manual->get_data()['id'] );
    $body['movie']['links']['kinopoisk'] = 'https://www.imdb.com/title/tt0126029/';
    check( call_api( $alice, 'POST', 'entries', $body )->get_status() === 400, 'Manual saving validates the provider and ID format' );
    update_option( 'rt_tmdb_token', 'secret-server-token' );
    $find_mock = function ( $preempt, $args, $url ) {
        if ( str_starts_with( $url, 'https://api.themoviedb.org/3/find/tt0126029?' ) ) {
            check( str_contains( $url, 'external_source=imdb_id' ), 'TMDB fallback uses the external IMDb identity endpoint' );
            return array( 'response' => array( 'code' => 200 ), 'headers' => array(), 'body' => wp_json_encode( array( 'movie_results' => array( array( 'id' => 808, 'title' => 'Шрэк', 'release_date' => '2001-05-18', 'vote_average' => 7.8 ) ) ) ) );
        }
        return $preempt;
    };
    add_filter( 'pre_http_request', $find_mock, 10, 3 );
    $tmdb_lookup = call_api( $alice, 'GET', 'lookup', null, array( 'provider' => 'imdb', 'id' => 'tt0126029' ) )->get_data();
    check( $tmdb_lookup['tmdb_id'] === 808 && $tmdb_lookup['imdb_id'] === 'tt0126029' && $tmdb_lookup['imdb_rating'] === null, 'TMDB resolves IMDb IDs without relabeling its own rating as IMDb' );
    remove_filter( 'pre_http_request', $find_mock, 10 ); delete_option( 'rt_tmdb_token' );
    $body = viewing( 'Матрица' ); $body['movie']['kinopoisk_id'] = 301;
    check( call_api( $alice, 'PUT', "entries/$kp_entry_id", $body )->get_status() === 200, 'Previously saved catalog movies can be edited after disconnection' );

    for ( $i = 0; $i < 25; $i++ ) { call_api( $alice, 'POST', 'entries', viewing( "Page test $i" ) ); }
    $page1 = call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Page test', 'page' => 1 ) )->get_data();
    $page2 = call_api( $alice, 'GET', 'entries', null, array( 'q' => 'Page test', 'page' => 2 ) )->get_data();
    check( count( $page1['items'] ) === 24 && count( $page2['items'] ) === 1 && $page1['total'] === 25, 'Paginates the collection without dropping records' );
    check( call_api( $alice, 'DELETE', 'household/membership' )->get_status() === 403, 'The household owner cannot accidentally leave' );
    check( call_api( $bob, 'DELETE', 'household/membership' )->get_status() === 200, 'A member can leave the household' );
    check( call_api( $bob, 'GET', 'entries' )->get_data()['total'] === 0, 'Leaving immediately revokes access to shared entries' );
    check( call_api( $bob, 'POST', 'household/join', array( 'code' => $code ) )->get_status() === 200, 'A member with a valid invitation can rejoin' );
    check( call_api( $alice, 'DELETE', "household/members/$bob" )->get_status() === 200, 'Owners can remove household members' );
    check( call_api( $bob, 'GET', 'entries' )->get_data()['total'] === 0, 'Removed members lose household access' );
    check( call_api( $bob, 'POST', 'household/join', array( 'code' => $code ) )->get_status() === 400, 'Removing a member revokes outstanding invitation codes' );
    check( call_api( $alice, 'DELETE', "entries/$private_id" )->get_status() === 200, 'Authors can delete their own entries' );

    // Keep browser tests independent of the API fixtures above.
    require '/wordpress/reel-invitation-checks.php';
    require '/wordpress/reel-companion-checks.php';
    require '/wordpress/reel-sharing-checks.php';
    $browser = new_user( 'browser' );
    new_user( 'linked_browser' );
    new_user( 'sharing_browser' );
    update_option( 'rt_kinopoisk_token', 'test-kinopoisk-key' );
    update_option( 'show_on_front', 'page' ); update_option( 'page_on_front', get_option( 'rt_page_id' ) );
    echo wp_json_encode( array( 'passed' => $passed, 'wordpress' => get_bloginfo( 'version' ), 'php' => PHP_VERSION, 'browser_user' => $browser ) );
} catch ( Throwable $e ) {
    echo wp_json_encode( array( 'passed' => $passed, 'failure' => $e->getMessage(), 'line' => $e->getLine() ) );
}
