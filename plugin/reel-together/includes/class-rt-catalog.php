<?php
defined( 'ABSPATH' ) || exit;

final class RT_Catalog {
    public static function providers() {
        $providers = array();
        if ( RT_App::token( 'kinopoisk' ) ) {
            $providers[] = array( 'id' => 'kinopoisk', 'name' => 'Kinopoisk — Russian titles & ratings' );
        }
        if ( RT_App::token() ) {
            $providers[] = array( 'id' => 'tmdb', 'name' => 'TMDB' );
        }
        return $providers;
    }

    public static function safe_poster( $url ) {
        if ( ! is_string( $url ) || strlen( $url ) > 500 ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        $paths = array(
            'kinopoiskapiunofficial.tech' => '/images/posters/',
            'avatars.mds.yandex.net' => '/get-kinopoisk-image/',
            'st.kp.yandex.net' => '/images/',
        );
        if ( ! is_array( $parts ) || ! isset( $paths[ $parts['host'] ?? '' ] ) ||
             ! in_array( $parts['scheme'] ?? '', array( 'https', 'http' ), true ) ||
             isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ||
             isset( $parts['query'] ) || isset( $parts['fragment'] ) ||
             ! str_starts_with( $parts['path'] ?? '', $paths[ $parts['host'] ] ) ) {
            return '';
        }
        return esc_url_raw( 'https://' . $parts['host'] . $parts['path'] );
    }

    public static function cached_movie( $provider, $id ) {
        return get_transient( 'rt_catalog_movie_' . $provider . '_' . (int) $id );
    }

    // Parse identities, never request a URL supplied by a visitor.
    public static function parse_id( $value, $provider ) {
        if ( in_array( $provider, array( 'kinopoisk', 'imdb' ), true ) && ( is_string( $value ) || is_int( $value ) ) ) {
            $value = trim( (string) $value );
            if ( '' === $value ) { return 'kinopoisk' === $provider ? 0 : ''; }
            if ( strlen( $value ) <= 500 ) {
                if ( preg_match( '~^https?://~i', $value ) ) {
                    $parts = wp_parse_url( $value );
                    $hosts = 'kinopoisk' === $provider ? array( 'kinopoisk.ru', 'www.kinopoisk.ru' ) : array( 'imdb.com', 'www.imdb.com', 'm.imdb.com' );
                    $pattern = 'kinopoisk' === $provider ? '~^/film/([1-9][0-9]{0,7})/?$~D' : '~^/title/(tt[0-9]{7,10})/?$~D';
                    if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['host'] ?? '' ), $hosts, true ) ||
                         isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ||
                         ! preg_match( $pattern, $parts['path'] ?? '', $match ) ) {
                        return self::invalid_id( $provider );
                    }
                    $value = $match[1];
                }
                if ( 'kinopoisk' === $provider && preg_match( '/^[1-9][0-9]{0,7}$/D', $value ) && (int) $value <= 15000000 ) { return (int) $value; }
                if ( 'imdb' === $provider && preg_match( '/^tt[0-9]{7,10}$/D', $value ) && (int) substr( $value, 2 ) > 0 ) { return $value; }
            }
        }
        return self::invalid_id( $provider );
    }

    private static function invalid_id( $provider ) {
        return new WP_Error( 'rt_id', 'kinopoisk' === $provider ? 'Enter a Kinopoisk film ID such as 430, or its film URL.' : 'Enter an IMDb title ID beginning with tt, or its title URL.', array( 'status' => 400 ) );
    }

    private static function request( $url, $provider ) {
        $token = RT_App::token( $provider );
        if ( ! $token ) {
            return new WP_Error( 'rt_catalog', 'Connect a movie catalog in Settings → Reel Together to load details. You can still save a title and its links manually.', array( 'status' => 503 ) );
        }
        $headers = 'kinopoisk' === $provider ? array( 'X-API-KEY' => $token ) : array( 'Authorization' => 'Bearer ' . $token );
        $headers['Accept'] = 'application/json';
        $response = wp_remote_get( $url, array( 'timeout' => 10, 'headers' => $headers, 'limit_response_size' => 300000, 'redirection' => 0 ) );
        if ( is_wp_error( $response ) ) {
            return new WP_Error( 'rt_catalog', 'The movie catalog could not connect. Try again or enter a title manually.', array( 'status' => 502 ) );
        }
        $code = wp_remote_retrieve_response_code( $response );
        if ( 404 === $code ) { return self::not_found(); }
        if ( in_array( $code, array( 402, 429 ), true ) ) {
            return new WP_Error( 'rt_catalog', 'The movie catalog request limit has been reached. Try again later; manual entry still works.', array( 'status' => 429 ) );
        }
        if ( 200 !== $code ) {
            return new WP_Error( 'rt_catalog', in_array( $code, array( 401, 403 ), true )
                ? 'The movie catalog rejected its API key. Ask the site owner to check Settings → Reel Together.'
                : 'The movie catalog is temporarily unavailable. Please try again later.', array( 'status' => 502 ) );
        }
        $data = json_decode( wp_remote_retrieve_body( $response ), true );
        return is_array( $data ) ? $data : self::unexpected();
    }

    private static function unexpected() {
        return new WP_Error( 'rt_catalog', 'The movie catalog returned an unexpected response.', array( 'status' => 502 ) );
    }

    private static function not_found() {
        return new WP_Error( 'rt_catalog', 'No movie with that exact ID was found in the connected catalog. You can still save its link manually.', array( 'status' => 404 ) );
    }

    private static function rating( $value ) {
        return is_numeric( $value ) && $value >= 0 && $value <= 10 ? round( (float) $value, 1 ) : null;
    }

    private static function map_movie( $movie, $provider, $imdb_id = '' ) {
        if ( ! is_array( $movie ) ) { return null; }
        $kinopoisk = 'kinopoisk' === $provider;
        $id = $movie[ $kinopoisk ? 'kinopoiskId' : 'id' ] ?? 0;
        $title = $kinopoisk ? ( ( $movie['nameRu'] ?? '' ) ?: ( ( $movie['nameOriginal'] ?? '' ) ?: ( $movie['nameEn'] ?? '' ) ) ) : ( $movie['title'] ?? '' );
        if ( ! is_string( $title ) || '' === trim( $title ) || false === filter_var( $id, FILTER_VALIDATE_INT ) || $id < 1 ) { return null; }
        $imdb = self::parse_id( $movie['imdbId'] ?? $imdb_id, 'imdb' );
        $imdb = is_wp_error( $imdb ) ? '' : $imdb;
        $item = array(
            'title' => sanitize_text_field( $title ),
            'year' => $kinopoisk ? (int) ( $movie['year'] ?? 0 ) : (int) substr( $movie['release_date'] ?? '', 0, 4 ),
            'tmdb_id' => $kinopoisk ? 0 : (int) $id,
            'kinopoisk_id' => $kinopoisk ? (int) $id : 0,
            'kinopoisk_rating' => $kinopoisk ? self::rating( $movie['ratingKinopoisk'] ?? null ) : null,
            'imdb_id' => $imdb,
            'imdb_rating' => $kinopoisk && $imdb ? self::rating( $movie['ratingImdb'] ?? null ) : null,
            'poster_path' => $kinopoisk ? '' : ( $movie['poster_path'] ?? '' ),
            'poster_url' => $kinopoisk ? self::safe_poster( $movie['posterUrlPreview'] ?? $movie['posterUrl'] ?? '' ) : '',
            'catalog_checked_at' => current_time( 'mysql', true ),
        );
        set_transient( 'rt_catalog_movie_' . $provider . '_' . (int) $id, $item, DAY_IN_SECONDS );
        return $item;
    }

    public static function lookup( $value, $provider ) {
        $id = self::parse_id( $value, $provider );
        if ( is_wp_error( $id ) ) { return $id; }
        if ( ! $id ) { return self::invalid_id( $provider ); }
        $source = 'kinopoisk' === $provider || RT_App::token( 'kinopoisk' ) ? 'kinopoisk' : 'tmdb';
        $token = RT_App::token( $source );
        if ( ! $token ) { return self::request( '', $source ); }
        $cache = 'rt_lookup_v3_' . md5( "$source:$token:$provider:$id" );
        $cached = get_transient( $cache );
        if ( false !== $cached ) { return $cached; }
        if ( 'kinopoisk' === $provider ) {
            $url = 'https://kinopoiskapiunofficial.tech/api/v2.2/films/' . $id;
        } elseif ( 'kinopoisk' === $source ) {
            $url = add_query_arg( array( 'imdbId' => $id, 'type' => 'FILM', 'page' => 1 ), 'https://kinopoiskapiunofficial.tech/api/v2.2/films' );
        } else {
            $url = add_query_arg( array( 'external_source' => 'imdb_id', 'language' => 'ru-RU' ), 'https://api.themoviedb.org/3/find/' . $id );
        }
        $data = self::request( $url, $source );
        if ( is_wp_error( $data ) ) { return $data; }
        $movies = 'kinopoisk' === $provider ? array( $data ) : ( $data[ 'kinopoisk' === $source ? 'items' : 'movie_results' ] ?? null );
        if ( ! is_array( $movies ) ) { return self::unexpected(); }
        $matches = array_values( array_filter( $movies, function ( $movie ) use ( $provider, $source, $id ) {
            if ( ! is_array( $movie ) || ( isset( $movie['type'] ) && 'FILM' !== $movie['type'] ) ) { return false; }
            if ( 'kinopoisk' === $provider ) { return (int) ( $movie['kinopoiskId'] ?? 0 ) === $id; }
            return 'tmdb' === $source || ( $movie['imdbId'] ?? '' ) === $id;
        } ) );
        // Never choose a similarly titled film or guess between ambiguous mappings.
        if ( 1 !== count( $matches ) ) { return self::not_found(); }
        $item = self::map_movie( $matches[0], $source, 'imdb' === $provider ? $id : '' );
        if ( ! $item ) { return self::unexpected(); }
        set_transient( $cache, $item, HOUR_IN_SECONDS );
        return $item;
    }

    public static function search( $query, $provider ) {
        if ( ! in_array( $provider, array( 'kinopoisk', 'tmdb' ), true ) ) {
            return new WP_Error( 'rt_catalog', 'Choose a supported movie catalog.', array( 'status' => 400 ) );
        }
        $token = RT_App::token( $provider );
        if ( ! $token ) { return self::request( '', $provider ); }
        $cache = 'rt_catalog_search_v3_' . md5( $provider . ':' . $token . ':' . $query );
        $cached = get_transient( $cache );
        if ( false !== $cached ) { return $cached; }
        $kinopoisk = 'kinopoisk' === $provider;
        $url = $kinopoisk
            ? add_query_arg( array( 'keyword' => $query, 'type' => 'FILM', 'page' => 1 ), 'https://kinopoiskapiunofficial.tech/api/v2.2/films' )
            : add_query_arg( array( 'query' => $query, 'include_adult' => 'false', 'language' => 'ru-RU' ), 'https://api.themoviedb.org/3/search/movie' );
        $data = self::request( $url, $provider );
        if ( is_wp_error( $data ) ) { return $data; }
        $results = $data[ $kinopoisk ? 'items' : 'results' ] ?? null;
        if ( ! is_array( $results ) ) { return self::unexpected(); }
        $items = array();
        foreach ( array_slice( $results, 0, 12 ) as $movie ) {
            $item = self::map_movie( $movie, $provider );
            if ( $item ) { $items[] = $item; }
        }
        set_transient( $cache, $items, HOUR_IN_SECONDS );
        return $items;
    }
}
