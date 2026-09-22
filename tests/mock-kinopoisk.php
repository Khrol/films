<?php
// Loaded only into the disposable WordPress test server. Never packaged or deployed.
add_filter( 'pre_http_request', function ( $preempt, $args, $url ) {
    if ( ! str_starts_with( $url, 'https://kinopoiskapiunofficial.tech/api/v2.2/films' ) ) { return $preempt; }
    $GLOBALS['rt_test_kinopoisk_calls'] = ( $GLOBALS['rt_test_kinopoisk_calls'] ?? 0 ) + 1;
    $GLOBALS['rt_test_kinopoisk_request'] = array( 'url' => $url, 'headers' => $args['headers'] );
    parse_str( wp_parse_url( $url, PHP_URL_QUERY ) ?? '', $query );
    $keyword = $query['keyword'] ?? '';
    $status = 'quota' === $keyword ? 402 : ( 'invalid-key' === $keyword ? 401 : 200 );
    $items = 'empty-results' === $keyword ? array() : array(
        array( 'kinopoiskId' => 301, 'nameRu' => 'Матрица', 'nameOriginal' => 'The Matrix', 'year' => 1999,
            'imdbId' => 'tt0133093', 'ratingKinopoisk' => 8.5, 'ratingImdb' => 8.7, 'posterUrlPreview' => 'https://kinopoiskapiunofficial.tech/images/posters/kp_small/301.jpg' ),
        array( 'kinopoiskId' => 999901, 'nameRu' => 'Без оценки', 'year' => 2025, 'ratingKinopoisk' => null, 'posterUrlPreview' => null ),
        array( 'kinopoiskId' => 999902, 'nameRu' => null, 'nameOriginal' => 'Original title', 'year' => null, 'ratingKinopoisk' => 4.3,
            'posterUrlPreview' => 'https://untrusted.example/poster.jpg' ),
    );
    $shrek = array( 'kinopoiskId' => 430, 'imdbId' => 'tt0126029', 'nameRu' => 'Шрэк', 'nameOriginal' => 'Shrek',
        'year' => 2001, 'type' => 'FILM', 'ratingKinopoisk' => 8.2, 'ratingImdb' => 7.9 );
    if ( preg_match( '~/films/([0-9]+)$~', wp_parse_url( $url, PHP_URL_PATH ), $match ) ) {
        return array( 'response' => array( 'code' => '999999' === $match[1] ? 404 : 200 ), 'headers' => array(), 'body' => wp_json_encode( $shrek ) );
    }
    if ( isset( $query['imdbId'] ) && 'tt0126029' === $query['imdbId'] ) { $items = array( $shrek ); }
    return array( 'response' => array( 'code' => $status ), 'headers' => array(),
        'body' => 'malformed-json' === $keyword ? '<html>Unavailable</html>' : wp_json_encode( array( 'items' => $items, 'total' => count( $items ), 'totalPages' => 1 ) ) );
}, 10, 3 );
