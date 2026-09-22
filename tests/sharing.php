<?php
$link_author = new_user( 'link_author' ); $link_wife = new_user( 'link_wife' );
$link_son = new_user( 'link_son' ); $link_outsider = new_user( 'link_outsider' );
call_api( $link_author, 'POST', 'household', array( 'name' => 'Linked family' ) );
$link_code = call_api( $link_author, 'POST', 'household/invite', array() )->get_data()['code'];
foreach ( array( $link_wife, $link_son ) as $member ) { call_api( $member, 'POST', 'household/join', array( 'code' => $link_code ) ); }
$label = call_api( $link_author, 'POST', 'companions', array( 'name' => 'My wife' ) )->get_data()['id'];
$son_label = call_api( $link_author, 'POST', 'companions', array( 'name' => 'My elder son', 'linked_user_id' => $link_son ) )->get_data()['id'];
$linked_body = viewing( 'Linked private viewing' );
$linked_body['watch_company'] = 'companions'; $linked_body['companion_ids'] = array( $label, $son_label );
$private_link_id = call_api( $link_author, 'POST', 'entries', $linked_body )->get_data()['id'];
$household_body = $linked_body; $household_body['scope'] = 'household'; $household_body['movie']['title'] = 'Linked household viewing';
$household_link_id = call_api( $link_author, 'POST', 'entries', $household_body )->get_data()['id'];
call_api( $link_author, 'POST', 'entries', viewing( 'Unrelated household viewing', 'household' ) );
call_api( $link_author, 'POST', 'entries', viewing( 'Unrelated private viewing' ) );

function linked_entries( $uid, $scope = 'mine', $query = '' ) {
    return call_api( $uid, 'GET', 'entries', null, array( 'scope' => $scope, 'q' => $query ) )->get_data();
}
check( linked_entries( $link_wife )['total'] === 0 && linked_entries( $link_wife, 'all' )['total'] === 2, 'My viewings excludes household entries without a participant link' );
foreach ( array( $link_author, $link_outsider, 'bad', -1 ) as $invalid_link ) {
    check( call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My wife', 'linked_user_id' => $invalid_link ) )->get_status() === 400, 'Rejects self, outside-household and malformed account links: ' . $invalid_link );
}
check( call_api( $link_wife, 'PUT', "companions/$label", array( 'name' => 'My wife', 'linked_user_id' => $link_son ) )->get_status() === 403, 'Other household members cannot change an author’s companion link' );
$linked = call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My wife', 'linked_user_id' => $link_wife ) )->get_data();
check( $linked['linked_user_id'] === $link_wife && $linked['linked_user_name'] === 'Link_wife', 'Companion labels optionally resolve to a household account' );
check( linked_entries( $link_wife )['total'] === 1 && linked_entries( $link_wife, 'all' )['total'] === 2, 'Linking includes earlier household viewings in the recipient’s diary but leaves personal ones private' );
check( linked_entries( $link_wife )['items'][0]['is_my_viewing'] && ! linked_entries( $link_wife )['items'][0]['can_edit'], 'A linked participant gets a read-only viewing attributed to its author' );
check( call_api( $link_wife, 'GET', 'bootstrap' )->get_data()['my_watched_count'] === 1, 'The diary count includes linked participation only for accessible viewings' );
check( call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner' ) )->get_data()['linked_user_id'] === $link_wife, 'Renaming preserves the optional account link' );
check( call_api( $link_author, 'POST', 'companions', array( 'name' => 'My partner', 'linked_user_id' => $link_son ) )->get_status() === 409, 'Duplicate-label creation cannot silently replace an existing account link' );

$explicit = $linked_body; $explicit['scope'] = 'linked'; $explicit['shared_companion_ids'] = array( $label );
check( call_api( $link_author, 'PUT', "entries/$private_link_id", $explicit )->get_status() === 200, 'The author can explicitly share a personal viewing with one selected linked account' );
$received = linked_entries( $link_wife, 'mine', 'Linked private' )['items'][0];
check( $received['id'] === $private_link_id && $received['notes'] === $explicit['notes'] && $received['rating'] === 4 && $received['scope'] === 'linked' && $received['author_name'] === 'Link_author', 'Sharing exposes the original viewing, notes and author’s rating without making a duplicate' );
check( linked_entries( $link_son, 'all', 'Linked private' )['total'] === 0 && linked_entries( $link_outsider, 'all' )['total'] === 0, 'Sharing a personal viewing excludes other tagged family members unless explicitly selected' );
check( linked_entries( $link_author, 'personal', 'Linked private' )['total'] === 0, 'Explicitly shared entries are no longer labeled Just for me' );
check( call_api( $link_wife, 'PUT', "entries/$private_link_id", $explicit )->get_status() === 403 && call_api( $link_wife, 'DELETE', "entries/$private_link_id" )->get_status() === 403, 'A recipient cannot edit or delete an author’s shared personal entry' );
$unselected = call_api( $link_author, 'POST', 'companions', array( 'name' => 'Another label', 'linked_user_id' => $link_wife ) )->get_data()['id'];
$invalid = $explicit; $invalid['shared_companion_ids'] = array( $unselected );
check( call_api( $link_author, 'PUT', "entries/$private_link_id", $invalid )->get_status() === 400, 'A sharing recipient must be tagged on the viewing' );
$invalid['shared_companion_ids'] = array();
check( call_api( $link_author, 'PUT', "entries/$private_link_id", $invalid )->get_status() === 400, 'Linked sharing requires an explicit recipient' );
$invalid['shared_companion_ids'] = array( '1 OR 1=1' );
check( call_api( $link_author, 'PUT', "entries/$private_link_id", $invalid )->get_status() === 400, 'Sharing rejects malformed recipient IDs' );
$legacy_edit = $explicit; unset( $legacy_edit['shared_companion_ids'], $legacy_edit['watch_company'], $legacy_edit['companion_ids'] );
$legacy_edit['notes'] = 'An updated shared note';
call_api( $link_author, 'PUT', "entries/$private_link_id", $legacy_edit );
check( linked_entries( $link_wife, 'mine', 'Linked private' )['items'][0]['notes'] === 'An updated shared note' && linked_entries( $link_son, 'all', 'Linked private' )['total'] === 0, 'Editing without sharing fields preserves existing grants without adding tagged recipients' );
call_api( $link_author, 'PUT', "entries/$private_link_id", $linked_body );
check( linked_entries( $link_wife, 'all', 'Linked private' )['total'] === 0 && linked_entries( $link_author, 'personal', 'Linked private' )['total'] === 1, 'Switching back to Just for me revokes personal sharing' );

check( call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'share_existing' => 'true' ) )->get_status() === 400, 'Bulk sharing requires explicit boolean consent' );
$bulk = call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'share_existing' => true ) )->get_data();
check( $bulk['shared_count'] === 1 && linked_entries( $link_wife )['total'] === 2, 'Explicit bulk consent includes earlier tagged personal viewings' );
check( linked_entries( $link_wife, 'all', 'Unrelated private' )['total'] === 0 && linked_entries( $link_son, 'all', 'Linked private' )['total'] === 0, 'Bulk sharing affects only tagged viewings and the selected account' );
check( call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'share_existing' => true ) )->get_data()['shared_count'] === 0, 'Repeated bulk sharing is idempotent' );
$later_private = call_api( $link_author, 'POST', 'entries', $linked_body )->get_data()['id'];
check( linked_entries( $link_wife, 'mine', 'Linked private' )['total'] === 1, 'Bulk consent does not silently share future personal viewings' );
call_api( $link_author, 'DELETE', "entries/$later_private" );
call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'linked_user_id' => $link_son ) );
check( linked_entries( $link_wife )['total'] === 0 && linked_entries( $link_son, 'all', 'Linked private' )['total'] === 0, 'Remapping a companion revokes the prior grant without passing personal entries to the new account' );
check( linked_entries( $link_son )['total'] === 1, 'Two labels linked to the same person do not duplicate a household viewing' );
call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'linked_user_id' => $link_wife ) );
check( linked_entries( $link_wife, 'all', 'Linked private' )['total'] === 0, 'Relinking does not reactivate earlier personal grants' );

$both = $explicit; $both['shared_companion_ids'] = array( $label, $son_label );
call_api( $link_author, 'PUT', "entries/$private_link_id", $both );
check( linked_entries( $link_wife, 'mine', 'Linked private' )['total'] === 1 && linked_entries( $link_son, 'mine', 'Linked private' )['total'] === 1, 'Multiple selected linked companions can each see the same personal viewing' );
$both['companion_ids'] = array( $son_label ); $both['shared_companion_ids'] = array( $son_label );
call_api( $link_author, 'PUT', "entries/$private_link_id", $both );
check( linked_entries( $link_wife, 'all', 'Linked private' )['total'] === 0 && linked_entries( $link_son, 'mine', 'Linked private' )['total'] === 1, 'Removing a companion and recipient revokes only their access' );
call_api( $link_author, 'PUT', "entries/$private_link_id", $explicit );
call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'linked_user_id' => 0 ) );
check( linked_entries( $link_wife )['total'] === 0, 'Unlinking a companion revokes their personal grants and participant identity' );
call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'linked_user_id' => $link_wife, 'share_existing' => true ) );
call_api( $link_wife, 'DELETE', 'household/membership' );
check( linked_entries( $link_wife, 'all' )['total'] === 0, 'Leaving the household revokes both household and directly shared personal access' );
call_api( $link_wife, 'POST', 'household/join', array( 'code' => $link_code ) );
check( linked_entries( $link_wife )['total'] === 0, 'Rejoining does not silently restore companion links or personal access' );
call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'linked_user_id' => $link_wife, 'share_existing' => true ) );
call_api( $link_author, 'DELETE', "household/members/$link_wife" );
check( linked_entries( $link_wife, 'all' )['total'] === 0, 'Removing a household member revokes their linked personal access' );
check( call_api( $link_author, 'PUT', "companions/$label", array( 'name' => 'My partner', 'share_existing' => true ) )->get_status() === 400, 'A removed member cannot receive further bulk sharing without a valid account link' );
call_api( $link_author, 'DELETE', "entries/$private_link_id" );
check( (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . RT_Store::table( 'entry_shares' ) . ' WHERE entry_id=%d', $private_link_id ) ) === 0, 'Deleting a viewing cleans up its sharing grants' );
