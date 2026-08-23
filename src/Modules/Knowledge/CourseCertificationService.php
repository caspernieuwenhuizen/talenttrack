<?php
namespace TT\Modules\Knowledge;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;

/**
 * CourseCertificationService (#2649, epic #2641) — finishing a course puts a
 * certificate on the coach's staff record.
 *
 * This is the wave that stops the knowledge library being an island, and it
 * is where CLAUDE.md §1 is satisfied rather than exempted. The player
 * question is the one `StaffCertificateExpiringAlert` already answers from
 * the other side: *every player in the squad needs the person running their
 * training to be qualified to run it.* A completion that lives only in
 * `tt_course_enrolments` cannot answer that; a row in
 * `tt_staff_certifications` can, and everything built on that table starts
 * working for free — the staff record, the PDP, the org-wide expiry roll-up
 * and the refresher alert.
 *
 * ## Both transitions, not just the happy one
 *
 * `tt_knowledge_course_completed` issues the certificate;
 * `tt_knowledge_course_reopened` archives it. The second matters more than it
 * sounds: W7 made approval reversible, so a reviewer who withdraws an
 * approval un-completes the course. Leaving the certificate standing would
 * leave a qualification on a coach's record — and in the club's expiry
 * roll-up — resting on work that has since been retracted.
 *
 * Archived rather than deleted. `tt_staff_certifications` carries
 * `archived_at` and the rest of StaffDevelopment already respects it, and a
 * certificate that was issued and then withdrawn is a fact about the
 * coaching, not a mistake to erase.
 *
 * ## Idempotent through `certification_id`
 *
 * The enrolment remembers which certificate it produced. Re-running
 * completion updates that row instead of issuing a second one, and a
 * completed → reopened → completed cycle reuses the same certificate rather
 * than leaving a trail of archived duplicates on the coach's record.
 *
 * ## The lookup row is resolved, not seeded per course
 *
 * Migration 0231 seeds the type for the one shipped course so it arrives with
 * a translated label. Any other course resolves-or-creates its type here, so
 * adding a course to the corpus never needs a migration.
 */
final class CourseCertificationService {

    /** Who the certificate is from, on a coach's record. */
    public const ISSUER = 'TalentTrack';

    private EnrolmentRepository $enrolments;

    public function __construct( ?EnrolmentRepository $enrolments = null ) {
        $this->enrolments = $enrolments ?? new EnrolmentRepository();
    }

    /** Wire the two completion transitions. */
    public static function register(): void {
        $service = new self();

        add_action( 'tt_knowledge_course_completed', [ $service, 'onCompleted' ], 10, 3 );
        add_action( 'tt_knowledge_course_reopened', [ $service, 'onReopened' ], 10, 3 );
    }

    /**
     * Issue — or refresh — the certificate for a completed course.
     *
     * @return int the certification id, or 0 when nothing was written
     */
    public function onCompleted( int $enrolment_id, string $course_slug, int $person_id ): int {
        if ( $enrolment_id <= 0 || $person_id <= 0 ) return 0;

        $manifest = CourseRegistry::get( $course_slug );
        if ( $manifest === null ) {
            // The course was withdrawn from the corpus after the coach
            // enrolled. The completion still stands — it is their record —
            // but there is no title to put on a certificate.
            Logger::info( 'knowledge.certification.course_missing', [
                'enrolment' => $enrolment_id,
                'course'    => $course_slug,
            ] );
            return 0;
        }

        $type_id = $this->certTypeId( $manifest );
        if ( $type_id <= 0 ) return 0;

        $enrolment = $this->enrolments->find( $enrolment_id );
        $existing  = $enrolment !== null && $enrolment->certification_id !== null
            ? (int) $enrolment->certification_id
            : 0;

        $issued_on  = $this->issuedOn( $enrolment );
        $expires_on = $this->expiresOn( $manifest, $issued_on );

        global $wpdb;
        $table = $wpdb->prefix . 'tt_staff_certifications';

        if ( $existing > 0 ) {
            // Un-archive and restamp. A reopened-then-recompleted course
            // lands back on the same certificate.
            $wpdb->update(
                $table,
                [
                    'cert_type_lookup_id' => $type_id,
                    'issued_on'           => $issued_on,
                    'expires_on'          => $expires_on,
                    'archived_at'         => null,
                ],
                [ 'id' => $existing, 'club_id' => CurrentClub::id() ]
            );

            Logger::info( 'knowledge.certification.refreshed', [
                'enrolment'     => $enrolment_id,
                'certification' => $existing,
            ] );

            return $existing;
        }

        $wpdb->insert( $table, [
            'club_id'             => CurrentClub::id(),
            'person_id'           => $person_id,
            'cert_type_lookup_id' => $type_id,
            'issuer'              => self::ISSUER,
            'issued_on'           => $issued_on,
            'expires_on'          => $expires_on,
        ] );

        $certification_id = (int) $wpdb->insert_id;
        if ( $certification_id <= 0 ) return 0;

        $this->enrolments->setCertification( $enrolment_id, $certification_id );

        Logger::info( 'knowledge.certification.issued', [
            'enrolment'     => $enrolment_id,
            'course'        => $course_slug,
            'person'        => $person_id,
            'certification' => $certification_id,
            'expires_on'    => $expires_on,
        ] );

        return $certification_id;
    }

    /**
     * Withdraw the certificate when the course stops being complete.
     *
     * The enrolment keeps its `certification_id`, so re-completing reuses the
     * row rather than issuing a second one.
     */
    public function onReopened( int $enrolment_id, string $course_slug, int $person_id ): void {
        if ( $enrolment_id <= 0 ) return;

        $enrolment = $this->enrolments->find( $enrolment_id );
        if ( $enrolment === null || $enrolment->certification_id === null ) return;

        global $wpdb;

        $wpdb->update(
            $wpdb->prefix . 'tt_staff_certifications',
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => (int) $enrolment->certification_id, 'club_id' => CurrentClub::id() ]
        );

        Logger::info( 'knowledge.certification.withdrawn', [
            'enrolment'     => $enrolment_id,
            'course'        => $course_slug,
            'certification' => (int) $enrolment->certification_id,
        ] );
    }

    /**
     * The `cert_type` lookup row for a course, created if absent.
     *
     * Matched on the name rather than a key column, because that is how the
     * rest of the `cert_type` vocabulary is addressed (see migration 0217) —
     * and it means an academy that already added a type with this name gets
     * their own row used instead of a duplicate appearing beside it.
     */
    private function certTypeId( CourseManifest $manifest ): int {
        global $wpdb;
        $lookups = $wpdb->prefix . 'tt_lookups';

        $name = $manifest->certificationName();

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$lookups} WHERE lookup_type = %s AND name = %s LIMIT 1",
            'cert_type',
            $name
        ) );

        if ( $existing > 0 ) return $existing;

        $max_sort = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE( MAX(sort_order), 0 ) FROM {$lookups} WHERE lookup_type = %s",
            'cert_type'
        ) );

        $wpdb->insert( $lookups, [
            'lookup_type' => 'cert_type',
            'name'        => $name,
            'description' => $manifest->summary(),
            'sort_order'  => $max_sort + 1,
            'club_id'     => CurrentClub::id(),
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * The date on the certificate.
     *
     * The enrolment's own `completed_at` where there is one, so a
     * re-issued certificate keeps the date the coach actually finished
     * rather than the date an administrator re-ran something.
     */
    private function issuedOn( ?object $enrolment ): string {
        $completed = $enrolment !== null ? (string) ( $enrolment->completed_at ?? '' ) : '';
        if ( $completed !== '' ) {
            $ts = strtotime( $completed );
            if ( $ts !== false ) return gmdate( 'Y-m-d', $ts );
        }

        return current_time( 'Y-m-d' );
    }

    /** Null unless the course declares a validity period. */
    private function expiresOn( CourseManifest $manifest, string $issued_on ): ?string {
        $months = $manifest->validForMonths();
        if ( $months <= 0 ) return null;

        $ts = strtotime( $issued_on . ' +' . $months . ' months' );

        return $ts === false ? null : gmdate( 'Y-m-d', $ts );
    }
}
