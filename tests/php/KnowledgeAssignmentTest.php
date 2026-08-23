<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Modules\Knowledge\CourseRegistry;
use TT\Modules\Knowledge\KnowledgeModule;
use TT\Modules\Knowledge\KnowledgePerson;
use TT\Modules\Knowledge\LessonContext;
use TT\Modules\Knowledge\Repositories\EnrolmentRepository;
use TT\Modules\Knowledge\Repositories\ProgressRepository;
use TT\Modules\Knowledge\Repositories\SubmissionRepository;
use TT\Modules\Knowledge\ReviewerResolver;
use TT\Modules\Knowledge\SubmissionService;
use TT\Modules\Media\Authorization\MediaVisibilityService;
use TT\Modules\Media\MediaAttachmentPolicy;
use TT\Modules\Media\MediaEntityType;
use TT\Modules\Media\MediaKind;

/**
 * #2648 — practical assignments and the review that closes them.
 *
 * Three things here are load-bearing beyond "does it store the row".
 *
 * A verdict has to move the learner's progress in **both** directions.
 * Approving completes a lesson; withdrawing that approval has to un-complete
 * it, or a certificate stands on work a reviewer has since retracted.
 *
 * Review authority is not a capability. Work is routed to mentors, who hold
 * none, so several assertions below check that a mentor can act and that a
 * bystanding coach cannot — the case a capability-only gate gets wrong in
 * both directions.
 *
 * And a submission accepts documents only. That is a safeguarding boundary,
 * not a preference: a submission hangs off no player, so an image attached
 * to one sits outside the consent rules that govern player media.
 */
final class KnowledgeAssignmentTest extends WP_UnitTestCase {

    private const COURSE = 'voetbalperiodisering';

    private int $author_user   = 0;
    private int $author_person = 0;
    private int $mentor_user   = 0;
    private int $mentor_person = 0;
    private int $other_user    = 0;
    private int $other_person  = 0;
    private int $enrolment     = 0;
    private string $lesson     = '';

    public function set_up(): void {
        parent::set_up();
        CourseRegistry::flushCache();
        KnowledgePerson::flush();
        MediaVisibilityService::flush();

        // The bootstrap runs migrations but not capability grants, so a
        // module test that skips this gets 403 on every REST assertion.
        KnowledgeModule::ensureCapabilities();

        $this->lesson = $this->firstLessonWithAssignment();

        [ $this->author_user, $this->author_person ] = $this->makePerson( 'Author', 'Coach', 'tt_coach' );
        [ $this->mentor_user, $this->mentor_person ] = $this->makePerson( 'Mentor', 'Coach', 'tt_coach' );
        [ $this->other_user,  $this->other_person  ] = $this->makePerson( 'Other',  'Coach', 'tt_coach' );

        $enrolments = new EnrolmentRepository();
        $enrolments->enrol( $this->author_person, self::COURSE );
        $row = $enrolments->findFor( $this->author_person, self::COURSE );
        $this->enrolment = $row !== null ? (int) $row->id : 0;
    }

    public function tear_down(): void {
        global $wpdb;
        foreach ( [ 'tt_course_submissions', 'tt_course_progress', 'tt_course_enrolments', 'tt_staff_mentorships' ] as $t ) {
            $wpdb->query( "DELETE FROM {$wpdb->prefix}{$t}" );
        }
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_people" );

        LessonContext::clear();
        KnowledgePerson::flush();
        MediaVisibilityService::flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ===== submitting ===== */

    public function test_submitting_stores_the_answer_and_marks_it_pending(): void {
        $result = ( new SubmissionService() )->submit(
            $this->enrolment,
            self::COURSE,
            $this->lesson,
            'Ik heb twee nulpuntmetingen gedaan met JO13-1.'
        );

        $this->assertSame( SubmissionService::OK, $result['status'] );

        $row = ( new SubmissionRepository() )->find( $result['id'] );
        $this->assertNotNull( $row );
        $this->assertSame( SubmissionRepository::OUTCOME_PENDING, (string) $row->outcome );
        $this->assertNotNull( $row->submitted_at );
        $this->assertStringContainsString( 'nulpuntmetingen', (string) $row->body );
    }

    public function test_an_empty_answer_is_refused(): void {
        $result = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, "   \n  " );

        $this->assertSame( SubmissionService::ERR_EMPTY, $result['status'] );
        $this->assertSame( 0, ( new SubmissionRepository() )->countPending() );
    }

    public function test_the_assignment_key_comes_from_the_block_not_the_slug(): void {
        $result = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );
        $row    = ( new SubmissionRepository() )->find( $result['id'] );

        $expected = CourseRegistry::lesson( self::COURSE, $this->lesson )->assignmentKey();

        $this->assertNotSame( '', $expected, 'the corpus lesson should declare an assignment id' );
        $this->assertSame( $expected, (string) $row->assignment_key );
    }

    public function test_a_second_submission_is_refused_while_one_is_pending(): void {
        $service = new SubmissionService();
        $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Eerste poging.' );

        $second = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Tweede poging.' );

        $this->assertSame( SubmissionService::ERR_ALREADY, $second['status'] );
    }

    public function test_changes_requested_reopens_the_submission(): void {
        $service = new SubmissionService();
        $first   = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Eerste poging.' );

        $service->review(
            $first['id'],
            SubmissionRepository::OUTCOME_CHANGES,
            'Voeg de tweede meting toe.',
            $this->mentor_person
        );

        $second = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Tweede poging, nu compleet.' );

        $this->assertSame( SubmissionService::OK, $second['status'] );
        $this->assertNotSame( $first['id'], $second['id'], 'a resubmission is a new row, not an overwrite' );

        // The earlier attempt and its feedback survive: that history is the
        // record of the coaching.
        $original = ( new SubmissionRepository() )->find( $first['id'] );
        $this->assertSame( SubmissionRepository::OUTCOME_CHANGES, (string) $original->outcome );
        $this->assertStringContainsString( 'tweede meting', (string) $original->feedback );
    }

    public function test_a_rejection_does_not_reopen_the_submission(): void {
        $service = new SubmissionService();
        $first   = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Poging.' );

        $service->review(
            $first['id'],
            SubmissionRepository::OUTCOME_REJECTED,
            'Dit gaat niet over jouw eigen team.',
            $this->mentor_person
        );

        $again = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Nog een poging.' );

        $this->assertSame( SubmissionService::ERR_ALREADY, $again['status'] );
    }

    /* ===== the verdict, and progress ===== */

    public function test_approval_marks_the_lesson_assignment_complete(): void {
        $service = new SubmissionService();
        $sub     = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        $result = $service->review( $sub['id'], SubmissionRepository::OUTCOME_APPROVED, '', $this->mentor_person );

        $this->assertSame( SubmissionService::OK, $result['status'] );

        $progress = ( new ProgressRepository() )->find( $this->enrolment, $this->lesson );
        $this->assertNotNull( $progress );
        $this->assertNotNull( $progress->assignment_approved_at );
    }

    public function test_withdrawing_approval_clears_it_again(): void {
        $service = new SubmissionService();
        $first   = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );
        $service->review( $first['id'], SubmissionRepository::OUTCOME_APPROVED, '', $this->mentor_person );

        // The reviewer changes their mind on the same submission.
        $service->review(
            $first['id'],
            SubmissionRepository::OUTCOME_CHANGES,
            'Bij nader inzien mist de tweede meting.',
            $this->mentor_person
        );

        $progress = ( new ProgressRepository() )->find( $this->enrolment, $this->lesson );
        $this->assertNull(
            $progress->assignment_approved_at,
            'a withdrawn approval must not leave the lesson standing as finished'
        );
    }

    public function test_feedback_is_mandatory_on_anything_but_approval(): void {
        $service = new SubmissionService();
        $sub     = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        foreach ( [ SubmissionRepository::OUTCOME_CHANGES, SubmissionRepository::OUTCOME_REJECTED ] as $outcome ) {
            $result = $service->review( $sub['id'], $outcome, '   ', $this->mentor_person );
            $this->assertSame( SubmissionService::ERR_NO_FEEDBACK, $result['status'], $outcome );
        }

        $row = ( new SubmissionRepository() )->find( $sub['id'] );
        $this->assertSame( SubmissionRepository::OUTCOME_PENDING, (string) $row->outcome );
    }

    public function test_approval_needs_no_feedback(): void {
        $service = new SubmissionService();
        $sub     = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        $result = $service->review( $sub['id'], SubmissionRepository::OUTCOME_APPROVED, '', $this->mentor_person );

        $this->assertSame( SubmissionService::OK, $result['status'] );
    }

    public function test_an_unknown_outcome_is_refused(): void {
        $service = new SubmissionService();
        $sub     = $service->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        $result = $service->review( $sub['id'], 'maybe', 'Hmm.', $this->mentor_person );

        $this->assertSame( SubmissionService::ERR_BAD_OUTCOME, $result['status'] );
    }

    /* ===== routing ===== */

    public function test_a_submission_is_routed_to_the_authors_mentor(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );

        $result = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );
        $row    = ( new SubmissionRepository() )->find( $result['id'] );

        $this->assertSame( $this->mentor_person, (int) $row->reviewer_person_id );
    }

    public function test_without_a_mentor_a_submission_stays_unrouted(): void {
        $result = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );
        $row    = ( new SubmissionRepository() )->find( $result['id'] );

        $this->assertNull( $row->reviewer_person_id );
    }

    public function test_an_ended_mentorship_does_not_route(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person, '2020-01-01' );

        $this->assertSame( 0, ReviewerResolver::forSubmitter( $this->author_person ) );
    }

    public function test_an_unrouted_submission_is_visible_to_every_capability_holder(): void {
        ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        // A mentor of somebody else, with no management capability, still
        // sees the unrouted queue — that is what stops unrouted work being
        // invisible to everyone.
        $pending = ( new SubmissionRepository() )->listPending( $this->other_person );

        $this->assertCount( 1, $pending );
    }

    public function test_routed_work_stays_out_of_another_mentors_queue(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        $this->assertCount( 1, ( new SubmissionRepository() )->listPending( $this->mentor_person ) );
        $this->assertCount( 0, ( new SubmissionRepository() )->listPending( $this->other_person ) );
    }

    /* ===== who may review ===== */

    public function test_a_mentor_may_review_without_the_management_capability(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );

        $this->assertFalse( user_can( $this->mentor_user, 'tt_manage_knowledge' ) );
        $this->assertTrue( ReviewerResolver::isReviewer( $this->mentor_user, $this->mentor_person ) );
        $this->assertTrue( ReviewerResolver::canReview( $this->mentor_user, $this->mentor_person, $this->mentor_person ) );
    }

    public function test_a_coach_who_mentors_nobody_is_not_a_reviewer(): void {
        $this->assertFalse( ReviewerResolver::isReviewer( $this->other_user, $this->other_person ) );
    }

    public function test_a_bystander_may_not_rule_on_someone_elses_routed_work(): void {
        $this->assertFalse(
            ReviewerResolver::canReview( $this->other_user, $this->other_person, $this->mentor_person )
        );
    }

    public function test_a_manager_may_clear_anyones_queue(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->assertTrue( ReviewerResolver::canReview( $admin, 0, $this->mentor_person ) );
    }

    /* ===== attachments: documents only ===== */

    public function test_a_submission_accepts_documents_and_refuses_photographs(): void {
        $type = MediaEntityType::COURSE_SUBMISSION;

        $this->assertTrue( MediaAttachmentPolicy::allows( $type, MediaKind::DOCUMENT ) );
        $this->assertFalse( MediaAttachmentPolicy::allows( $type, MediaKind::IMAGE ) );
        $this->assertFalse( MediaAttachmentPolicy::allows( $type, MediaKind::VIDEO ) );
        $this->assertFalse( MediaAttachmentPolicy::allowsExternalLink( $type ) );
    }

    public function test_the_original_media_targets_are_unrestricted(): void {
        foreach ( [ MediaEntityType::PLAYER, MediaEntityType::TEAM, MediaEntityType::ACTIVITY ] as $type ) {
            $this->assertTrue( MediaAttachmentPolicy::allows( $type, MediaKind::IMAGE ), $type );
            $this->assertTrue( MediaAttachmentPolicy::allows( $type, MediaKind::VIDEO ), $type );
            $this->assertTrue( MediaAttachmentPolicy::allowsExternalLink( $type ), $type );
        }
    }

    public function test_the_author_and_the_reviewer_may_reach_a_submissions_attachments(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        $visibility = new MediaVisibilityService();

        $this->assertTrue(
            $visibility->canAttachTo( $this->author_user, MediaEntityType::COURSE_SUBMISSION, $sub['id'] ),
            'the coach may attach to their own coursework'
        );
        $this->assertTrue(
            $visibility->canAttachTo( $this->mentor_user, MediaEntityType::COURSE_SUBMISSION, $sub['id'] ),
            'the reviewer must be able to open what they are reviewing'
        );
    }

    public function test_a_bystander_may_not_reach_a_submissions_attachments(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        MediaVisibilityService::flush();

        $this->assertFalse(
            ( new MediaVisibilityService() )->canAttachTo(
                $this->other_user,
                MediaEntityType::COURSE_SUBMISSION,
                $sub['id']
            )
        );
    }

    /* ===== the wizard's draft ===== */

    public function test_a_draft_is_invisible_until_it_is_handed_in(): void {
        $service = new SubmissionService();
        $draft   = $service->startDraft( $this->enrolment, self::COURSE, $this->lesson );

        $this->assertSame( SubmissionService::OK, $draft['status'] );

        $service->saveDraft( $draft['id'], 'Concept.' );

        $this->assertSame( 0, ( new SubmissionRepository() )->countPending(), 'a draft is not in the queue' );
        $this->assertNull(
            ( new SubmissionRepository() )->latestFor( $this->enrolment, $this->lesson ),
            'a draft is not what the lesson shows as handed in'
        );

        $service->handInDraft( $draft['id'] );

        $this->assertSame( 1, ( new SubmissionRepository() )->countPending() );
        $this->assertNotNull( ( new SubmissionRepository() )->latestFor( $this->enrolment, $this->lesson ) );
    }

    public function test_starting_the_wizard_twice_resumes_one_draft(): void {
        $service = new SubmissionService();

        $first  = $service->startDraft( $this->enrolment, self::COURSE, $this->lesson );
        $second = $service->startDraft( $this->enrolment, self::COURSE, $this->lesson );

        $this->assertSame( $first['id'], $second['id'] );
    }

    public function test_an_empty_draft_cannot_be_handed_in(): void {
        $service = new SubmissionService();
        $draft   = $service->startDraft( $this->enrolment, self::COURSE, $this->lesson );

        $result = $service->handInDraft( $draft['id'] );

        $this->assertSame( SubmissionService::ERR_EMPTY, $result['status'] );
        $this->assertSame( 0, ( new SubmissionRepository() )->countPending() );
    }

    public function test_handing_the_same_draft_in_twice_does_not_reset_the_clock(): void {
        $service = new SubmissionService();
        $draft   = $service->startDraft( $this->enrolment, self::COURSE, $this->lesson );
        $service->saveDraft( $draft['id'], 'Concept.' );

        $this->assertSame( SubmissionService::OK, $service->handInDraft( $draft['id'] )['status'] );
        $this->assertSame( SubmissionService::ERR_NOT_FOUND, $service->handInDraft( $draft['id'] )['status'] );

        $this->assertSame( 1, ( new SubmissionRepository() )->countPending() );
    }

    /* ===== REST ===== */

    public function test_rest_submit_creates_a_submission(): void {
        wp_set_current_user( $this->author_user );

        $request = new WP_REST_Request( 'POST', "/talenttrack/v1/courses/" . self::COURSE . "/submissions/{$this->lesson}" );
        $request->set_param( 'body', 'Mijn antwoord via de API.' );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 201, $response->get_status() );
        $this->assertSame( 1, ( new SubmissionRepository() )->countPending() );
    }

    public function test_rest_submit_is_refused_for_an_anonymous_caller(): void {
        wp_set_current_user( 0 );

        $request = new WP_REST_Request( 'POST', "/talenttrack/v1/courses/" . self::COURSE . "/submissions/{$this->lesson}" );
        $request->set_param( 'body', 'Antwoord.' );

        $this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
    }

    public function test_rest_review_is_refused_to_a_non_reviewer(): void {
        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        wp_set_current_user( $this->other_user );

        $request = new WP_REST_Request( 'PATCH', "/talenttrack/v1/submissions/{$sub['id']}" );
        $request->set_param( 'outcome', SubmissionRepository::OUTCOME_APPROVED );

        // Refused at the coarse gate: this coach mentors nobody, so they are
        // not a reviewer of anything.
        $this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
    }

    public function test_rest_review_records_the_verdict_for_the_mentor(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        wp_set_current_user( $this->mentor_user );

        $request = new WP_REST_Request( 'PATCH', "/talenttrack/v1/submissions/{$sub['id']}" );
        $request->set_param( 'outcome', SubmissionRepository::OUTCOME_APPROVED );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 200, $response->get_status() );
        $this->assertSame( 0, ( new SubmissionRepository() )->countPending() );
    }

    public function test_rest_review_refuses_a_verdict_on_someone_elses_routed_work(): void {
        // The other coach mentors a third party, so they pass the coarse
        // gate — and must still be refused this particular submission.
        $this->makeMentorship( $this->other_person, $this->mentor_person );
        $this->makeMentorship( $this->mentor_person, $this->author_person );

        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        wp_set_current_user( $this->other_user );

        $request = new WP_REST_Request( 'PATCH', "/talenttrack/v1/submissions/{$sub['id']}" );
        $request->set_param( 'outcome', SubmissionRepository::OUTCOME_APPROVED );
        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
        $this->assertSame( 'not_your_review', $response->get_data()['errors'][0]['code'] ?? '' );
    }

    public function test_rest_review_requires_feedback_on_changes_requested(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        $sub = ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        wp_set_current_user( $this->mentor_user );

        $request = new WP_REST_Request( 'PATCH', "/talenttrack/v1/submissions/{$sub['id']}" );
        $request->set_param( 'outcome', SubmissionRepository::OUTCOME_CHANGES );

        $this->assertSame( 400, rest_get_server()->dispatch( $request )->get_status() );
    }

    public function test_rest_queue_lists_pending_work_for_a_reviewer(): void {
        $this->makeMentorship( $this->mentor_person, $this->author_person );
        ( new SubmissionService() )->submit( $this->enrolment, self::COURSE, $this->lesson, 'Antwoord.' );

        wp_set_current_user( $this->mentor_user );

        $response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/talenttrack/v1/submissions' ) );

        $this->assertSame( 200, $response->get_status() );
        $this->assertCount( 1, $response->get_data()['data']['submissions'] );
    }

    /* ===== helpers ===== */

    /** @return array{0:int,1:int} user id, person id */
    private function makePerson( string $first, string $last, string $role ): array {
        $user_id = self::factory()->user->create( [ 'role' => $role ] );

        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_people', [
            'club_id'    => 1,
            'first_name' => $first,
            'last_name'  => $last,
            'wp_user_id' => $user_id,
        ] );

        KnowledgePerson::flush();

        return [ $user_id, (int) $wpdb->insert_id ];
    }

    private function makeMentorship( int $mentor, int $mentee, ?string $ended_on = null ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_staff_mentorships', [
            'club_id'          => 1,
            'mentor_person_id' => $mentor,
            'mentee_person_id' => $mentee,
            'started_on'       => '2024-01-01',
            'ended_on'         => $ended_on,
            'created_by'       => 1,
        ] );
    }

    /**
     * The corpus is the fixture. Picking the first lesson that declares an
     * assignment keeps the test honest if the course is ever reordered.
     */
    private function firstLessonWithAssignment(): string {
        foreach ( CourseRegistry::lessons( self::COURSE ) as $slug => $lesson ) {
            if ( $lesson->hasAssignment() ) return (string) $slug;
        }

        $this->fail( 'the shipped course has no lesson with an assignment' );
    }
}
