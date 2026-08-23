<?php
/**
 * Migration 0231 — course completion becomes a staff certification (#2649,
 * epic #2641).
 *
 * Two changes, both additive.
 *
 * **`tt_course_enrolments.certification_id`** links a completed enrolment to
 * the `tt_staff_certifications` row it produced. Without it, re-running
 * completion would issue a second certificate, and a reopened course would
 * leave the first one standing on work that is no longer finished. The link
 * is what makes both transitions idempotent.
 *
 * **A `cert_type` lookup row for the shipped course**, with its Dutch label in
 * `tt_translations`. Note the destination: `tt_lookups.translations` was
 * dropped in migration 0087, so a seed that writes there loses the label
 * silently. This follows migration 0217, which established the shape for this
 * exact vocabulary.
 *
 * Only the shipped course is seeded here. `CourseCertificationService`
 * resolves-or-creates the lookup row for any other course at completion time,
 * so adding a course never needs a migration — this exists so the one course
 * we ship arrives with a properly translated label rather than one invented
 * on first completion.
 *
 * The course's declared `methodology_principles` are deliberately NOT stored.
 * The corpus is versioned with the plugin, so the manifest is the record;
 * copying it into a column would add a second source that can go stale. See
 * the issue for why the principles do not join to `tt_methodologies` at all.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;
use TT\Infrastructure\Logging\Logger;

return new class extends Migration {

    /** Canonical English name; the Dutch label goes to tt_translations. */
    private const CERT_NAME = 'Football periodisation';

    private const CERT_NL = 'Voetbalperiodisering';

    public function getName(): string {
        return '0231_knowledge_certification';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->linkColumn( $p );
        $this->seedCertType( $p );
    }

    private function linkColumn( string $p ): void {
        $table = $p . 'tt_course_enrolments';

        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        if ( ! MigrationHelpers::addColumnIfMissing(
            $table,
            'certification_id',
            'BIGINT UNSIGNED DEFAULT NULL',
            'completed_at'
        ) ) {
            throw new \RuntimeException( '0231: failed to add certification_id to tt_course_enrolments' );
        }
    }

    /**
     * Seed the certificate type for the shipped course.
     *
     * Existence-checked on (lookup_type, name) so a re-run is a no-op and an
     * academy that already added a type with this name keeps theirs.
     */
    private function seedCertType( string $p ): void {
        global $wpdb;

        $lookups      = $p . 'tt_lookups';
        $translations = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups ) ) !== $lookups ) {
            return;
        }

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s LIMIT 1",
            'cert_type',
            self::CERT_NAME
        ) );

        if ( $existing > 0 ) {
            Logger::info( 'migration.0231.cert_type_present', [ 'id' => $existing ] );
            return;
        }

        $max_sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( MAX(sort_order), 0 ) FROM {$lookups} WHERE lookup_type = %s",
            'cert_type'
        ) );

        $wpdb->insert( $lookups, [
            'lookup_type' => 'cert_type',
            'name'        => self::CERT_NAME,
            'description' => 'Completed the in-app course on football periodisation',
            'sort_order'  => $max_sort + 1,
            'club_id'     => 1,
        ] );

        $id = (int) $wpdb->insert_id;
        if ( $id <= 0 ) {
            Logger::info( 'migration.0231.cert_type_insert_failed', [] );
            return;
        }

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations ) ) !== $translations ) {
            Logger::info( 'migration.0231.no_translations_table', [ 'lookup_id' => $id ] );
            return;
        }

        // en_US is the canonical display value, matching the contract
        // migration 0131 set for every other lookup.
        $now = current_time( 'mysql', true );
        $written = 0;

        foreach ( [ 'en_US' => self::CERT_NAME, 'nl_NL' => self::CERT_NL ] as $locale => $value ) {
            $ok = $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO {$translations}
                   (club_id, entity_type, entity_id, field, locale, value, updated_at)
                 VALUES (%d, %s, %d, %s, %s, %s, %s)",
                1, 'lookup', $id, 'name', $locale, $value, $now
            ) );
            if ( $ok === 1 ) $written++;
        }

        Logger::info( 'migration.0231.summary', [
            'lookup_id'            => $id,
            'translations_written' => $written,
        ] );
    }
};
