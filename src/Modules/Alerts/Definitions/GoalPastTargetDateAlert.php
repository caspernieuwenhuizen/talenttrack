<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * GoalPastTargetDateAlert (#2636, epic #2629) — a development goal whose
 * date has passed and which nobody closed.
 *
 * Which player question does this answer? *What does this player need
 * next?* A goal past its date and still open is the one piece of the
 * player's record that claims to answer that and no longer does. Either the
 * player achieved it and nobody said so, or they did not and the plan needs
 * changing. Both are real answers; leaving the row untouched is not one.
 *
 * "Still open" is the same predicate every goals query uses — anything that
 * is not `completed` or `cancelled`. Deliberately not a list of open
 * statuses: `tt_goals.status` has picked up values over the years
 * (`pending_approval` in #0058, display-cased strings from older imports),
 * and an allowlist would silently stop alerting the day another appears.
 *
 * ## Both thresholds are configuration
 *
 * A short grace after the date, because a goal reviewed on Monday for a
 * Sunday deadline is normal practice and an alert on Sunday evening would
 * be wrong more often than right. And a lookback, because a goal more than
 * a season past its date is not overdue — it is abandoned, and the fix is a
 * tidy-up rather than an alert. Without the second bound, the first sweep
 * after this ships returns every stale goal the academy ever wrote and
 * buries the handful still worth acting on.
 */
final class GoalPastTargetDateAlert extends AbstractPlayerAlert {

    public const SUBJECT_TYPE = 'goal';

    /** tt_config key: days after the target date before the alert appears. */
    public const CONFIG_KEY_GRACE_DAYS = 'alerts_goal_overdue_grace_days';

    /** tt_config key: how far past its date a goal is still worth chasing. */
    public const CONFIG_KEY_LOOKBACK_DAYS = 'alerts_goal_overdue_lookback_days';

    private const DEFAULT_GRACE_DAYS    = 3;
    private const DEFAULT_LOOKBACK_DAYS = 365;

    /**
     * Severity ageing is the definition's own judgement about how much the
     * condition matters, not a club policy knob — the club-configurable
     * escalation epic decision 13 describes is `escalatesTo`, which lands
     * with the Workflow interop in #2635.
     */
    private const URGENT_AFTER_DAYS = 30;

    public function key(): string {
        return 'goals.past_target_date';
    }

    public function module(): string {
        return 'goals';
    }

    public function label(): string {
        return __( 'Goal past its target date', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A development goal has passed the date it was aimed at and is still open. Either the player got there and nobody recorded it, or the plan needs changing.', 'talenttrack' );
    }

    public function capRequired(): string {
        return 'tt_edit_goals';
    }

    /** A month past the date, the goal has stopped being a plan. */
    protected function severityFor( object $row ): string {
        return $this->daysSince( (string) ( $row->due_date ?? '' ) ) >= self::URGENT_AFTER_DAYS
            ? Severity::URGENT
            : Severity::ATTENTION;
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /**
     * Whoever set the goal. The head coach of the player's team is added by
     * the base class; the id travelled out of the same query rather than
     * being looked up per row.
     *
     * @return list<int>
     */
    protected function extraRecipientsFor( object $row ): array {
        return [ (int) ( $row->created_by ?? 0 ) ];
    }

    protected function urlFor( object $row ): string {
        return RecordLink::detailUrlFor( 'goals', $this->subjectIdFor( $row ) );
    }

    protected function titleFor( object $row ): string {
        $goal = trim( (string) ( $row->goal_title ?? '' ) );
        if ( $goal === '' ) $goal = __( 'Untitled goal', 'talenttrack' );

        return sprintf(
            /* translators: 1: player name, 2: goal title */
            __( '%1$s\'s goal "%2$s" is past its target date and still open.', 'talenttrack' ),
            $this->playerName( $row ),
            $goal
        );
    }

    /** @return array<string,mixed> */
    protected function payloadFor( object $row ): array {
        return [
            'goal_title' => (string) ( $row->goal_title ?? '' ),
            'due_date'   => (string) ( $row->due_date ?? '' ),
        ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p        = $wpdb->prefix;
        $grace    = $this->threshold( self::CONFIG_KEY_GRACE_DAYS, self::DEFAULT_GRACE_DAYS );
        $lookback = $this->threshold( self::CONFIG_KEY_LOOKBACK_DAYS, self::DEFAULT_LOOKBACK_DAYS );

        // Two independent config keys, and nothing stops an admin setting
        // them the wrong way round — which would select nothing at all, and
        // silently.
        if ( $lookback <= $grace ) $lookback = $grace + self::DEFAULT_LOOKBACK_DAYS;

        // The join to players gives the base class a `team_id` for
        // head-coach resolution and doubles as the archive/status gate: a
        // released player's stale goals are not worth chasing.
        //
        // The zero-date guard is not paranoia — imports and the Spond sync
        // write `0000-00-00`, which is neither NULL nor empty and compares
        // as older than every real date, so without it every such goal is
        // permanently overdue.
        $sql = $wpdb->prepare(
            "SELECT g.id AS subject_id, g.player_id, g.title AS goal_title,
                    g.due_date, g.created_by,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_goals g
         INNER JOIN {$p}tt_players p ON p.id = g.player_id
              WHERE " . QueryHelpers::clubScopeWhere( 'g' ) . "
                AND g.archived_at IS NULL
                AND g.trashed_at IS NULL
                AND ( g.status IS NULL OR g.status NOT IN ( 'completed', 'cancelled' ) )
                AND g.due_date IS NOT NULL
                AND g.due_date <> '0000-00-00'
                AND g.due_date <= DATE_SUB( CURDATE(), INTERVAL %d DAY )
                AND g.due_date >= DATE_SUB( CURDATE(), INTERVAL %d DAY )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'g.id' ) . "
              ORDER BY g.due_date ASC, g.id ASC",
            $grace,
            $lookback
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );
        return is_array( $rows ) ? $rows : [];
    }
}
