<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseCertificationService;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\TeamCourseCoverage;

/**
 * #2649 — completion becomes a certification.
 *
 * The wave that stops the library being an island, so the assertions that
 * matter are the ones about the *other* module's table.
 *
 * Two properties carry the weight. Completion must be idempotent — a second
 * completion may not issue a second certificate, or a coach's record fills up
 * with duplicates. And reopening must withdraw it: W7 made approval
 * reversible, so a certificate left standing after a withdrawn approval is a
 * qualification resting on retracted work, sitting in the club's expiry
 * roll-up.
 */
final class KnowledgeCertificationTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $person_id = 0;
    private int $enrolment = 0;

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgePerson::flush();
        KnowledgeModule::ensureCapabilities();

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => 'Cert',
            'last_name'  => 'Tester',
            'wp_user_id' => self::factory()->user->create( [ 'role' => 'tt_coach' ] ),
        ] );
        $this->person_id = (int) $wpdb->insert_id;

        $repo = new EnrolmentRepository();
        $repo->enrol( $this->person_id, self::COURSE );
        $row = $repo->findFor( $this->person_id, self::COURSE );
        $this->enrolment = $row !== null ? (int) $row->id : 0;
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_enrolments', 'tt_staff_certifications', 'tt_user_role_scopes' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_people" );

        KnowledgePerson::flush();
        parent::tear_down();
    }

    private function certifications(): array {
        global $wpdb;
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_staff_certifications WHERE person_id = %d ORDER BY id ASC",
            $this->person_id
        ) ) ?: [];
    }

    /* ===== issuing ===== */

    public function test_completing_a_course_writes_a_certification(): void {
        $id = ( new CourseCertificationService() )->onCompleted(
            $this->enrolment,
            self::COURSE,
            $this->person_id
        );

        $this->assertGreaterThan( 0, $id );

        $rows = $this->certifications();
        $this->assertCount( 1, $rows );
        $this->assertSame( $this->person_id, (int) $rows[0]->person_id );
        $this->assertSame( CourseCertificationService::ISSUER, (string) $rows[0]->issuer );
        $this->assertNull( $rows[0]->archived_at );
    }

    public function test_the_certification_type_is_named_after_the_course(): void {
        ( new CourseCertificationService() )->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $rows = $this->certifications();

        global $wpdb;
        $name = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_lookups WHERE id = %d",
            (int) $rows[0]->cert_type_lookup_id
        ) );

        $this->assertSame( CourseRegistry::get( self::COURSE )->certificationName(), $name );
    }

    public function test_the_enrolment_remembers_its_certification(): void {
        $id = ( new CourseCertificationService() )->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $row = ( new EnrolmentRepository() )->find( $this->enrolment );

        $this->assertSame( $id, (int) $row->certification_id );
    }

    public function test_completing_twice_does_not_issue_a_second_certificate(): void {
        $service = new CourseCertificationService();

        $first  = $service->onCompleted( $this->enrolment, self::COURSE, $this->person_id );
        $second = $service->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $this->assertSame( $first, $second );
        $this->assertCount( 1, $this->certifications() );
    }

    /* ===== withdrawing ===== */

    public function test_reopening_archives_the_certificate(): void {
        $service = new CourseCertificationService();
        $service->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $service->onReopened( $this->enrolment, self::COURSE, $this->person_id );

        $rows = $this->certifications();
        $this->assertCount( 1, $rows, 'the row stays; it is archived, not deleted' );
        $this->assertNotNull( $rows[0]->archived_at );
    }

    public function test_recompleting_revives_the_same_certificate(): void {
        $service = new CourseCertificationService();
        $service->onCompleted( $this->enrolment, self::COURSE, $this->person_id );
        $service->onReopened( $this->enrolment, self::COURSE, $this->person_id );

        $service->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $rows = $this->certifications();
        $this->assertCount( 1, $rows, 'a reopen/recomplete cycle must not leave a trail of archived duplicates' );
        $this->assertNull( $rows[0]->archived_at );
    }

    /* ===== expiry ===== */

    public function test_a_course_without_valid_for_months_never_expires(): void {
        ( new CourseCertificationService() )->onCompleted( $this->enrolment, self::COURSE, $this->person_id );

        $this->assertSame( 0, CourseRegistry::get( self::COURSE )->validForMonths() );
        $this->assertNull( $this->certifications()[0]->expires_on );
    }

    public function test_an_unknown_course_writes_nothing(): void {
        $id = ( new CourseCertificationService() )->onCompleted( $this->enrolment, 'withdrawn-course', $this->person_id );

        $this->assertSame( 0, $id );
        $this->assertCount( 0, $this->certifications() );
    }

    /* ===== the migration and the corpus must agree ===== */

    /**
     * Migration 0231 seeds the certificate type with a translated Dutch
     * label. If the manifest names a different certificate, the service
     * creates a second, untranslated lookup row beside it and the seeded
     * label is orphaned — silently, since both paths "work".
     */
    public function test_the_seeded_certificate_type_matches_the_manifest(): void {
        $declared = CourseRegistry::get( self::COURSE )->certificationName();

        global $wpdb;
        $seeded = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_lookups WHERE lookup_type = %s AND name = %s",
            'cert_type',
            $declared
        ) );

        $this->assertSame(
            1,
            $seeded,
            "migration 0231 seeds a cert_type that the manifest's certification_name must match exactly"
        );
    }

    /* ===== the team question ===== */

    public function test_team_coverage_reports_who_has_finished(): void {
        global $wpdb;

        $team_id = 4242;
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => $this->person_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $before = TeamCourseCoverage::summaryFor( $team_id, self::COURSE );
        $this->assertSame( 1, $before['total'] );
        $this->assertSame( 0, $before['done'] );

        ( new EnrolmentRepository() )->markCompleted( $this->enrolment );

        $after = TeamCourseCoverage::summaryFor( $team_id, self::COURSE );
        $this->assertSame( 1, $after['done'] );
    }

    public function test_team_coverage_lists_staff_who_never_started(): void {
        global $wpdb;

        $team_id = 4243;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_course_enrolments" );
        $wpdb->insert( $wpdb->prefix . 'tt_user_role_scopes', [
            'person_id'  => $this->person_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $rows = TeamCourseCoverage::forTeam( $team_id, self::COURSE );

        // Somebody who never enrolled is the answer to "is my staff trained",
        // not a row to leave out.
        $this->assertCount( 1, $rows );
        $this->assertSame( EnrolmentRepository::STATUS_NOT_STARTED, $rows[0]['status'] );
    }
}
