<?php
namespace TT\Modules\Workflow\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Forms\RequestConsentForm;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * RequestConsentTemplate (#3812) — ask the child's own club to pass a
 * consent request on to the family.
 *
 * The step between "I spotted a child at another club" and "the family has
 * said yes". It is the one thing that protects the child — the proof that
 * the academy went through the coordinator and never collected family data
 * — and until this template it lived in a scout's mailbox, where the
 * answer to "did the consent emails go out?" was whatever they could
 * remember.
 *
 * ## The task is the state; the log is the record
 *
 * `tt_prospects` still has no status column (the 0066 decision), so the
 * pipeline stage keeps coming from workflow tasks alone and this template
 * is what puts a prospect in the **Consent requested** column. The detail
 * — who was asked, when, what came back — goes to
 * `tt_prospect_consent_requests`, which the classifier never reads. One
 * state machine, as before.
 *
 * Assigned to the scout who discovered the prospect, passed through
 * `TaskContext.extras['initiated_by']` the way `LogProspectTemplate` does:
 * they are the person who knows which club to ring.
 *
 * Deadline: 21 days. Asking a club to pass something on to a family is not
 * a same-week errand, and a deadline that fires before a reasonable club
 * has replied would train people to ignore it. It exists so a request
 * nobody ever chased surfaces on the dashboard.
 *
 * No chain step. This template deliberately spawns nothing: the invite
 * task is arranged by whoever holds that decision, and a consent request
 * that came back `declined` must not auto-produce an invitation.
 */
class RequestConsentTemplate extends TaskTemplate {

    public const KEY = 'request_consent';

    public function key(): string { return self::KEY; }

    public function featureKey(): ?string { return 'onboarding_pipeline_workflow'; }

    public function name(): string {
        return __( 'Request consent from the family', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Ask the child\'s current club to pass a consent request on to the family, and record what came back. No family contact details are collected here — only the route the academy used.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+21 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new LambdaResolver( static function ( TaskContext $ctx ): array {
            $uid = (int) ( $ctx->extras['initiated_by'] ?? 0 );
            return $uid > 0 ? [ $uid ] : [];
        } );
    }

    public function formClass(): string {
        return RequestConsentForm::class;
    }

    public function entityLinks(): array {
        return [ 'prospect_id' ];
    }

    /**
     * Stamp the prospect onto the task row the way `LogProspectTemplate`
     * does, so the pipeline query can join on it. Without this the task
     * exists and the board cannot see it.
     */
    public function onComplete( array $task, array $response ): void {
        $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
        if ( $prospect_id > 0 ) return;

        $prospect_id = isset( $response['prospect_id'] ) ? (int) $response['prospect_id'] : 0;
        if ( $prospect_id <= 0 ) return;

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_workflow_tasks',
            [ 'prospect_id' => $prospect_id ],
            [ 'id' => (int) $task['id'] ]
        );
    }
}
