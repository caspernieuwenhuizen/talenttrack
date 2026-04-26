<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * IdeaStatus — the state machine for `tt_dev_ideas.status`.
 *
 * Internal statuses cover the full lifecycle including the transient
 * `promoting` and `promotion-failed` states. Player-facing labels
 * collapse those down to four buckets per the locked decisions on
 * #0009: "In review", "Accepted", "Not accepted", and "Submitted".
 */
final class IdeaStatus {

    public const SUBMITTED         = 'submitted';
    public const REFINING          = 'refining';
    public const READY_FOR_APPROVAL = 'ready-for-approval';
    public const REJECTED          = 'rejected';
    public const PROMOTING         = 'promoting';
    public const PROMOTED          = 'promoted';
    public const PROMOTION_FAILED  = 'promotion-failed';
    public const IN_PROGRESS       = 'in-progress';
    public const DONE              = 'done';

    /** @return list<string> */
    public static function all(): array {
        return [
            self::SUBMITTED,
            self::REFINING,
            self::READY_FOR_APPROVAL,
            self::REJECTED,
            self::PROMOTING,
            self::PROMOTED,
            self::PROMOTION_FAILED,
            self::IN_PROGRESS,
            self::DONE,
        ];
    }

    /** @return list<string> Statuses shown in the kanban board. */
    public static function boardColumns(): array {
        return [
            self::SUBMITTED,
            self::REFINING,
            self::READY_FOR_APPROVAL,
            self::PROMOTED,
            self::IN_PROGRESS,
            self::DONE,
        ];
    }

    public static function label( string $status ): string {
        switch ( $status ) {
            case self::SUBMITTED:          return __( 'Submitted', 'talenttrack' );
            case self::REFINING:           return __( 'Refining', 'talenttrack' );
            case self::READY_FOR_APPROVAL: return __( 'Ready for approval', 'talenttrack' );
            case self::REJECTED:           return __( 'Rejected', 'talenttrack' );
            case self::PROMOTING:          return __( 'Promoting…', 'talenttrack' );
            case self::PROMOTED:           return __( 'Accepted', 'talenttrack' );
            case self::PROMOTION_FAILED:   return __( 'Promotion failed', 'talenttrack' );
            case self::IN_PROGRESS:        return __( 'In progress', 'talenttrack' );
            case self::DONE:               return __( 'Done', 'talenttrack' );
        }
        return $status;
    }

    /**
     * Map an internal status to the four-bucket player-facing label.
     * `promoting` and `promotion-failed` are transient-internal — they
     * collapse to "In review" so authors don't see API plumbing.
     */
    public static function authorFacingLabel( string $status ): string {
        switch ( $status ) {
            case self::SUBMITTED:
            case self::REFINING:
            case self::READY_FOR_APPROVAL:
            case self::PROMOTING:
            case self::PROMOTION_FAILED:
                return __( 'In review', 'talenttrack' );
            case self::REJECTED:
                return __( 'Not accepted', 'talenttrack' );
            case self::PROMOTED:
            case self::IN_PROGRESS:
            case self::DONE:
                return __( 'Accepted', 'talenttrack' );
        }
        return self::label( $status );
    }
}
