<?php
/**
 * Migration 0296 — a People record for every staff login (#4091).
 *
 * `POST trial-cases/{id}/staff` takes `person_id` only from this release,
 * and `StaffDirectory` reads People alone. An account that held a staff
 * role but was never entered in People used to come back from the
 * directory with `person_id` null and could only be put on a panel by its
 * account id; this gives each such account its record, so nobody drops
 * out of the pickers when `user_id` goes.
 *
 * The rule lives in `StaffPersonProvisioner`, which also creates the record
 * whenever an account gains a staff role from now on, so the backfill and
 * the live path cannot disagree about who counts:
 *
 *   - an account holding one of `StaffDirectory::STAFF_ROLES`;
 *   - no person row of this club holds it yet, in any state;
 *   - not bound to a player or, as a parent, to a child, and not holding
 *     the player or parent role.
 *
 * Name from the account's first and last name, else its display name;
 * `role_type` from its staff role; `wp_user_id` and `club_id` set.
 * `tt_people` has no `uuid` column, so none is written.
 *
 * Idempotent: a second run finds every staff account held and creates
 * nothing. The count created is logged.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\People\StaffPersonProvisioner;

return new class extends Migration {

    public function getName(): string {
        return '0296_backfill_staff_people';
    }

    public function up(): void {
        global $wpdb;
        $people = $wpdb->prefix . 'tt_people';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $people ) ) !== $people ) return;
        if ( ! class_exists( StaffPersonProvisioner::class ) ) return;

        $created = ( new StaffPersonProvisioner() )->backfill();

        if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
            \TT\Infrastructure\Logging\Logger::info( 'migration.0296.staff_people_backfilled', [ 'created' => $created ] );
        }
    }
};
