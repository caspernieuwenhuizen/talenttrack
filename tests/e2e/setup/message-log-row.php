<?php
/**
 * One row in the message log for the e2e suite (#4810).
 *
 * Run with WP-CLI from the plugin directory:
 *
 *   npx wp-env run tests-cli --env-cwd=wp-content/plugins/talenttrack \
 *       wp eval-file tests/e2e/setup/message-log-row.php
 *
 * The message log renders its table only when the log holds a row, and a
 * fresh wp-env site has sent nothing. Without one, `stack-table-a11y.spec.js`
 * would skip the message log instead of checking that its stacked table
 * keeps its column headers on a phone. The row has the shape
 * `CommsAuditLogger::record()` writes, with a held status and a hint so the
 * outcome cell carries more than one part.
 *
 * Idempotent: the row is keyed on a fixed uuid.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

global $wpdb;
$tt_e2e_table = $wpdb->prefix . 'tt_comms_log';
$tt_e2e_uuid  = '00000000-0000-4000-8000-000000004810';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tt_e2e_table ) ) !== $tt_e2e_table ) {
	fwrite( STDERR, "tt_comms_log is missing; no message log row written.\n" );
	return;
}

$tt_e2e_exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tt_e2e_table} WHERE uuid = %s", $tt_e2e_uuid ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
if ( $tt_e2e_exists > 0 ) {
	echo "Message log row already present.\n";
	return;
}

$wpdb->insert( $tt_e2e_table, [
	'club_id'           => 1,
	'uuid'              => $tt_e2e_uuid,
	'template_key'      => 'goal_nudge',
	'message_type'      => 'goal_nudge',
	'channel'           => 'email',
	'sender_user_id'    => 0,
	'recipient_kind'    => 'parent',
	'address_blob'      => 'ouder@example.org',
	'subject'           => 'Je doel loopt bijna af',
	'payload_hash'      => hash( 'sha256', 'e2e' ),
	'status'            => 'quiet_hours',
	'attempt'           => 1,
	'created_at'        => current_time( 'mysql', true ),
] );

echo $wpdb->insert_id > 0 ? "Message log row written.\n" : 'Message log row not written: ' . $wpdb->last_error . "\n";
