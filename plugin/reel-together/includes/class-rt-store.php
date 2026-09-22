<?php
defined( 'ABSPATH' ) || exit;

final class RT_Store {
    public static function table( $name ) {
        global $wpdb;
        return $wpdb->prefix . 'rt_' . $name;
    }

    public static function install() {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $tables = array(
            'companions' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                name varchar(80) NOT NULL,
                name_key varchar(64) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_name (user_id,name_key)",
            'invitation_requests' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                email_key varchar(64) NOT NULL,
                name varchar(100) NOT NULL,
                email varchar(100) NOT NULL,
                status varchar(12) NOT NULL DEFAULT 'pending',
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY email_key (email_key),
                KEY review_queue (status,created_at)",
            'households' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_id bigint(20) unsigned NOT NULL,
                name varchar(100) NOT NULL,
                invite_hash varchar(64) NOT NULL DEFAULT '',
                invite_expires datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                KEY owner_id (owner_id)",
            'members' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                household_id bigint(20) unsigned NOT NULL,
                user_id bigint(20) unsigned NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY user_id (user_id),
                KEY household_id (household_id)",
            'movies' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                movie_key varchar(64) NOT NULL,
                title varchar(200) NOT NULL,
                release_year smallint(5) unsigned NOT NULL DEFAULT 0,
                tmdb_id bigint(20) unsigned NOT NULL DEFAULT 0,
                kinopoisk_id bigint(20) unsigned NOT NULL DEFAULT 0,
                kinopoisk_rating decimal(3,1) DEFAULT NULL,
                imdb_id varchar(12) NOT NULL DEFAULT '',
                imdb_rating decimal(3,1) DEFAULT NULL,
                linked_kinopoisk_id bigint(20) unsigned NOT NULL DEFAULT 0,
                linked_imdb_id varchar(12) NOT NULL DEFAULT '',
                poster_path varchar(200) NOT NULL DEFAULT '',
                poster_url varchar(500) NOT NULL DEFAULT '',
                catalog_checked_at datetime DEFAULT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY movie_key (movie_key)",
            'entries' => "id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                user_id bigint(20) unsigned NOT NULL,
                household_id bigint(20) unsigned NOT NULL DEFAULT 0,
                movie_id bigint(20) unsigned NOT NULL,
                status varchar(12) NOT NULL,
                watched_on date DEFAULT NULL,
                rating tinyint(3) unsigned DEFAULT NULL,
                attendees varchar(200) NOT NULL DEFAULT '',
                watch_company varchar(12) NOT NULL DEFAULT 'unspecified',
                companion_ids varchar(500) NOT NULL DEFAULT '',
                notes text NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY personal (user_id,household_id,status),
                KEY household (household_id,status),
                KEY movie_id (movie_id)",
        );
        foreach ( $tables as $name => $columns ) {
            dbDelta( 'CREATE TABLE ' . self::table( $name ) . " (\n$columns\n) $charset;" );
        }
        update_option( 'rt_schema_version', RT_VERSION, false );
    }

    public static function household( $user_id = null ) {
        global $wpdb;
        $user_id = $user_id ?? get_current_user_id();
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT h.* FROM ' . self::table( 'households' ) . ' h JOIN ' . self::table( 'members' ) .
            ' m ON h.id=m.household_id WHERE m.user_id=%d', $user_id
        ), ARRAY_A );
    }

    // Every read is scoped here; personal rows never become visible through household membership.
    public static function visibility( $scope = 'all' ) {
        global $wpdb;
        $uid = get_current_user_id();
        $household = self::household();
        $personal = $wpdb->prepare( '(e.user_id=%d AND e.household_id=0)', $uid );
        $shared = $household ? $wpdb->prepare( 'e.household_id=%d', $household['id'] ) : '1=0';
        return 'personal' === $scope ? $personal : ( 'household' === $scope ? $shared : "($personal OR $shared)" );
    }

    public static function entry( $id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT e.* FROM ' . self::table( 'entries' ) . ' e WHERE e.id=%d AND ' . self::visibility(), $id
        ), ARRAY_A );
    }

    public static function format_entry( $row ) {
        foreach ( array( 'id', 'user_id', 'household_id', 'movie_id', 'release_year', 'tmdb_id', 'kinopoisk_id', 'linked_kinopoisk_id' ) as $key ) {
            $row[ $key ] = (int) $row[ $key ];
        }
        $row['rating'] = $row['rating'] ? (int) $row['rating'] : null;
        $row['kinopoisk_rating'] = null !== $row['kinopoisk_rating'] ? (float) $row['kinopoisk_rating'] : null;
        $row['imdb_rating'] = null !== $row['imdb_rating'] ? (float) $row['imdb_rating'] : null;
        $row['can_edit'] = (int) $row['user_id'] === get_current_user_id();
        $row['scope'] = $row['household_id'] ? 'household' : 'personal';
        $row['author_name'] = get_the_author_meta( 'display_name', $row['user_id'] );
        $row['companions'] = RT_Companions::for_entry( $row['companion_ids'] );
        unset( $row['companion_ids'] );
        return $row;
    }

    public static function movie( $data ) {
        global $wpdb;
        if ( ! is_array( $data ) || ! is_string( $data['title'] ?? null ) ) {
            return new WP_Error( 'rt_movie', 'Enter a movie title.', array( 'status' => 400 ) );
        }
        $tmdb = $data['tmdb_id'] ?? 0;
        $kinopoisk = $data['kinopoisk_id'] ?? 0;
        if ( false === filter_var( $tmdb, FILTER_VALIDATE_INT ) || false === filter_var( $kinopoisk, FILTER_VALIDATE_INT ) || $tmdb < 0 || $kinopoisk < 0 || ( $tmdb && $kinopoisk ) ) {
            return new WP_Error( 'rt_movie', 'Choose one valid movie source.', array( 'status' => 400 ) );
        }
        $provider = $kinopoisk ? 'kinopoisk' : 'tmdb';
        $catalog_id = $kinopoisk ?: $tmdb;
        $table = self::table( 'movies' );
        $key = $catalog_id ? hash( 'sha256', "$provider:$catalog_id" ) : null;
        $existing = $key ? $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE movie_key=%s", $key ) ) : null;
        $cached = $catalog_id ? RT_Catalog::cached_movie( $provider, $catalog_id ) : false;
        if ( $existing && ! $cached ) {
            return (int) $existing;
        }
        if ( $catalog_id && ! $cached ) {
            return new WP_Error( 'rt_movie', 'Search and select this movie again before saving it.', array( 'status' => 400 ) );
        }
        if ( $cached ) { $data = $cached; }
        $title = sanitize_text_field( $data['title'] );
        $year = $data['year'] ?? 0;
        if ( '' === $title || self::length( $title ) > 200 || false === filter_var( $year, FILTER_VALIDATE_INT ) ||
             ( 0 !== (int) $year && ( $year < 1870 || $year > (int) gmdate( 'Y' ) + 5 ) ) ||
             false === filter_var( $tmdb, FILTER_VALIDATE_INT ) || $tmdb < 0 ) {
            return new WP_Error( 'rt_movie', 'Check the title and release year.', array( 'status' => 400 ) );
        }
        $poster = is_string( $data['poster_path'] ?? null ) ? $data['poster_path'] : '';
        if ( ! preg_match( '~^/[a-zA-Z0-9]+\.(jpg|png)$~', $poster ) ) {
            $poster = '';
        }
        $links = $cached ? array() : ( $data['links'] ?? array() );
        if ( ! is_array( $links ) ) {
            return new WP_Error( 'rt_movie', 'Enter valid movie links.', array( 'status' => 400 ) );
        }
        $linked_kp = RT_Catalog::parse_id( $links['kinopoisk'] ?? '', 'kinopoisk' );
        $linked_imdb = RT_Catalog::parse_id( $links['imdb'] ?? '', 'imdb' );
        if ( is_wp_error( $linked_kp ) ) { return $linked_kp; }
        if ( is_wp_error( $linked_imdb ) ) { return $linked_imdb; }
        // Unverified links belong to the author's manual record, never shared catalog metadata.
        $suffix = $linked_kp || $linked_imdb ? ":links:$linked_kp:$linked_imdb" : '';
        $key = $key ?: hash( 'sha256', get_current_user_id() . ':' . strtolower( $title ) . ':' . $year . $suffix );
        $existing = $existing ?: $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE movie_key=%s", $key ) );
        $values = array(
            'movie_key' => $key, 'title' => $title, 'release_year' => (int) $year,
            'tmdb_id' => (int) $tmdb, 'poster_path' => $poster,
            'kinopoisk_id' => (int) $kinopoisk,
            'kinopoisk_rating' => $cached ? $cached['kinopoisk_rating'] : null,
            'imdb_id' => $cached ? ( $cached['imdb_id'] ?? '' ) : '',
            'imdb_rating' => $cached ? ( $cached['imdb_rating'] ?? null ) : null,
            'linked_kinopoisk_id' => $linked_kp,
            'linked_imdb_id' => $linked_imdb,
            'poster_url' => $cached ? $cached['poster_url'] : '',
            'catalog_checked_at' => $cached ? $cached['catalog_checked_at'] : null,
        );
        if ( $existing ) {
            if ( $cached ) { $wpdb->update( $table, $values, array( 'id' => $existing ) ); }
            return (int) $existing;
        }
        $ok = $wpdb->insert( $table, $values );
        if ( ! $ok ) {
            // A concurrent request may have inserted the same movie.
            $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE movie_key=%s", $key ) );
            return $existing ? (int) $existing : new WP_Error( 'rt_save', 'Could not save the movie.', array( 'status' => 500 ) );
        }
        return (int) $wpdb->insert_id;
    }

    public static function length( $value ) {
        return preg_match_all( '/./us', $value );
    }
}
