<?php
namespace TT\Modules\Pdp\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;

/**
 * PdpConversationDueTemplate — fires per (player, conversation) at the
 * configured lead time before scheduled_at. Assigned to the PDP file's
 * owner_coach_id; escalates to head of academy once the deadline passes
 * (escalation lands in Sprint 2).
 *
 * Sprint 1 ships the template registration only; the cron / event
 * dispatcher wiring + the live form land in Sprint 2.
 */
class PdpConversationDueTemplate extends TaskTemplate {

    public const KEY = 'pdp_conversation_due';

    public function key(): string { return self::KEY; }

    public function name(): string {
        return __( 'PDP conversation due', 'talenttrack' );
    }

    public function description(): string {
        return __( 'Reminder for the owning coach that a PDP conversation is coming up.', 'talenttrack' );
    }

    public function defaultSchedule(): array {
        // Sprint 2 swaps to event-based ('tt_pdp_conversation_scheduled').
        return [ 'type' => 'manual' ];
    }

    public function defaultDeadlineOffset(): string {
        return '+7 days';
    }

    public function defaultAssignee(): AssigneeResolver {
        return new LambdaResolver( static function ( TaskContext $context ): array {
            $convs = new PdpConversationsRepository();
            $files = new PdpFilesRepository();
            $conv_id = (int) ( $context->extras['conversation_id'] ?? 0 );
            $conv = $conv_id > 0 ? $convs->find( $conv_id ) : null;
            if ( ! $conv ) return [];
            $file = $files->find( (int) $conv->pdp_file_id );
            if ( ! $file || empty( $file->owner_coach_id ) ) return [];
            return [ (int) $file->owner_coach_id ];
        } );
    }

    public function formClass(): string {
        return PdpStubForm::class;
    }

    public function entityLinks(): array {
        return [ 'player_id' ];
    }
}
