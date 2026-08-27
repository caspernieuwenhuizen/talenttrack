<?php
/**
 * Migration 0239 — `tt_invitations.sent_at` (#2964, epic #2960).
 *
 * An academy admin setting up a new install wants to add their staff,
 * check the place works, and only then send everyone their credentials.
 * That was impossible: `InvitationEmailNotifier` hooks
 * `tt_invitation_created` and dispatches the accept link the instant an
 * invitation row exists, so creating a staff member and mailing them
 * their credentials were the same irreversible action.
 *
 * WHY A TIMESTAMP RATHER THAN A NEW STATUS
 *
 * The state that was missing is "created, not yet delivered", and the
 * status vocabulary (`pending` / `accepted` / `expired` / `revoked`)
 * cannot express it — `pending` already means both "sent, awaiting
 * acceptance" and, now, "not sent at all". The obvious move is a fifth
 * status, and it is the wrong one: `InvitationStatus` describes those
 * values as "the contract between code and database; never change them",
 * they resolve through `tt_translations` via lookup rows seeded in
 * migration 0108, and every existing `!== PENDING` comparison would have
 * to be re-audited against a new member.
 *
 * A nullable timestamp adds the distinction without reopening any of
 * that: `sent_at IS NULL` means nobody has been mailed yet, and every
 * status comparison in the codebase keeps working untouched.
 *
 * BACKFILL
 *
 * Existing rows are stamped from `created_at`, because under the old
 * behaviour creation *was* delivery. Leaving them NULL would make every
 * historical invitation look unsent, and the first admin to open the list
 * would be invited to re-send credentials to people who already have them.
 *
 * Additive and idempotent.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0239_invitation_sent_at';
    }

    public function up(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tt_invitations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        // Ask before adding: addColumnIfMissing() returns true both when it
        // adds the column and when it was already there, so it cannot tell
        // us whether this is the introducing run. Backfilling on a re-run
        // would stamp rows that were deliberately left unsent.
        $is_new = ! MigrationHelpers::columnExists( $table, 'sent_at' );

        MigrationHelpers::addColumnIfMissing(
            $table,
            'sent_at',
            'DATETIME DEFAULT NULL'
        );

        if ( $is_new ) {
            $wpdb->query( "UPDATE {$table} SET sent_at = created_at WHERE sent_at IS NULL" );
        }
    }
};
