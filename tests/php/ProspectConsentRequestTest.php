<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Prospects\Domain\ConsentGate;
use TT\Modules\Prospects\Domain\ConsentOutcome;
use TT\Modules\Prospects\Domain\ProspectStageClassifier;
use TT\Modules\Prospects\Repositories\ProspectConsentRequestsRepository;
use TT\Modules\Wizards\Prospect\ParentStep;
use TT\Modules\Workflow\Forms\InviteToTestTrainingForm;

/**
 * #3812 — a prospect gets a "consent requested" step with a dated log,
 * before any contact details exist.
 *
 * The state the recruitment journey actually starts in: a scout has seen a
 * child at another club and holds nothing about the family. Until this,
 * the wizard refused to create the prospect at all, the one record that
 * protects the child lived in the scout's mailbox, and an invitation could
 * go out with no consent behind it.
 *
 * Four things these tests pin: the prospect can be created with no family
 * data, the log holds no family-identifying field, the invite is a hard
 * block without consent, and an open request holds the retention clock.
 */
final class ProspectConsentRequestTest extends WP_UnitTestCase {

    private int $prospect_id;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->prospect_id = $this->seedProspect();
    }

    /** The reported case: a scout with no family details can still log the find. */
    public function test_a_prospect_can_be_created_with_no_parent_contact(): void {
        $step   = new ParentStep();
        $result = $step->validate( [], [] );

        $this->assertIsArray( $result, 'an empty parent step must be accepted, not refused' );
        $this->assertSame( '', $result['parent_email'] );
        $this->assertSame( '', $result['parent_phone'] );
    }

    /** What did NOT relax: contact details still need consent. */
    public function test_entering_contact_details_still_requires_consent(): void {
        $step   = new ParentStep();
        $result = $step->validate( [ 'parent_email' => 'ouder@example.com' ], [] );

        $this->assertInstanceOf( \WP_Error::class, $result );
        $this->assertSame( 'no_consent', $result->get_error_code() );
    }

    /** With consent ticked, the same body passes. */
    public function test_contact_details_with_consent_are_accepted(): void {
        $step   = new ParentStep();
        $result = $step->validate( [ 'parent_email' => 'ouder@example.com', 'consent_given' => '1' ], [] );

        $this->assertIsArray( $result );
        $this->assertSame( 1, $result['consent_given'] );
    }

    /** The log: date, who was asked, outcome, notes. */
    public function test_a_consent_request_is_recorded_and_read_back(): void {
        $repo = new ProspectConsentRequestsRepository();
        $id   = $repo->create(
            $this->prospect_id,
            '2026-09-01',
            'Youth coordinator at VV Voorbeeld',
            ConsentOutcome::AWAITING,
            'Left a message.'
        );
        $this->assertGreaterThan( 0, $id );

        $rows = $repo->forProspect( $this->prospect_id );
        $this->assertCount( 1, $rows );
        $this->assertSame( '2026-09-01', $rows[0]['asked_at'] );
        $this->assertSame( ConsentOutcome::AWAITING, $rows[0]['outcome'] );
    }

    /**
     * The table holds no family-identifying column. Asserted rather than
     * described: this is the rule the whole step exists to keep, and a
     * later migration adding `parent_email` here must fail loudly.
     */
    public function test_the_log_table_holds_no_family_identifying_column(): void {
        global $wpdb;
        $columns = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}tt_prospect_consent_requests" );
        $columns = array_map( 'strval', (array) $columns );

        foreach ( [ 'parent_name', 'parent_email', 'parent_phone', 'email', 'phone', 'address', 'guardian_email' ] as $forbidden ) {
            $this->assertNotContains(
                $forbidden,
                $columns,
                'the consent log records the route the academy used, never the family'
            );
        }

        $this->assertContains( 'club_id', $columns, 'tenancy scaffold, CLAUDE.md §4' );
        $this->assertContains( 'uuid', $columns, 'portable identity, CLAUDE.md §4' );
    }

    /** An unknown outcome is refused rather than stored. */
    public function test_an_unknown_outcome_is_refused(): void {
        $repo = new ProspectConsentRequestsRepository();
        $this->assertSame( 0, $repo->create( $this->prospect_id, '2026-09-01', 'Their club', 'maybe' ) );
        $this->assertSame( [], $repo->forProspect( $this->prospect_id ) );
    }

    /** The gate: no consent on record, no invitation. */
    public function test_the_invite_is_refused_without_consent(): void {
        $this->assertFalse( ConsentGate::hasConsent( $this->prospect_id ) );

        $errors = ( new InviteToTestTrainingForm() )->validate(
            [ 'new_date' => '2026-10-01T18:30', 'invitation_message' => 'Come along.' ],
            [ 'prospect_id' => $this->prospect_id ]
        );

        $this->assertArrayHasKey( '__form', $errors );
        $this->assertNotSame( '', (string) $errors['__form'] );
    }

    /** Recording an `agreed` outcome makes the same invite pass. */
    public function test_an_agreed_outcome_opens_the_invite(): void {
        ( new ProspectConsentRequestsRepository() )->create(
            $this->prospect_id,
            '2026-09-01',
            'Youth coordinator at VV Voorbeeld',
            ConsentOutcome::AGREED
        );

        $this->assertTrue( ConsentGate::hasConsent( $this->prospect_id ) );

        $errors = ( new InviteToTestTrainingForm() )->validate(
            [ 'new_date' => '2026-10-01T18:30', 'invitation_message' => 'Come along.' ],
            [ 'prospect_id' => $this->prospect_id ]
        );
        $this->assertSame( [], $errors );
    }

    /** Consent straight from the family opens it too — the older route. */
    public function test_a_consent_date_on_the_prospect_opens_the_invite(): void {
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_prospects",
            [ 'consent_given_at' => '2026-09-01 00:00:00' ],
            [ 'id' => $this->prospect_id ]
        );

        $this->assertTrue( ConsentGate::hasConsent( $this->prospect_id ) );
    }

    /** A declined request is not consent. */
    public function test_a_declined_request_is_not_consent(): void {
        ( new ProspectConsentRequestsRepository() )->create(
            $this->prospect_id,
            '2026-09-01',
            'Their club',
            ConsentOutcome::DECLINED
        );

        $this->assertFalse( ConsentGate::hasConsent( $this->prospect_id ) );
    }

    /** The new stage sits between Prospects and Invited, and below both invite rules. */
    public function test_an_open_consent_task_classifies_as_the_consent_stage(): void {
        $cutoff = time() - 90 * DAY_IN_SECONDS;

        $waiting = (object) [ 'created_at' => gmdate( 'Y-m-d H:i:s' ), 'open_consent' => 91 ];
        $this->assertSame( 'consent', ProspectStageClassifier::classify( $waiting, $cutoff ) );

        $nothing = (object) [ 'created_at' => gmdate( 'Y-m-d H:i:s' ) ];
        $this->assertSame( 'prospects', ProspectStageClassifier::classify( $nothing, $cutoff ) );

        // A stale consent task must not drag an invited prospect back.
        $invited = (object) [
            'created_at'   => gmdate( 'Y-m-d H:i:s' ),
            'open_consent' => 91,
            'done_invite'  => 1,
        ];
        $this->assertSame( 'invited', ProspectStageClassifier::classify( $invited, $cutoff ) );
    }

    private function seedProspect(): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'      => 1,
            'first_name'   => 'Consent',
            'last_name'    => 'Prospect',
            'current_club' => 'VV Voorbeeld',
        ] );
        $this->assertNotFalse( $ok, 'prospect insert must succeed' );
        return (int) $wpdb->insert_id;
    }
}
