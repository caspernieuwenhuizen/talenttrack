<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * EvaluationNotSharedAlert (#2636, epic #2629) — an evaluation exists but
 * the player was never told anything.
 *
 * Which player question does this answer? *What does this player need
 * next?* An evaluation the player never sees cannot answer that for them.
 * The ratings are staff analysis and the `notes` field is explicitly
 * staff-only; the one part written for the player to read is
 * `player_feedback` (#1386), rendered on their "My evaluations" tile and
 * visible to their parents. An evaluation with that field empty has been
 * recorded *about* a player without anything being said *to* them.
 *
 * "Never shared" is exactly that empty field. There is no separate share
 * flag on `tt_evaluations` and this definition deliberately does not invent
 * one — the player-facing surface reads `player_feedback`, so the honest
 * test of whether the player got something is whether that field has
 * content.
 *
 * ## Why the lookback is bounded
 *
 * Feedback has a short shelf life: telling a coach in April what they
 * should have written to a player in September is not actionable, it is
 * just a backlog. Worse, an unbounded query would return every
 * feedback-less evaluation the academy ever recorded, blow through the
 * evaluator's occurrence ceiling on the first sweep, and take the rest of
 * the definition's results down with it. Two thresholds bound it, both in
 * `tt_config`: a grace period before the alert appears, and a lookback
 * after which it stops.
 */
final class EvaluationNotSharedAlert extends AbstractPlayerAlert {

    public const SUBJECT_TYPE = 'evaluation';

    /** tt_config key: days after the evaluation before the alert appears. */
    public const CONFIG_KEY_GRACE_DAYS = 'alerts_eval_share_grace_days';

    /** tt_config key: how far back the alert still considers an evaluation. */
    public const CONFIG_KEY_LOOKBACK_DAYS = 'alerts_eval_share_lookback_days';

    private const DEFAULT_GRACE_DAYS    = 7;
    private const DEFAULT_LOOKBACK_DAYS = 60;

    public function key(): string {
        return 'evaluations.saved_not_shared';
    }

    public function module(): string {
        return 'evaluations';
    }

    public function label(): string {
        return __( 'Evaluation not shared with the player', 'talenttrack' );
    }

    public function description(): string {
        return __( 'An evaluation was recorded but nothing was written for the player to read. The ratings and the internal notes stay with staff, so from the player\'s side nothing happened at all.', 'talenttrack' );
    }

    /**
     * Adding the feedback means editing the evaluation, so that is the
     * capability that gates receipt.
     */
    public function capRequired(): string {
        return 'tt_edit_evaluations';
    }

    protected function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /**
     * The coach who wrote it. The head coach of the player's team is added
     * by the base class; this is the person who actually has something to
     * say, and the id travelled out of the same query rather than being
     * looked up per row.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [ (int) ( $row->coach_id ?? 0 ) ];
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'evaluations', $this->subjectIdFor( $row ) );
    }

    protected function titleFor( object $row ): string {
        return sprintf(
            /* translators: %s: player name */
            __( 'The evaluation recorded for %s has no feedback for the player.', 'talenttrack' ),
            $this->playerName( $row )
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [ 'eval_date' => (string) ( $row->eval_date ?? '' ) ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p        = $wpdb->prefix;
        $grace    = $this->threshold( self::CONFIG_KEY_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );
        $lookback = $this->threshold( self::CONFIG_KEY_LOOKBACK_DAYS, self::DEFAULT_LOOKBACK_DAYS );

        // A lookback shorter than the grace period would select nothing at
        // all, silently. Clamp rather than trust the pair: these are two
        // independent config keys and nothing stops an admin setting them
        // the wrong way round.
        if ( $lookback <= $grace ) $lookback = $grace + self::DEFAULT_LOOKBACK_DAYS;

        // The join to players is what gives the base class a `team_id` for
        // head-coach resolution, and it doubles as the archive/status gate:
        // an evaluation of a released player is not worth chasing.
        //
        // `club_id IS NULL` is tolerated on the evaluations side because
        // rows predating the #0052 tenancy backfill can still carry it.
        $sql = $wpdb->prepare(
            "SELECT e.id AS subject_id, e.player_id, e.eval_date, e.coach_id,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_evaluations e
         INNER JOIN {$p}tt_players p ON p.id = e.player_id
              WHERE e.archived_at IS NULL
                AND e.trashed_at IS NULL
                AND ( e.club_id = %d OR e.club_id IS NULL )
                AND ( e.player_feedback IS NULL OR e.player_feedback = '' )
                AND e.eval_date <= DATE_SUB( CURDATE(), INTERVAL %d DAY )
                AND e.eval_date >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'e.id' ) . "
              ORDER BY e.eval_date ASC, e.id ASC",
            CurrentClub::id(),
            $grace,
            $lookback
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
