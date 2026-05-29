<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\IdeaStatus as CanonicalIdeaStatus;

/**
 * IdeaStatus — the state machine for `tt_dev_ideas.status`.
 *
 * Internal statuses cover the full lifecycle including the transient
 * `promoting` and `promotion-failed` states. Player-facing labels
 * collapse those down to four buckets per the locked decisions on
 * #0009: "In review", "Accepted", "Not accepted", and "Submitted".
 *
 * v4.12.9 (#988 PR-set 7) — the canonical idea status values moved
 * into `TT\Domain\Vocabularies\Lookups\IdeaStatus`. The constants
 * below alias the new vocabulary for one release per #988's locked
 * plan, and will be removed in the next minor; new code should
 * reference `TT\Domain\Vocabularies\Lookups\IdeaStatus::*` directly.
 * The module-local `label()` / `authorFacingLabel()` / `boardColumns()`
 * helpers stay in place — they encode rendering rules that aren't
 * part of the vocabulary contract.
 */
final class IdeaStatus {

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::SUBMITTED}; removed in next minor. */
    public const SUBMITTED          = CanonicalIdeaStatus::SUBMITTED;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::REFINING}; removed in next minor. */
    public const REFINING           = CanonicalIdeaStatus::REFINING;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::READY_FOR_APPROVAL}; removed in next minor. */
    public const READY_FOR_APPROVAL = CanonicalIdeaStatus::READY_FOR_APPROVAL;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::REJECTED}; removed in next minor. */
    public const REJECTED           = CanonicalIdeaStatus::REJECTED;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::PROMOTING}; removed in next minor. */
    public const PROMOTING          = CanonicalIdeaStatus::PROMOTING;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::PROMOTED}; removed in next minor. */
    public const PROMOTED           = CanonicalIdeaStatus::PROMOTED;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::PROMOTION_FAILED}; removed in next minor. */
    public const PROMOTION_FAILED   = CanonicalIdeaStatus::PROMOTION_FAILED;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::IN_PROGRESS}; removed in next minor. */
    public const IN_PROGRESS        = CanonicalIdeaStatus::IN_PROGRESS;

    /** @deprecated since v4.12.9 — use {@see CanonicalIdeaStatus::DONE}; removed in next minor. */
    public const DONE               = CanonicalIdeaStatus::DONE;

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

    /**
     * Operator-editable label for a stored status value. Resolves
     * through `tt_translations` via `LookupTranslator::byTypeAndName(
     * 'idea_status', $value)`; pre-migration installs fall back to the
     * canonical English label.
     */
    public static function label( string $status ): string {
        if ( $status === '' ) return '';
        if ( class_exists( '\\TT\\Infrastructure\\Query\\LookupTranslator' ) ) {
            $label = \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'idea_status', $status );
            if ( $label !== '' && $label !== $status ) return $label;
        }
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
