<?php
/**
 * Two players with a PDP form conversation each, for `pdp-preparation.spec.js`
 * (#4979).
 *
 * Run with WP-CLI from the plugin directory:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack \
 *       wp eval-file tests/e2e/setup/pdp-preparation.php
 *
 * The player's preparation needs what a fresh wp-env site does not have: a
 * current season, the academy's conversation forms switched on, a player
 * with an account of their own, and a PDP file with a planned talk. Two
 * players, so the spec reads a fixed clock from the data instead of moving
 * one:
 *
 *  - `e2e_pop_open` (password `password`): the talk is in 5 days, inside
 *    the 14-day window, nothing entered;
 *  - `e2e_pop_locked`: the talk was yesterday, so the player's part is
 *    locked, with one score and the strongest quality entered;
 *  - `e2e_pop_coach` (Mila Trainer4980): the talk is in 5 days, for the
 *    trainer's side (#4980): the admin scores it and signs it off.
 *  - `e2e_pop_read` (Jip Reader4981) and the linked parent
 *    `e2e_pop_read_parent`: yesterday's talk, signed off here by the
 *    admin, for reading and confirming it (#4981).
 *
 * Idempotent: a second run puts both back as described, so the spec starts
 * from the same state on every run.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Pdp\Forms\PdpFormCategorySeeder;
use TT\Modules\Pdp\Forms\PdpFormRegistry;

global $wpdb;
$p    = $wpdb->prefix;
$club = 1;

// A current season covering today, unless there is one.
$season = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}tt_seasons WHERE club_id = %d AND is_current = 1 LIMIT 1", $club ) );
if ( $season <= 0 ) {
	$year = (int) gmdate( 'Y' ) - ( (int) gmdate( 'n' ) < 7 ? 1 : 0 );
	$wpdb->insert( "{$p}tt_seasons", [
		'club_id'    => $club,
		'name'       => $year . '/' . substr( (string) ( $year + 1 ), 2 ),
		'start_date' => $year . '-07-01',
		'end_date'   => ( $year + 1 ) . '-06-30',
		'is_current' => 1,
	] );
	$season = (int) $wpdb->insert_id;
}
if ( $season <= 0 ) {
	fwrite( STDERR, 'No current season could be set: ' . $wpdb->last_error . "\n" );
	exit( 1 );
}

// The academy's conversation forms.
$forms   = [ 'outfield' => 'jg4it_elite_outfield', 'goalkeeper' => 'jg4it_elite_goalkeeper' ];
$refusal = PdpFormCategorySeeder::ensure( $forms );
if ( ! is_array( $refusal ) ) {
	fwrite( STDERR, 'The conversation forms could not be switched on: ' . wp_json_encode( $refusal ) . "\n" );
	exit( 1 );
}
QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, (string) wp_json_encode( $forms ) );

// No team: the preparation needs none, and a team here would change what
// other specs find on a fresh site (the attendance grid draws its date range
// only once a team exists).

/**
 * One player with an account, a PDP file in the current season and its
 * first talk, put back to `$scheduled` with only `$entries` on it.
 *
 * @param array{scores?:array<string,int>, answers?:array<string,string>} $entries
 */
$tt_e2e_player = static function ( string $login, string $first, int $scheduled, array $entries, string $last = 'E2E' ) use ( $wpdb, $p, $club, $season ): int {
	$user = get_user_by( 'login', $login );
	$uid  = $user ? (int) $user->ID : (int) wp_insert_user( [
		'user_login' => $login,
		'user_pass'  => 'password',
		'user_email' => $login . '@example.test',
		'role'       => 'tt_player',
	] );
	if ( $uid <= 0 ) {
		fwrite( STDERR, "Account {$login} could not be made.\n" );
		exit( 1 );
	}

	$player = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}tt_players WHERE club_id = %d AND wp_user_id = %d", $club, $uid ) );
	if ( $player <= 0 ) {
		$wpdb->insert( "{$p}tt_players", [
			'club_id' => $club, 'first_name' => $first, 'last_name' => $last,
			'status' => 'active', 'wp_user_id' => $uid, 'preferred_positions' => '["CB"]',
		] );
		$player = (int) $wpdb->insert_id;
	}

	$file = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}tt_pdp_files WHERE club_id = %d AND player_id = %d AND season_id = %d", $club, $player, $season ) );
	if ( $file <= 0 ) {
		$wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => $club, 'player_id' => $player, 'season_id' => $season, 'status' => 'open' ] );
		$file = (int) $wpdb->insert_id;
	}

	$conv = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}tt_pdp_conversations WHERE club_id = %d AND pdp_file_id = %d AND sequence = 1", $club, $file ) );
	if ( $conv <= 0 ) {
		$wpdb->insert( "{$p}tt_pdp_conversations", [ 'club_id' => $club, 'pdp_file_id' => $file, 'sequence' => 1, 'template_key' => 'start' ] );
		$conv = (int) $wpdb->insert_id;
	}
	if ( $player <= 0 || $file <= 0 || $conv <= 0 ) {
		fwrite( STDERR, "The PDP fixture for {$login} was not written: {$wpdb->last_error}\n" );
		exit( 1 );
	}

	// Back to the state the spec starts from.
	foreach ( [ 'tt_pdp_form_scores', 'tt_pdp_form_answers', 'tt_pdp_form_goals' ] as $table ) {
		$wpdb->delete( $p . $table, [ 'conversation_id' => $conv ] );
	}
	$wpdb->update( "{$p}tt_pdp_conversations", [
		'scheduled_at'       => gmdate( 'Y-m-d H:i:s', $scheduled ),
		'player_prepared_at' => null,
		'form_key'           => $entries === [] ? null : 'jg4it_elite_outfield',
		'form_version'       => $entries === [] ? null : 1,
		'coach_signoff_at'   => null,
		'conducted_at'       => null,
		'not_held_at'        => null,
	], [ 'id' => $conv ] );

	$now = current_time( 'mysql', true );
	foreach ( $entries['scores'] ?? [] as $item => $score ) {
		$wpdb->insert( "{$p}tt_pdp_form_scores", [
			'uuid' => wp_generate_uuid4(), 'club_id' => $club, 'conversation_id' => $conv, 'form_key' => 'jg4it_elite_outfield',
			'item_key' => $item, 'author' => 'player', 'score' => $score, 'updated_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
		] );
	}
	foreach ( $entries['answers'] ?? [] as $field => $body ) {
		$wpdb->insert( "{$p}tt_pdp_form_answers", [
			'uuid' => wp_generate_uuid4(), 'club_id' => $club, 'conversation_id' => $conv, 'form_key' => 'jg4it_elite_outfield',
			'field_key' => $field, 'author' => 'player', 'body' => $body, 'updated_by' => $uid, 'created_at' => $now, 'updated_at' => $now,
		] );
	}
	echo "PDP preparation fixture for {$login}: conversation {$conv}.\n";
	return $conv;
};

$tt_e2e_player( 'e2e_pop_open', 'Sam', time() + 5 * DAY_IN_SECONDS, [] );
$tt_e2e_player( 'e2e_pop_locked', 'Noor', time() - DAY_IN_SECONDS, [
	'scores'  => [ 'aannames' => 4 ],
	'answers' => [ 'strongest_quality' => 'Mijn kopbal bij corners' ],
] );
// #4980 — a talk the trainer fills in and signs off, found by its last name.
$tt_e2e_player( 'e2e_pop_coach', 'Mila', time() + 5 * DAY_IN_SECONDS, [], 'Trainer4980' );

// #4981 — a talk the trainer has signed off, for the player and the parent to
// read and confirm. The player did not prepare (one score saved, never marked
// ready), so their column reads "not filled in". The parent is linked, and
// the child shares the PDP.
$tt_e2e_read = $tt_e2e_player( 'e2e_pop_read', 'Jip', time() - DAY_IN_SECONDS, [ 'scores' => [ 'aannames' => 4 ] ], 'Reader4981' );
$tt_e2e_kid  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT player_id FROM {$p}tt_pdp_files f JOIN {$p}tt_pdp_conversations c ON c.pdp_file_id = f.id WHERE c.id = %d", $tt_e2e_read ) );
$tt_e2e_mum  = get_user_by( 'login', 'e2e_pop_read_parent' );
$tt_e2e_mum  = $tt_e2e_mum ? (int) $tt_e2e_mum->ID : (int) wp_insert_user( [
	'user_login' => 'e2e_pop_read_parent',
	'user_pass'  => 'password',
	'user_email' => 'e2e_pop_read_parent@example.test',
	'role'       => 'tt_parent',
] );
if ( $tt_e2e_kid <= 0 || $tt_e2e_mum <= 0 ) {
	fwrite( STDERR, "The #4981 reader fixture could not be made.\n" );
	exit( 1 );
}
( new \TT\Modules\Invitations\PlayerParentsRepository() )->link( $tt_e2e_kid, $tt_e2e_mum, true );

$tt_e2e_admin   = 1;
$tt_e2e_entries = new \TT\Modules\Pdp\Forms\PdpFormEntriesRepository();
$tt_e2e_def     = (array) PdpFormRegistry::definition( 'jg4it_elite_outfield' );
foreach ( PdpFormRegistry::items( $tt_e2e_def ) as $tt_e2e_item ) {
	$tt_e2e_entries->saveScore( $tt_e2e_read, 'jg4it_elite_outfield', (string) $tt_e2e_item['key'], 'coach', 3, $tt_e2e_admin );
}
$tt_e2e_entries->saveAnswer( $tt_e2e_read, 'jg4it_elite_outfield', 'strongest_quality', 'coach', 'Afgesproken: het overzicht in de opbouw.', $tt_e2e_admin );
foreach ( [ 1, 2 ] as $tt_e2e_slot ) {
	$tt_e2e_entries->saveGoal( $tt_e2e_read, $tt_e2e_slot, [
		'action_text'  => "Ontwikkelpunt {$tt_e2e_slot}: twee keer per training scannen voor de aanname.",
		'support_text' => 'Een oefenvorm met coaching op het kijken.',
		'due_date'     => gmdate( 'Y-m-d', time() + 60 * DAY_IN_SECONDS ),
	], $tt_e2e_admin );
}
$wpdb->update( "{$p}tt_pdp_conversations", [ 'player_ack_at' => null, 'parent_ack_at' => null ], [ 'id' => $tt_e2e_read ] );
$tt_e2e_conv   = ( new \TT\Modules\Pdp\Repositories\PdpConversationsRepository() )->find( $tt_e2e_read );
$tt_e2e_signed = $tt_e2e_conv ? \TT\Modules\Pdp\Forms\PdpFormSignoff::signOff( $tt_e2e_admin, $tt_e2e_conv, $tt_e2e_kid ) : null;
if ( ! is_array( $tt_e2e_signed ) ) {
	fwrite( STDERR, 'The #4981 talk could not be signed off: ' . wp_json_encode( $tt_e2e_signed instanceof \WP_Error ? $tt_e2e_signed->get_error_messages() : $tt_e2e_signed ) . "\n" );
	exit( 1 );
}
echo "PDP reader fixture: conversation {$tt_e2e_read} signed off.\n";
