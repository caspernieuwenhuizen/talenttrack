<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Prospects\Repositories\ProspectsRepository;
use TT\Modules\Workflow\Chain\ChainStep;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\ConfirmTestTrainingForm;
use TT\Modules\Workflow\Resolvers\RoleBasedResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * ConfirmTestTrainingTemplate (#0081 child 2b — onboarding pipeline link 3/5).
 *
 * Spawned by `InviteToTestTrainingTemplate` once the HoD has chosen a
 * session and composed the invitation. The task is technically assigned
 * to the HoD (because parents aren't in the WP user model the same way
 * staff are), but its completion is **also** triggerable by an inbound
 * parent action — the public REST endpoint `POST /prospects/{token}/confirm`
 * accepts a parent's click on the invitation email's "yes I'll come"
 * link and completes the task on the parent's behalf.
 *
 * Pipeline stage: **Invited** (still column 2 on the future widget).
 * Stays here until the parent confirms / declines.
 *
 * Default deadline: 5 days. Anchored to the test-training session date
 * minus 1 day in `defaultDeadlineOffset()` only as a relative offset
 * (the engine's offset is "+N days" relative to spawn time, not to
 * an arbitrary date) — operators that want strict "1 day before
 * session" timing can override per-template via `tt_workflow_template_config`.
 *
 * Chain steps:
 *   - confirmed → spawn `RecordTestTrainingOutcomeTemplate` for the
 *     coach to record the post-session outcome.
 *   - declined / no_show → archive the prospect with the matching
 *     reason; no chain spawn.
 *
 * Required cap: `tt_invite_prospects` (HoD, Admin per matrix).
 */
class ConfirmTestTrainingTemplate extends TaskTemplate {

    public const KEY = 'confirm_test_training';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'Confirm test-training attendance', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Track the parent confirmation for an invited prospect — confirmed, declined, or no response.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+5 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new RoleBasedResolver( 'tt_head_dev' );
    }

    public function formClass(): string {
        return ConfirmTestTrainingForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id' ];
    }

    /**
     * On completion: archive the prospect with the right reason for
     * non-confirmed outcomes. The chain step below handles the
     * confirmed-path spawn; this hook handles the terminal-outcome
     * side effects.
     */
    public function onComplete( array $task, array $response ): void {
        $outcome     = (string) ( $response['outcome'] ?? '' );
        $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
        if ( $prospect_id <= 0 || $outcome === '' ) return;

        if ( $outcome === 'declined' ) {
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_PARENT_WITHDREW,
                (int) ( $task['assignee_user_id'] ?? 0 )
            );
        } elseif ( $outcome === 'no_response' ) {
            ( new ProspectsRepository() )->archive(
                $prospect_id,
                ProspectsRepository::ARCHIVE_REASON_NO_SHOW,
                (int) ( $task['assignee_user_id'] ?? 0 )
            );
        }
    }

    public function chainSteps(): array {
        return [
            new ChainStep(
                'await_outcome',
                RecordTestTrainingOutcomeTemplate::KEY,
                static function ( array $task, array $response ): bool {
                    return ( (string) ( $response['outcome'] ?? '' ) ) === 'confirmed';
                },
                static function ( array $task, array $response ): TaskContext {
                    return new TaskContext(
                        null, null, null, null, null, null,
                        (int) ( $task['id'] ?? 0 ),
                        (int) ( $task['prospect_id'] ?? 0 )
                    );
                },
                'Coach records the post-attendance outcome'
            ),
        ];
    }
}
