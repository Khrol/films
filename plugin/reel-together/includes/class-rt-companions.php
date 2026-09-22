<?php
defined( 'ABSPATH' ) || exit;

final class RT_Companions {
    public static function visible() {
        global $wpdb;
        $table = RT_Store::table( 'companions' );
        $entries = RT_Store::table( 'entries' );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.* FROM $table c WHERE c.user_id=%d OR EXISTS (SELECT 1 FROM $entries e WHERE " .
            RT_Store::visibility() . " AND e.watch_company='companions' AND e.companion_ids LIKE CONCAT('%%|',c.id,'|%%')) ORDER BY c.name, c.id", get_current_user_id() ), ARRAY_A );
        return array_map( array( self::class, 'format' ), $rows );
    }

    private static function format( $row ) {
        $linked = self::active_link( $row );
        return array( 'id' => (int) $row['id'], 'name' => $row['name'], 'user_id' => (int) $row['user_id'],
            'linked_user_id' => $linked, 'linked_user_name' => $linked ? get_the_author_meta( 'display_name', $linked ) : '',
            'can_edit' => (int) $row['user_id'] === get_current_user_id(),
            'owner_name' => get_the_author_meta( 'display_name', $row['user_id'] ) );
    }

    public static function active_link( $row ) {
        $uid = (int) ( $row['linked_user_id'] ?? 0 );
        if ( ! $uid || $uid === (int) $row['user_id'] || ! get_userdata( $uid ) ) { return 0; }
        $owner = RT_Store::household( $row['user_id'] );
        $recipient = RT_Store::household( $uid );
        return $owner && $recipient && (int) $owner['id'] === (int) $recipient['id'] &&
            (int) $owner['id'] === (int) $row['linked_household_id'] ? $uid : 0;
    }

    public static function for_entry( $encoded ) {
        global $wpdb;
        $ids = array_values( array_filter( array_map( 'absint', explode( '|', $encoded ) ) ) );
        if ( ! $ids ) { return array(); }
        $rows = $wpdb->get_results( 'SELECT * FROM ' . RT_Store::table( 'companions' ) . ' WHERE id IN (' . implode( ',', $ids ) . ') ORDER BY name,id', ARRAY_A );
        return array_map( array( self::class, 'format' ), $rows );
    }

    public static function selection( $body, $existing, $status ) {
        global $wpdb;
        if ( 'watchlist' === $status ) { return array( 'watch_company' => 'unspecified', 'companion_ids' => '' ); }
        $company = $body['watch_company'] ?? ( $existing['watch_company'] ?? 'unspecified' );
        if ( ! in_array( $company, array( 'unspecified', 'alone', 'companions' ), true ) ) {
            return new WP_Error( 'rt_company', 'Choose who watched this film.', array( 'status' => 400 ) );
        }
        if ( ! array_key_exists( 'companion_ids', $body ) && 'companions' === $company && $existing ) {
            $ids = array_values( array_filter( explode( '|', $existing['companion_ids'] ) ) );
        } else { $ids = $body['companion_ids'] ?? array(); }
        if ( ! is_array( $ids ) || count( $ids ) > 20 ) {
            return new WP_Error( 'rt_company', 'Select up to 20 viewing companions.', array( 'status' => 400 ) );
        }
        foreach ( $ids as $id ) {
            if ( false === filter_var( $id, FILTER_VALIDATE_INT ) || $id < 1 ) {
                return new WP_Error( 'rt_company', 'Select valid viewing companions.', array( 'status' => 400 ) );
            }
        }
        $ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
        if ( 'companions' !== $company && $ids ) {
            return new WP_Error( 'rt_company', 'Choose “With other people” to select companions.', array( 'status' => 400 ) );
        }
        if ( 'companions' === $company ) {
            if ( ! $ids ) { return new WP_Error( 'rt_company', 'Select at least one viewing companion.', array( 'status' => 400 ) ); }
            $count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RT_Store::table( 'companions' ) .
                ' WHERE user_id=%d AND id IN (' . implode( ',', $ids ) . ')', get_current_user_id() ) );
            if ( $count !== count( $ids ) ) {
                return new WP_Error( 'rt_company', 'Select companions from your own list.', array( 'status' => 400 ) );
            }
        }
        sort( $ids, SORT_NUMERIC );
        return array( 'watch_company' => $company, 'companion_ids' => $ids ? '|' . implode( '|', $ids ) . '|' : '' );
    }

    public static function save( $request ) {
        global $wpdb;
        $id = (int) $request->get_param( 'id' );
        $uid = get_current_user_id();
        $table = RT_Store::table( 'companions' );
        $existing = $id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d AND user_id=%d", $id, $uid ), ARRAY_A ) : null;
        if ( $id && ! $existing ) {
            return new WP_Error( 'rt_companion', 'This companion is unavailable or belongs to another person.', array( 'status' => 403 ) );
        }
        $name = $request->get_param( 'name' );
        if ( ! is_string( $name ) ) { $name = ''; }
        $name = sanitize_text_field( $name );
        if ( '' === $name || RT_Store::length( $name ) > 80 ) {
            return new WP_Error( 'rt_companion', 'Enter a companion’s name or label, up to 80 characters.', array( 'status' => 400 ) );
        }
        $key = hash( 'sha256', function_exists( 'mb_strtolower' ) ? mb_strtolower( $name, 'UTF-8' ) : strtolower( $name ) );
        $duplicate = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE user_id=%d AND name_key=%s", $uid, $key ) );
        if ( $duplicate && $id && $duplicate !== $id ) {
            return new WP_Error( 'rt_companion', 'You already have a companion with that name.', array( 'status' => 409 ) );
        }
        if ( ! $id && ! $duplicate && (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE user_id=%d", $uid ) ) >= 100 ) {
            return new WP_Error( 'rt_companion', 'You can keep up to 100 viewing companions.', array( 'status' => 400 ) );
        }
        if ( ! $id && $duplicate ) {
            $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $duplicate ), ARRAY_A );
        }
        $linked = $request->get_param( 'linked_user_id' ) ?? ( $existing['linked_user_id'] ?? 0 );
        if ( false === filter_var( $linked, FILTER_VALIDATE_INT ) || $linked < 0 ) {
            return new WP_Error( 'rt_companion', 'Choose a valid household member.', array( 'status' => 400 ) );
        }
        $linked = (int) $linked;
        $household = $linked ? RT_Store::household() : null;
        $target = $linked ? RT_Store::household( $linked ) : null;
        if ( $linked && ( $linked === $uid || ! get_userdata( $linked ) || ! $household || ! $target || (int) $household['id'] !== (int) $target['id'] ) ) {
            return new WP_Error( 'rt_companion', 'Choose another current member of your household.', array( 'status' => 400 ) );
        }
        $share = $request->get_param( 'share_existing' ) ?? false;
        if ( ! is_bool( $share ) || ( $share && ! $linked ) ) {
            return new WP_Error( 'rt_companion', 'Choose a linked account before sharing earlier viewings.', array( 'status' => 400 ) );
        }
        if ( ! $id && $duplicate && ( $linked !== (int) $existing['linked_user_id'] || $share ) ) {
            return new WP_Error( 'rt_companion', 'Edit your existing companion to change their account or share earlier viewings.', array( 'status' => 409 ) );
        }
        $linked_household = $household ? (int) $household['id'] : 0;
        if ( $id && ( $linked !== (int) $existing['linked_user_id'] || $linked_household !== (int) $existing['linked_household_id'] ) ) {
            if ( false === $wpdb->delete( RT_Store::table( 'entry_shares' ), array( 'companion_id' => $id ) ) ) {
                return new WP_Error( 'rt_companion', 'Could not revoke the previous sharing. Please try again.', array( 'status' => 500 ) );
            }
        }
        $data = array( 'user_id' => $uid, 'name' => $name, 'name_key' => $key,
            'linked_user_id' => $linked, 'linked_household_id' => $linked_household );
        if ( $id ) {
            $ok = $wpdb->update( $table, $data, array( 'id' => $id, 'user_id' => $uid ) );
        } elseif ( $duplicate ) {
            $id = $duplicate; $ok = true;
        } else {
            $ok = $wpdb->insert( $table, $data );
            $id = (int) $wpdb->insert_id;
        }
        if ( false === $ok ) { return new WP_Error( 'rt_companion', 'Could not save this companion.', array( 'status' => 500 ) ); }
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id=%d", $id ), ARRAY_A );
        $shared = $share ? RT_Sharing::share_existing( $row ) : 0;
        if ( is_wp_error( $shared ) ) { return $shared; }
        return array_merge( self::format( $row ), array( 'shared_count' => $shared ) );
    }
}
