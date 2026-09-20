<?php
namespace TT\Modules\Prospects\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Prospects\ProspectScope;
use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\Templates\InviteToTestTrainingTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * ProposeTestTrainingService (#3710) — put a prospect forward for a test
 * training when the pipeline chain never produced the invite task.
 *
 * `invite_to_test_training` is the only link between a prospect and a test
 * training, and until this it was only ever spawned by the chain. So every
 * prospect whose chain was cancelled, who was logged while the workflow
 * feature was off, who was imported, or who was seeded by the demo
 * generator sat in the first column with nothing to click. The board could
 * not answer "where is this prospect going next" for any of them.
 *
 * The scout proposes, the head of development decides: this spawns the
 * HoD's invite task, and `tt_invite_prospects` still gates the invitation
 * itself. A scout who has watched a player twice can put them forward
 * without being handed the power to book the trial.
 *
 * The idempotency guard lives here rather than in the view, because the
 * view is not the only possible caller — the REST route is the other — and
 * two scouts clicking on the same prospect must still produce one task.
 */
class ProposeTestTrainingService {

    /** The pipeline templates whose open task means the prospect is already moving. */
    private const PIPELINE_TEMPLATES = [
        'log_prospect',
        'invite_to_test_training',
        'confirm_test_training',
        'record_test_training_outcome',
        'await_team_offer_decision',
    ];

    /**
     * May this user put this prospect forward right now?
     *
     * Four things have to hold: the workflow feature is on (with it off
     * nothing would be created and the button would be a lie), the caller
     * may edit prospects and may see this one, the prospect is still in the
     * funnel, and nothing is already in flight for them.
     */
    public static function canPropose( int $user_id, int $prospect_id ): bool {
        if ( $user_id <= 0 || $prospect_id <= 0 ) return false;
        if ( ! FeatureRegistry::isEnabled( 'onboarding_pipeline_workflow' ) ) return false;
        if ( ! AuthorizationService::userCanOrMatrix( $user_id, 'tt_edit_prospects' ) ) return false;
        if ( ! ProspectScope::canSee( $user_id, $prospect_id ) ) return false;

        $row = ( new ProspectsRepository() )->find( $prospect_id );
        if ( $row === null ) return false;
        $prospect = (array) $row;
        if ( ! empty( $prospect['archived_at'] ) ) return false;
        if ( (int) ( $prospect['promoted_to_player_id'] ?? 0 ) > 0 ) return false;
        if ( (int) ( $prospect['promoted_to_trial_case_id'] ?? 0 ) > 0 ) return false;

        return ! self::hasPipelineHistory( $prospect_id );
    }

    /**
     * Spawn the invite task, or hand back the one that is already open.
     *
     * @return int|\WP_Error The task id the caller should be taken to.
     */
    public static function propose( int $user_id, int $prospect_id ) {
        $existing = self::openInviteTaskId( $prospect_id );
        if ( $existing > 0 ) return $existing;

        if ( ! self::canPropose( $user_id, $prospect_id ) ) {
            return new \WP_Error(
                'cannot_propose',
                __( 'This prospect cannot be put forward for a test training.', 'talenttrack' )
            );
        }

        $task_ids = WorkflowModule::engine()->dispatch(
            InviteToTestTrainingTemplate::KEY,
            new TaskContext( null, null, null, null, null, null, null, $prospect_id )
        );
        if ( empty( $task_ids ) ) {
            // The engine answers with an empty list when the template is
            // switched off or resolves to nobody. The second is the one an
            // academy hits: no account holds the head-of-development role,
            // so there is nobody to address the invite to.
            return new \WP_Error(
                'no_task_created',
                __( 'Nobody could be found to send this to. Check that somebody holds the head of development role.', 'talenttrack' )
            );
        }

        return (int) $task_ids[0];
    }

    /**
     * The open `invite_to_test_training` task for this prospect, if any.
     * This is the idempotency guard: a second proposal returns the first
     * one's task rather than addressing the HoD twice about one child.
     */
    public static function openInviteTaskId( int $prospect_id ): int {
        if ( $prospect_id <= 0 ) return 0;
        global $wpdb;
        $tasks = $wpdb->prefix . 'tt_workflow_tasks';

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$tasks}
              WHERE club_id = %d AND prospect_id = %d
                AND template_key = 'invite_to_test_training'
                AND status IN ('open','in_progress','overdue')
              ORDER BY id ASC LIMIT 1",
            CurrentClub::id(),
            $prospect_id
        ) );
    }

    /**
     * Has this prospect ever been through the pipeline? An open task means
     * they are already moving; a completed one means they were invited once
     * already, and a second proposal would walk them backwards through a
     * stage they have left.
     */
    private static function hasPipelineHistory( int $prospect_id ): bool {
        global $wpdb;
        $tasks = $wpdb->prefix . 'tt_workflow_tasks';

        $placeholders = implode( ',', array_fill( 0, count( self::PIPELINE_TEMPLATES ), '%s' ) );
        $params       = array_merge(
            [ CurrentClub::id(), $prospect_id ],
            self::PIPELINE_TEMPLATES
        );

        $sql = "SELECT COUNT(*) FROM {$tasks}
                 WHERE club_id = %d AND prospect_id = %d
                   AND template_key IN ({$placeholders})
                   AND status IN ('open','in_progress','overdue','completed')";

        return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) > 0;
    }
}
