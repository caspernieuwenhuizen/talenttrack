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
$tt_e2e_player = static function ( string $login, string $first, int $scheduled, array $entries, string $last = 'E2E' ) use ( $wpdb, $p, $club, $season ): void {
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
};

$tt_e2e_player( 'e2e_pop_open', 'Sam', time() + 5 * DAY_IN_SECONDS, [] );
$tt_e2e_player( 'e2e_pop_locked', 'Noor', time() - DAY_IN_SECONDS, [
	'scores'  => [ 'aannames' => 4 ],
	'answers' => [ 'strongest_quality' => 'Mijn kopbal bij corners' ],
] );
// #4980 — a talk the trainer fills in and signs off, found by its last name.
$tt_e2e_player( 'e2e_pop_coach', 'Mila', time() + 5 * DAY_IN_SECONDS, [], 'Trainer4980' );
