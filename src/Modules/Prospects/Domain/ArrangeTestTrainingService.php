<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * ArrangeTestTrainingService (#3932) — attach a test training to the
 * prospect it was arranged for, from the direct route.
 *
 * #3710 gave a `tt_invite_prospects` holder a deep link from the pipeline
 * straight to the New test training form, on the reasoning that somebody
 * who may issue the invitation does not need to address a task to
 * themselves. The form had no prospect on it, so the training they
 * created was not linked to the child they were looking at — the head of
 * development's route produced an orphan session and the prospect stayed
 * where they were.
 *
 * There is no join table: a prospect is linked to a test training through
 * a completed `invite_to_test_training` task carrying the prospect and,
 * in its response, the session id. That is what the stage classifier
 * reads, and it is why this service writes the same record rather than
 * inventing a second shape for the same fact.
 *
 * The task is addressed to the person doing it. They are not asking
 * anybody's permission — that is the whole point of the direct route —
 * so a task addressed to somebody else would be a fiction, and the
 * pipeline would show an invitation waiting on a person who was never
 * involved.
 */
class ArrangeTestTrainingService {

    /**
     * May this user arrange a test training for this prospect?
     *
     * Three things: the capability that #3869 made real, sight of this
     * particular child, and a prospect still in the funnel. Archived and
     * promoted prospects are past the point where a first visit means
     * anything.
     */
    public static function canArrange( int $user_id, int $prospect_id ): bool {
        if ( $user_id <= 0 || $prospect_id <= 0 ) return false;
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_invite_prospects' ) ) return false;
        if ( ! ProspectScope::canSee( $user_id, $prospect_id ) ) return false;

        $found = ( new ProspectsRepository() )->find( $prospect_id );
        if ( $found === null ) return false;

        $row = (array) $found;
        if ( ! empty( $row['archived_at'] ) ) return false;
        if ( (int) ( $row['promoted_to_player_id'] ?? 0 ) > 0 ) return false;
        if ( (int) ( $row['promoted_to_trial_case_id'] ?? 0 ) > 0 ) return false;

        return true;
    }

    /**
     * Why this user may not arrange one, or null when they may.
     *
     * Deliberately vague about *which* of the reasons applies when the
     * prospect is out of reach: a caller who may not see a child must not
     * learn from the wording that the id exists. The consent refusal is
     * the one that says something concrete, because the person reading it
     * can act on it.
     */
    public static function refusalFor( int $user_id, int $prospect_id ): ?\WP_Error {
        if ( ! self::canArrange( $user_id, $prospect_id ) ) {
            return new \WP_Error(
                'cannot_arrange',
                __( 'You cannot arrange a test training for this prospect.', 'talenttrack' )
            );
        }
        if ( ! ConsentGate::hasConsent( $prospect_id ) ) {
            return new \WP_Error( 'no_consent', ConsentGate::missingConsentMessage() );
        }
        return null;
    }

    /**
     * Record that this prospect was invited to this test training.
     *
     * Reuses the invite task already open for the prospect when there is
     * one, so the head of development who arranges the session from the
     * board does not leave their own task sitting behind them. Otherwise
     * it writes one and completes it in the same breath — completion is
     * what the classifier reads, and what spawns the parent-confirmation
     * follow-up.
     *
     * @return int|\WP_Error The completed task's id.
     */
    public static function link( int $user_id, int $prospect_id, int $test_training_id ) {
        $refusal = self::refusalFor( $user_id, $prospect_id );
        if ( $refusal !== null ) return $refusal;

        if ( $test_training_id <= 0 ) {
            return new \WP_Error(
                'no_test_training',
                __( 'There is no test training to link.', 'talenttrack' )
            );
        }

        $tasks   = new TasksRepository();
        $task_id = ProposeTestTrainingService::openInviteTaskId( $prospect_id );

        if ( $task_id <= 0 ) {
            $task_id = $tasks->create( [
                'template_key'     => InviteToTestTrainingTemplate::KEY,
                'assignee_user_id' => $user_id,
                'due_at'           => current_time( 'mysql' ),
                'prospect_id'      => $prospect_id,
            ] );
        }

        if ( $task_id <= 0 ) {
            return new \WP_Error(
                'task_not_created',
                __( 'The test training was saved but could not be linked to the prospect.', 'talenttrack' )
            );
        }

        $ok = WorkflowModule::engine()->complete( $task_id, [
            'test_training_id'   => $test_training_id,
            'new_date'           => '',
            'new_location'       => '',
            // The direct route has no invitation composer on it — the
            // person arranging the session is the person who will send
            // the message, and they are already in touch with the family.
            'invitation_message' => '',
        ] );

        if ( ! $ok ) {
            return new \WP_Error(
                'link_failed',
                __( 'The test training was saved but could not be linked to the prospect.', 'talenttrack' )
            );
        }

        return $task_id;
    }

    /**
     * The prospects this user could arrange a test training for, for the
     * form's picker. Narrowed by the viewer's scope in SQL, not after the
     * fact, and never wider than `ProspectScope` allows.
     *
     * @return list<array{id:int,label:string}>
     */
    public static function pickableFor( int $user_id, int $limit = 200 ): array {
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_invite_prospects' ) ) return [];

        $rows = ( new ProspectsRepository() )->search( [
            'status'    => 'active',
            'scope_sql' => ProspectScope::sqlClause( $user_id, '' ),
            'orderby'   => 'last_name',
            'order'     => 'asc',
            'limit'     => $limit,
        ] );

        $out = [];
        foreach ( $rows as $found ) {
            $row  = (array) $found;
            $name = trim( (string) ( $row['first_name'] ?? '' ) . ' ' . (string) ( $row['last_name'] ?? '' ) );
            if ( $name === '' ) $name = __( '(unnamed prospect)', 'talenttrack' );
            $club = (string) ( $row['current_club'] ?? '' );
            $out[] = [
                'id'    => (int) ( $row['id'] ?? 0 ),
                'label' => $club !== '' ? $name . ' — ' . $club : $name,
            ];
        }
        return $out;
    }
}
