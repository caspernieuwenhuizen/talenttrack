<?php
/**
 * Migration 0318 — Report Studio reports are no longer published to the
 * generic staff persona (#4903, owner decision D-52).
 *
 * `ReportAccess::PUBLISHABLE_PERSONAS` no longer holds `staff`: a report
 * reaches the roles that work with the player or team by name. This removes
 * the publications to that persona that were made before. A report published
 * to staff and to other personas stays published to the others.
 *
 * A second run finds nothing to do. Only `staff` publications are touched.
 * Run-alone (no other migration in parallel).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    /** The persona whose publications are removed. */
    public const PERSONA = 'staff';

    public function getName(): string {
        return '0318_report_studio_unpublished_from_staff';
    }

    public function up(): void {
        global $wpdb;
        $publications = "{$wpdb->prefix}tt_report_publications";

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $publications ) ) !== $publications ) {
            $this->report( 0, 'tt_report_publications is missing' );
            return;
        }

        $removed = $this->exec( $wpdb->prepare(
            "DELETE FROM {$publications} WHERE persona_key = %s",
            self::PERSONA
        ) );
        $this->report( $removed, sprintf( '%d publication(s) to the staff persona removed', $removed ) );
    }
};
