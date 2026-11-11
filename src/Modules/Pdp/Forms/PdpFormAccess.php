<?php
namespace TT\Modules\Pdp\Forms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Players\ParentChildResolver;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Pdp\PdpAccess;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Modules\Wizards\Evaluation\EvaluationInserter;

/**
 * PdpFormAccess (#4977, epic #4975) — who may read and write which part of
 * a PDP conversation form, and when.
 *
 * | Part                                       | Linked player | Linked parent        | Staff who may see the file | Staff who may edit the file |
 * |--------------------------------------------|---------------|----------------------|----------------------------|-----------------------------|
 * | Player's part, before "klaar"              | read, write   | none                 | none                       | none                        |
 * | Player's part, after "klaar", before sign-off | read, write until locked | none   | read                       | read                        |
 * | Trainer's part, before sign-off            | none          | none                 | read                       | read, write                 |
 * | Everything, after sign-off                 | read          | read, if `pdp` shown | read                       | read                        |
 *
 * - Staff includes the global PDP readers: the head of development and the
 *   academy admin see nothing of the player's part before "klaar" either
 *   (owner decision 5 has no exception).
 * - A parent sees nothing of the form before sign-off, not even their own
 *   child's preparation (less visibility by default, CLAUDE.md §1).
 * - The player's scores are the player's own: no one else writes them
 *   (decision 8).
 * - The read-only observer holds no PDP right and is refused before this
 *   gate (`tt_view_pdp`).
 *
 * The gate sits beside the repository, as `PdpPrepAccess` does, so every
 * surface asks the same question: the REST routes, the views, the print.
 * The shape a surface reads is `PdpFormReader`.
 */
final class PdpFormAccess {

    private int $user_id;
    private object $conversation;
    private int $player_id;

    private ?bool $is_player = null;
    private ?bool $is_parent = null;
    private ?bool $sees_file = null;
    private ?bool $edits_file = null;
    private ?bool $current_talk = null;

    public function __construct( int $user_id, object $conversation, int $player_id ) {
        $this->user_id      = $user_id;
        $this->conversation = $conversation;
        $this->player_id    = $player_id;
    }

    /**
     * The gate for one conversation, as a user: the conversation and its
     * player loaded in the current club. Null when the conversation does
     * not exist here, so another club's reads as missing (404), never as
     * forbidden. Every form route starts here.
     */
    public static function forConversation( int $user_id, int $conversation_id ): ?self {
        if ( $conversation_id <= 0 ) return null;
        $conversation = ( new \TT\Modules\Pdp\Repositories\PdpConversationsRepository() )->find( $conversation_id );
        if ( $conversation === null ) return null;
        $player_id = \TT\Modules\Pdp\Prep\PdpPrepAccess::playerFor( $conversation_id );
        if ( $player_id <= 0 ) return null;
        return new self( $user_id, $conversation, $player_id );
    }

    public function conversation(): object {
        return $this->conversation;
    }

    public function playerId(): int {
        return $this->player_id;
    }

    /** May the user open the form at all? */
    public function canRead(): bool {
        if ( $this->user_id <= 0 || $this->player_id <= 0 ) return false;
        if ( $this->isPlayer() || $this->seesFile() ) return true;
        return $this->signedOff() && $this->parentReads();
    }

    /** May the user read the player's scores and answers? */
    public function readsPlayerPart(): bool {
        if ( $this->isPlayer() ) return true;
        if ( $this->seesFile() ) return $this->prepared() || $this->signedOff();
        return $this->signedOff() && $this->parentReads();
    }

    /** May the user read the trainer's scores, answers and SMART points? */
    public function readsCoachPart(): bool {
        if ( $this->seesFile() ) return true;
        if ( ! $this->signedOff() ) return false;
        return $this->isPlayer() || $this->parentReads();
    }

    /** Is the user the player the form is about? */
    public function isPlayer(): bool {
        if ( $this->is_player === null ) {
            global $wpdb;
            $this->is_player = $this->user_id > 0 && $this->player_id > 0 && (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE id = %d AND wp_user_id = %d AND club_id = %d",
                $this->player_id, $this->user_id, CurrentClub::id()
            ) ) > 0;
        }
        return $this->is_player;
    }

    /** May the user write the player's part now? Only the player, until it locks. */
    public function writesPlayerPart( ?int $now_ts = null ): bool {
        return $this->isPlayer() && ! PdpCycleState::playerPartLocked( $this->conversation, $now_ts );
    }

    /** May the user write the trainer's part? Staff who may edit the file, until sign-off. */
    public function writesCoachPart(): bool {
        return ! $this->signedOff() && $this->editsFile();
    }

    /** May the user choose the form? The same staff, on the same terms. */
    public function choosesForm(): bool {
        return $this->writesCoachPart();
    }

    /**
     * May the user sign the conversation off? Staff who may edit the file
     * and may write an evaluation for the player, because sign-off writes one.
     */
    public function signsOff(): bool {
        return $this->editsFile() && EvaluationInserter::mayWriteFor( $this->user_id, $this->player_id );
    }

    /**
     * Is this the talk the file is on now (#2041)? The trainer's part is
     * talk content, written and signed off in order, like the conversation
     * route's notes. Choosing the form stays open, as scheduling does.
     */
    public function isCurrentTalk(): bool {
        if ( $this->current_talk === null ) {
            $row = (array) $this->conversation;
            $this->current_talk = (int) ( $row['id'] ?? 0 ) === ( new \TT\Modules\Pdp\Repositories\PdpConversationsRepository() )->activeConversationId( (int) ( $row['pdp_file_id'] ?? 0 ) );
        }
        return $this->current_talk;
    }

    /**
     * Would the trainer's part take a write from this user now? The same
     * rule `PATCH …/form/coach` applies: not signed off, held, the current
     * talk, and a user who writes the trainer's part.
     */
    public function coachWritable(): bool {
        return $this->writesCoachPart()
            && ! PdpCycleState::isNotHeld( $this->conversation )
            && $this->isCurrentTalk();
    }

    /**
     * May this user sign the conversation off now? A writable trainer's
     * part, and the right to sign off. Completeness is `PdpFormSignoff`'s,
     * read as `missing`.
     */
    public function signsOffNow(): bool {
        return $this->coachWritable() && $this->signsOff();
    }

    private function signedOff(): bool {
        return ! empty( ( (array) $this->conversation )['coach_signoff_at'] );
    }

    private function prepared(): bool {
        return ! empty( ( (array) $this->conversation )['player_prepared_at'] );
    }

    private function seesFile(): bool {
        if ( $this->sees_file === null ) {
            $this->sees_file = ! $this->isPlayer() && PdpAccess::canSeeFile( $this->user_id, $this->player_id );
        }
        return $this->sees_file;
    }

    private function editsFile(): bool {
        if ( $this->edits_file === null ) {
            $this->edits_file = ! $this->isPlayer() && PdpAccess::canEditFile( $this->user_id, $this->player_id );
        }
        return $this->edits_file;
    }

    /**
     * A linked parent whose child has not kept the PDP from them (owner
     * decision 7: inside the PDP the scores follow the `pdp` switch).
     */
    private function parentReads(): bool {
        if ( $this->is_parent === null ) {
            $this->is_parent = ParentChildResolver::isParentOf( $this->user_id, $this->player_id, ParentChildResolver::NEED_RECORD )
                && AuthorizationService::parentCanViewSection( $this->user_id, $this->player_id, 'pdp' );
        }
        return $this->is_parent;
    }
}
