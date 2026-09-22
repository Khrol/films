<?php
defined( 'ABSPATH' ) || exit;

final class RT_Sharing {
    // SQL clauses use the caller's entry alias e and recheck both household memberships.
    public static function participant_condition( $uid ) {
        global $wpdb;
        $companions = RT_Store::table( 'companions' );
        $members = RT_Store::table( 'members' );
        return $wpdb->prepare( "(e.status='watched' AND e.watch_company='companions' AND EXISTS (
            SELECT 1 FROM $companions c JOIN $members a ON a.user_id=c.user_id AND a.household_id=c.linked_household_id
            JOIN $members b ON b.user_id=c.linked_user_id AND b.household_id=c.linked_household_id
            WHERE c.user_id=e.user_id AND c.linked_user_id=%d AND e.companion_ids LIKE CONCAT('%%|',c.id,'|%%')
            AND (e.household_id=0 OR e.household_id=c.linked_household_id)))", $uid );
    }

    public static function grant_condition( $uid = null ) {
        global $wpdb;
        $shares = RT_Store::table( 'entry_shares' );
        $companions = RT_Store::table( 'companions' );
        $members = RT_Store::table( 'members' );
        $recipient = null === $uid ? '' : $wpdb->prepare( ' AND s.user_id=%d', $uid );
        return "(e.household_id=0 AND e.status='watched' AND e.watch_company='companions' AND EXISTS (
            SELECT 1 FROM $shares s JOIN $companions c ON c.id=s.companion_id AND c.user_id=e.user_id
                AND c.linked_user_id=s.user_id AND c.linked_household_id=s.household_id
            JOIN $members a ON a.user_id=c.user_id AND a.household_id=s.household_id
            JOIN $members b ON b.user_id=s.user_id AND b.household_id=s.household_id
            WHERE s.entry_id=e.id AND e.companion_ids LIKE CONCAT('%|',c.id,'|%') $recipient))";
    }

    public static function shared_ids( $entry_id ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT s.companion_id FROM ' . RT_Store::table( 'entry_shares' ) . ' s JOIN ' . RT_Store::table( 'entries' ) .
            ' e ON e.id=s.entry_id WHERE e.id=%d AND ' . self::grant_condition_for_share(), $entry_id ) );
        return array_map( 'intval', $ids );
    }

    private static function grant_condition_for_share() {
        $companions = RT_Store::table( 'companions' );
        $members = RT_Store::table( 'members' );
        return "e.household_id=0 AND e.status='watched' AND e.watch_company='companions' AND EXISTS (
            SELECT 1 FROM $companions c JOIN $members a ON a.user_id=c.user_id AND a.household_id=c.linked_household_id
            JOIN $members b ON b.user_id=c.linked_user_id AND b.household_id=c.linked_household_id
            WHERE c.id=s.companion_id AND c.user_id=e.user_id AND c.linked_user_id=s.user_id
            AND c.linked_household_id=s.household_id AND e.companion_ids LIKE CONCAT('%%|',c.id,'|%%'))";
    }

    public static function validate_recipients( $ids, $company ) {
        global $wpdb;
        if ( ! is_array( $ids ) || ! count( $ids ) || count( $ids ) > 20 ) {
            return new WP_Error( 'rt_share', 'Select at least one linked companion to share this viewing with.', array( 'status' => 400 ) );
        }
        $recipients = array();
        foreach ( $ids as $id ) {
            if ( false === filter_var( $id, FILTER_VALIDATE_INT ) || $id < 1 || ! str_contains( $company['companion_ids'], '|' . (int) $id . '|' ) ) {
                return new WP_Error( 'rt_share', 'Share only with companions selected for this viewing.', array( 'status' => 400 ) );
            }
            $companion = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . RT_Store::table( 'companions' ) . ' WHERE id=%d AND user_id=%d', $id, get_current_user_id() ), ARRAY_A );
            if ( ! $companion || ! RT_Companions::active_link( $companion ) ) {
                return new WP_Error( 'rt_share', 'Link this companion to a current household member before sharing.', array( 'status' => 400 ) );
            }
            $recipients[ (int) $id ] = $companion;
        }
        return $recipients;
    }

    public static function replace( $entry_id, $recipients ) {
        global $wpdb;
        $table = RT_Store::table( 'entry_shares' );
        if ( false === $wpdb->delete( $table, array( 'entry_id' => $entry_id ) ) ) { return false; }
        foreach ( $recipients as $companion ) {
            if ( false === $wpdb->insert( $table, array( 'entry_id' => $entry_id, 'companion_id' => $companion['id'],
                'user_id' => $companion['linked_user_id'], 'household_id' => $companion['linked_household_id'] ) ) ) { return false; }
        }
        return true;
    }

    public static function share_existing( $companion ) {
        global $wpdb;
        $entries = RT_Store::table( 'entries' );
        $shares = RT_Store::table( 'entry_shares' );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT e.id FROM $entries e WHERE e.user_id=%d AND e.household_id=0
            AND e.status='watched' AND e.watch_company='companions' AND e.companion_ids LIKE %s
            AND NOT EXISTS (SELECT 1 FROM $shares s WHERE s.entry_id=e.id AND s.companion_id=%d)",
            get_current_user_id(), '%|' . (int) $companion['id'] . '|%', $companion['id'] ) );
        foreach ( $ids as $id ) {
            if ( false === $wpdb->insert( $shares, array( 'entry_id' => $id, 'companion_id' => $companion['id'],
                'user_id' => $companion['linked_user_id'], 'household_id' => $companion['linked_household_id'] ) ) ) {
                return new WP_Error( 'rt_share', 'Some earlier viewings could not be shared. Please try again.', array( 'status' => 500 ) );
            }
        }
        return count( $ids );
    }

    public static function unlink_member( $user_id, $household_id ) {
        global $wpdb;
        $companions = RT_Store::table( 'companions' );
        $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM $companions WHERE linked_household_id=%d AND (user_id=%d OR linked_user_id=%d)", $household_id, $user_id, $user_id ) );
        foreach ( $ids as $id ) {
            $wpdb->delete( RT_Store::table( 'entry_shares' ), array( 'companion_id' => $id ) );
            $wpdb->update( $companions, array( 'linked_user_id' => 0, 'linked_household_id' => 0 ), array( 'id' => $id ) );
        }
    }
}
