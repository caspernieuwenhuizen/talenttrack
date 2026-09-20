<?php
namespace TT\Modules\Alerts\Definitions;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Contracts\AudienceAwareAlert;
use TT\Modules\Alerts\Domain\AlertAudience;
use TT\Modules\Alerts\Domain\AlertContext;
use TT\Modules\Alerts\Domain\Severity;

/**
 * GoalUpdatedForMyChildAlert (#3795) — a goal on your child's plan has
 * changed since it was written.
 *
 * Which player question does this answer? *What does this player need
 * next?* The family reads the same goal the coach does; when its status or
 * its date moves, the answer to that question has moved with it. The
 * reporter found out their son's goal had been re-dated only because the
 * coach happened to text them.
 *
 * ## Derived from state, not from a save hook
 *
 * Deliberately. An alert that depended on an action firing at the moment of
 * the edit would miss every change made by an import, a bulk edit, a CLI
 * run or a route that forgot to fire it — and would be silently wrong in
 * exactly the cases nobody tests. `tt_goals.updated_at` moves on any write
 * (`ON UPDATE CURRENT_TIMESTAMP`), so "changed since it was created, within
 * the window" is a condition the sweep can read off the row, and the
 * reconcile resolves it when the window passes.
 *
 * The cost of that choice, stated plainly: the schema records *that* the row
 * changed, not *which column*. A title correction therefore reads as a
 * change like a re-dating does. The alternative was a per-column history
 * table for one notification, which is a much larger thing than the one the
 * family asked for; the copy says "was updated" rather than claiming to know
 * which field moved.
 *
 * ## Family audience only
 *
 * Whoever made the change does not need telling they made it, and the staff
 * side of an unattended goal is already `goals.past_target_date`.
 */
final class GoalUpdatedForMyChildAlert extends AbstractPlayerAlert implements AudienceAwareAlert {

    use FamilyAudienceTrait;

    public const SUBJECT_TYPE = 'goal';

    /** tt_config key: how long a change to a goal stays news. */
    public const CONFIG_KEY_RECENT_DAYS = 'alerts_goal_updated_recent_days';

    private const DEFAULT_RECENT_DAYS = 14;

    public function key(): string {
        return 'goals.updated_for_my_child';
    }

    public function module(): string {
        return 'goals';
    }

    public function label(): string {
        return __( 'A goal for your child was updated', 'talenttrack' );
    }

    public function description(): string {
        return __( 'A development goal on your child\'s plan has changed since it was written — its status, its target date or its wording.', 'talenttrack' );
    }

    /** @return list<string> */
    public function audiences(): array {
        return [ AlertAudience::PARENT ];
    }

    /**
     * Empty on purpose — see `EvaluationSharedWithFamilyAlert::capRequired()`.
     * The guardian link is the gate, re-checked on every run.
     */
    public function capRequired(): string {
        return '';
    }

    public function subjectType(): string {
        return self::SUBJECT_TYPE;
    }

    /** News, not a problem. */
    public function defaultSeverity(): string {
        return Severity::INFO;
    }

    protected function titleFor( object $row ): string {
        return $this->familyTitleFor( $row );
    }

    protected function familyTitleFor( object $row ): string {
        $goal = trim( (string) ( $row->goal_title ?? '' ) );
        if ( $goal === '' ) $goal = __( 'Untitled goal', 'talenttrack' );

        return sprintf(
            /* translators: 1: player name, 2: goal title */
            __( '%1$s\'s goal "%2$s" was updated.', 'talenttrack' ),
            $this->playerName( $row ),
            $goal
        );
    }

    /** @return array<string,mixed> */
    protected function familyPayloadFor( object $row ): array {
        return [
            'goal_title' => (string) ( $row->goal_title ?? '' ),
            'status'     => (string) ( $row->status ?? '' ),
            'due_date'   => (string) ( $row->due_date ?? '' ),
        ];
    }

    /** @return list<object> */
    protected function rows( AlertContext $context ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $days = $this->threshold( self::CONFIG_KEY_RECENT_DAYS, self::DEFAULT_RECENT_DAYS );

        // `updated_at > created_at` is what separates a change from the
        // creation. Both columns default to the same timestamp on insert, so
        // a goal that has never been touched since it was written never
        // matches — a family should hear about a goal being *written* from
        // the conversation that wrote it, not from a notification.
        //
        // The join to players is the archive / status gate as everywhere
        // else: a released player's goals stop notifying, which is the same
        // rule `ParentChildResolver` applies to guardian access itself.
        $sql = $wpdb->prepare(
            "SELECT g.id AS subject_id, g.player_id, g.title AS goal_title,
                    g.status, g.due_date,
                    p.first_name, p.last_name, p.team_id
               FROM {$p}tt_goals g
         INNER JOIN {$p}tt_players p ON p.id = g.player_id
              WHERE " . QueryHelpers::clubScopeWhere( 'g' ) . "
                AND g.archived_at IS NULL
                AND g.trashed_at IS NULL
                AND g.updated_at IS NOT NULL
                AND g.created_at IS NOT NULL
                AND g.updated_at > g.created_at
                AND g.updated_at >= DATE_SUB( NOW(), INTERVAL %d DAY )
                AND " . $this->activePlayerWhere( 'p' )
            . $context->applyScope( self::SUBJECT_TYPE, 'g.id' ) . "
              ORDER BY g.updated_at DESC, g.id ASC",
            $days
        );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $sql );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = $row;
        }
        return $out;
    }
}
