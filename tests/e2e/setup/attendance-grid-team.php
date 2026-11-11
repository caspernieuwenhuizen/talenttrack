<?php
/**
 * One team for the e2e suite (#5035).
 *
 * Run with WP-CLI from the plugin directory:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack \
 *       wp eval-file tests/e2e/setup/attendance-grid-team.php
 *
 * The attendance grid draws its filter bar, and with it the period control
 * and its custom date range, only once the academy has a team. A fresh
 * wp-env site has none, so `filterbar.spec.js`'s guard for the phone
 * sheet's Apply on a bare filter bar (#3321) skipped itself on every run.
 * With this team it runs.
 *
 * Idempotent: the team is keyed on its name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;
$tt_e2e_table = $wpdb->prefix . 'tt_teams';
$tt_e2e_name  = 'E2E Grid O13';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tt_e2e_table ) ) !== $tt_e2e_table ) {
	// Fail the setup step rather than let the spec skip again in silence.
	fwrite( STDERR, "tt_teams is missing; no team written.\n" );
	exit( 1 );
}

$tt_e2e_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt_e2e_table} WHERE club_id = 1 AND name = %s", $tt_e2e_name ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $tt_e2e_exists > 0 ) {
	echo "Attendance grid team already present.\n";
	return;
}

$tt_e2e_ok = $wpdb->insert( $tt_e2e_table, [
	'club_id' => 1,
	'uuid'    => wp_generate_uuid4(),
	'name'    => $tt_e2e_name,
] );
if ( $tt_e2e_ok === false ) {
	fwrite( STDERR, 'The attendance grid team could not be written: ' . $wpdb->last_error . "\n" );
	exit( 1 );
}
echo "Attendance grid team written.\n";
